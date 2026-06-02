#!/usr/local/bin/python3

"""
    Copyright (c) 2026 OPNsense

    Redistribution and use in source and binary forms, with or without
    modification, are permitted provided that the following conditions are met:

    1. Redistributions of source code must retain the above copyright notice,
     this list of conditions and the following disclaimer.

    2. Redistributions in binary form must reproduce the above copyright
     notice, this list of conditions and the following disclaimer in the
     documentation and/or other materials provided with the distribution.

    THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
    INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
    AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
    AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
    OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
    SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
    INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
    CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
    ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
    POSSIBILITY OF SUCH DAMAGE.

    --------------------------------------------------------------------------

    Re-evaluate live pf states against the ruleset that was just loaded ("rematch states").

    Invoked (detached) from filter_configure_sync() after a successful filter reload, when
    system/rematch_states is enabled. The normalized ruleset is read from
    /tmp/rematch_ruleset.json (written by the same reload). For every state created by an
    automation (MVC) filter rule, we evaluate the current ruleset; states that would no longer
    be passed are killed, so traffic immediately follows the updated ruleset instead of
    persisting on stale states until they expire.
"""
import ipaddress
import os
import subprocess
import syslog

import ujson

from lib.states import query_states
from lib import rematch

RULESET_FILE = '/tmp/rematch_ruleset.json'


class SystemResolver:
    """Resolve address tokens against the running system (alias tables + interface addresses)."""

    def __init__(self):
        self._alias_cache = {}
        self._iface_cache = None

    def _load_interfaces(self):
        if self._iface_cache is not None:
            return self._iface_cache
        self._iface_cache = {}
        try:
            sp = subprocess.run(['/sbin/ifconfig', '-a'], capture_output=True, text=True)
        except OSError:
            return self._iface_cache
        current = None
        for line in sp.stdout.splitlines():
            if line and not line[0].isspace():
                current = line.split(':')[0]
                self._iface_cache[current] = {'networks': [], 'addresses': [], 'groups': []}
            elif current is not None:
                parts = line.split()
                try:
                    if parts and parts[0] == 'groups:':
                        self._iface_cache[current]['groups'].extend(parts[1:])
                    elif parts and parts[0] == 'inet':
                        addr = parts[1]
                        netmask = parts[parts.index('netmask') + 1] if 'netmask' in parts else None
                        prefix = _netmask_to_prefix(netmask) if netmask else 32
                        self._iface_cache[current]['networks'].append(
                            ipaddress.ip_network('%s/%d' % (addr, prefix), strict=False))
                        self._iface_cache[current]['addresses'].append(
                            ipaddress.ip_network('%s/32' % addr))
                    elif parts and parts[0] == 'inet6':
                        addr = parts[1].split('%')[0]
                        prefix = int(parts[parts.index('prefixlen') + 1]) if 'prefixlen' in parts else 128
                        self._iface_cache[current]['networks'].append(
                            ipaddress.ip_network('%s/%d' % (addr, prefix), strict=False))
                        self._iface_cache[current]['addresses'].append(
                            ipaddress.ip_network('%s/128' % addr))
                except (ValueError, IndexError):
                    continue
        return self._iface_cache

    def alias(self, name):
        if name in self._alias_cache:
            return self._alias_cache[name]
        result = None
        try:
            sp = subprocess.run(['/sbin/pfctl', '-t', name, '-T', 'show'],
                                capture_output=True, text=True)
            if sp.returncode == 0:
                nets = []
                for entry in sp.stdout.split():
                    try:
                        nets.append(ipaddress.ip_network(entry.strip(), strict=False))
                    except ValueError:
                        continue
                result = nets
        except OSError:
            result = None
        self._alias_cache[name] = result
        return result

    def iface_net(self, ifn):
        data = self._load_interfaces().get(ifn)
        return data['networks'] if data else None

    def iface_addr(self, ifn):
        data = self._load_interfaces().get(ifn)
        return data['addresses'] if data else None

    def iface_groups(self, ifn):
        data = self._load_interfaces().get(ifn)
        return data['groups'] if data else []

    def self_addr(self):
        nets = []
        for data in self._load_interfaces().values():
            nets.extend(data['addresses'])
        return nets


def _netmask_to_prefix(netmask):
    """ifconfig prints the IPv4 netmask in hex (e.g. 0xffffff00)."""
    try:
        if netmask.startswith('0x'):
            return bin(int(netmask, 16)).count('1')
        return ipaddress.ip_network('0.0.0.0/%s' % netmask, strict=False).prefixlen
    except (ValueError, AttributeError):
        return 32


def kill_by_ids(state_ids):
    commands = ["/sbin/pfctl -k id -k %s" % sid for sid in state_ids]
    chunk_size = 500
    for chunk in [commands[i:i + chunk_size] for i in range(0, len(commands), chunk_size)]:
        subprocess.run([";\n".join(chunk)], capture_output=True, text=True, shell=True)


def kill_states(to_kill_by_label, keep_count_by_label):
    """
    Terminate the selected states with as few process spawns as possible.

    When *every* state of a given rule (label) is being evicted - the common case when a rule
    is disabled, deleted or its match was tightened - the kernel can purge them all in a single
    `pfctl -k label` call instead of forking one pfctl per state. We only fall back to per-state
    id kills for rules where some states are being kept (e.g. the rule was widened), where a
    label purge would wrongly drop the surviving flows.
    """
    fallback_ids = []
    for label, ids in to_kill_by_label.items():
        if label and keep_count_by_label.get(label, 0) == 0:
            subprocess.run(['/sbin/pfctl', '-k', 'label', '-k', label], capture_output=True, text=True)
        else:
            fallback_ids.extend(ids)
    if fallback_ids:
        kill_by_ids(fallback_ids)


def main():
    result = {'evaluated': 0, 'killed': 0, 'uncertain': 0}
    if not os.path.isfile(RULESET_FILE):
        print(ujson.dumps(result))
        return

    with open(RULESET_FILE, 'r') as handle:
        rules = ujson.loads(handle.read())

    # we only re-evaluate (and possibly kill) states owned by automation rules, but the
    # match decision considers the whole ruleset so we never drop a flow that some other
    # rule still permits.
    automation_labels = set(r['label'] for r in rules if r.get('origin') == 'automation' and r.get('label'))
    if not automation_labels:
        print(ujson.dumps(result))
        return

    resolver = SystemResolver()
    compiled = rematch.CompiledRuleset(rules)

    to_kill_by_label = {}
    keep_count_by_label = {}
    for state in query_states(rule_label='', filter_str=''):
        label = state.get('label')
        if label not in automation_labels:
            continue
        result['evaluated'] += 1
        verdict = rematch.evaluate(compiled, state, resolver)
        if verdict == 'block':
            to_kill_by_label.setdefault(label, []).append(state['id'])
        else:
            keep_count_by_label[label] = keep_count_by_label.get(label, 0) + 1
            if verdict == 'uncertain':
                result['uncertain'] += 1

    killed = sum(len(ids) for ids in to_kill_by_label.values())
    if killed:
        kill_states(to_kill_by_label, keep_count_by_label)
        result['killed'] = killed
        syslog.openlog('firewall', logoption=syslog.LOG_PID, facility=syslog.LOG_LOCAL4)
        syslog.syslog(syslog.LOG_NOTICE,
                      'rematch states: terminated %d state(s) that no longer match the ruleset' % killed)

    print(ujson.dumps(result))


if __name__ == '__main__':
    main()
