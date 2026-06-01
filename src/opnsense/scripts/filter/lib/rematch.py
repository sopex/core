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

    Pure, side-effect free evaluator used by the "rematch states" feature.

    Given a normalized ruleset (as exported by FilterRule::toCriteria()) and a single
    live pf(4) state (as returned by lib.states.query_states()), decide whether the
    current ruleset would still pass that state.

    The matcher reimplements a *subset* of pf's first/last-match + quick semantics. It is
    deliberately conservative: whenever it cannot resolve a token (e.g. a port alias, or an
    alias table it cannot read) it returns "uncertain" for that state, which the caller treats
    as "keep" so we never drop traffic we are not sure about. The only states that get killed
    are ones that the ruleset would *definitely* block / no longer match.
"""
import ipaddress

ANY = 'any'


def proto_matches(rule_proto, state_proto):
    """rule_proto None means 'any'. Handles the combined 'tcp/udp' token."""
    if not rule_proto:
        return True
    state_proto = (state_proto or '').lower()
    if rule_proto == 'tcp/udp':
        return state_proto in ('tcp', 'udp')
    return rule_proto == state_proto


def af_matches(rule_af, state_ipproto):
    """rule_af: 'inet' | 'inet6' | None ; state_ipproto: 'ipv4' | 'ipv6'."""
    if not rule_af:
        return True
    if rule_af == 'inet':
        return state_ipproto == 'ipv4'
    if rule_af == 'inet6':
        return state_ipproto == 'ipv6'
    return True


def direction_matches(rule_dir, state_dir):
    if rule_dir in (None, '', 'any'):
        return True
    return rule_dir == state_dir


def interface_matches(rule_if, rule_ifnot, state_if):
    """rule_if None means floating (matches any interface)."""
    if not rule_if:
        return True
    matched = (rule_if == state_if)
    return (not matched) if rule_ifnot else matched


def _resolve_atom(atom, resolver):
    """Resolve a single address atom to a list of ip_network, or 'ANY', or 'UNKNOWN'."""
    atom = atom.strip()
    if atom in ('', ANY):
        return 'ANY'
    if atom.startswith('(') and atom.endswith(')'):
        inner = atom[1:-1]
        if inner == 'self':
            nets = resolver.self_addr()
        elif inner.endswith(':network'):
            nets = resolver.iface_net(inner[:-len(':network')])
        else:
            nets = resolver.iface_addr(inner)
        return nets if nets is not None else 'UNKNOWN'
    if atom.startswith('$'):
        nets = resolver.alias(atom[1:])
        return nets if nets is not None else 'UNKNOWN'
    try:
        return [ipaddress.ip_network(atom, strict=False)]
    except ValueError:
        return 'UNKNOWN'


def addr_matches(token, addr, resolver):
    """Tri-state address match: True / False / None (cannot determine)."""
    if token is None:
        return True
    token = token.strip()
    neg = token.startswith('!')
    if neg:
        token = token[1:].strip()
    if token in ('', ANY):
        # 'any' matches everything; '!any' matches nothing
        return not neg

    try:
        ipaddr = ipaddress.ip_address(addr)
    except (ValueError, TypeError):
        return None

    base = False
    unknown = False
    for atom in token.split(','):
        res = _resolve_atom(atom, resolver)
        if res == 'ANY':
            base = True
            break
        if res == 'UNKNOWN':
            unknown = True
            continue
        for net in res:
            if ipaddr.version == net.version and ipaddr in net:
                base = True
                break
        if base:
            break

    if not base and unknown:
        # an unresolved atom could have matched, so we cannot be sure
        return None
    return (not base) if neg else base


def port_matches(token, port):
    """Tri-state port match. Port aliases ($name) are not resolvable here -> None."""
    if token is None or token == '' or token == ANY:
        return True
    if str(token).startswith('$'):
        return None
    try:
        pnum = int(port)
    except (TypeError, ValueError):
        # state without a meaningful port (e.g. icmp) - don't discriminate on it
        return True
    token = str(token)
    if ':' in token:
        lo, hi = token.split(':', 1)
        try:
            return int(lo) <= pnum <= int(hi)
        except ValueError:
            return None
    try:
        return int(token) == pnum
    except ValueError:
        return None


def rule_matches(rule, state, resolver):
    """Tri-state: does this single rule match this state? True / False / None."""
    if not direction_matches(rule.get('direction'), state.get('direction')):
        return False
    if not interface_matches(rule.get('interface'), rule.get('interfacenot'), state.get('iface')):
        return False
    if not af_matches(rule.get('ipprotocol'), state.get('ipproto')):
        return False
    if not proto_matches(rule.get('protocol'), state.get('proto')):
        return False

    tri = [
        addr_matches(rule.get('from'), state.get('src_addr'), resolver),
        addr_matches(rule.get('to'), state.get('dst_addr'), resolver),
        port_matches(rule.get('from_port'), state.get('src_port')),
        port_matches(rule.get('to_port'), state.get('dst_port')),
    ]
    if any(t is False for t in tri):
        return False
    if any(t is None for t in tri):
        return None
    return True


def evaluate(rules, state, resolver):
    """
    Walk the ruleset in order applying pf last-match / quick semantics.

    Returns one of: 'pass', 'block', 'uncertain'. The caller should only kill the state
    when the result is 'block'.
    """
    last_action = 'block'  # pf default policy is deny
    for rule in rules:
        match = rule_matches(rule, state, resolver)
        if match is True:
            if rule.get('quick'):
                return rule.get('action', 'block')
            last_action = rule.get('action', 'block')
        elif match is None:
            # this rule might match (and, if quick, might short-circuit either way) -
            # we can no longer be certain of the outcome, so keep the state.
            return 'uncertain'
    return last_action
