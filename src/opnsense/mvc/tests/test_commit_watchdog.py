#!/usr/bin/env python3

# Copyright (C) 2026 Konstantinos Spartalis
# All rights reserved.
#
# Redistribution and use in source and binary forms, with or without
# modification, are permitted provided that the following conditions are met:
#
# 1. Redistributions of source code must retain the above copyright notice,
#    this list of conditions and the following disclaimer.
#
# 2. Redistributions in binary form must reproduce the above copyright
#    notice, this list of conditions and the following disclaimer in the
#    documentation and/or other materials provided with the distribution.
#
# THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
# INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
# AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
# AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
# OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
# SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
# INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
# CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
# ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
# POSSIBILITY OF SUCH DAMAGE.

import json
import os
import shutil
import subprocess
import sys
import tempfile
import time
import unittest
import xml.etree.ElementTree as ET

# Import the watchdog class from src/opnsense/scripts/system/commit_watchdog.py
script_dir = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..', 'scripts', 'system'))
sys.path.insert(0, script_dir)

from commit_watchdog import CommitWatchdog, check_watchdog_supervisor


class TestCommitWatchdog(unittest.TestCase):
    def setUp(self):
        self.test_dir = tempfile.mkdtemp(prefix='watchdog_test_')
        self.marker_file = os.path.join(self.test_dir, 'commit_rollback_pending.json')
        self.snapshot_file = os.path.join(self.test_dir, 'snapshot.xml')
        self.notice_file = os.path.join(self.test_dir, 'commit_rollback_notice.json')
        self.socket_file = os.path.join(self.test_dir, 'watchdog.sock')
        self.config_file = os.path.join(self.test_dir, 'config.xml')
        self.backup_dir = os.path.join(self.test_dir, 'backup')
        if sys.platform == 'win32':
            self.reload_script = os.path.join(self.test_dir, 'mock_reload.bat')
            with open(self.reload_script, 'w') as f:
                f.write("@exit /b 0\n")
            self.mock_shutdown = os.path.join(self.test_dir, 'mock_shutdown.bat')
            with open(self.mock_shutdown, 'w') as f:
                f.write("@exit /b 0\n")
        else:
            self.reload_script = os.path.join(self.test_dir, 'mock_reload.sh')
            with open(self.reload_script, 'w') as f:
                f.write("#!/bin/sh\nexit 0\n")
            os.chmod(self.reload_script, 0o755)
            self.mock_shutdown = os.path.join(self.test_dir, 'mock_shutdown.sh')
            with open(self.mock_shutdown, 'w') as f:
                f.write("#!/bin/sh\nexit 0\n")
            os.chmod(self.mock_shutdown, 0o755)

        # Create dummy config and snapshot
        self.dummy_snapshot = b'<opnsense><system><hostname>firewall-snap</hostname></system></opnsense>'
        self.dummy_modified = b'<opnsense><system><hostname>firewall-mod</hostname></system></opnsense>'

        with open(self.snapshot_file, 'wb') as f:
            f.write(self.dummy_snapshot)
        with open(self.config_file, 'wb') as f:
            f.write(self.dummy_modified)

        # Create dummy marker
        self.marker_data = {
            'session_id': 'test-session-1234',
            'username': 'admin',
            'source': 'gui',
            'created_at': int(time.time()),
            'countdown_seconds': 180,
            'extension_seconds': 300,
            'max_extensions': 3,
            'extensions_count': 0,
            'saves_count': 0,
            'gui_url': 'https://192.168.1.1',
            'countdown_active': False
        }
        with open(self.marker_file, 'w', encoding='utf-8') as f:
            json.dump(self.marker_data, f)

    def tearDown(self):
        shutil.rmtree(self.test_dir, ignore_errors=True)

    def get_watchdog(self, **kwargs):
        defaults = {
            'marker_file': self.marker_file,
            'snapshot_file': self.snapshot_file,
            'socket_file': self.socket_file,
            'notice_file': self.notice_file,
            'config_file': self.config_file,
            'backup_dir': self.backup_dir,
            'reload_script': self.reload_script,
            'idle_timeout': 3600.0,
            'shutdown_bin': self.mock_shutdown,
            'reboot_bin': self.mock_shutdown
        }
        defaults.update(kwargs)
        return CommitWatchdog(**defaults)

    def test_load_marker_initial_state(self):
        wd = self.get_watchdog()
        self.assertEqual(wd.session_id, 'test-session-1234')
        self.assertEqual(wd.username, 'admin')
        self.assertEqual(wd.countdown_seconds, 180.0)
        self.assertEqual(wd.extension_seconds, 300.0)
        self.assertEqual(wd.max_extensions, 3)
        self.assertFalse(wd.countdown_active)
        self.assertEqual(wd.saves_count, 0)

    def test_handle_command_status_before_save(self):
        wd = self.get_watchdog()
        res = json.loads(wd.handle_command('STATUS'))
        self.assertEqual(res['status'], 'ok')
        self.assertFalse(res['countdown_active'])
        self.assertEqual(res['remaining_seconds'], 0)
        self.assertEqual(res['saves_count'], 0)

    def test_handle_command_save_starts_countdown(self):
        wd = self.get_watchdog()
        t_before = time.monotonic()
        res = json.loads(wd.handle_command('SAVE config-1791532743.6206.xml 1'))
        t_after = time.monotonic()

        self.assertEqual(res['status'], 'ok')
        self.assertTrue(wd.countdown_active)
        self.assertEqual(wd.saves_count, 1)
        self.assertIn('config-1791532743.6206.xml', wd.revisions)
        self.assertGreaterEqual(wd.deadline_monotonic, t_before + 180)
        self.assertLessEqual(wd.deadline_monotonic, t_after + 180 + 0.1)

        # Status after save
        st = json.loads(wd.handle_command('STATUS'))
        self.assertTrue(st['countdown_active'])
        self.assertGreater(st['remaining_seconds'], 170)

        # Check sidecar index
        sidecar_file = os.path.join(self.backup_dir, 'session_tags.json')
        self.assertTrue(os.path.exists(sidecar_file))
        with open(sidecar_file, 'r', encoding='utf-8') as f:
            tags = json.load(f)
        self.assertEqual(tags.get('config-1791532743.6206.xml'), 'Pending')

    def test_multiple_user_saves_restart_countdown(self):
        wd = self.get_watchdog()
        wd.handle_command('SAVE config-1.xml 1')
        deadline1 = wd.deadline_monotonic

        time.sleep(0.05)
        wd.handle_command('SAVE config-2.xml 1')
        deadline2 = wd.deadline_monotonic

        self.assertEqual(wd.saves_count, 2)
        self.assertGreater(deadline2, deadline1)

    def test_background_save_does_not_push_countdown_out(self):
        wd = self.get_watchdog()
        wd.handle_command('SAVE config-1.xml 1')
        deadline_user = wd.deadline_monotonic

        time.sleep(0.05)
        # Background save with is_user=0
        res = json.loads(wd.handle_command('SAVE config-bg.xml 0'))
        self.assertEqual(res['status'], 'ok')
        self.assertEqual(wd.saves_count, 2)
        self.assertIn('config-bg.xml', wd.revisions)
        # Monotonic deadline should NOT have moved forward!
        self.assertEqual(wd.deadline_monotonic, deadline_user)

    def test_absolute_session_ceiling_enforced(self):
        # 10s countdown, 5s extension, max 2 extensions => ceiling is at most 20s
        self.marker_data['countdown_seconds'] = 10
        self.marker_data['extension_seconds'] = 5
        self.marker_data['max_extensions'] = 2
        with open(self.marker_file, 'w', encoding='utf-8') as f:
            json.dump(self.marker_data, f)

        wd = self.get_watchdog()
        wd.handle_command('SAVE config-1.xml 1')
        ceiling = wd.absolute_ceiling_monotonic

        # Repeated saves cannot push deadline past ceiling
        for i in range(5):
            wd.handle_command(f'SAVE config-{i+2}.xml 1')
            self.assertLessEqual(wd.deadline_monotonic, ceiling)

    def test_extend_countdown(self):
        wd = self.get_watchdog()
        # Cannot extend before first save
        res0 = json.loads(wd.handle_command('EXTEND'))
        self.assertEqual(res0['status'], 'failed')

        # Save first
        wd.handle_command('SAVE config-1.xml 1')
        deadline_orig = wd.deadline_monotonic

        # Now extend
        res1 = json.loads(wd.handle_command('EXTEND'))
        self.assertEqual(res1['status'], 'ok')
        self.assertEqual(res1['extensions_count'], 1)
        self.assertAlmostEqual(wd.deadline_monotonic, deadline_orig + 300, delta=1.0)

    def test_max_extensions_enforced(self):
        wd = self.get_watchdog()
        wd.handle_command('SAVE config-1.xml 1')

        # max_extensions is 3
        res1 = json.loads(wd.handle_command('EXTEND'))
        self.assertEqual(res1['status'], 'ok')
        res2 = json.loads(wd.handle_command('EXTEND'))
        self.assertEqual(res2['status'], 'ok')
        res3 = json.loads(wd.handle_command('EXTEND'))
        self.assertEqual(res3['status'], 'ok')

        # 4th extension must be rejected
        res4 = json.loads(wd.handle_command('EXTEND'))
        self.assertEqual(res4['status'], 'failed')
        self.assertEqual(res4['error'], 'max_extensions_reached')

    def test_handle_confirm(self):
        wd = self.get_watchdog()
        res = json.loads(wd.handle_command('CONFIRM'))
        self.assertEqual(res['status'], 'ok')
        self.assertFalse(wd.running)
        self.assertFalse(os.path.exists(self.marker_file))
        self.assertFalse(os.path.exists(self.snapshot_file))

    def test_atomic_revert(self):
        wd = self.get_watchdog()
        # Config has modified content
        with open(self.config_file, 'rb') as f:
            self.assertIn(b'firewall-mod', f.read())

        success = wd.execute_revert(reason='test_manual_revert')
        self.assertTrue(success)

        # Config must now be restored to snapshot content
        with open(self.config_file, 'rb') as f:
            content = f.read()
            self.assertIn(b'firewall-snap', content)
            self.assertNotIn(b'firewall-mod', content)

        # Notice file must be created
        self.assertTrue(os.path.exists(self.notice_file))
        with open(self.notice_file, 'r', encoding='utf-8') as f:
            notice = json.load(f)
            self.assertEqual(notice['username'], 'admin')
            self.assertEqual(notice['reason'], 'test_manual_revert')
            self.assertEqual(notice['gui_url'], 'https://192.168.1.1')

        # Reverted backup XML must be created in backup dir
        backups = [b for b in os.listdir(self.backup_dir) if b.endswith('.xml')]
        self.assertGreater(len(backups), 0)
        backup_xml = os.path.join(self.backup_dir, backups[0])
        tree = ET.parse(backup_xml)
        rev = tree.find('revision')
        self.assertIsNotNone(rev)
        tag = rev.find('session_tag')
        self.assertIsNotNone(tag)
        self.assertEqual(tag.text, 'Reverted')

        # Sidecar index must record Reverted
        sidecar_file = os.path.join(self.backup_dir, 'session_tags.json')
        self.assertTrue(os.path.exists(sidecar_file))
        with open(sidecar_file, 'r', encoding='utf-8') as f:
            tags = json.load(f)
        self.assertEqual(tags.get(backups[0]), 'Reverted')

        # Marker and snapshot must be deleted
        self.assertFalse(os.path.exists(self.marker_file))
        self.assertFalse(os.path.exists(self.snapshot_file))

    def test_failed_revert_preserves_snapshot_and_marker(self):
        # Point config_file to an uncreatable / directory path to trigger write error
        bad_config_file = os.path.join(self.test_dir, 'nonexistent_sub', 'cannot_write.xml')
        wd = self.get_watchdog(config_file=bad_config_file)

        success = wd.execute_revert(reason='test_failure')
        self.assertFalse(success)

        # Snapshot and marker MUST be preserved on failure!
        self.assertTrue(os.path.exists(self.marker_file))
        self.assertTrue(os.path.exists(self.snapshot_file))

    def test_failed_reload_preserves_snapshot_and_marker(self):
        # Create a reload script that exits with non-zero status
        if sys.platform == 'win32':
            failing_reload = os.path.join(self.test_dir, 'failing_reload.bat')
            with open(failing_reload, 'w') as f:
                f.write("@exit /b 1\n")
        else:
            failing_reload = os.path.join(self.test_dir, 'failing_reload.sh')
            with open(failing_reload, 'w') as f:
                f.write("#!/bin/sh\nexit 1\n")
            os.chmod(failing_reload, 0o755)

        wd = self.get_watchdog(reload_script=failing_reload)
        success = wd.execute_revert(reason='test_reload_failure')
        self.assertFalse(success)

        # Snapshot and marker MUST be preserved when reload fails so boot syshook can recover!
        self.assertTrue(os.path.exists(self.marker_file))
        self.assertTrue(os.path.exists(self.snapshot_file))

    def test_reload_subprocess_flags(self):
        from unittest.mock import patch, MagicMock
        wd = self.get_watchdog()
        with patch('subprocess.run') as mock_run:
            mock_run.return_value = MagicMock(returncode=0)
            success = wd.execute_revert(reason='test_flags')
            self.assertTrue(success)
            mock_run.assert_called_once_with(
                [self.reload_script],
                stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL,
                close_fds=True,
                timeout=180
            )

    def test_failed_boot_revert_preserves_snapshot_and_marker(self):
        bad_config_file = os.path.join(self.test_dir, 'nonexistent_sub', 'cannot_write.xml')
        wd = self.get_watchdog(config_file=bad_config_file)

        success = wd.execute_boot_revert()
        self.assertFalse(success)

        # Snapshot and marker MUST be preserved on boot failure!
        self.assertTrue(os.path.exists(self.marker_file))
        self.assertTrue(os.path.exists(self.snapshot_file))

    def test_load_marker_resumes_remaining_time(self):
        # Emulate watchdog restart: countdown was active with 42s remaining
        now = time.time()
        self.marker_data['countdown_active'] = True
        self.marker_data['expire_epoch'] = now + 42.0
        with open(self.marker_file, 'w', encoding='utf-8') as f:
            json.dump(self.marker_data, f)

        wd = self.get_watchdog()
        self.assertTrue(wd.countdown_active)
        rem = wd.deadline_monotonic - time.monotonic()
        # Must be approximately 42s, NOT 180s!
        self.assertAlmostEqual(rem, 42.0, delta=1.5)

    def test_idle_timeout_monotonic_tracking(self):
        wd = self.get_watchdog()
        self.assertFalse(wd.countdown_active)
        # Idle deadline is 3600 seconds in the future
        self.assertGreater(wd.idle_deadline_monotonic, time.monotonic() + 3500)

    def test_external_marker_removal_detected(self):
        wd = self.get_watchdog()
        os.unlink(self.marker_file)
        self.assertFalse(os.path.exists(self.marker_file))
        self.assertFalse(wd.load_marker())

    def test_revert_updates_prior_session_revisions_to_reverted(self):
        os.makedirs(self.backup_dir, exist_ok=True)
        prior_backup = os.path.join(self.backup_dir, 'config-1728460100.5012.xml')
        prior_xml = b'<opnsense><revision><time>1728460100.5012</time><session_tag>Pending</session_tag></revision></opnsense>'
        with open(prior_backup, 'wb') as f:
            f.write(prior_xml)

        self.marker_data['revisions'] = ['config-1728460100.5012.xml']
        with open(self.marker_file, 'w', encoding='utf-8') as f:
            json.dump(self.marker_data, f)

        wd = self.get_watchdog()
        success = wd.execute_revert(reason='countdown_expired')
        self.assertTrue(success)

        # Prior backup file must remain untouched
        with open(prior_backup, 'rb') as f:
            self.assertEqual(f.read(), prior_xml)

        # Sidecar index must record Reverted
        sidecar_file = os.path.join(self.backup_dir, 'session_tags.json')
        with open(sidecar_file, 'r', encoding='utf-8') as f:
            tags = json.load(f)
        self.assertEqual(tags.get('config-1728460100.5012.xml'), 'Reverted')

    def test_confirm_updates_prior_session_revisions_to_confirmed(self):
        os.makedirs(self.backup_dir, exist_ok=True)
        prior_backup = os.path.join(self.backup_dir, 'config-1728460111.2234.xml')
        prior_xml = b'<opnsense><revision><time>1728460111.2234</time><session_tag>Pending</session_tag></revision></opnsense>'
        with open(prior_backup, 'wb') as f:
            f.write(prior_xml)

        self.marker_data['revisions'] = ['config-1728460111.2234.xml']
        with open(self.marker_file, 'w', encoding='utf-8') as f:
            json.dump(self.marker_data, f)

        wd = self.get_watchdog()
        res = json.loads(wd.handle_command('CONFIRM'))
        self.assertEqual(res['status'], 'ok')

        # Prior backup file must remain untouched
        with open(prior_backup, 'rb') as f:
            self.assertEqual(f.read(), prior_xml)

        # Sidecar index must also record Confirmed
        sidecar_file = os.path.join(self.backup_dir, 'session_tags.json')
        with open(sidecar_file, 'r', encoding='utf-8') as f:
            tags = json.load(f)
        self.assertEqual(tags.get('config-1728460111.2234.xml'), 'Confirmed')

    def test_execute_boot_revert(self):
        os.makedirs(self.backup_dir, exist_ok=True)
        prior_backup = os.path.join(self.backup_dir, 'config-1728460200.0011.xml')
        prior_xml = b'<opnsense><revision><time>1728460200.0011</time><session_tag>Pending</session_tag></revision></opnsense>'
        with open(prior_backup, 'wb') as f:
            f.write(prior_xml)

        self.marker_data['username'] = 'bob'
        self.marker_data['source'] = 'api'
        self.marker_data['revisions'] = ['config-1728460200.0011.xml']
        with open(self.marker_file, 'w', encoding='utf-8') as f:
            json.dump(self.marker_data, f)

        wd = self.get_watchdog()
        success = wd.execute_boot_revert()
        self.assertTrue(success)

        # Config must be restored to snapshot
        with open(self.config_file, 'rb') as f:
            self.assertIn(b'firewall-snap', f.read())

        # Notice file must reflect original session user and source
        self.assertTrue(os.path.exists(self.notice_file))
        with open(self.notice_file, 'r', encoding='utf-8') as f:
            notice = json.load(f)
            self.assertEqual(notice['username'], 'bob')
            self.assertEqual(notice['source'], 'api')
            self.assertEqual(notice['reason'], 'unconfirmed_at_boot')

        # Prior backup file must remain untouched
        with open(prior_backup, 'rb') as f:
            self.assertEqual(f.read(), prior_xml)

        # Sidecar index must record Reverted
        sidecar_file = os.path.join(self.backup_dir, 'session_tags.json')
        with open(sidecar_file, 'r', encoding='utf-8') as f:
            tags = json.load(f)
        self.assertEqual(tags.get('config-1728460200.0011.xml'), 'Reverted')

        # Marker and snapshot must be deleted
        self.assertFalse(os.path.exists(self.marker_file))
        self.assertFalse(os.path.exists(self.snapshot_file))

    def test_boot_revert_orphan_marker_cleanup(self):
        os.unlink(self.snapshot_file)
        self.assertTrue(os.path.exists(self.marker_file))
        self.assertFalse(os.path.exists(self.snapshot_file))

        wd = self.get_watchdog()
        success = wd.execute_boot_revert()
        self.assertFalse(success)
        # Orphan marker must be removed
        self.assertFalse(os.path.exists(self.marker_file))

    def test_zero_max_extensions(self):
        self.marker_data['max_extensions'] = 0
        with open(self.marker_file, 'w', encoding='utf-8') as f:
            json.dump(self.marker_data, f)

        wd = self.get_watchdog()
        wd.handle_command('SAVE config-1.xml 1')
        res = json.loads(wd.handle_command('EXTEND'))
        self.assertEqual(res['status'], 'failed')
        self.assertEqual(res['error'], 'max_extensions_reached')

    def test_handle_command_revert(self):
        wd = self.get_watchdog()
        res = json.loads(wd.handle_command('REVERT'))
        self.assertEqual(res['status'], 'ok')
        self.assertEqual(res['message'], 'reverting')
        self.assertEqual(res['gui_url'], 'https://192.168.1.1')
        self.assertTrue(wd.trigger_revert_manual)


if __name__ == '__main__':
    unittest.main()
