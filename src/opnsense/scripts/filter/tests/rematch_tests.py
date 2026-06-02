import unittest
import sys
import os
sys.path.insert(0, "%s/../lib" % os.path.dirname(__file__))
import rematch


class FakeResolver:
    """Deterministic resolver for the evaluator unit tests."""
    def __init__(self, aliases=None, ifnets=None, ifaddrs=None, selfaddr=None, ifgroups=None):
        import ipaddress
        self._ipn = ipaddress.ip_network
        self.aliases = aliases or {}
        self.ifnets = ifnets or {}
        self.ifaddrs = ifaddrs or {}
        self.selfaddr = selfaddr or []
        self.ifgroups = ifgroups or {}

    def _nets(self, items):
        return [self._ipn(i, strict=False) for i in items]

    def alias(self, name):
        return self._nets(self.aliases[name]) if name in self.aliases else None

    def iface_net(self, ifn):
        return self._nets(self.ifnets[ifn]) if ifn in self.ifnets else None

    def iface_addr(self, ifn):
        return self._nets(self.ifaddrs[ifn]) if ifn in self.ifaddrs else None

    def iface_groups(self, ifn):
        return self.ifgroups.get(ifn, [])

    def self_addr(self):
        return self._nets(self.selfaddr)


def rule(over=None):
    base = {
        'label': 'r', 'origin': 'automation', 'action': 'pass', 'quick': True,
        'interface': None, 'interfacenot': False, 'direction': 'in',
        'ipprotocol': None, 'protocol': None, 'from': 'any', 'from_port': None,
        'to': 'any', 'to_port': None, 'keepstate': True,
    }
    if over:
        base.update(over)
    return base


def state(over=None):
    base = {
        'iface': 'em0', 'direction': 'in', 'proto': 'tcp', 'ipproto': 'ipv4',
        'src_addr': '192.168.1.50', 'src_port': '40000',
        'dst_addr': '10.0.0.10', 'dst_port': '443',
        'nat_addr': None, 'nat_port': None,
    }
    if over:
        base.update(over)
    return base


class TestRematchEvaluator(unittest.TestCase):
    def setUp(self):
        self.r = FakeResolver(
            aliases={'IOT': ['192.168.30.0/24'], 'WEBPORTS': []},
            ifnets={'em0': ['192.168.1.0/24']},
            ifaddrs={'em0': ['192.168.1.1/32']},
            selfaddr=['192.168.1.1/32', '10.0.0.1/32'],
            ifgroups={'em0': ['IOTGROUP'], 'em1': []},
        )

    def test_iot_allow_removed_falls_to_block(self):
        rules = [rule({'action': 'block', 'from': '192.168.30.0/24', 'to': '192.168.30.0/24'})]
        st = state({'src_addr': '192.168.30.5', 'dst_addr': '192.168.30.6'})
        self.assertEqual(rematch.evaluate(rules, st, self.r), 'block')

    def test_iot_allow_present_passes(self):
        rules = [
            rule({'action': 'pass', 'from': '192.168.30.0/24', 'to': '192.168.30.0/24'}),
            rule({'action': 'block'}),
        ]
        st = state({'src_addr': '192.168.30.5', 'dst_addr': '192.168.30.6'})
        self.assertEqual(rematch.evaluate(rules, st, self.r), 'pass')

    def test_narrowing_still_matches(self):
        rules = [rule({'action': 'pass', 'from': '192.168.1.0/24'})]
        st = state({'src_addr': '192.168.1.50'})
        self.assertEqual(rematch.evaluate(rules, st, self.r), 'pass')

    def test_empty_ruleset_defaults_to_block(self):
        self.assertEqual(rematch.evaluate([], state(), self.r), 'block')

    def test_quick_first_match_wins(self):
        rules = [
            rule({'action': 'pass', 'quick': True, 'from': '192.168.1.0/24'}),
            rule({'action': 'block', 'quick': True}),
        ]
        self.assertEqual(rematch.evaluate(rules, state({'src_addr': '192.168.1.50'}), self.r), 'pass')

    def test_non_quick_last_match_wins(self):
        rules = [
            rule({'action': 'pass', 'quick': False}),
            rule({'action': 'block', 'quick': False, 'from': '192.168.1.0/24'}),
        ]
        self.assertEqual(rematch.evaluate(rules, state({'src_addr': '192.168.1.50'}), self.r), 'block')

    def test_alias_resolution(self):
        rules = [rule({'action': 'pass', 'from': '$IOT'}), rule({'action': 'block'})]
        self.assertEqual(rematch.evaluate(rules, state({'src_addr': '192.168.30.9'}), self.r), 'pass')
        self.assertEqual(rematch.evaluate(rules, state({'src_addr': '192.168.40.9'}), self.r), 'block')

    def test_unresolved_alias_is_uncertain(self):
        rules = [rule({'action': 'pass', 'from': '$DOESNOTEXIST'}), rule({'action': 'block'})]
        self.assertEqual(rematch.evaluate(rules, state(), self.r), 'uncertain')

    def test_port_alias_is_uncertain(self):
        rules = [rule({'action': 'pass', 'to_port': '$WEBPORTS'}), rule({'action': 'block'})]
        self.assertEqual(rematch.evaluate(rules, state(), self.r), 'uncertain')

    def test_interface_network_token(self):
        rules = [rule({'action': 'pass', 'from': '(em0:network)'}), rule({'action': 'block'})]
        self.assertEqual(rematch.evaluate(rules, state({'src_addr': '192.168.1.77'}), self.r), 'pass')
        self.assertEqual(rematch.evaluate(rules, state({'src_addr': '10.9.9.9'}), self.r), 'block')

    def test_negation(self):
        rules = [rule({'action': 'block', 'from': '!192.168.1.0/24', 'quick': True}), rule({'action': 'pass'})]
        self.assertEqual(rematch.evaluate(rules, state({'src_addr': '192.168.1.50'}), self.r), 'pass')
        self.assertEqual(rematch.evaluate(rules, state({'src_addr': '8.8.8.8'}), self.r), 'block')

    def test_protocol_mismatch_skips_rule(self):
        rules = [rule({'action': 'pass', 'protocol': 'udp'}), rule({'action': 'block'})]
        self.assertEqual(rematch.evaluate(rules, state({'proto': 'tcp'}), self.r), 'block')

    def test_tcpudp_combined(self):
        rules = [rule({'action': 'pass', 'protocol': 'tcp/udp'}), rule({'action': 'block'})]
        self.assertEqual(rematch.evaluate(rules, state({'proto': 'udp'}), self.r), 'pass')

    def test_af_mismatch(self):
        rules = [rule({'action': 'pass', 'ipprotocol': 'inet6'}), rule({'action': 'block'})]
        self.assertEqual(rematch.evaluate(rules, state({'ipproto': 'ipv4'}), self.r), 'block')

    def test_interface_binding(self):
        rules = [rule({'action': 'pass', 'interface': 'em1'}), rule({'action': 'block'})]
        self.assertEqual(rematch.evaluate(rules, state({'iface': 'em0'}), self.r), 'block')
        self.assertEqual(rematch.evaluate(rules, state({'iface': 'em1'}), self.r), 'pass')

    def test_port_range(self):
        rules = [rule({'action': 'pass', 'to_port': '1000:2000'}), rule({'action': 'block'})]
        self.assertEqual(rematch.evaluate(rules, state({'dst_port': '1500'}), self.r), 'pass')
        self.assertEqual(rematch.evaluate(rules, state({'dst_port': '3000'}), self.r), 'block')

    # --- NAT awareness (#1) ---
    def test_snat_outbound_matches_pre_nat_source(self):
        # WAN outbound SNAT: src_addr is the pre-NAT internal address, nat_addr the public one.
        rules = [rule({'action': 'pass', 'direction': 'out', 'interface': 'wan',
                       'from': '192.168.1.0/24'}), rule({'action': 'block'})]
        st = state({'iface': 'wan', 'direction': 'out', 'src_addr': '192.168.1.50',
                    'nat_addr': '198.51.100.5', 'nat_port': '23456'})
        self.assertEqual(rematch.evaluate(rules, st, self.r), 'pass')

    def test_snat_outbound_matches_post_nat_source(self):
        # a rule written against the public address still keeps the flow (match on either tuple)
        rules = [rule({'action': 'pass', 'direction': 'out', 'interface': 'wan',
                       'from': '198.51.100.0/24'}), rule({'action': 'block'})]
        st = state({'iface': 'wan', 'direction': 'out', 'src_addr': '192.168.1.50',
                    'nat_addr': '198.51.100.5'})
        self.assertEqual(rematch.evaluate(rules, st, self.r), 'pass')

    # --- interface groups (#4) ---
    def test_group_rule_matches_member_interface(self):
        rules = [rule({'action': 'pass', 'interface': 'IOTGROUP', 'from': 'any'}),
                 rule({'action': 'block'})]
        # em0 is a member of IOTGROUP -> group rule matches -> pass
        self.assertEqual(rematch.evaluate(rules, state({'iface': 'em0'}), self.r), 'pass')
        # em1 is not a member -> group rule does not match -> block
        self.assertEqual(rematch.evaluate(rules, state({'iface': 'em1'}), self.r), 'block')

    # --- compiled ruleset index (#2) ---
    def test_compiled_ruleset_equivalence(self):
        rules = [
            rule({'action': 'pass', 'protocol': 'udp'}),
            rule({'action': 'pass', 'ipprotocol': 'inet6'}),
            rule({'action': 'pass', 'protocol': 'tcp', 'from': '192.168.1.0/24'}),
            rule({'action': 'block'}),
        ]
        compiled = rematch.CompiledRuleset(rules)
        st = state({'proto': 'tcp', 'ipproto': 'ipv4', 'src_addr': '192.168.1.50'})
        # compiled result must equal a full unindexed walk
        self.assertEqual(rematch.evaluate(compiled, st, self.r), rematch.evaluate(rules, st, self.r))
        self.assertEqual(rematch.evaluate(compiled, st, self.r), 'pass')
        # candidates() must have pruned the udp / inet6 rules for this tcp/ipv4 state
        self.assertEqual(len(compiled.candidates(st)), 2)


if __name__ == '__main__':
    unittest.main()
