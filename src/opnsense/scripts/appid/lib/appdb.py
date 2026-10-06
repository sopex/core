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

    application control (appidd) database handling.

    The database is a json document offering categories and applications:

        {
            "version": 2026100701,
            "categories": {"p2p": {"name": "Peer to Peer"}},
            "applications": {
                "bittorrent": {"name": "BitTorrent", "category": "p2p", "risk": 4, "ndpi": ["BitTorrent"]}
            }
        }

    A baseline is installed by package, signed updates are stored separately, the highest valid version wins
    and is offered to appidd using a symlink.
"""

import configparser
import glob
import json
import os
import re
import subprocess
import syslog

PACKAGE_DB = '/usr/local/share/appid/applications.json'
UPDATE_DB = '/var/db/appid/applications.json'
ACTIVE_DB = '/var/db/appid/active.json'
CUSTOM_APPS = '/usr/local/etc/appidd/custom_applications.conf'
KEY_DIR = '/usr/local/etc/appid/keys'
# option list caches used by the firewall rule and custom application models
CACHE_FILES = ['/tmp/appid_applications.json', '/tmp/appid_categories.json']

# identifiers reserved for core (unclassified flows and user defined applications)
UNKNOWN_ID = 'unknown'
CUSTOM_CATEGORY = 'custom'
CUSTOM_PREFIX = 'custom_'
ID_PATTERN = re.compile(r'^[a-z0-9][a-z0-9_.\-]{0,63}$')


class DatabaseError(Exception):
    pass


def validate(data):
    """ validate database structure
        :param data: parsed json document
        :return: data when valid, raises DatabaseError otherwise
    """
    if not isinstance(data, dict):
        raise DatabaseError('database should be an object')
    if type(data.get('version')) is not int or data['version'] <= 0:
        raise DatabaseError('version should be a positive integer')
    for section in ['categories', 'applications']:
        if not isinstance(data.get(section), dict):
            raise DatabaseError('%s should be an object' % section)
        for item_id, item in data[section].items():
            if not ID_PATTERN.match(item_id) or item_id == UNKNOWN_ID or item_id.startswith(CUSTOM_PREFIX):
                raise DatabaseError('invalid or reserved %s identifier "%s"' % (section, item_id))
            if not isinstance(item, dict) or not isinstance(item.get('name'), str) or not item['name']:
                raise DatabaseError('%s "%s" requires a name' % (section, item_id))
    if CUSTOM_CATEGORY in data['categories']:
        raise DatabaseError('reserved category identifier "%s"' % CUSTOM_CATEGORY)
    for app_id, app in data['applications'].items():
        if app.get('category') not in data['categories']:
            raise DatabaseError('application "%s" refers to an unknown category' % app_id)
        if type(app.get('risk')) is not int or not 1 <= app['risk'] <= 5:
            raise DatabaseError('application "%s" requires a risk between 1 and 5' % app_id)
        ndpi = app.get('ndpi', [])
        if not isinstance(ndpi, list) or not all(isinstance(x, str) for x in ndpi):
            raise DatabaseError('application "%s" offers an invalid ndpi protocol list' % app_id)
    return data


def load(filename):
    """ load and validate a database file
        :param filename: database filename
        :return: database or None when not found, raises DatabaseError when invalid
    """
    if not os.path.isfile(filename):
        return None
    try:
        with open(filename, 'r') as f_in:
            data = json.load(f_in)
    except ValueError as e:
        raise DatabaseError('unable to parse %s (%s)' % (filename, e))
    return validate(data)


def select(candidates=None):
    """ select the highest valid database version
        :param candidates: list of database filenames, defaults to update and package locations
        :return: tuple (filename, database), both None when no valid database was found
    """
    selected = (None, None)
    for filename in candidates if candidates is not None else [UPDATE_DB, PACKAGE_DB]:
        try:
            data = load(filename)
        except DatabaseError as e:
            syslog.syslog(syslog.LOG_ERR, 'appid: skipping database %s' % e)
            continue
        if data is not None and (selected[1] is None or data['version'] > selected[1]['version']):
            selected = (filename, data)
    return selected


def active(active_db=ACTIVE_DB, candidates=None):
    """ return the database offered to appidd, fall back to selection when no (valid) active database exists
        :return: tuple (filename, database)
    """
    try:
        data = load(active_db)
        if data is not None:
            return os.path.realpath(active_db), data
    except DatabaseError as e:
        syslog.syslog(syslog.LOG_ERR, 'appid: active database invalid %s' % e)
    return select(candidates)


def activate(active_db=ACTIVE_DB, candidates=None):
    """ point the active database symlink to the highest valid version
        :return: selected filename or None when no valid database exists
    """
    filename, data = select(candidates)
    if filename is None:
        if os.path.islink(active_db):
            os.unlink(active_db)
        return None
    if not os.path.islink(active_db) or os.readlink(active_db) != filename:
        os.makedirs(os.path.dirname(active_db), exist_ok=True)
        tmp_link = '%s.tmp' % active_db
        if os.path.lexists(tmp_link):
            os.unlink(tmp_link)
        os.symlink(filename, tmp_link)
        os.replace(tmp_link, active_db)
    return filename


def load_custom(filename=CUSTOM_APPS):
    """ load user defined applications as generated from the configuration
        :return: dict of applications indexed by identifier
    """
    result = {}
    if os.path.isfile(filename):
        cnf = configparser.ConfigParser(interpolation=None)
        cnf.read(filename)
        for app_id in cnf.sections():
            if not app_id.startswith(CUSTOM_PREFIX):
                continue
            try:
                risk = int(cnf.get(app_id, 'risk', fallback='3'))
            except ValueError:
                risk = 3
            result[app_id] = {
                'name': cnf.get(app_id, 'name', fallback=app_id),
                'category': cnf.get(app_id, 'category', fallback='') or CUSTOM_CATEGORY,
                'risk': risk,
                'description': cnf.get(app_id, 'description', fallback=''),
                'custom': True
            }
    return result


def categories(data):
    """ list all categories, including the ones reserved for core
        :param data: database or None
        :return: dict category identifier => name
    """
    result = {UNKNOWN_ID: 'Unclassified', CUSTOM_CATEGORY: 'Custom'}
    for cat_id, cat in (data or {}).get('categories', {}).items():
        result[cat_id] = cat['name']
    return result


def applications(data, custom=None):
    """ list all applications, including unclassified traffic and user defined applications
        :param data: database or None
        :param custom: user defined applications (see load_custom())
        :return: dict application identifier => application
    """
    result = {
        UNKNOWN_ID: {
            'name': 'Unknown',
            'category': UNKNOWN_ID,
            'risk': 3,
            'description': 'Traffic which could not be classified',
            'custom': False
        }
    }
    for app_id, app in (data or {}).get('applications', {}).items():
        result[app_id] = {
            'name': app['name'],
            'category': app['category'],
            'risk': app['risk'],
            'description': app.get('description', ''),
            'custom': False
        }
    result.update(custom or {})
    return result


def verify_signature(filename, signature, key_dir=KEY_DIR):
    """ verify a detached ed25519 signature against the trusted public keys
        :param filename: signed file
        :param signature: detached signature file
        :param key_dir: directory containing trusted public keys (*.pub, PEM encoded)
        :return: name of the key that verified the signature or None
    """
    for key in sorted(glob.glob('%s/*.pub' % key_dir)):
        sp = subprocess.run(
            ['/usr/bin/openssl', 'pkeyutl', '-verify', '-pubin', '-inkey', key, '-rawin',
             '-in', filename, '-sigfile', signature],
            capture_output=True
        )
        if sp.returncode == 0:
            return os.path.basename(key)
    return None


def flush_caches():
    """ remove option list caches so the user interface picks up changes
    """
    for filename in CACHE_FILES:
        if os.path.isfile(filename):
            os.unlink(filename)
