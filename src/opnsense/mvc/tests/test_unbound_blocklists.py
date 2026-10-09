#!/usr/bin/env python3

import hashlib
import json
import os
from pathlib import Path
import runpy
import sys
import tempfile
import time
import unittest
from unittest.mock import patch

script_dir = Path(__file__).resolve().parents[2] / 'scripts' / 'unbound'
sys.path.insert(0, str(script_dir))

from blocklists import BlocklistParser
from blocklists.default_bl import DefaultBlocklistHandler


class TestCachedBlocklists(unittest.TestCase):
    def setUp(self):
        self.cache = tempfile.TemporaryDirectory()
        self.addCleanup(self.cache.cleanup)
        self.uri = 'https://blocklist.invalid/domains.txt'
        self.cache_path = '/tmp/bl_cache/' + hashlib.md5(self.uri.encode()).hexdigest()
        self.local_path = Path(self.cache.name) / 'domains.txt'
        self.handler = DefaultBlocklistHandler()
        self.handler.cache_only = True
        original_open = open
        original_exists = os.path.exists
        original_replace = os.replace
        original_stat = os.stat
        original_makedirs = os.makedirs

        def local(path):
            path = str(path)
            if path == self.cache_path:
                return self.local_path
            if path == self.cache_path + '.tmp':
                return str(self.local_path) + '.tmp'
            if path == '/var/unbound/data':
                return self.cache.name
            if path.startswith('/var/unbound/data/'):
                return str(Path(self.cache.name) / Path(path).name)
            if path == '/tmp/bl_cache/':
                return self.cache.name
            return path

        for target, replacement in [
            ('builtins.open', lambda path, *args, **kwargs: original_open(local(path), *args, **kwargs)),
            ('os.path.exists', lambda path: original_exists(local(path))),
            ('os.replace', lambda src, dst: original_replace(local(src), local(dst))),
            ('os.makedirs', lambda path, *args, **kwargs: original_makedirs(local(path), *args, **kwargs)),
            ('os.stat', lambda path, *args, **kwargs: original_stat(local(path), *args, **kwargs)),
        ]:
            patcher = patch(target, replacement)
            patcher.start()
            self.addCleanup(patcher.stop)

    def test_generate_uses_expired_cache_without_network(self):
        self.local_path.write_text('0.0.0.0 blocked.example\n')
        with patch.object(self.handler, '_uri_reader', side_effect=AssertionError('network used')) as reader:
            self.assertEqual(list(self.handler._domains_in_blocklist(self.uri, 0)), ['blocked.example'])
            reader.assert_not_called()

    def test_generate_with_missing_cache_keeps_custom_domains(self):
        self.handler.cnf_parsed = [{
            'id': 'policy', 'includes.patterns': ['custom.example'], 'blocklists.test': self.uri
        }]
        with patch.object(self.handler, '_uri_reader', side_effect=AssertionError('network used')) as reader:
            self.assertEqual(list(self.handler.blocklists_iter()), [
                ('custom.example', 'policy', {'bl': 'Custom', 'wildcard': False})
            ])
            reader.assert_not_called()
        self.assertFalse(self.local_path.exists())

    def test_update_still_fetches_expired_cache(self):
        self.local_path.write_text('blocked.example\n')
        self.handler.cache_only = False
        with patch.object(self.handler, '_uri_reader', side_effect=RuntimeError('offline')) as reader:
            self.assertEqual(list(self.handler._domains_in_blocklist(self.uri, 0)), ['blocked.example'])
            reader.assert_called_once_with(self.uri)

    def test_generation_applies_current_policy_to_cached_domains(self):
        self.local_path.write_text('blocked.example\n')
        self.handler.cache_only = False
        self.handler.cnf_parsed = [{
            'id': 'current-policy', 'source_nets': ['192.0.2.0/24'],
            'blocklists.test': self.uri, 'cache_ttl': 0,
        }]
        parser = BlocklistParser.__new__(BlocklistParser)
        parser.handlers = [self.handler]
        parser.startup_time = time.time()
        with patch.object(self.handler, '_uri_reader') as reader:
            parser.update_blocklist(cache_only=True)
            reader.assert_not_called()
        generated = json.loads((Path(self.cache.name) / 'dnsbl.json').read_text())
        self.assertEqual(generated['config']['current-policy']['source_nets'], ['192.0.2.0/24'])
        self.assertEqual(generated['data']['blocked.example'][0]['idx'], 'current-policy')

    def test_generate_and_update_dispatch(self):
        for command, kwargs in [('generate', {'cache_only': True}), ('update', {})]:
            with self.subTest(command=command), patch('blocklists.BlocklistParser') as parser, \
                    patch.object(sys, 'argv', ['blocklists.py', command]):
                runpy.run_path(str(script_dir / 'blocklists.py'), run_name='__main__')
                parser.return_value.update_blocklist.assert_called_once_with(**kwargs)


if __name__ == '__main__':
    unittest.main()
