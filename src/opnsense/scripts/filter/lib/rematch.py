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

    NAT awareness: lib.states reports the *pre-NAT* address in src_addr/dst_addr and the
    translated address separately in nat_addr. OPNsense filter rules are written against
    pre-NAT addresses, so we match on src_addr/dst_addr, but additionally accept a match on
    the translated address (for the relevant direction). This only ever *adds* matches, so it
    can never cause a false eviction of a translated flow.

    Interface groups: OPNsense interface groups are native pf groups, so a state's interface is
    a physical member (e.g. em0) while a group rule says "on <group>". interface_matches()
    consults the resolver for the physical interface's group memberships so group rules match
    their members instead of being treated as a hard mismatch.
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


def interface_matches(rule_if, rule_ifnot, state_if, resolver=None):
    """
    rule_if None means floating (matches any interface). A rule interface may also be an
    interface *group*; in that case the physical state interface matches if it is a member of
    that group, which we discover via the resolver.
    """
    if not rule_if:
        return True
    matched = (rule_if == state_if)
    if not matched and resolver is not None:
        try:
            groups = resolver.iface_groups(state_if) or []
        except AttributeError:
            groups = []
        matched = rule_if in groups
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


def _addr_in_token(token, addr, resolver):
    """Tri-state: does a single address match this (possibly negated, comma-joined) token?"""
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


def addr_matches(token, addrs, resolver):
    """
    Tri-state address match against one or more candidate addresses (pre- and, where relevant,
    post-NAT). A match on *any* candidate counts, which biases towards keeping translated flows.
    """
    if isinstance(addrs, (list, tuple)):
        candidates = [a for a in addrs if a]
    else:
        candidates = [addrs] if addrs else []
    if not candidates:
        return _addr_in_token(token, None, resolver)
    saw_unknown = False
    for addr in candidates:
        res = _addr_in_token(token, addr, resolver)
        if res is True:
            return True
        if res is None:
            saw_unknown = True
    return None if saw_unknown else False


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


def _source_candidates(state):
    addrs = [state.get('src_addr')]
    if state.get('nat_addr') and state.get('direction') == 'out':
        addrs.append(state.get('nat_addr'))
    return addrs


def _dest_candidates(state):
    addrs = [state.get('dst_addr')]
    if state.get('nat_addr') and state.get('direction') == 'in':
        addrs.append(state.get('nat_addr'))
    return addrs


def rule_matches(rule, state, resolver):
    """Tri-state: does this single rule match this state? True / False / None."""
    if not direction_matches(rule.get('direction'), state.get('direction')):
        return False
    if not interface_matches(rule.get('interface'), rule.get('interfacenot'), state.get('iface'), resolver):
        return False
    if not af_matches(rule.get('ipprotocol'), state.get('ipproto')):
        return False
    if not proto_matches(rule.get('protocol'), state.get('proto')):
        return False

    tri = [
        addr_matches(rule.get('from'), _source_candidates(state), resolver),
        addr_matches(rule.get('to'), _dest_candidates(state), resolver),
        port_matches(rule.get('from_port'), state.get('src_port')),
        port_matches(rule.get('to_port'), state.get('dst_port')),
    ]
    if any(t is False for t in tri):
        return False
    if any(t is None for t in tri):
        return None
    return True


class CompiledRuleset:
    """
    Pre-indexes the ruleset by address-family and protocol so each state only walks the rules
    that could possibly match it, instead of the whole ruleset. Rules excluded by the index
    would return False from rule_matches() anyway, so the verdict is identical to a full walk
    while avoiding the O(states x rules) blow-up on large systems.

    Original rule order is preserved within each bucket, which is required for pf's
    last-match / quick semantics.
    """

    def __init__(self, rules):
        self.rules = list(rules)
        self._cache = {}

    def candidates(self, state):
        key = (state.get('ipproto'), (state.get('proto') or '').lower())
        cached = self._cache.get(key)
        if cached is None:
            af, proto = key
            cached = [
                r for r in self.rules
                if af_matches(r.get('ipprotocol'), af) and proto_matches(r.get('protocol'), proto)
            ]
            self._cache[key] = cached
        return cached


def evaluate(rules, state, resolver):
    """
    Walk the ruleset in order applying pf last-match / quick semantics.

    `rules` may be a plain list or a CompiledRuleset. Returns one of: 'pass', 'block',
    'uncertain'. The caller should only kill the state when the result is 'block'.
    """
    if isinstance(rules, CompiledRuleset):
        rules = rules.candidates(state)

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
