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

Session state machine:

    pending   - session started, no saves yet; closes on idle timeout
    counting  - at least one save; reverts when the countdown expires
    reverting - a revert was requested or the countdown expired; retried with
                backoff until it succeeds, the box reboots, or an admin confirms

Invariants:
    * Once a save has been recorded, the session can only end by confirm or revert.
      The idle timeout never discards recorded changes.
    * Any save arms the countdown if it is not running (fail closed). Only user saves
      restart a running countdown, so background saves cannot postpone a revert.
    * The session ceiling starts at the first save, so a save always gets a full countdown.
    * Only one process executes a revert at a time (revert lock).
"""

import fcntl
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
REVERT_LOCK_FILE = '/var/run/commit_watchdog_revert.lock'
CONFIG_FILE = '/conf/config.xml'
BACKUP_DIR = '/conf/backup'
RELOAD_SCRIPT = '/usr/local/etc/rc.reload_all'
SHUTDOWN_BIN = '/sbin/shutdown'
REBOOT_BIN = '/sbin/reboot'

IDLE_TIMEOUT_SECONDS = 3600.0
DEFAULT_COUNTDOWN_SECONDS = 600.0
DEFAULT_EXTENSION_SECONDS = 300.0
DEFAULT_MAX_EXTENSIONS = 6
DEFAULT_MAX_SESSION_SECONDS = 7200.0
RETRY_BASE_SECONDS = 15.0
RETRY_MAX_SECONDS = 300.0


def revert_backoff(attempts):
    """Delay before the next revert attempt after `attempts` failed attempts (15s, 30s, ... capped at 5m)."""
    if attempts <= 0:
        return 0.0
    return min(RETRY_MAX_SECONDS, RETRY_BASE_SECONDS * (2 ** (attempts - 1)))


def acquire_revert_lock(lock_file):
    """Try to take the revert lock without blocking. Returns an open fd, or None when another process holds it."""
    try:
        fd = os.open(lock_file, os.O_CREAT | os.O_RDWR, 0o600)
    except OSError:
        return None
    try:
        fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        return fd
    except OSError:
        os.close(fd)
        return None


def release_revert_lock(fd):
    if fd is None:
        return
    try:
        fcntl.flock(fd, fcntl.LOCK_UN)
    except OSError:
        pass
    try:
        os.close(fd)
    except OSError:
        pass


def revert_lock_held(lock_file):
    """True when some process is executing a revert right now."""
    if not os.path.exists(lock_file):
        return False
    fd = acquire_revert_lock(lock_file)
    if fd is None:
        return True
    release_revert_lock(fd)
    return False


def read_pid(pid_file):
    try:
        with open(pid_file, 'r') as f:
            pid = int(f.read().strip())
        return pid if pid > 0 else None
    except Exception:
        return None


def watchdog_pid_alive(pid_file):
    """Liveness by process, not by socket: a watchdog busy in rc.reload_all cannot answer, but is alive."""
    pid = read_pid(pid_file)
    if pid is None:
        return False
    try:
        os.kill(pid, 0)
    except ProcessLookupError:
        return False
    except PermissionError:
        return True
    # Guard against PID reuse: the process must be our watchdog script.
    try:
        res = subprocess.run(['/bin/ps', '-o', 'command=', '-p', str(pid)],
                             stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, timeout=5)
        command = res.stdout.decode('utf-8', 'replace')
        if res.returncode == 0 and command.strip():
            return 'commit_watchdog' in command
    except Exception:
        pass
    # ps unavailable or inconclusive: trust the signal check
    return True


class CommitWatchdog:
    def __init__(self, marker_file=MARKER_FILE, snapshot_file=SNAPSHOT_FILE,
                 socket_file=SOCKET_FILE, notice_file=NOTICE_FILE,
                 config_file=CONFIG_FILE, backup_dir=BACKUP_DIR,
                 reload_script=RELOAD_SCRIPT, idle_timeout=IDLE_TIMEOUT_SECONDS,
                 shutdown_bin=SHUTDOWN_BIN, reboot_bin=REBOOT_BIN,
                 pid_file=PID_FILE, supervisor_pid_file=SUPERVISOR_PID_FILE,
                 revert_lock_file=REVERT_LOCK_FILE,
                 clock=time.monotonic, wallclock=time.time):
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
        self.pid_file = pid_file
        self.supervisor_pid_file = supervisor_pid_file
        self.revert_lock_file = revert_lock_file
        self._mono = clock
        self._wall = wallclock

        self.running = True
        self.countdown_active = False
        self.deadline_monotonic = 0.0
        self.session_ceiling_monotonic = float('inf')
        self.idle_deadline_monotonic = self._mono() + self.idle_timeout

        self.countdown_seconds = DEFAULT_COUNTDOWN_SECONDS
        self.extension_seconds = DEFAULT_EXTENSION_SECONDS
        self.max_extensions = DEFAULT_MAX_EXTENSIONS
        self.max_session_seconds = DEFAULT_MAX_SESSION_SECONDS
        self.extensions_count = 0
        self.saves_count = 0
        self.username = 'admin'
        self.source = 'gui'
        self.session_id = ''
        self.gui_url = ''
        self.revisions = []

        # reverting state
        self.is_reverting = False
        self.revert_reason = 'manual'
        self.revert_attempts = 0
        self.last_revert_attempt_wall = None
        self.last_revert_error = ''
        self.next_revert_monotonic = 0.0
        self.reboot_initiated = False
        self.last_revert_outcome = None

        self.server_sock = None
        self.load_marker()

    # ------------------------------------------------------------------ state

    def load_marker(self):
        if not os.path.exists(self.marker_file):
            return False
        try:
            with open(self.marker_file, 'r', encoding='utf-8') as f:
                data = json.load(f)
            now_mono = self._mono()
            now_wall = self._wall()

            self.session_id = data.get('session_id', '')
            self.username = data.get('username', 'admin')
            self.source = data.get('source', 'gui')
            self.countdown_seconds = float(data.get('countdown_seconds', DEFAULT_COUNTDOWN_SECONDS))
            self.extension_seconds = float(data.get('extension_seconds', DEFAULT_EXTENSION_SECONDS))
            self.max_extensions = int(data.get('max_extensions', DEFAULT_MAX_EXTENSIONS))
            self.max_session_seconds = max(
                float(data.get('max_session_seconds', DEFAULT_MAX_SESSION_SECONDS)),
                self.countdown_seconds
            )
            self.extensions_count = int(data.get('extensions_count', 0))
            self.saves_count = int(data.get('saves_count', 0))
            self.gui_url = data.get('gui_url', '')
            self.reboot_initiated = bool(data.get('reboot_initiated', False))
            self.revisions = list(data.get('revisions', []) or [])
            created_at = float(data.get('created_at', now_wall))

            # Session ceiling only exists once the first save armed the countdown
            if data.get('session_ceiling_epoch'):
                rem_ceiling = max(0.0, float(data['session_ceiling_epoch']) - now_wall)
                self.session_ceiling_monotonic = now_mono + rem_ceiling
            else:
                self.session_ceiling_monotonic = float('inf')

            if data.get('status') == 'reverting' or data.get('reverting'):
                # Resume a revert that a previous process requested or failed to finish
                self.is_reverting = True
                self.countdown_active = False
                self.revert_reason = data.get('revert_reason', 'manual')
                self.revert_attempts = int(data.get('revert_attempts', 0))
                self.last_revert_error = data.get('last_revert_error', '')
                last_attempt = data.get('last_revert_attempt_epoch')
                self.last_revert_attempt_wall = float(last_attempt) if last_attempt else None
                wait = 0.0
                if self.revert_attempts > 0 and self.last_revert_attempt_wall is not None:
                    elapsed = max(0.0, now_wall - self.last_revert_attempt_wall)
                    wait = max(0.0, revert_backoff(self.revert_attempts) - elapsed)
                self.next_revert_monotonic = now_mono + wait
            elif data.get('countdown_active') or self.revisions:
                # Recorded changes always keep a countdown running (fail closed)
                self.countdown_active = True
                if data.get('countdown_active') and data.get('expire_epoch'):
                    rem = float(data['expire_epoch']) - now_wall
                    self.deadline_monotonic = now_mono + max(0.0, rem)
                else:
                    self.deadline_monotonic = now_mono + self.countdown_seconds
                if self.session_ceiling_monotonic == float('inf'):
                    self.session_ceiling_monotonic = now_mono + self.max_session_seconds
                self.deadline_monotonic = min(self.deadline_monotonic, self.session_ceiling_monotonic)
            else:
                self.countdown_active = False
                idle_rem = (created_at + self.idle_timeout) - now_wall
                self.idle_deadline_monotonic = now_mono + max(0.0, idle_rem)

            return True
        except Exception as e:
            syslog.syslog(syslog.LOG_ERR, f"commit-watchdog: error reading marker: {e}")
            return False

    def update_marker(self):
        """Persist live state. The watchdog is the only regular writer of the marker."""
        if not os.path.exists(self.marker_file):
            return
        try:
            with open(self.marker_file, 'r', encoding='utf-8') as f:
                data = json.load(f)
            now_mono = self._mono()
            now_wall = self._wall()
            data['reboot_initiated'] = self.reboot_initiated
            data['countdown_active'] = self.countdown_active
            data['extensions_count'] = self.extensions_count
            data['saves_count'] = self.saves_count
            data['revisions'] = self.revisions
            data['max_session_seconds'] = self.max_session_seconds
            data.pop('absolute_ceiling_epoch', None)
            if self.countdown_active:
                rem = max(0.0, self.deadline_monotonic - now_mono)
                data['expire_epoch'] = now_wall + rem
            if self.session_ceiling_monotonic != float('inf'):
                data['session_ceiling_epoch'] = now_wall + max(0.0, self.session_ceiling_monotonic - now_mono)
            if self.is_reverting:
                data['status'] = 'reverting'
                data['reverting'] = True
                data['countdown_active'] = False
                data['revert_reason'] = self.revert_reason
                data['revert_attempts'] = self.revert_attempts
                data['last_revert_error'] = self.last_revert_error
                if self.last_revert_attempt_wall is not None:
                    data['last_revert_attempt_epoch'] = self.last_revert_attempt_wall
                data.setdefault('revert_started_at', int(now_wall))
            tmp_marker = self.marker_file + '.tmp'
            with open(tmp_marker, 'w', encoding='utf-8') as f:
                json.dump(data, f, indent=2)
                f.flush()
                os.fsync(f.fileno())
            os.replace(tmp_marker, self.marker_file)
        except Exception as e:
            syslog.syslog(syslog.LOG_ERR, f"commit-watchdog: error updating marker: {e}")

    def set_reverting_marker(self, reason='manual'):
        """Enter the reverting state and persist it so GUI, API and a restarted watchdog all see it."""
        self.is_reverting = True
        self.countdown_active = False
        self.revert_reason = reason
        self.update_marker()

    def _arm_countdown(self):
        """Start or restart the countdown; the first arm also starts the session ceiling."""
        now = self._mono()
        if self.session_ceiling_monotonic == float('inf'):
            self.session_ceiling_monotonic = now + self.max_session_seconds
        self.countdown_active = True
        self.deadline_monotonic = min(now + self.countdown_seconds, self.session_ceiling_monotonic)

    def _remaining(self, deadline):
        return max(0, int(round(deadline - self._mono())))

    # ------------------------------------------------------------ history

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

    # ------------------------------------------------------------- socket

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
            if self.is_reverting:
                return json.dumps({
                    "status": "reverting",
                    "reverting": True,
                    "countdown_active": False,
                    "remaining_seconds": 0,
                    "revert_reason": self.revert_reason,
                    "revert_attempts": self.revert_attempts,
                    "last_revert_error": self.last_revert_error,
                    "next_retry_seconds": self._remaining(self.next_revert_monotonic),
                    "reboot_initiated": self.reboot_initiated,
                    "gui_url": self.gui_url
                })
            ceiling_started = self.session_ceiling_monotonic != float('inf')
            return json.dumps({
                "status": "ok",
                "countdown_active": self.countdown_active,
                "remaining_seconds": self._remaining(self.deadline_monotonic) if self.countdown_active else 0,
                "extensions_count": self.extensions_count,
                "max_extensions": self.max_extensions,
                "saves_count": self.saves_count,
                "max_session_seconds": self.max_session_seconds,
                "session_limit_remaining_seconds":
                    self._remaining(self.session_ceiling_monotonic) if ceiling_started else None,
                "gui_url": self.gui_url
            })

        elif cmd == 'SAVE':
            # Format: SAVE [filename|none] [is_user: 0|1]
            if self.is_reverting:
                # Saves made while the revert reloads services are side effects, not session changes
                return json.dumps({"status": "reverting", "reverting": True})

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

            if not self.countdown_active:
                # Fail closed: any recorded change arms the countdown, whoever made it
                self._arm_countdown()
                if not is_user:
                    syslog.syslog(syslog.LOG_NOTICE,
                                  "commit-watchdog: non-interactive save armed the countdown")
            elif is_user:
                self._arm_countdown()
            # else: background save while counting; never postpones the revert

            self.update_marker()
            return json.dumps({
                "status": "ok",
                "countdown_seconds": self.countdown_seconds,
                "saves_count": self.saves_count,
                "countdown_active": self.countdown_active,
                "remaining_seconds": self._remaining(self.deadline_monotonic)
            })

        elif cmd == 'EXTEND':
            if self.is_reverting:
                return json.dumps({"status": "failed", "error": "reverting"})
            if not self.countdown_active:
                return json.dumps({"status": "failed", "error": "not_counting"})
            if self.extensions_count >= self.max_extensions:
                return json.dumps({"status": "failed", "error": "max_extensions_reached"})
            if self.deadline_monotonic >= self.session_ceiling_monotonic - 0.5:
                return json.dumps({"status": "failed", "error": "session_limit_reached"})
            self.deadline_monotonic = min(self.deadline_monotonic + self.extension_seconds,
                                          self.session_ceiling_monotonic)
            self.extensions_count += 1
            self.update_marker()
            return json.dumps({
                "status": "ok",
                "extensions_count": self.extensions_count,
                "max_extensions": self.max_extensions,
                "extension_seconds": self.extension_seconds,
                "remaining_seconds": self._remaining(self.deadline_monotonic)
            })

        elif cmd == 'CONFIRM':
            if revert_lock_held(self.revert_lock_file):
                # Another process (one-shot fallback) is restoring right now; never pull the snapshot from under it
                return json.dumps({"status": "failed", "error": "revert_in_progress"})
            if self.is_reverting:
                syslog.syslog(syslog.LOG_WARNING,
                              f"commit-session: pending rollback cancelled by confirm after "
                              f"{self.revert_attempts} failed attempt(s)")
            self.tag_revisions_in_history(self.revisions, 'Confirmed')
            self.cleanup_session()
            self.running = False
            return json.dumps({"status": "ok", "message": "confirmed"})

        elif cmd == 'REVERT':
            reason = parts[1] if len(parts) > 1 else 'manual'
            if not self.is_reverting:
                self.set_reverting_marker(reason)
            # An explicit request skips any backoff wait
            self.next_revert_monotonic = self._mono()
            return json.dumps({
                "status": "ok",
                "message": "reverting",
                "gui_url": self.gui_url
            })

        return json.dumps({"status": "failed", "error": "unknown_command"})

    # ------------------------------------------------------------ cleanup

    def stop_supervisor(self):
        # Stop supervisor if running so daemon -r doesn't respawn after intentional exit
        sup_pid = read_pid(self.supervisor_pid_file)
        if sup_pid is not None:
            try:
                os.kill(sup_pid, signal.SIGTERM)
            except Exception:
                pass

    def cleanup_session(self):
        self.stop_supervisor()
        for path in [self.marker_file, self.snapshot_file, self.socket_file,
                     self.pid_file, self.supervisor_pid_file]:
            if os.path.exists(path):
                try:
                    os.unlink(path)
                except OSError:
                    pass

    # ------------------------------------------------------------- revert

    def _write_reverted_history(self, snapshot_bytes, description):
        """Best effort: record the restored config as a Reverted revision. Never fails the revert."""
        revert_time = f"{self._wall():.4f}"
        backup_name = f"config-{revert_time}.xml"
        try:
            os.makedirs(self.backup_dir, mode=0o750, exist_ok=True)
            backup_file = os.path.join(self.backup_dir, backup_name)
            try:
                root = ET.fromstring(snapshot_bytes)
                rev = root.find('revision')
                if rev is None:
                    rev = ET.SubElement(root, 'revision')
                for tag, value in (('username', self.username), ('time', revert_time),
                                   ('description', description), ('session_tag', 'Reverted')):
                    el = rev.find(tag)
                    if el is None:
                        el = ET.SubElement(rev, tag)
                    el.text = value
                ET.ElementTree(root).write(backup_file, encoding='utf-8', xml_declaration=True)
            except Exception as e:
                syslog.syslog(syslog.LOG_ERR, f"commit-session: failed to write XML revision tag: {e}")
                with open(backup_file, 'wb') as f:
                    f.write(snapshot_bytes)
            os.chmod(backup_file, 0o640)
        except Exception as e:
            syslog.syslog(syslog.LOG_ERR, f"commit-session: failed to record reverted revision: {e}")

        all_revisions = list(self.revisions)
        if backup_name not in all_revisions:
            all_revisions.append(backup_name)
        self.tag_revisions_in_history(all_revisions, 'Reverted')
        return backup_name

    def _write_notice(self, reason, backup_name):
        """Best effort: login notice for the next admin. Never fails the revert."""
        try:
            notice_data = {
                'reverted_at': int(self._wall()),
                'reverted_at_iso': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime(self._wall())),
                'username': self.username,
                'source': self.source,
                'reason': reason,
                'backup_id': backup_name,
                'gui_url': self.gui_url
            }
            tmp_notice = self.notice_file + '.tmp'
            with open(tmp_notice, 'w', encoding='utf-8') as f:
                json.dump(notice_data, f, indent=2)
                f.flush()
                os.fsync(f.fileno())
            os.chmod(tmp_notice, 0o640)
            os.replace(tmp_notice, self.notice_file)
        except Exception as e:
            syslog.syslog(syslog.LOG_ERR, f"commit-session: failed to write revert notice: {e}")

    def _schedule_retry(self, error):
        self.last_revert_error = error
        self.next_revert_monotonic = self._mono() + revert_backoff(self.revert_attempts)
        self.update_marker()
        syslog.syslog(
            syslog.LOG_CRIT,
            f"commit-session: revert attempt {self.revert_attempts} failed ({error}); "
            f"retrying in {int(revert_backoff(self.revert_attempts))}s"
        )

    def execute_revert(self, reason='countdown_expired'):
        """
        Restore the snapshot and reload services.
        Returns True only when the box runs the restored config again. On False, last_revert_outcome is
        'busy' (another process is reverting), 'failed' (retry scheduled) or 'rebooting'.
        """
        if not self.is_reverting or self.revert_reason != reason:
            self.set_reverting_marker(reason)

        lock_fd = acquire_revert_lock(self.revert_lock_file)
        if lock_fd is None:
            self.last_revert_outcome = 'busy'
            self.next_revert_monotonic = self._mono() + RETRY_BASE_SECONDS
            syslog.syslog(syslog.LOG_NOTICE, "commit-session: another process is reverting; waiting")
            return False

        tmp_config = self.config_file + '.revert_tmp'
        try:
            self.revert_attempts += 1
            self.last_revert_attempt_wall = self._wall()
            self.update_marker()
            syslog.syslog(
                syslog.LOG_WARNING,
                f"commit-session: configuration rollback triggered for user {self.username} via {self.source} "
                f"(reason: {reason}, attempt {self.revert_attempts})"
            )

            if not os.path.exists(self.snapshot_file):
                self.last_revert_outcome = 'failed'
                self._schedule_retry('snapshot missing')
                return False

            # 1. Atomically restore snapshot to config.xml. Until os.replace succeeds nothing has changed,
            #    so every failure up to here leaves a consistent box that can simply be retried.
            try:
                with open(self.snapshot_file, 'rb') as f:
                    snapshot_bytes = f.read()
                if not snapshot_bytes:
                    raise ValueError('snapshot is empty')
                with open(tmp_config, 'wb') as f:
                    f.write(snapshot_bytes)
                    f.flush()
                    os.fsync(f.fileno())
                os.chmod(tmp_config, 0o640)
                os.replace(tmp_config, self.config_file)
            except Exception as e:
                if os.path.exists(tmp_config):
                    try:
                        os.unlink(tmp_config)
                    except OSError:
                        pass
                self.last_revert_outcome = 'failed'
                self._schedule_retry(f"restore failed: {e}")
                return False

            # 2./3. History and notice are best effort; they must never block getting the old config back
            backup_name = self._write_reverted_history(
                snapshot_bytes, f"Reverted to snapshot from protected session ({reason})"
            )
            self._write_notice(reason, backup_name)

            # 4. Reload all services (DEVNULL and close_fds=True avoid daemon pipe inheritance deadlock)
            syslog.syslog(syslog.LOG_NOTICE, f"commit-session: executing {self.reload_script} following revert")
            reload_ok = False
            if os.path.exists(self.reload_script):
                try:
                    res = subprocess.run(
                        [self.reload_script],
                        stdout=subprocess.DEVNULL,
                        stderr=subprocess.DEVNULL,
                        close_fds=True,
                        timeout=180
                    )
                    if res.returncode == 0:
                        reload_ok = True
                    else:
                        syslog.syslog(
                            syslog.LOG_ERR,
                            f"commit-session: {self.reload_script} exited with code {res.returncode}"
                        )
                except Exception as e:
                    syslog.syslog(syslog.LOG_ERR, f"commit-session: failed to run reload script: {e}")

            # 5. Reload failed: config.xml is already restored, so reboot. Marker and snapshot stay so the
            #    early boot hook finishes the job; stop retrying only once a reboot command succeeds.
            if not reload_ok:
                syslog.syslog(syslog.LOG_CRIT, "commit-session: reload-all failed; rebooting system now!")
                for cmd in ([self.shutdown_bin, '-r', 'now'], [self.reboot_bin]):
                    if os.path.exists(cmd[0]):
                        try:
                            res = subprocess.run(cmd, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                                                 close_fds=True)
                        except Exception as e:
                            syslog.syslog(syslog.LOG_ERR, f"commit-session: failed to run {cmd[0]}: {e}")
                            continue
                        if res.returncode == 0:
                            self.last_revert_outcome = 'rebooting'
                            self.reboot_initiated = True
                            self.last_revert_error = 'service reload failed; rebooting'
                            self.update_marker()
                            self.stop_supervisor()
                            return False
                        syslog.syslog(syslog.LOG_ERR,
                                      f"commit-session: {cmd[0]} exited with code {res.returncode}")
                self.last_revert_outcome = 'failed'
                self._schedule_retry('service reload failed; reboot commands failed')
                return False

            # 6. Success: clean up marker and snapshot only now
            self.last_revert_outcome = 'ok'
            self.cleanup_session()
            return True

        except Exception as e:
            self.last_revert_outcome = 'failed'
            self._schedule_retry(f"unexpected error: {e}")
            return False
        finally:
            release_revert_lock(lock_fd)

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

        tmp_config = self.config_file + '.boot_tmp'
        try:
            with open(self.snapshot_file, 'rb') as f:
                snapshot_bytes = f.read()
            if not snapshot_bytes:
                raise ValueError('snapshot is empty')

            # Atomically restore snapshot to config.xml
            with open(tmp_config, 'wb') as f:
                f.write(snapshot_bytes)
                f.flush()
                os.fsync(f.fileno())
            os.chmod(tmp_config, 0o640)
            os.replace(tmp_config, self.config_file)
        except Exception as e:
            syslog.syslog(syslog.LOG_CRIT, f"commit-session: critical error during boot revert: {e}")
            if os.path.exists(tmp_config):
                try:
                    os.unlink(tmp_config)
                except OSError:
                    pass
            # Do NOT cleanup marker and snapshot on failure!
            return False

        backup_name = self._write_reverted_history(
            snapshot_bytes, f"Reverted unconfirmed protected session at boot ({user} via {src})"
        )
        self._write_notice('unconfirmed_at_boot', backup_name)

        # Clean up marker and snapshot ONLY on restore success
        self.cleanup_session()
        return True

    # --------------------------------------------------------------- loop

    def _finish_revert_attempt(self, ok):
        if ok or self.reboot_initiated:
            self.running = False

    def step(self):
        """One pass of the time-based state machine. Called by run() after socket handling."""
        now = self._mono()

        if self.is_reverting:
            if not self.reboot_initiated and now >= self.next_revert_monotonic:
                self._finish_revert_attempt(self.execute_revert(self.revert_reason))
            return

        if self.countdown_active:
            if now >= self.deadline_monotonic:
                syslog.syslog(syslog.LOG_WARNING, "commit-watchdog: countdown reached zero, reverting!")
                self._finish_revert_attempt(self.execute_revert('countdown_expired'))
            return

        if self.revisions:
            # Should not happen (saves arm the countdown); fail closed if it does
            self._arm_countdown()
            self.update_marker()
            return

        if now >= self.idle_deadline_monotonic:
            syslog.syslog(
                syslog.LOG_NOTICE,
                "commit-session: session idle timeout (60 minutes with no saves), session closed without changes."
            )
            self.cleanup_session()
            self.running = False

    def run(self):
        syslog.openlog('commit-watchdog', syslog.LOG_PID, syslog.LOG_AUTH)
        syslog.syslog(syslog.LOG_NOTICE, f"commit-watchdog: started for session {self.session_id}")

        self.setup_socket()

        def sig_handler(signum, frame):
            # Only stops the loop between steps; a revert in progress always runs to completion
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

            if not self.running:
                break
            self.step()

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


def spawn_supervised_watchdog(supervisor_pid_file=SUPERVISOR_PID_FILE, pid_file=PID_FILE):
    python_bin = sys.executable or '/usr/local/bin/python3'
    script_path = os.path.abspath(__file__)
    daemon_bin = '/usr/sbin/daemon'
    if os.path.exists(daemon_bin):
        subprocess.run([
            daemon_bin, '-f', '-r',
            '-P', supervisor_pid_file,
            '-p', pid_file,
            python_bin, script_path
        ])
    else:
        subprocess.Popen([python_bin, script_path])


def check_watchdog_supervisor(marker_file=MARKER_FILE, socket_file=SOCKET_FILE, pid_file=PID_FILE,
                              supervisor_pid_file=SUPERVISOR_PID_FILE, revert_lock_file=REVERT_LOCK_FILE,
                              spawn=None, pid_alive=None):
    """
    Supervisor check intended to run periodically (1-minute cron).
    Never interferes with a revert in progress, and judges liveness by process rather than by
    socket response, because a watchdog running rc.reload_all cannot answer the socket.
    Returns 0 always; the action taken is logged.
    """
    spawn = spawn or spawn_supervised_watchdog
    pid_alive = pid_alive or watchdog_pid_alive

    if revert_lock_held(revert_lock_file):
        return 0

    if not os.path.exists(marker_file):
        # No session active. If orphan supervisor or socket exists, clean up.
        sup_pid = read_pid(supervisor_pid_file)
        if sup_pid is not None:
            try:
                os.kill(sup_pid, signal.SIGTERM)
            except Exception:
                pass
        for path in (supervisor_pid_file, pid_file, socket_file):
            if os.path.exists(path):
                try:
                    os.unlink(path)
                except OSError:
                    pass
        return 0

    # Marker exists: a live watchdog process is enough, whatever state it is in
    if pid_alive(pid_file):
        return 0

    if os.path.exists(socket_file):
        try:
            s = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
            s.settimeout(2.0)
            s.connect(socket_file)
            s.sendall(b"STATUS\n")
            data = s.recv(1024).decode('utf-8')
            s.close()
            if json.loads(data).get('status') in ('ok', 'reverting'):
                return 0
        except Exception:
            pass

    syslog.openlog('commit-watchdog', syslog.LOG_PID, syslog.LOG_AUTH)
    syslog.syslog(syslog.LOG_WARNING,
                  "commit-watchdog: supervisor check detected dead watchdog with active session; respawning!")
    for pfile in (supervisor_pid_file, pid_file):
        pid = read_pid(pfile)
        if pid is not None:
            try:
                os.kill(pid, signal.SIGTERM)
            except Exception:
                pass
        if os.path.exists(pfile):
            try:
                os.unlink(pfile)
            except OSError:
                pass
    if os.path.exists(socket_file):
        try:
            os.unlink(socket_file)
        except OSError:
            pass
    spawn(supervisor_pid_file=supervisor_pid_file, pid_file=pid_file)
    return 0


if __name__ == '__main__':
    if len(sys.argv) > 1 and sys.argv[1] == '--boot-revert':
        watchdog = CommitWatchdog()
        ok = watchdog.execute_boot_revert()
        sys.exit(0 if ok else 1)
    elif len(sys.argv) > 1 and sys.argv[1] == '--revert':
        # One-shot fallback used when the watchdog is unreachable. On failure the marker stays in the
        # reverting state with its attempt count; the cron check then respawns a watchdog that retries.
        reason = sys.argv[2] if len(sys.argv) > 2 else 'manual_fallback'
        watchdog = CommitWatchdog()
        ok = watchdog.execute_revert(reason=reason)
        sys.exit(0 if ok else 1)
    elif len(sys.argv) > 1 and sys.argv[1] == '--check':
        sys.exit(check_watchdog_supervisor())
    else:
        watchdog = CommitWatchdog()
        watchdog.run()
