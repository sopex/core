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
import shutil
import sys
import tempfile
import unittest
sys.path.insert(0, "%s/.." % os.path.dirname(__file__))
import update_db
from .appdb_tests import DATABASE, create_keypair, sign, write_json


class TestUpdate(unittest.TestCase):
    """ database updates, downloads are served from a local directory
    """
    def setUp(self):
        self.tmpdir = tempfile.TemporaryDirectory()
        self.keys = '%s/keys' % self.tmpdir.name
        self.remote = '%s/remote' % self.tmpdir.name
        self.package = '%s/package.json' % self.tmpdir.name
        self.update_db = '%s/db/applications.json' % self.tmpdir.name
        os.mkdir(self.keys)
        os.mkdir(self.remote)
        self.private_key = create_keypair(self.keys, 'trusted')[0]
        write_json(self.package, DATABASE)
        self.download = update_db.download
        update_db.download = self.local_download

    def tearDown(self):
        update_db.download = self.download
        self.tmpdir.cleanup()

    def local_download(self, url, target):
        filename = '%s/%s' % (self.remote, os.path.basename(url))
        if not os.path.isfile(filename):
            raise update_db.appdb.DatabaseError('%s not found' % url)
        with open(filename, 'rb') as f_in:
            shutil.copyfileobj(f_in, target)
        target.flush()

    def publish(self, data, signed=True):
        filename = write_json('%s/applications.json' % self.remote, data)
        if signed:
            sign(self.private_key, filename)
        return 'https://example.org/applications.json'

    def run_update(self, url):
        return update_db.update(url, self.keys, self.update_db, [self.update_db, self.package])

    def test_newer(self):
        newer = copy.deepcopy(DATABASE)
        newer['version'] += 1
        result = self.run_update(self.publish(newer))
        self.assertEqual(result['status'], 'ok')
        self.assertEqual(result['version'], newer['version'])
        with open(self.update_db) as f_in:
            self.assertEqual(json.load(f_in), newer)
        # a second update keeps the previous one for rollback
        newer['version'] += 1
        self.assertEqual(self.run_update(self.publish(newer))['status'], 'ok')
        self.assertTrue(os.path.isfile('%s.prev' % self.update_db))

    def test_not_newer(self):
        result = self.run_update(self.publish(DATABASE))
        self.assertEqual(result['status'], 'current')
        self.assertFalse(os.path.isfile(self.update_db))

    def test_unsigned(self):
        newer = copy.deepcopy(DATABASE)
        newer['version'] += 1
        result = self.run_update(self.publish(newer, signed=False))
        self.assertEqual(result['status'], 'error')
        self.assertFalse(os.path.isfile(self.update_db))

    def test_bad_signature(self):
        newer = copy.deepcopy(DATABASE)
        newer['version'] += 1
        url = self.publish(newer)
        newer['version'] += 1
        write_json('%s/applications.json' % self.remote, newer)
        result = self.run_update(url)
        self.assertEqual(result['status'], 'error')
        self.assertEqual(result['message'], 'signature verification failed')

    def test_invalid_content(self):
        invalid = copy.deepcopy(DATABASE)
        invalid['version'] += 1
        invalid['applications']['tor']['risk'] = 9
        result = self.run_update(self.publish(invalid))
        self.assertEqual(result['status'], 'error')
        self.assertTrue(result['message'].startswith('invalid database'))
        self.assertFalse(os.path.isfile(self.update_db))
