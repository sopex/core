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
    public const SUPERVISOR_PID_FILE = '/var/run/commit_watchdog_sup.pid';
    public const SIDECAR_TAGS_FILE = '/conf/backup/session_tags.json';
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
     * Determine GUI reconnection URL from snapshot configuration and request context
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
            $portVal = (string)$xml->system->webgui->port;
            if ($portVal !== ($protocol === 'https' ? '443' : '80')) {
                $port = ':' . $portVal;
            }
        }

        // 1. Capture host user actually used to connect
        $host = '';
        if (!empty($_SERVER['HTTP_HOST'])) {
            $hostStr = trim($_SERVER['HTTP_HOST']);
            if (preg_match('/^\[([a-fA-F0-9:]+)\](?::\d+)?$/', $hostStr, $matches)) {
                $host = $matches[1];
            } elseif (preg_match('/^([^:]+)(?::\d+)?$/', $hostStr, $matches)) {
                $host = $matches[1];
            } else {
                $host = $hostStr;
            }
        } elseif (!empty($_SERVER['SERVER_ADDR'])) {
            $host = trim($_SERVER['SERVER_ADDR']);
        }

        // 2. Fallback for console / CLI: use LAN IP only if valid IP (not "dhcp"), otherwise 127.0.0.1
        if (empty($host)) {
            if (isset($xml->interfaces->lan->ipaddr)) {
                $lanIp = (string)$xml->interfaces->lan->ipaddr;
                if (filter_var($lanIp, FILTER_VALIDATE_IP)) {
                    $host = $lanIp;
                }
            }
        }
        if (empty($host)) {
            $host = '127.0.0.1';
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $host = '[' . $host . ']';
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
     * Get current session state (read-only query; watchdog manages timeouts and lifecycle)
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

        $data = @json_decode(@file_get_contents(self::MARKER_FILE), true);
        if (!is_array($data)) {
            return [
                'active' => true,
                'error' => 'corrupted marker file',
                'has_notice' => $this->hasRevertNotice(),
            ];
        }

        // Live status query from the detached watchdog
        $watchdogStatus = $this->sendWatchdogCommand('STATUS');
        if (!empty($watchdogStatus) && isset($watchdogStatus['remaining_seconds'])) {
            $data['remaining_seconds'] = max(0, (int)$watchdogStatus['remaining_seconds']);
            $data['countdown_active'] = !empty($watchdogStatus['countdown_active']);
            $data['extensions_count'] = (int)($watchdogStatus['extensions_count'] ?? $data['extensions_count'] ?? 0);
            $data['saves_count'] = (int)($watchdogStatus['saves_count'] ?? $data['saves_count'] ?? 0);
            $data['watchdog_alive'] = true;
        } else {
            // Watchdog not answering; fallback computation from expire_epoch
            $data['watchdog_alive'] = false;
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

        // Dedicated snapshot directory outside /conf/backup/
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
        $countdownSec = $settings['countdown'] * 60;
        $extensionSec = $settings['extension'] * 60;
        $maxTotalSec = $countdownSec + ($settings['max_extensions'] * $extensionSec);

        $markerData = [
            'session_id' => $sessionId,
            'username' => $username,
            'source' => $source,
            'created_at' => $now,
            'created_at_iso' => date('c', $now),
            'snapshot_file' => self::SNAPSHOT_FILE,
            'countdown_seconds' => $countdownSec,
            'extension_seconds' => $extensionSec,
            'max_extensions' => $settings['max_extensions'],
            'extensions_count' => 0,
            'saves_count' => 0,
            'gui_url' => $guiUrl,
            'status' => 'pending',
            'countdown_active' => false,
            'expire_epoch' => null,
            'absolute_ceiling_epoch' => $now + $maxTotalSec,
            'revisions' => [],
        ];

        File::file_put_contents(self::MARKER_FILE, json_encode($markerData, JSON_PRETTY_PRINT), 0640);

        // Single spawn path: spawn detached watchdog daemon supervised by daemon -r
        $pythonBin = '/usr/local/bin/python3';
        if (file_exists('/usr/sbin/daemon') && file_exists(self::WATCHDOG_SCRIPT)) {
            Shell::shell_safe(
                '/usr/sbin/daemon -f -r -P %s -p %s %s %s',
                [self::SUPERVISOR_PID_FILE, self::PID_FILE, $pythonBin, self::WATCHDOG_SCRIPT]
            );
        }

        // Wait for socket with timeout (up to 3 seconds)
        $socketReady = false;
        for ($i = 0; $i < 30; $i++) {
            usleep(100000); // 100ms
            if (file_exists(self::SOCKET_FILE)) {
                $status = $this->sendWatchdogCommand('STATUS');
                if ($status && isset($status['status']) && $status['status'] === 'ok') {
                    $socketReady = true;
                    break;
                }
            }
        }

        if (!$socketReady) {
            // Watchdog failed to start; abort session immediately to prevent unprotected state
            $this->stopWatchdogProcess();
            @unlink(self::MARKER_FILE);
            @unlink(self::SNAPSHOT_FILE);
            @unlink(self::SOCKET_FILE);

            openlog('audit', LOG_ODELAY, LOG_AUTH);
            syslog(LOG_ERR, 'commit-session: failed to start watchdog daemon within timeout; session aborted.');

            return [
                'status' => 'failed',
                'message' => gettext('Failed to start commit watchdog daemon within timeout.'),
            ];
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
     * Hook called on every configuration save while a session is active.
     * The watchdog daemon is the sole writer of the marker and sidecar index.
     * @param string|null $backupFilename
     * @param array|null $revision
     */
    public function onConfigSave(?string $backupFilename = null, ?array &$revision = null): void
    {
        if (!$this->isActive()) {
            return;
        }

        // Determine if this save was initiated by a real user context
        $isUser = false;
        if (!empty($_SESSION['Username']) || !empty($_SERVER['PHP_AUTH_USER'])) {
            $isUser = true;
        } elseif (php_sapi_name() === 'cli' && function_exists('posix_isatty') && @posix_isatty(STDIN)) {
            $isUser = true;
        } elseif (isset($revision['username'])) {
            $u = $revision['username'];
            if ($u !== '(root)' && $u !== 'system' && $u !== '(system)' && !str_starts_with($u, '(root)@')) {
                $isUser = true;
            }
        }

        // Command format: SAVE [filename] [is_user: 0|1]
        $cmd = 'SAVE';
        if (!empty($backupFilename)) {
            $cmd .= ' ' . $backupFilename . ' ' . ($isUser ? '1' : '0');
        } else {
            $cmd .= ' none ' . ($isUser ? '1' : '0');
        }

        $this->sendWatchdogCommand($cmd);

        $username = $revision['username'] ?? (!empty($_SESSION['Username']) ? $_SESSION['Username'] : 'unknown');
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
                'commit-session: configuration saved by %s via %s (user action: %s)',
                $username,
                $source,
                $isUser ? 'yes' : 'no'
            )
        );
    }

    /**
     * Extend countdown via watchdog
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

        // Send EXTEND to watchdog; watchdog validates and updates marker
        $resp = $this->sendWatchdogCommand('EXTEND');
        if (!empty($resp) && isset($resp['status'])) {
            if ($resp['status'] === 'ok') {
                openlog('audit', LOG_ODELAY, LOG_AUTH);
                syslog(
                    LOG_NOTICE,
                    sprintf(
                        'commit-session: countdown extended by %s via %s (extension %d/%d)',
                        $username,
                        $source,
                        $resp['extensions_count'] ?? 1,
                        $resp['max_extensions'] ?? 6
                    )
                );
                return $resp;
            } else {
                $err = $resp['error'] ?? 'extension_failed';
                if ($err === 'max_extensions_reached') {
                    $msg = gettext('Maximum number of extensions reached.');
                } elseif ($err === 'not_counting') {
                    $msg = gettext('No countdown is currently running (no changes saved yet).');
                } else {
                    $msg = gettext('Failed to extend countdown.');
                }
                return [
                    'status' => 'failed',
                    'message' => $msg,
                ];
            }
        }

        return [
            'status' => 'failed',
            'message' => gettext('Watchdog daemon is unreachable.'),
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

        // Notify watchdog to update revisions to Confirmed, cleanup, and exit cleanly
        $this->sendWatchdogCommand('CONFIRM');

        // Stop supervisor and clean any remaining artifacts
        $this->stopWatchdogProcess();
        @unlink(self::MARKER_FILE);
        @unlink(self::SNAPSHOT_FILE);
        @unlink(self::SOCKET_FILE);

        openlog('audit', LOG_ODELAY, LOG_AUTH);
        syslog(LOG_NOTICE, sprintf('commit-session: session confirmed by %s via %s', $username, $source));

        return [
            'status' => 'ok',
            'message' => gettext('Session confirmed successfully.'),
        ];
    }

    /**
     * Revert configuration to snapshot and reload services.
     * Uses detached execution to prevent web GUI restarts from terminating the rollback mid-flight.
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
        $guiUrl = $data['gui_url'] ?? $this->getGuiUrl();

        // 1. Try instructing the running detached watchdog via socket
        $resp = $this->sendWatchdogCommand('REVERT ' . $reason);
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

        // 2. Fallback: If watchdog was unreachable, start a one-shot detached revert process.
        // Never run rc.reload_all inside PHP-CGI; restarting lighttpd kills PHP mid-reload.
        $this->stopWatchdogProcess();

        $pythonBin = '/usr/local/bin/python3';
        if (file_exists('/usr/sbin/daemon') && file_exists(self::WATCHDOG_SCRIPT)) {
            Shell::shell_safe(
                '/usr/sbin/daemon -f %s %s --revert %s',
                [$pythonBin, self::WATCHDOG_SCRIPT, $reason]
            );
        } else {
            Shell::shell_safe(
                '%s %s --revert %s > /dev/null 2>&1 &',
                [$pythonBin, self::WATCHDOG_SCRIPT, $reason]
            );
        }

        openlog('audit', LOG_ODELAY, LOG_AUTH);
        syslog(
            LOG_WARNING,
            sprintf('commit-session: configuration revert initiated in detached fallback by %s via %s (reason: %s)', $username, $source, $reason)
        );

        return [
            'status' => 'ok',
            'message' => gettext('Configuration revert initiated in detached background process.'),
            'gui_url' => $guiUrl,
        ];
    }

    /**
     * Stop supervisor and watchdog processes
     */
    private function stopWatchdogProcess(): void
    {
        if (file_exists(self::SUPERVISOR_PID_FILE)) {
            $supPid = trim(@file_get_contents(self::SUPERVISOR_PID_FILE));
            if (is_numeric($supPid) && (int)$supPid > 0) {
                if (function_exists('posix_kill') && defined('SIGTERM')) {
                    @posix_kill((int)$supPid, SIGTERM);
                } else {
                    Shell::shell_safe('/bin/kill -TERM %s', [$supPid]);
                }
            }
            @unlink(self::SUPERVISOR_PID_FILE);
        }

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
     * Update session tag in sidecar index and backup XML files
     * @param array $revisions
     * @param string $tag
     */
    public function tagRevisionsInHistory(array $revisions, string $tag): void
    {
        if (empty($revisions)) {
            return;
        }

        $backupDir = (new AppConfig())->application->configDir . '/backup/';
        $tagsFile = $backupDir . 'session_tags.json';
        $tags = [];
        if (file_exists($tagsFile)) {
            $tags = @json_decode(file_get_contents($tagsFile), true) ?: [];
        }

        foreach ($revisions as $rev) {
            $revStr = trim((string)$rev);
            if (str_starts_with($revStr, 'config-') && str_ends_with($revStr, '.xml')) {
                $filename = $revStr;
            } else {
                $cleanTime = preg_replace('/[^0-9.]/', '', $revStr);
                $filename = 'config-' . $cleanTime . '.xml';
            }

            $tags[$filename] = $tag;
        }

        @file_put_contents($tagsFile . '.tmp', json_encode($tags, JSON_PRETTY_PRINT));
        @rename($tagsFile . '.tmp', $tagsFile);
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
