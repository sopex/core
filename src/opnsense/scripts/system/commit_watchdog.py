#!/usr/local/bin/python3

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

"""
Commit-Confirmed Watchdog Daemon.
Runs as a detached background process tracking protected change sessions using a monotonic clock.
If the countdown expires or an explicit revert is ordered, atomically restores config.xml
and triggers rc.reload_all (or reboots on reload failure).
"""

import json
import os
import select
import signal
import socket
import subprocess
import sys
import time
import xml.etree.ElementTree as ET

try:
    import syslog
except ImportError:
    class MockSyslog:
        LOG_PID = 1
        LOG_AUTH = 32
        LOG_NOTICE = 5
        LOG_WARNING = 4
        LOG_ERR = 3
        LOG_CRIT = 2

        @staticmethod
        def openlog(*args, **kwargs):
            pass

        @staticmethod
        def syslog(*args, **kwargs):
            pass

    syslog = MockSyslog()

MARKER_FILE = '/conf/commit_rollback_pending.json'
SNAPSHOT_FILE = '/conf/commit_rollback/snapshot.xml'
NOTICE_FILE = '/conf/commit_rollback_notice.json'
SOCKET_FILE = '/var/run/commit_watchdog.sock'
PID_FILE = '/var/run/commit_watchdog.pid'
SUPERVISOR_PID_FILE = '/var/run/commit_watchdog_sup.pid'
CONFIG_FILE = '/conf/config.xml'
BACKUP_DIR = '/conf/backup'
RELOAD_SCRIPT = '/usr/local/etc/rc.reload_all'
SHUTDOWN_BIN = '/sbin/shutdown'
REBOOT_BIN = '/sbin/reboot'
IDLE_TIMEOUT_SECONDS = 3600.0


class CommitWatchdog:
    def __init__(self, marker_file=MARKER_FILE, snapshot_file=SNAPSHOT_FILE,
                 socket_file=SOCKET_FILE, notice_file=NOTICE_FILE,
                 config_file=CONFIG_FILE, backup_dir=BACKUP_DIR,
                 reload_script=RELOAD_SCRIPT, idle_timeout=IDLE_TIMEOUT_SECONDS,
                 shutdown_bin=SHUTDOWN_BIN, reboot_bin=REBOOT_BIN):
        self.marker_file = marker_file
        self.snapshot_file = snapshot_file
        self.socket_file = socket_file
        self.notice_file = notice_file
        self.config_file = config_file
        self.backup_dir = backup_dir
        self.reload_script = reload_script
        self.idle_timeout = idle_timeout
        self.shutdown_bin = shutdown_bin
        self.reboot_bin = reboot_bin

        self.running = True
        self.countdown_active = False
        self.deadline_monotonic = 0.0
        self.absolute_ceiling_monotonic = float('inf')
        self.idle_deadline_monotonic = time.monotonic() + self.idle_timeout

        self.countdown_seconds = 180.0
        self.extension_seconds = 300.0
        self.max_extensions = 6
        self.extensions_count = 0
        self.saves_count = 0
        self.username = 'admin'
        self.source = 'gui'
        self.session_id = ''
        self.gui_url = ''
        self.revisions = []
        self.trigger_revert_manual = False

        self.server_sock = None
        self.load_marker()

    def load_marker(self):
        if not os.path.exists(self.marker_file):
            return False
        try:
            with open(self.marker_file, 'r', encoding='utf-8') as f:
                data = json.load(f)
            self.session_id = data.get('session_id', '')
            self.username = data.get('username', 'admin')
            self.source = data.get('source', 'gui')
            self.countdown_seconds = float(data.get('countdown_seconds', 180))
            self.extension_seconds = float(data.get('extension_seconds', 300))
            self.max_extensions = int(data.get('max_extensions', 6))
            self.extensions_count = int(data.get('extensions_count', 0))
            self.saves_count = int(data.get('saves_count', 0))
            self.gui_url = data.get('gui_url', '')
            self.revisions = data.get('revisions', [])

            created_at = float(data.get('created_at', time.time()))

            # Absolute session ceiling to ensure background saves or loops cannot postpone revert forever
            if data.get('absolute_ceiling_epoch'):
                rem_ceiling = max(0.0, float(data['absolute_ceiling_epoch']) - time.time())
                self.absolute_ceiling_monotonic = time.monotonic() + rem_ceiling
            else:
                max_total = self.countdown_seconds + (self.max_extensions * self.extension_seconds)
                self.absolute_ceiling_monotonic = time.monotonic() + max_total

            # Resume remaining time instead of restarting full countdown
            if data.get('countdown_active'):
                self.countdown_active = True
                if data.get('expire_epoch'):
                    rem = float(data['expire_epoch']) - time.time()
                    self.deadline_monotonic = time.monotonic() + max(0.0, rem)
                else:
                    self.deadline_monotonic = time.monotonic() + self.countdown_seconds
            else:
                self.countdown_active = False
                idle_rem = (created_at + self.idle_timeout) - time.time()
                self.idle_deadline_monotonic = time.monotonic() + max(0.0, idle_rem)

            return True
        except Exception as e:
            syslog.syslog(syslog.LOG_ERR, f"commit-watchdog: error reading marker: {e}")
            return False

    def update_marker(self):
        if not os.path.exists(self.marker_file):
            return
        try:
            with open(self.marker_file, 'r', encoding='utf-8') as f:
                data = json.load(f)
            data['countdown_active'] = self.countdown_active
            data['extensions_count'] = self.extensions_count
            data['saves_count'] = self.saves_count
            data['revisions'] = self.revisions
            if self.countdown_active:
                rem = max(0.0, self.deadline_monotonic - time.monotonic())
                data['expire_epoch'] = time.time() + rem
                ceiling_rem = max(0.0, self.absolute_ceiling_monotonic - time.monotonic())
                data['absolute_ceiling_epoch'] = time.time() + ceiling_rem
            with open(self.marker_file + '.tmp', 'w', encoding='utf-8') as f:
                json.dump(data, f, indent=2)
                f.flush()
                os.fsync(f.fileno())
            os.replace(self.marker_file + '.tmp', self.marker_file)
        except Exception as e:
            syslog.syslog(syslog.LOG_ERR, f"commit-watchdog: error updating marker: {e}")

    def tag_revisions_in_history(self, revisions, tag):
        """Update session_tags.json sidecar index for revisions without touching backup files."""
        if not revisions:
            return
        os.makedirs(self.backup_dir, mode=0o750, exist_ok=True)
        tags_file = os.path.join(self.backup_dir, 'session_tags.json')
        tags = {}
        if os.path.exists(tags_file):
            try:
                with open(tags_file, 'r', encoding='utf-8') as f:
                    tags = json.load(f)
            except Exception:
                tags = {}

        for rev in revisions:
            rev_str = str(rev).strip()
            if rev_str.startswith('config-') and rev_str.endswith('.xml'):
                filename = rev_str
            else:
                clean_time = ''.join(c for c in rev_str if c.isdigit() or c == '.')
                filename = f"config-{clean_time}.xml"

            tags[filename] = tag

        try:
            tmp_tags = tags_file + '.tmp'
            with open(tmp_tags, 'w', encoding='utf-8') as f:
                json.dump(tags, f, indent=2)
                f.flush()
                os.fsync(f.fileno())
            os.chmod(tmp_tags, 0o640)
            os.replace(tmp_tags, tags_file)
        except Exception as e:
            syslog.syslog(syslog.LOG_ERR, f"commit-watchdog: failed to update session_tags.json: {e}")

    def setup_socket(self):
        try:
            if os.path.exists(self.socket_file):
                os.unlink(self.socket_file)
            self.server_sock = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
            self.server_sock.bind(self.socket_file)
            os.chmod(self.socket_file, 0o600)
            self.server_sock.listen(5)
            self.server_sock.setblocking(False)
        except Exception as e:
            syslog.syslog(syslog.LOG_ERR, f"commit-watchdog: failed to bind socket: {e}")
            self.server_sock = None

    def handle_command(self, cmd_line):
        parts = cmd_line.strip().split()
        if not parts:
            return json.dumps({"error": "empty_command"})
        cmd = parts[0].upper()

        if cmd == 'STATUS':
            rem = max(0, int(round(self.deadline_monotonic - time.monotonic()))) if self.countdown_active else 0
            return json.dumps({
                "status": "ok",
                "countdown_active": self.countdown_active,
                "remaining_seconds": rem,
                "extensions_count": self.extensions_count,
                "max_extensions": self.max_extensions,
                "saves_count": self.saves_count,
                "gui_url": self.gui_url
            })

        elif cmd == 'SAVE':
            # Format: SAVE [filename] [is_user: 0|1]
            rev_file = parts[1] if len(parts) > 1 and not parts[1].isdigit() and parts[1].lower() != 'none' else None
            is_user = True
            if len(parts) > 2:
                is_user = (parts[2] == '1' or parts[2].lower() == 'true')
            elif len(parts) > 1 and parts[1].isdigit():
                is_user = (parts[1] == '1')

            if rev_file and rev_file not in self.revisions:
                self.revisions.append(rev_file)
                self.tag_revisions_in_history([rev_file], 'Pending')

            self.saves_count += 1

            if is_user:
                self.countdown_active = True
                new_deadline = time.monotonic() + self.countdown_seconds
                self.deadline_monotonic = min(new_deadline, self.absolute_ceiling_monotonic)

            self.update_marker()
            rem = max(0, int(round(self.deadline_monotonic - time.monotonic()))) if self.countdown_active else 0
            return json.dumps({
                "status": "ok",
                "countdown_seconds": self.countdown_seconds,
                "saves_count": self.saves_count,
                "countdown_active": self.countdown_active,
                "remaining_seconds": rem
            })

        elif cmd == 'EXTEND':
            if not self.countdown_active:
                return json.dumps({"status": "failed", "error": "not_counting"})
            if self.extensions_count >= self.max_extensions:
                return json.dumps({"status": "failed", "error": "max_extensions_reached"})
            new_deadline = self.deadline_monotonic + self.extension_seconds
            self.deadline_monotonic = min(new_deadline, self.absolute_ceiling_monotonic)
            self.extensions_count += 1
            self.update_marker()
            rem = max(0, int(round(self.deadline_monotonic - time.monotonic())))
            return json.dumps({
                "status": "ok",
                "extensions_count": self.extensions_count,
                "max_extensions": self.max_extensions,
                "extension_seconds": self.extension_seconds,
                "remaining_seconds": rem
            })

        elif cmd == 'CONFIRM':
            self.tag_revisions_in_history(self.revisions, 'Confirmed')
            self.cleanup_session()
            self.running = False
            return json.dumps({"status": "ok", "message": "confirmed"})

        elif cmd == 'REVERT':
            self.trigger_revert_manual = True
            self.revert_reason = parts[1] if len(parts) > 1 else 'manual'
            return json.dumps({
                "status": "ok",
                "message": "reverting",
                "gui_url": self.gui_url
            })

        return json.dumps({"status": "failed", "error": "unknown_command"})

    def cleanup_session(self):
        # Stop supervisor if running so daemon -r doesn't respawn after intentional exit
        if os.path.exists(SUPERVISOR_PID_FILE):
            try:
                with open(SUPERVISOR_PID_FILE, 'r') as f:
                    sup_pid = int(f.read().strip())
                if sup_pid > 0:
                    os.kill(sup_pid, signal.SIGTERM)
            except Exception:
                pass
            try:
                os.unlink(SUPERVISOR_PID_FILE)
            except OSError:
                pass

        for path in [self.marker_file, self.snapshot_file, self.socket_file, PID_FILE]:
            if os.path.exists(path):
                try:
                    os.unlink(path)
                except OSError:
                    pass

    def execute_revert(self, reason='countdown_expired'):
        syslog.syslog(
            syslog.LOG_WARNING,
            f"commit-session: configuration rollback triggered for user {self.username} via {self.source} (reason: {reason})"
        )

        if not os.path.exists(self.snapshot_file):
            syslog.syslog(syslog.LOG_ERR, f"commit-session: snapshot file {self.snapshot_file} missing during revert!")
            return False

        try:
            with open(self.snapshot_file, 'rb') as f:
                snapshot_bytes = f.read()

            # 1. Atomically restore snapshot to config.xml
            tmp_config = self.config_file + '.revert_tmp'
            with open(tmp_config, 'wb') as f:
                f.write(snapshot_bytes)
                f.flush()
                os.fsync(f.fileno())
            os.chmod(tmp_config, 0o640)
            os.replace(tmp_config, self.config_file)

            # 2. Record history revision marked as Reverted in /conf/backup/
            revert_time = f"{time.time():.4f}"
            os.makedirs(self.backup_dir, mode=0o750, exist_ok=True)
            backup_file = os.path.join(self.backup_dir, f"config-{revert_time}.xml")

            try:
                root = ET.fromstring(snapshot_bytes)
                rev = root.find('revision')
                if rev is None:
                    rev = ET.SubElement(root, 'revision')
                rev_user = rev.find('username')
                if rev_user is None:
                    rev_user = ET.SubElement(rev, 'username')
                rev_user.text = self.username

                rev_time_el = rev.find('time')
                if rev_time_el is None:
                    rev_time_el = ET.SubElement(rev, 'time')
                rev_time_el.text = revert_time

                rev_desc = rev.find('description')
                if rev_desc is None:
                    rev_desc = ET.SubElement(rev, 'description')
                rev_desc.text = f"Reverted to snapshot from protected session ({reason})"

                rev_tag = rev.find('session_tag')
                if rev_tag is None:
                    rev_tag = ET.SubElement(rev, 'session_tag')
                rev_tag.text = 'Reverted'

                tree = ET.ElementTree(root)
                tree.write(backup_file, encoding='utf-8', xml_declaration=True)
                os.chmod(backup_file, 0o640)
            except Exception as e:
                syslog.syslog(syslog.LOG_ERR, f"commit-session: failed to write XML revision tag: {e}")
                with open(backup_file, 'wb') as f:
                    f.write(snapshot_bytes)
                os.chmod(backup_file, 0o640)

            # 3. Update session revisions + revert backup in backup history to Reverted
            all_revisions = list(self.revisions)
            if f"config-{revert_time}.xml" not in all_revisions:
                all_revisions.append(f"config-{revert_time}.xml")
            self.tag_revisions_in_history(all_revisions, 'Reverted')

            # 4. Create login notice file
            notice_data = {
                'reverted_at': int(time.time()),
                'reverted_at_iso': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()),
                'username': self.username,
                'source': self.source,
                'reason': reason,
                'backup_id': f"config-{revert_time}.xml",
                'gui_url': self.gui_url
            }
            tmp_notice = self.notice_file + '.tmp'
            with open(tmp_notice, 'w', encoding='utf-8') as f:
                json.dump(notice_data, f, indent=2)
                f.flush()
                os.fsync(f.fileno())
            os.chmod(tmp_notice, 0o640)
            os.replace(tmp_notice, self.notice_file)

            # 5. Clean up pending marker and snapshot ONLY on restore success
            self.cleanup_session()

            # 6. Run reload-all-services path
            syslog.syslog(syslog.LOG_NOTICE, f"commit-session: executing {self.reload_script} following revert")
            reload_ok = False
            if os.path.exists(self.reload_script):
                try:
                    res = subprocess.run([self.reload_script], capture_output=True, timeout=180)
                    if res.returncode == 0:
                        reload_ok = True
                    else:
                        syslog.syslog(
                            syslog.LOG_ERR,
                            f"commit-session: {self.reload_script} exited with code {res.returncode}"
                        )
                except Exception as e:
                    syslog.syslog(syslog.LOG_ERR, f"commit-session: failed to run reload script: {e}")

            # 7. If reload fails, reboot system
            if not reload_ok:
                syslog.syslog(syslog.LOG_CRIT, "commit-session: reload-all failed; rebooting system now!")
                if os.path.exists(self.shutdown_bin):
                    subprocess.run([self.shutdown_bin, '-r', 'now'])
                elif os.path.exists(self.reboot_bin):
                    subprocess.run([self.reboot_bin])

            return True

        except Exception as e:
            syslog.syslog(syslog.LOG_CRIT, f"commit-session: critical error during revert: {e}")
            if os.path.exists(tmp_config):
                try:
                    os.unlink(tmp_config)
                except OSError:
                    pass
            # Do NOT cleanup snapshot and marker on failure; keep them for fallback or recovery!
            return False

    def execute_boot_revert(self):
        """Execute revert at early boot before any service reads config.xml."""
        if not os.path.exists(self.snapshot_file):
            if os.path.exists(self.marker_file):
                syslog.openlog('config', syslog.LOG_PID, syslog.LOG_AUTH)
                syslog.syslog(syslog.LOG_ERR, "commit-session: boot-time revert failed: snapshot missing; removing orphan marker.")
                try:
                    os.unlink(self.marker_file)
                except OSError:
                    pass
            return False

        self.load_marker()
        user = self.username or 'unknown'
        src = self.source or 'unknown'

        syslog.openlog('config', syslog.LOG_PID, syslog.LOG_AUTH)
        syslog.syslog(
            syslog.LOG_NOTICE,
            f"commit-session: boot-time revert: unconfirmed protected session by {user} via {src} reverted to snapshot."
        )

        try:
            with open(self.snapshot_file, 'rb') as f:
                snapshot_bytes = f.read()

            # Atomically restore snapshot to config.xml
            tmp_config = self.config_file + '.boot_tmp'
            with open(tmp_config, 'wb') as f:
                f.write(snapshot_bytes)
                f.flush()
                os.fsync(f.fileno())
            os.chmod(tmp_config, 0o640)
            os.replace(tmp_config, self.config_file)

            # Record backup revision in backup directory tagged as Reverted
            revert_time = f"{time.time():.4f}"
            os.makedirs(self.backup_dir, mode=0o750, exist_ok=True)
            backup_file = os.path.join(self.backup_dir, f"config-{revert_time}.xml")

            try:
                root = ET.fromstring(snapshot_bytes)
                rev = root.find('revision')
                if rev is None:
                    rev = ET.SubElement(root, 'revision')
                rev_user = rev.find('username')
                if rev_user is None:
                    rev_user = ET.SubElement(rev, 'username')
                rev_user.text = user

                rev_time_el = rev.find('time')
                if rev_time_el is None:
                    rev_time_el = ET.SubElement(rev, 'time')
                rev_time_el.text = revert_time

                rev_desc = rev.find('description')
                if rev_desc is None:
                    rev_desc = ET.SubElement(rev, 'description')
                rev_desc.text = f"Reverted unconfirmed protected session at boot ({user} via {src})"

                rev_tag = rev.find('session_tag')
                if rev_tag is None:
                    rev_tag = ET.SubElement(rev, 'session_tag')
                rev_tag.text = 'Reverted'

                tree = ET.ElementTree(root)
                tree.write(backup_file, encoding='utf-8', xml_declaration=True)
                os.chmod(backup_file, 0o640)
            except Exception as e:
                syslog.syslog(syslog.LOG_ERR, f"commit-session: failed to write boot XML revision tag: {e}")
                with open(backup_file, 'wb') as f:
                    f.write(snapshot_bytes)
                os.chmod(backup_file, 0o640)

            # Update pending revisions in backup history to Reverted
            all_revisions = list(self.revisions)
            if f"config-{revert_time}.xml" not in all_revisions:
                all_revisions.append(f"config-{revert_time}.xml")
            self.tag_revisions_in_history(all_revisions, 'Reverted')

            # Create login notice
            notice_data = {
                'reverted_at': int(time.time()),
                'reverted_at_iso': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()),
                'username': user,
                'source': src,
                'reason': 'unconfirmed_at_boot',
                'backup_id': f"config-{revert_time}.xml",
                'gui_url': self.gui_url
            }
            tmp_notice = self.notice_file + '.tmp'
            with open(tmp_notice, 'w', encoding='utf-8') as f:
                json.dump(notice_data, f, indent=2)
                f.flush()
                os.fsync(f.fileno())
            os.chmod(tmp_notice, 0o640)
            os.replace(tmp_notice, self.notice_file)

            # Clean up marker and snapshot ONLY on restore success
            self.cleanup_session()
            return True

        except Exception as e:
            syslog.syslog(syslog.LOG_CRIT, f"commit-session: critical error during boot revert: {e}")
            if os.path.exists(tmp_config):
                try:
                    os.unlink(tmp_config)
                except OSError:
                    pass
            # Do NOT cleanup marker and snapshot on failure!
            return False

    def run(self):
        syslog.openlog('commit-watchdog', syslog.LOG_PID, syslog.LOG_AUTH)
        syslog.syslog(syslog.LOG_NOTICE, f"commit-watchdog: started for session {self.session_id}")

        self.setup_socket()

        def sig_handler(signum, frame):
            self.running = False

        try:
            signal.signal(signal.SIGTERM, sig_handler)
            signal.signal(signal.SIGINT, sig_handler)
        except Exception:
            pass

        while self.running:
            # Check if marker still exists; if removed externally, stop
            if not os.path.exists(self.marker_file):
                syslog.syslog(syslog.LOG_NOTICE, "commit-watchdog: marker removed, exiting")
                break

            # Handle socket input if available
            r_list = [self.server_sock] if self.server_sock else []
            if not r_list:
                time.sleep(0.5)
            else:
                try:
                    readable, _, _ = select.select(r_list, [], [], 0.5)
                except (select.error, OSError):
                    readable = []
                    time.sleep(0.5)

                for sock in readable:
                    try:
                        conn, _ = sock.accept()
                        conn.settimeout(2.0)
                        data = conn.recv(1024).decode('utf-8')
                        if data:
                            resp = self.handle_command(data)
                            conn.sendall(resp.encode('utf-8') + b"\n")
                        conn.close()
                    except Exception as e:
                        syslog.syslog(syslog.LOG_ERR, f"commit-watchdog: socket handling error: {e}")

            if self.trigger_revert_manual:
                self.execute_revert(reason=getattr(self, 'revert_reason', 'manual'))
                self.running = False
                break

            # Monotonic time checks
            now_mono = time.monotonic()

            if self.countdown_active:
                rem = self.deadline_monotonic - now_mono
                if rem <= 0:
                    syslog.syslog(syslog.LOG_WARNING, "commit-watchdog: countdown reached zero, reverting!")
                    self.execute_revert(reason='countdown_expired')
                    self.running = False
                    break
            else:
                # Session opened without any saves yet: check 60-minute idle timeout
                if now_mono >= self.idle_deadline_monotonic:
                    syslog.syslog(
                        syslog.LOG_NOTICE,
                        "commit-session: session idle timeout (60 minutes with no saves), session closed without changes."
                    )
                    self.cleanup_session()
                    self.running = False
                    break

        # Cleanup socket on exit
        if self.server_sock:
            try:
                self.server_sock.close()
            except Exception:
                pass
        if os.path.exists(self.socket_file):
            try:
                os.unlink(self.socket_file)
            except OSError:
                pass
        syslog.syslog(syslog.LOG_NOTICE, "commit-watchdog: terminated")


def check_watchdog_supervisor():
    """Supervisor check intended to run periodically (e.g. 1-minute cron)."""
    marker_file = MARKER_FILE
    socket_file = SOCKET_FILE

    if not os.path.exists(marker_file):
        # No session active. If orphan supervisor or socket exists, clean up.
        if os.path.exists(SUPERVISOR_PID_FILE):
            try:
                with open(SUPERVISOR_PID_FILE, 'r') as f:
                    sup_pid = int(f.read().strip())
                if sup_pid > 0:
                    os.kill(sup_pid, signal.SIGTERM)
            except Exception:
                pass
            try:
                os.unlink(SUPERVISOR_PID_FILE)
            except OSError:
                pass
        if os.path.exists(PID_FILE):
            try:
                os.unlink(PID_FILE)
            except OSError:
                pass
        if os.path.exists(socket_file):
            try:
                os.unlink(socket_file)
            except OSError:
                pass
        return 0

    # Marker exists: verify watchdog is alive and responsive
    alive = False
    if os.path.exists(socket_file):
        try:
            s = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
            s.settimeout(2.0)
            s.connect(socket_file)
            s.sendall(b"STATUS\n")
            data = s.recv(1024).decode('utf-8')
            s.close()
            resp = json.loads(data)
            if resp.get('status') == 'ok':
                alive = True
        except Exception:
            alive = False

    if not alive:
        syslog.openlog('commit-watchdog', syslog.LOG_PID, syslog.LOG_AUTH)
        syslog.syslog(syslog.LOG_WARNING, "commit-watchdog: supervisor check detected dead watchdog with active session; respawning!")
        for pfile in [SUPERVISOR_PID_FILE, PID_FILE]:
            if os.path.exists(pfile):
                try:
                    with open(pfile, 'r') as pf:
                        p = int(pf.read().strip())
                    if p > 0:
                        os.kill(p, signal.SIGTERM)
                except Exception:
                    pass
                try:
                    os.unlink(pfile)
                except OSError:
                    pass
        if os.path.exists(socket_file):
            try:
                os.unlink(socket_file)
            except OSError:
                pass
        python_bin = sys.executable or '/usr/local/bin/python3'
        script_path = os.path.abspath(__file__)
        daemon_bin = '/usr/sbin/daemon'
        if os.path.exists(daemon_bin):
            subprocess.run([
                daemon_bin, '-f', '-r',
                '-P', SUPERVISOR_PID_FILE,
                '-p', PID_FILE,
                python_bin, script_path
            ])
        else:
            subprocess.Popen([python_bin, script_path])
    return 0


if __name__ == '__main__':
    if len(sys.argv) > 1 and sys.argv[1] == '--boot-revert':
        watchdog = CommitWatchdog()
        ok = watchdog.execute_boot_revert()
        sys.exit(0 if ok else 1)
    elif len(sys.argv) > 1 and sys.argv[1] == '--revert':
        reason = sys.argv[2] if len(sys.argv) > 2 else 'manual_fallback'
        watchdog = CommitWatchdog()
        ok = watchdog.execute_revert(reason=reason)
        sys.exit(0 if ok else 1)
    elif len(sys.argv) > 1 and sys.argv[1] == '--check':
        sys.exit(check_watchdog_supervisor())
    else:
        watchdog = CommitWatchdog()
        watchdog.run()
