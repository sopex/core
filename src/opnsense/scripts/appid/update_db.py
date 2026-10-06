#!/usr/local/bin/python3

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

    --------------------------------------------------------------------------------------

    update the application control database from the configured location.

    The database ({url}) and its detached ed25519 signature ({url}.sig) are downloaded, the signature should
    verify against one of the trusted public keys, after which the content is validated and only accepted when
    newer than the database currently in use. The previous update is kept for manual rollback.
"""

import argparse
import configparser
import json
import os
import signal
import sys
import syslog
import tempfile
import requests
from lib import appdb

CONFIG_FILE = '/usr/local/etc/appidd/appidd.conf'
PIDFILE = '/var/run/appidd.pid'
MAX_DOWNLOAD_SIZE = 64 * 1024 * 1024


def download(url, target):
    """ download url into target file, refuse oversized content
        :param url: location to fetch
        :param target: open (binary) file handle
    """
    req = requests.get(url, stream=True, timeout=60)
    req.raise_for_status()
    received = 0
    for chunk in req.iter_content(chunk_size=65536):
        received += len(chunk)
        if received > MAX_DOWNLOAD_SIZE:
            raise appdb.DatabaseError('download %s exceeds the maximum size' % url)
        target.write(chunk)
    target.flush()


def reload_daemon():
    """ signal appidd to reload its policy and database when running
    """
    try:
        with open(PIDFILE, 'r') as f_in:
            os.kill(int(f_in.read().strip()), signal.SIGHUP)
    except (OSError, ValueError):
        pass


def update(url, key_dir=appdb.KEY_DIR, update_db=appdb.UPDATE_DB, candidates=None):
    """ fetch, verify and install a database update
        :return: dict with status ("ok", "current" or "error"), version and message
    """
    current_filename, current = appdb.select(candidates)
    current_version = current['version'] if current else 0
    os.makedirs(os.path.dirname(update_db), exist_ok=True)
    with tempfile.NamedTemporaryFile(dir=os.path.dirname(update_db), suffix='.json') as db_file, \
            tempfile.NamedTemporaryFile(dir=os.path.dirname(update_db), suffix='.sig') as sig_file:
        try:
            download(url, db_file)
            download('%s.sig' % url, sig_file)
        except (requests.exceptions.RequestException, appdb.DatabaseError) as e:
            return {'status': 'error', 'version': current_version, 'message': 'download failed: %s' % e}

        key = appdb.verify_signature(db_file.name, sig_file.name, key_dir)
        if key is None:
            return {'status': 'error', 'version': current_version, 'message': 'signature verification failed'}

        try:
            data = appdb.load(db_file.name)
        except appdb.DatabaseError as e:
            return {'status': 'error', 'version': current_version, 'message': 'invalid database: %s' % e}

        if data['version'] <= current_version:
            return {'status': 'current', 'version': current_version, 'message': 'database is up to date'}

        if os.path.isfile(update_db):
            os.replace(update_db, '%s.prev' % update_db)
        os.chmod(db_file.name, 0o644)
        os.link(db_file.name, update_db)

    return {
        'status': 'ok',
        'version': data['version'],
        'message': 'database updated from version %d using key %s' % (current_version, key)
    }


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--activate', help='only (re)select the active database', action='store_true')
    cmd_args = parser.parse_args()

    if cmd_args.activate:
        result = {'status': 'ok', 'message': 'active database %s' % appdb.activate()}
        appdb.flush_caches()
    else:
        cnf = configparser.ConfigParser(interpolation=None)
        cnf.read(CONFIG_FILE)
        url = cnf.get('update', 'url', fallback='')
        if not url:
            result = {'status': 'error', 'message': 'no update location configured'}
        else:
            result = update(url)
            syslog.syslog(syslog.LOG_NOTICE if result['status'] != 'error' else syslog.LOG_ERR,
                          'appid: %s' % result['message'])
            if result['status'] == 'ok':
                appdb.activate()
                appdb.flush_caches()
                reload_daemon()

    print(json.dumps(result))
    sys.exit(0 if result['status'] != 'error' else 1)
