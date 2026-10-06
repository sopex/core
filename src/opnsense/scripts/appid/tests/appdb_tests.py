"""
    Copyright (c) 2026 Konstantinos Spartalis
    All rights reserved.

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
"""
import copy
import json
import os
import subprocess
import sys
import tempfile
import unittest
sys.path.insert(0, "%s/.." % os.path.dirname(__file__))
from lib import appdb

DATABASE = {
    'version': 2026100701,
    'categories': {'p2p': {'name': 'Peer to Peer'}, 'proxy': {'name': 'Proxy'}},
    'applications': {
        'bittorrent': {'name': 'BitTorrent', 'category': 'p2p', 'risk': 4, 'ndpi': ['BitTorrent']},
        'tor': {'name': 'Tor', 'category': 'proxy', 'risk': 5, 'description': 'The onion router'}
    }
}


def write_json(filename, data):
    with open(filename, 'w') as f_out:
        json.dump(data, f_out)
    return filename


def create_keypair(directory, name):
    """ create an ed25519 keypair, returns (private key, public key) filenames
    """
    private_key = '%s/%s.key' % (directory, name)
    public_key = '%s/%s.pub' % (directory, name)
    subprocess.run(['openssl', 'genpkey', '-algorithm', 'ed25519', '-out', private_key], check=True)
    subprocess.run(['openssl', 'pkey', '-in', private_key, '-pubout', '-out', public_key], check=True)
    return private_key, public_key


def sign(private_key, filename):
    subprocess.run(
        ['openssl', 'pkeyutl', '-sign', '-inkey', private_key, '-rawin', '-in', filename, '-out', '%s.sig' % filename],
        check=True
    )
    return '%s.sig' % filename


class TestValidate(unittest.TestCase):
    def test_valid(self):
        self.assertEqual(appdb.validate(copy.deepcopy(DATABASE)), DATABASE)

    def assertInvalid(self, mutate):
        data = copy.deepcopy(DATABASE)
        mutate(data)
        with self.assertRaises(appdb.DatabaseError):
            appdb.validate(data)

    def test_version(self):
        self.assertInvalid(lambda d: d.update({'version': '1'}))
        self.assertInvalid(lambda d: d.update({'version': 0}))
        self.assertInvalid(lambda d: d.pop('version'))

    def test_sections(self):
        self.assertInvalid(lambda d: d.update({'applications': []}))
        self.assertInvalid(lambda d: d.pop('categories'))

    def test_reserved_identifiers(self):
        self.assertInvalid(lambda d: d['applications'].update({'unknown': d['applications']['tor']}))
        self.assertInvalid(lambda d: d['applications'].update({'custom_abc': d['applications']['tor']}))
        self.assertInvalid(lambda d: d['categories'].update({'custom': {'name': 'Custom'}}))
        self.assertInvalid(lambda d: d['applications'].update({'Bad Id': d['applications']['tor']}))

    def test_application_properties(self):
        self.assertInvalid(lambda d: d['applications']['tor'].update({'category': 'missing'}))
        self.assertInvalid(lambda d: d['applications']['tor'].update({'risk': 6}))
        self.assertInvalid(lambda d: d['applications']['tor'].update({'risk': '3'}))
        self.assertInvalid(lambda d: d['applications']['tor'].update({'ndpi': 'Tor'}))
        self.assertInvalid(lambda d: d['applications']['tor'].update({'name': ''}))


class TestSelection(unittest.TestCase):
    def setUp(self):
        self.tmpdir = tempfile.TemporaryDirectory()
        self.package = '%s/package.json' % self.tmpdir.name
        self.update = '%s/update.json' % self.tmpdir.name
        self.active = '%s/db/active.json' % self.tmpdir.name

    def tearDown(self):
        self.tmpdir.cleanup()

    def test_missing(self):
        self.assertEqual(appdb.select([self.update, self.package]), (None, None))
        self.assertIsNone(appdb.activate(self.active, [self.update, self.package]))

    def test_highest_version_wins(self):
        newer = copy.deepcopy(DATABASE)
        newer['version'] += 1
        write_json(self.package, DATABASE)
        write_json(self.update, newer)
        self.assertEqual(appdb.select([self.update, self.package])[0], self.update)
        newer['version'] -= 2
        write_json(self.update, newer)
        self.assertEqual(appdb.select([self.update, self.package])[0], self.package)

    def test_invalid_skipped(self):
        write_json(self.package, DATABASE)
        with open(self.update, 'w') as f_out:
            f_out.write('{not json')
        self.assertEqual(appdb.select([self.update, self.package])[0], self.package)

    def test_activate(self):
        write_json(self.package, DATABASE)
        self.assertEqual(appdb.activate(self.active, [self.update, self.package]), self.package)
        self.assertEqual(os.readlink(self.active), self.package)
        self.assertEqual(appdb.active(self.active, [])[1]['version'], DATABASE['version'])
        # newer update takes over
        newer = copy.deepcopy(DATABASE)
        newer['version'] += 1
        write_json(self.update, newer)
        appdb.activate(self.active, [self.update, self.package])
        self.assertEqual(os.readlink(self.active), self.update)
        # nothing left, link removed
        os.unlink(self.update)
        os.unlink(self.package)
        self.assertIsNone(appdb.activate(self.active, [self.update, self.package]))
        self.assertFalse(os.path.lexists(self.active))


class TestListing(unittest.TestCase):
    def setUp(self):
        self.tmpdir = tempfile.TemporaryDirectory()
        self.custom = '%s/custom_applications.conf' % self.tmpdir.name
        with open(self.custom, 'w') as f_out:
            f_out.write("[custom_0123abcd]\nname=Intranet\ncategory=\nrisk=2\nhosts=intranet.example.com\n\n")
            f_out.write("[custom_4567]\nname=Tracker\ncategory=p2p\nrisk=x\nhosts=tracker.example.com\n\n")
            f_out.write("[other]\nname=Ignored\n")

    def tearDown(self):
        self.tmpdir.cleanup()

    def test_load_custom(self):
        custom = appdb.load_custom(self.custom)
        self.assertEqual(sorted(custom.keys()), ['custom_0123abcd', 'custom_4567'])
        self.assertEqual(custom['custom_0123abcd']['category'], appdb.CUSTOM_CATEGORY)
        self.assertEqual(custom['custom_0123abcd']['risk'], 2)
        self.assertEqual(custom['custom_4567']['category'], 'p2p')
        self.assertEqual(custom['custom_4567']['risk'], 3)
        self.assertEqual(appdb.load_custom('%s/missing.conf' % self.tmpdir.name), {})

    def test_categories(self):
        self.assertEqual(appdb.categories(None), {'unknown': 'Unclassified', 'custom': 'Custom'})
        self.assertEqual(appdb.categories(DATABASE)['p2p'], 'Peer to Peer')

    def test_applications(self):
        apps = appdb.applications(DATABASE, appdb.load_custom(self.custom))
        self.assertEqual(sorted(apps.keys()), ['bittorrent', 'custom_0123abcd', 'custom_4567', 'tor', 'unknown'])
        self.assertTrue(apps['custom_4567']['custom'])
        self.assertFalse(apps['tor']['custom'])
        self.assertEqual(apps['tor']['description'], 'The onion router')
        self.assertEqual(list(appdb.applications(None).keys()), ['unknown'])


class TestSignature(unittest.TestCase):
    def setUp(self):
        self.tmpdir = tempfile.TemporaryDirectory()
        self.keys = '%s/keys' % self.tmpdir.name
        os.mkdir(self.keys)
        self.trusted = create_keypair(self.keys, 'trusted')[0]
        self.untrusted = create_keypair(self.tmpdir.name, 'untrusted')[0]
        self.database = write_json('%s/applications.json' % self.tmpdir.name, DATABASE)

    def tearDown(self):
        self.tmpdir.cleanup()

    def test_trusted(self):
        signature = sign(self.trusted, self.database)
        self.assertEqual(appdb.verify_signature(self.database, signature, self.keys), 'trusted.pub')

    def test_untrusted(self):
        signature = sign(self.untrusted, self.database)
        self.assertIsNone(appdb.verify_signature(self.database, signature, self.keys))

    def test_tampered(self):
        signature = sign(self.trusted, self.database)
        with open(self.database, 'a') as f_out:
            f_out.write(' ')
        self.assertIsNone(appdb.verify_signature(self.database, signature, self.keys))

    def test_no_keys(self):
        signature = sign(self.trusted, self.database)
        self.assertIsNone(appdb.verify_signature(self.database, signature, '%s/empty' % self.tmpdir.name))
