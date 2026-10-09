<?php

/*
 * Copyright (C) 2026 Konstantinos Spartalis
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * 1. Redistributions of source code must retain the above copyright notice,
 *    this list of conditions and the following disclaimer.
 *
 * 2. Redistributions in binary form must reproduce the above copyright
 *    notice, this list of conditions and the following disclaimer in the
 *    documentation and/or other materials provided with the distribution.
 *
 * THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
 * INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
 * AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 * AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
 * OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 */

namespace OPNsense\Core;

use OPNsense\Core\AppConfig;
use OPNsense\Core\Backend;
use OPNsense\Core\Config;
use OPNsense\Core\File;
use OPNsense\Core\Shell;
use OPNsense\Core\Singleton;

/**
 * Class CommitSession
 * Manages commit-confirmed protected change sessions and automatic rollback.
 * @package OPNsense\Core
 */
class CommitSession extends Singleton
{
    public const SNAPSHOT_DIR = '/conf/commit_rollback';
    public const SNAPSHOT_FILE = '/conf/commit_rollback/snapshot.xml';
    public const MARKER_FILE = '/conf/commit_rollback_pending.json';
    public const NOTICE_FILE = '/conf/commit_rollback_notice.json';
    public const SOCKET_FILE = '/var/run/commit_watchdog.sock';
    public const PID_FILE = '/var/run/commit_watchdog.pid';
    public const RELOAD_SCRIPT = '/usr/local/etc/rc.reload_all';
    public const WATCHDOG_SCRIPT = '/usr/local/opnsense/scripts/system/commit_watchdog.py';

    /**
     * Check if a protected change session is currently active
     * @return bool
     */
    public function isActive(): bool
    {
        return file_exists(self::MARKER_FILE);
    }

    /**
     * Retrieve configuration settings for rollback
     * @return array
     */
    public function getSettings(): array
    {
        $cnf = Config::getInstance()->object();
        $countdown = 3;
        $extension = 5;
        $maxExtensions = 6;

        if (isset($cnf->system->commit_rollback)) {
            $cr = $cnf->system->commit_rollback;
            if (!empty($cr->countdown)) {
                $countdown = max(1, min(30, (int)$cr->countdown));
            }
            if (!empty($cr->extension)) {
                $extension = max(1, min(30, (int)$cr->extension));
            }
            if (isset($cr->max_extensions) && (string)$cr->max_extensions !== '') {
                $maxExtensions = max(0, min(60, (int)$cr->max_extensions));
            }
        }

        return [
            'countdown' => $countdown,
            'extension' => $extension,
            'max_extensions' => $maxExtensions,
        ];
    }

    /**
     * Determine GUI reconnection URL from configuration
     * @param \SimpleXMLElement|null $xml
     * @return string
     */
    public function getGuiUrl(?\SimpleXMLElement $xml = null): string
    {
        if ($xml === null) {
            $xml = Config::getInstance()->object();
        }

        $protocol = 'https';
        if (isset($xml->system->webgui->protocol) && !empty((string)$xml->system->webgui->protocol)) {
            $protocol = (string)$xml->system->webgui->protocol;
        }

        $port = '';
        if (isset($xml->system->webgui->port) && !empty((string)$xml->system->webgui->port)) {
            $port = ':' . (string)$xml->system->webgui->port;
        }

        $host = '';
        if (isset($xml->interfaces->lan->ipaddr) && !empty((string)$xml->interfaces->lan->ipaddr)) {
            $host = (string)$xml->interfaces->lan->ipaddr;
        } elseif (!empty($_SERVER['HTTP_HOST'])) {
            $host = explode(':', $_SERVER['HTTP_HOST'])[0];
        } elseif (!empty($_SERVER['SERVER_ADDR'])) {
            $host = $_SERVER['SERVER_ADDR'];
        } else {
            $host = '127.0.0.1';
        }

        return sprintf('%s://%s%s', $protocol, $host, $port);
    }

    /**
     * Send command to the detached watchdog via UNIX socket
     * @param string $command
     * @return array|null
     */
    private function sendWatchdogCommand(string $command): ?array
    {
        if (!file_exists(self::SOCKET_FILE)) {
            return null;
        }

        $socket = @fsockopen('unix://' . self::SOCKET_FILE, -1, $errno, $errstr, 2);
        if (!$socket) {
            return null;
        }

        stream_set_timeout($socket, 2);
        fwrite($socket, trim($command) . "\n");
        $response = trim(fgets($socket));
        fclose($socket);

        if (!empty($response)) {
            $decoded = json_decode($response, true);
            if (is_array($decoded)) {
                return $decoded;
            }
            return ['status' => $response];
        }

        return null;
    }

    /**
     * Get current session state
     * @return array
     */
    public function getState(): array
    {
        if (!$this->isActive()) {
            return [
                'active' => false,
                'has_notice' => $this->hasRevertNotice(),
            ];
        }

        $data = @json_decode(file_get_contents(self::MARKER_FILE), true);
        if (!is_array($data)) {
            return [
                'active' => true,
                'error' => 'corrupted marker file',
                'has_notice' => $this->hasRevertNotice(),
            ];
        }

        // Fallback check: 60-minute idle session timeout with no saves
        if (empty($data['countdown_active']) && !empty($data['created_at'])) {
            if ((time() - (int)$data['created_at']) >= 3600) {
                $this->stopWatchdogProcess();
                @unlink(self::MARKER_FILE);
                @unlink(self::SNAPSHOT_FILE);
                @unlink(self::SOCKET_FILE);
                @unlink(self::PID_FILE);
                openlog('audit', LOG_ODELAY, LOG_AUTH);
                syslog(LOG_NOTICE, 'commit-session: session closed due to 60-minute idle timeout with no changes.');
                return [
                    'active' => false,
                    'has_notice' => $this->hasRevertNotice(),
                ];
            }
        }

        // Try getting live status from the watchdog process
        $watchdogStatus = $this->sendWatchdogCommand('STATUS');
        if (!empty($watchdogStatus) && isset($watchdogStatus['remaining_seconds'])) {
            $data['remaining_seconds'] = max(0, (int)$watchdogStatus['remaining_seconds']);
            $data['countdown_active'] = !empty($watchdogStatus['countdown_active']);
            $data['extensions_count'] = (int)($watchdogStatus['extensions_count'] ?? $data['extensions_count'] ?? 0);
        } else {
            // Fallback calculation based on epoch if watchdog socket query didn't respond
            if (!empty($data['countdown_active']) && !empty($data['expire_epoch'])) {
                $data['remaining_seconds'] = max(0, (int)round($data['expire_epoch'] - time()));
            } else {
                $data['remaining_seconds'] = 0;
            }
        }

        $data['active'] = true;
        $data['has_notice'] = $this->hasRevertNotice();
        return $data;
    }

    /**
     * Start a protected change session
     * @param string $username
     * @param string $source
     * @return array
     */
    public function start(string $username, string $source = 'gui'): array
    {
        if ($this->isActive()) {
            return [
                'status' => 'failed',
                'message' => gettext('A protected change session is already active.'),
            ];
        }

        $settings = $this->getSettings();
        $configFile = (new AppConfig())->application->configDir . '/config.xml';

        if (!file_exists($configFile)) {
            return [
                'status' => 'failed',
                'message' => gettext('Current configuration file does not exist.'),
            ];
        }

        // Create dedicated snapshot directory outside /conf/backup/
        if (!file_exists(self::SNAPSHOT_DIR)) {
            @mkdir(self::SNAPSHOT_DIR, 0750, true);
        }

        // Copy snapshot atomically
        if (!copy($configFile, self::SNAPSHOT_FILE)) {
            return [
                'status' => 'failed',
                'message' => gettext('Failed to create configuration snapshot.'),
            ];
        }
        chmod(self::SNAPSHOT_FILE, 0640);

        // Snapshot simplexml for GUI reconnect details
        $snapshotXml = @simplexml_load_file(self::SNAPSHOT_FILE);
        $guiUrl = $this->getGuiUrl($snapshotXml ?: null);

        $sessionId = bin2hex(random_bytes(16));
        $now = time();

        $markerData = [
            'session_id' => $sessionId,
            'username' => $username,
            'source' => $source,
            'created_at' => $now,
            'created_at_iso' => date('c', $now),
            'snapshot_file' => self::SNAPSHOT_FILE,
            'countdown_seconds' => $settings['countdown'] * 60,
            'extension_seconds' => $settings['extension'] * 60,
            'max_extensions' => $settings['max_extensions'],
            'extensions_count' => 0,
            'saves_count' => 0,
            'gui_url' => $guiUrl,
            'status' => 'pending',
            'countdown_active' => false,
            'expire_epoch' => null,
            'revisions' => [],
        ];

        File::file_put_contents(self::MARKER_FILE, json_encode($markerData, JSON_PRETTY_PRINT), 0640);

        // Spawn detached watchdog daemon using /usr/sbin/daemon
        $pythonBin = '/usr/local/bin/python3';
        if (file_exists('/usr/sbin/daemon') && file_exists(self::WATCHDOG_SCRIPT)) {
            Shell::shell_safe(
                '/usr/sbin/daemon -f -p %s %s %s',
                [self::PID_FILE, $pythonBin, self::WATCHDOG_SCRIPT]
            );
        }
        if (!file_exists(self::SOCKET_FILE)) {
            (new Backend())->configdRun('commit_session start_watchdog');
        }

        // System log
        openlog('audit', LOG_ODELAY, LOG_AUTH);
        syslog(
            LOG_NOTICE,
            sprintf(
                'commit-session: session started by %s via %s (countdown: %dm, max extensions: %d)',
                $username,
                $source,
                $settings['countdown'],
                $settings['max_extensions']
            )
        );

        return [
            'status' => 'ok',
            'session_id' => $sessionId,
            'settings' => $settings,
            'gui_url' => $guiUrl,
        ];
    }

    /**
     * Hook called on every configuration save while a session is active
     * @param array|null $revision
     */
    public function onConfigSave(?array &$revision): void
    {
        if (!$this->isActive()) {
            return;
        }

        $markerContent = @file_get_contents(self::MARKER_FILE);
        $data = @json_decode($markerContent, true);
        if (!is_array($data)) {
            return;
        }

        $countdownSeconds = (int)($data['countdown_seconds'] ?? 180);
        $now = time();

        $data['saves_count'] = ($data['saves_count'] ?? 0) + 1;
        $data['countdown_active'] = true;
        $data['expire_epoch'] = $now + $countdownSeconds;
        $data['last_save_time'] = $now;

        // Track revision timestamp
        if (!empty($revision['time'])) {
            $data['revisions'][] = (string)$revision['time'];
        }

        // Tag revision
        if (is_array($revision)) {
            $revision['session_tag'] = 'Pending';
            $revision['session_id'] = $data['session_id'] ?? '';
        }

        File::file_put_contents(self::MARKER_FILE, json_encode($data, JSON_PRETTY_PRINT), 0640);

        // Signal the watchdog daemon to restart monotonic countdown
        $this->sendWatchdogCommand('SAVE');

        $username = $revision['username'] ?? (!empty($_SESSION['Username']) ? $_SESSION['Username'] : ($data['username'] ?? 'unknown'));
        $source = 'console';
        if (!empty($_SERVER['REQUEST_URI'])) {
            if (str_starts_with($_SERVER['REQUEST_URI'], '/api/') || !empty($_SERVER['HTTP_X_CLIENT_TYPE'])) {
                $source = 'api';
            } else {
                $source = 'gui';
            }
        }

        openlog('audit', LOG_ODELAY, LOG_AUTH);
        syslog(
            LOG_NOTICE,
            sprintf(
                'commit-session: configuration saved by %s via %s; countdown restarted (%ds)',
                $username,
                $source,
                $countdownSeconds
            )
        );
    }

    /**
     * Extend countdown
     * @param string $username
     * @param string $source
     * @return array
     */
    public function extend(string $username, string $source = 'gui'): array
    {
        if (!$this->isActive()) {
            return [
                'status' => 'failed',
                'message' => gettext('No active protected change session.'),
            ];
        }

        $data = @json_decode(file_get_contents(self::MARKER_FILE), true);
        if (!is_array($data)) {
            return [
                'status' => 'failed',
                'message' => gettext('Invalid session marker.'),
            ];
        }

        if (empty($data['countdown_active'])) {
            return [
                'status' => 'failed',
                'message' => gettext('No countdown is currently running (no changes saved yet).'),
            ];
        }

        $extensionsCount = (int)($data['extensions_count'] ?? 0);
        $maxExtensions = (int)($data['max_extensions'] ?? 6);

        if ($extensionsCount >= $maxExtensions) {
            return [
                'status' => 'failed',
                'message' => sprintf(gettext('Maximum number of extensions (%d) reached.'), $maxExtensions),
            ];
        }

        $extensionSeconds = (int)($data['extension_seconds'] ?? 300);

        // Update watchdog
        $resp = $this->sendWatchdogCommand('EXTEND');
        if ($resp && isset($resp['status']) && $resp['status'] === 'ok') {
            $data['extensions_count'] = (int)($resp['extensions_count'] ?? ($extensionsCount + 1));
            $data['expire_epoch'] = ($data['expire_epoch'] ?? time()) + $extensionSeconds;
        } else {
            $data['extensions_count'] = $extensionsCount + 1;
            $data['expire_epoch'] = ($data['expire_epoch'] ?? time()) + $extensionSeconds;
        }

        File::file_put_contents(self::MARKER_FILE, json_encode($data, JSON_PRETTY_PRINT), 0640);

        openlog('audit', LOG_ODELAY, LOG_AUTH);
        syslog(
            LOG_NOTICE,
            sprintf(
                'commit-session: countdown extended by %ds by %s via %s (extension %d/%d)',
                $extensionSeconds,
                $username,
                $source,
                $data['extensions_count'],
                $maxExtensions
            )
        );

        return [
            'status' => 'ok',
            'extensions_count' => $data['extensions_count'],
            'max_extensions' => $maxExtensions,
            'extension_seconds' => $extensionSeconds,
        ];
    }

    /**
     * Confirm changes and end protected change session
     * @param string $username
     * @param string $source
     * @return array
     */
    public function confirm(string $username, string $source = 'gui'): array
    {
        if (!$this->isActive()) {
            return [
                'status' => 'failed',
                'message' => gettext('No active protected change session to confirm.'),
            ];
        }

        $data = @json_decode(file_get_contents(self::MARKER_FILE), true);
        $revisions = is_array($data) ? ($data['revisions'] ?? []) : [];

        // Notify watchdog to exit cleanly
        $this->sendWatchdogCommand('CONFIRM');

        // Kill watchdog PID if still running
        $this->stopWatchdogProcess();

        // Update session revision tags in backup history to "Confirmed"
        $this->tagRevisionsInHistory($revisions, 'Confirmed');

        // Delete marker and snapshot
        @unlink(self::MARKER_FILE);
        @unlink(self::SNAPSHOT_FILE);
        @unlink(self::SOCKET_FILE);
        @unlink(self::PID_FILE);

        openlog('audit', LOG_ODELAY, LOG_AUTH);
        syslog(LOG_NOTICE, sprintf('commit-session: session confirmed by %s via %s', $username, $source));

        return [
            'status' => 'ok',
            'message' => gettext('Session confirmed successfully.'),
        ];
    }

    /**
     * Revert configuration to snapshot and reload services
     * @param string $username
     * @param string $source
     * @param string $reason
     * @return array
     */
    public function revert(string $username, string $source = 'gui', string $reason = 'manual'): array
    {
        if (!$this->isActive() && !file_exists(self::SNAPSHOT_FILE)) {
            return [
                'status' => 'failed',
                'message' => gettext('No snapshot available to revert to.'),
            ];
        }

        $data = @json_decode(@file_get_contents(self::MARKER_FILE), true);
        $revisions = is_array($data) ? ($data['revisions'] ?? []) : [];
        $guiUrl = $data['gui_url'] ?? $this->getGuiUrl();

        // Instruct the detached watchdog to execute the revert in its detached process
        $resp = $this->sendWatchdogCommand('REVERT');
        if ($resp && isset($resp['status']) && $resp['status'] === 'ok') {
            openlog('audit', LOG_ODELAY, LOG_AUTH);
            syslog(
                LOG_WARNING,
                sprintf('commit-session: configuration revert initiated by %s via %s (reason: %s)', $username, $source, $reason)
            );
            return [
                'status' => 'ok',
                'message' => gettext('Configuration revert initiated by watchdog daemon.'),
                'gui_url' => $resp['gui_url'] ?? $guiUrl,
            ];
        }

        // Fallback: If watchdog was not running, stop watchdog process and execute direct revert in PHP
        $this->stopWatchdogProcess();

        // Restore snapshot to config.xml atomically
        $app = new AppConfig();
        $targetFile = $app->application->configDir . '/config.xml';
        $tmpFile = $app->application->configDir . '/config.xml.revert_tmp';

        if (!file_exists(self::SNAPSHOT_FILE)) {
            return [
                'status' => 'failed',
                'message' => gettext('Snapshot file missing.'),
            ];
        }

        $snapshotContent = file_get_contents(self::SNAPSHOT_FILE);
        if (empty($snapshotContent)) {
            return [
                'status' => 'failed',
                'message' => gettext('Snapshot file is empty.'),
            ];
        }

        // Atomically write temp file and rename
        File::file_put_contents($tmpFile, $snapshotContent, 0640, 0, $app->globals->owner);
        if (!rename($tmpFile, $targetFile)) {
            @unlink($tmpFile);
            return [
                'status' => 'failed',
                'message' => gettext('Atomic rename of restored configuration failed.'),
            ];
        }

        // Record a history revision marked as Reverted
        $revertTime = sprintf('%0.2f', microtime(true));
        $backupDir = $app->application->configDir . '/backup/';
        if (!file_exists($backupDir)) {
            @mkdir($backupDir, 0750, true);
        }
        $backupFile = $backupDir . 'config-' . $revertTime . '.xml';

        // Add Reverted revision tag to the restored config backup
        $revertedXml = @simplexml_load_string($snapshotContent);
        if ($revertedXml) {
            if (!isset($revertedXml->revision)) {
                $revNode = $revertedXml->addChild('revision');
            } else {
                $revNode = $revertedXml->revision;
            }
            $revNode->username = $username;
            $revNode->time = $revertTime;
            $revNode->description = sprintf('Reverted to snapshot from protected session (%s)', $reason);
            $revNode->session_tag = 'Reverted';
            File::file_put_contents($backupFile, $revertedXml->asXML(), 0640, 0, $app->globals->owner);
        } else {
            File::file_put_contents($backupFile, $snapshotContent, 0640, 0, $app->globals->owner);
        }

        // Tag previous session revisions as Reverted
        $this->tagRevisionsInHistory($revisions, 'Reverted');

        // Create login notice file
        $noticeData = [
            'reverted_at' => time(),
            'reverted_at_iso' => date('c'),
            'username' => $username,
            'source' => $source,
            'reason' => $reason,
            'backup_id' => 'config-' . $revertTime . '.xml',
            'gui_url' => $guiUrl,
        ];
        File::file_put_contents(self::NOTICE_FILE, json_encode($noticeData, JSON_PRETTY_PRINT), 0640);

        // Delete marker and snapshot
        @unlink(self::MARKER_FILE);
        @unlink(self::SNAPSHOT_FILE);
        @unlink(self::SOCKET_FILE);
        @unlink(self::PID_FILE);

        openlog('audit', LOG_ODELAY, LOG_AUTH);
        syslog(
            LOG_WARNING,
            sprintf('commit-session: configuration reverted to snapshot by %s via %s (reason: %s)', $username, $source, $reason)
        );

        // Reload all services or reboot if reload fails
        $reloadSuccess = false;
        if (file_exists(self::RELOAD_SCRIPT)) {
            $exitCode = Shell::run_safe(self::RELOAD_SCRIPT);
            $reloadSuccess = ($exitCode === 0);
        }

        if (!$reloadSuccess) {
            syslog(LOG_ERR, 'commit-session: service reload failed following configuration revert; initiating reboot!');
            if (file_exists('/sbin/shutdown')) {
                Shell::shell_safe('/sbin/shutdown -r now');
            } elseif (file_exists('/sbin/reboot')) {
                Shell::shell_safe('/sbin/reboot');
            }
        }

        return [
            'status' => 'ok',
            'message' => gettext('Configuration successfully reverted to snapshot.'),
            'gui_url' => $guiUrl,
        ];
    }

    /**
     * Stop the watchdog process if running
     */
    private function stopWatchdogProcess(): void
    {
        if (file_exists(self::PID_FILE)) {
            $pid = trim(@file_get_contents(self::PID_FILE));
            if (is_numeric($pid) && (int)$pid > 0) {
                if (function_exists('posix_kill') && defined('SIGTERM')) {
                    @posix_kill((int)$pid, SIGTERM);
                } else {
                    Shell::shell_safe('/bin/kill -TERM %s', [$pid]);
                }
            }
            @unlink(self::PID_FILE);
        }
        @unlink(self::SOCKET_FILE);
    }

    /**
     * Update session tag in backup files
     * @param array $revisions
     * @param string $tag
     */
    private function tagRevisionsInHistory(array $revisions, string $tag): void
    {
        $backupDir = (new AppConfig())->application->configDir . '/backup/';
        foreach ($revisions as $revTime) {
            $revTimeClean = preg_replace('/[^0-9.]/', '', $revTime);
            $bckFile = $backupDir . 'config-' . $revTimeClean . '.xml';
            if (file_exists($bckFile)) {
                $xml = @simplexml_load_file($bckFile);
                if ($xml && isset($xml->revision)) {
                    $xml->revision->session_tag = $tag;
                    @file_put_contents($bckFile, $xml->asXML());
                }
            }
        }
    }

    /**
     * Unified diff between snapshot and current configuration
     * @return array
     */
    public function getDiff(): array
    {
        $items = [];
        $app = new AppConfig();
        $currentConfig = $app->application->configDir . '/config.xml';

        if (!file_exists(self::SNAPSHOT_FILE) || !file_exists($currentConfig)) {
            return $items;
        }

        $diff = Shell::shell_safe('/usr/bin/diff -u %s %s', [self::SNAPSHOT_FILE, $currentConfig], true);
        if (!empty($diff)) {
            foreach ($diff as $line) {
                $items[] = htmlspecialchars($line, ENT_QUOTES | ENT_HTML401);
            }
        }

        return $items;
    }

    /**
     * Check if a revert notice exists for display after login
     * @return bool
     */
    public function hasRevertNotice(): bool
    {
        return file_exists(self::NOTICE_FILE);
    }

    /**
     * Get login revert notice data
     * @return array|null
     */
    public function getRevertNotice(): ?array
    {
        if (!$this->hasRevertNotice()) {
            return null;
        }
        $data = @json_decode(file_get_contents(self::NOTICE_FILE), true);
        return is_array($data) ? $data : null;
    }

    /**
     * Dismiss the login revert notice
     * @return bool
     */
    public function dismissRevertNotice(): bool
    {
        if (file_exists(self::NOTICE_FILE)) {
            return @unlink(self::NOTICE_FILE);
        }
        return false;
    }
}
