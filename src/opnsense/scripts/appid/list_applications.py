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

    list applications and categories known to application control (appidd)
"""

import argparse
import json
from lib import appdb

if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    group = parser.add_mutually_exclusive_group()
    group.add_argument('--categories', help='list categories', action='store_true')
    group.add_argument('--details', help='list applications including properties', action='store_true')
    group.add_argument('--info', help='show active database information', action='store_true')
    cmd_args = parser.parse_args()

    filename, database = appdb.active()
    categories = appdb.categories(database)
    applications = appdb.applications(database, appdb.load_custom())

    if cmd_args.categories:
        result = categories
    elif cmd_args.details:
        result = []
        for app_id, app in applications.items():
            app['id'] = app_id
            app['category_name'] = categories.get(app['category'], app['category'])
            result.append(app)
    elif cmd_args.info:
        result = {
            'filename': filename,
            'version': database['version'] if database else None,
            'applications': len(database['applications']) if database else 0,
            'categories': len(database['categories']) if database else 0
        }
    else:
        # option list for the firewall rule application selection, grouped per category
        result = {}
        for app_id, app in applications.items():
            category = categories.get(app['category'], app['category'])
            if category not in result:
                result[category] = {}
            result[category][app_id] = app['name']

    print(json.dumps(result))
