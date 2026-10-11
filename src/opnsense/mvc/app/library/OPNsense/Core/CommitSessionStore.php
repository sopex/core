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

/**
 * The session's only state machine. Usable before MVC starts and with isolated paths in tests.
 * Lock order: config.xml, then state.lock. Neither lock is held while applying services.
 * Monotonic deadlines survive worker restarts; the early boot hook consumes pending sessions.
 */
class CommitSessionStore
{
    private const APPLY_PATHS = [
        'filter' => 'filter', 'nat' => 'filter', 'aliases' => 'filter',
        'OPNsense/Firewall/Filter' => 'filter', 'OPNsense/Firewall/Alias' => 'filter',
        'OPNsense/Firewall/Category' => 'filter', 'OPNsense/wireguard' => 'wireguard',
        // Remove the specific subtrees before comparing the rest of the Unbound model.
        'OPNsense/unboundplus/dnsbl' => 'unbound.dnsbl',
        'OPNsense/unboundplus/general' => 'unbound.general',
        'OPNsense/unboundplus' => 'unbound.reconfigure',
        'dnsmasq' => 'dnsmasq.reconfigure', 'OPNsense/Kea' => 'kea.reconfigure',
        'OPNsense/Syslog' => 'syslog.reconfigure', 'OPNsense/monit' => 'monit.reconfigure',
        'OPNsense/Hostwatch' => 'hostwatch.reconfigure', 'revision' => null
    ];
    private const APPLY_ORDER = [
        'wireguard', 'dnsmasq.reconfigure', 'unbound.general', 'unbound.reconfigure', 'unbound.dnsbl',
        'kea.reconfigure', 'filter', 'syslog.reconfigure', 'monit.reconfigure', 'hostwatch.reconfigure'
    ];
    private \Closure $clock;
    private \Closure $wallclock;

    public function __construct(
        private string $configDir = '/conf',
        private string $runDir = '/var/run',
        private string $dnsblFile = '/var/unbound/data/dnsbl.json',
        ?\Closure $clock = null,
        ?\Closure $wallclock = null
    ) {
        $this->clock = $clock ?? fn() => hrtime(true) / 1e9;
        $this->wallclock = $wallclock ?? fn() => microtime(true);
    }

    private function path(string $name): string
    {
        return $this->configDir . '/' . $name;
    }

    private function lock(string $name = 'state', bool $wait = true)
    {
        $handle = fopen($this->runDir . '/commit_session_' . $name . '.lock', 'ce');
        if (!$handle) {
            throw new \RuntimeException('Cannot lock protected session');
        }
        if (!flock($handle, LOCK_EX | ($wait ? 0 : LOCK_NB))) {
            fclose($handle);
            if ($wait) {
                throw new \RuntimeException('Cannot lock protected session');
            }
            return null;
        }
        return $handle;
    }

    private function locked(callable $operation)
    {
        $lock = $this->lock();
        try {
            return $operation();
        } finally {
            fclose($lock);
        }
    }

    private function read(): ?array
    {
        if (!$this->active()) {
            return null;
        }
        $state = json_decode($this->contents($this->path('commit_rollback_pending.json')), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($state) || ($state['format'] ?? null) !== 1) {
            throw new \RuntimeException('Invalid session marker; keep the snapshot for recovery');
        }
        // Read pending sessions written by earlier versions without losing their recovery plan.
        if (isset($state['services']['unbound'])) {
            unset($state['services']['unbound']);
            $state['services'] += ['unbound.general' => true, 'unbound.dnsbl' => true];
        }
        $state['unsupported'] = ($state['unsupported'] ?? false) || ($state['reboot'] ?? false);
        unset($state['reboot'], $state['reboot_requested']);
        if (($state['last_clock'] ?? ($state['idle_deadline'] - 3600)) > ($this->clock)()) {
            // A new monotonic origin must not carry an old retry deadline into this boot.
            $state['status'] = 'reverting';
            $state['countdown_active'] = false;
            $state['retry_at'] = ($this->clock)();
            $state['snapshot_restored'] = false;
        }
        return $state;
    }

    private function contents(string $path): string
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException('Cannot read protected session data');
        }
        return $contents;
    }

    private function write(array $state): void
    {
        $state['last_clock'] = ($this->clock)();
        $this->atomic($this->path('commit_rollback_pending.json'), json_encode($state, JSON_THROW_ON_ERROR));
    }

    private function syncDirectory(string $directory): void
    {
        $handle = fopen($directory, 'r');
        try {
            if (!fsync($handle)) {
                throw new \RuntimeException('Cannot sync session directory');
            }
        } finally {
            fclose($handle);
        }
    }

    private function writeStream($handle, $contents): void
    {
        if (is_resource($contents)) {
            if (stream_copy_to_stream($contents, $handle) !== fstat($contents)['size']) {
                throw new \RuntimeException('Cannot copy snapshot data');
            }
        } else {
            for ($offset = 0; $offset < strlen($contents); $offset += $written) {
                $written = fwrite($handle, substr($contents, $offset));
                if (!$written) {
                    throw new \RuntimeException('Cannot write protected session data');
                }
            }
        }
        if (!fflush($handle) || !fsync($handle)) {
            throw new \RuntimeException('Cannot sync protected session data');
        }
    }

    /** Stream potentially large downloaded blocklists instead of loading them into PHP memory. */
    private function copy(string $source, string $target, int $mode = 0640): void
    {
        $handle = fopen($source, 'rbe');
        if (!$handle) {
            throw new \RuntimeException('Cannot open snapshot data');
        }
        try {
            $this->atomic($target, $handle, $mode);
        } finally {
            fclose($handle);
        }
    }

    private function atomic(string $path, $contents, int $mode = 0640): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0750, true)) {
            throw new \RuntimeException('Cannot create snapshot directory');
        }
        $temporary = $path . '.tmp';
        $handle = fopen($temporary, 'wbe');
        if (!$handle) {
            throw new \RuntimeException('Cannot create protected session data');
        }
        try {
            if (!chmod($temporary, $mode)) {
                throw new \RuntimeException('Cannot protect session file permissions');
            }
            $this->writeStream($handle, $contents);
        } finally {
            fclose($handle);
        }
        if (!rename($temporary, $path)) {
            throw new \RuntimeException('Cannot replace protected session data');
        }
        $this->syncDirectory($directory);
    }

    private function xml(string $contents): \DOMDocument
    {
        $xml = new \DOMDocument();
        $xml->preserveWhiteSpace = false;
        if (!@$xml->loadXML($contents, LIBXML_NONET) || $xml->doctype || $xml->documentElement->tagName !== 'opnsense') {
            throw new \RuntimeException('Invalid configuration XML');
        }
        return $xml;
    }

    /** Changed subtrees select the same backend operations used by the GUI. */
    private function plan(string $proposed): array
    {
        $documents = [$this->xml($this->contents($this->path('commit_rollback/snapshot.xml'))), $this->xml($proposed)];
        $services = [];
        foreach (self::APPLY_PATHS as $path => $service) {
            $values = [];
            foreach ($documents as $document) {
                $values[] = '';
                foreach ((new \DOMXPath($document))->query('/opnsense/' . $path) as $node) {
                    // Canonicalize an isolated document: libxml's subtree node-set walk scales poorly.
                    $subtree = new \DOMDocument();
                    $subtree->appendChild($subtree->importNode($node, true));
                    $values[array_key_last($values)] .= $subtree->C14N();
                    $node->parentNode->removeChild($node);
                }
            }
            if ($service && $values[0] !== $values[1]) {
                $services[$service] = true;
            }
        }
        foreach ($documents as $document) {
            foreach (['OPNsense/Firewall', 'OPNsense'] as $container) {
                foreach ((new \DOMXPath($document))->query('/opnsense/' . $container) as $node) {
                    if (!$node->hasChildNodes() && !$node->hasAttributes()) {
                        $node->parentNode->removeChild($node);
                    }
                }
            }
        }
        if (isset($services['wireguard'])) {
            $services['filter'] = true;
        }
        return ['services' => $services, 'unsupported' => $documents[0]->C14N() !== $documents[1]->C14N()];
    }

    public function active(): bool
    {
        clearstatcache();
        return is_file($this->path('commit_rollback_pending.json'));
    }

    public function workerAlive(): bool
    {
        $handle = @fopen($this->runDir . '/commit_session_worker.lock', 'ce');
        if (!$handle) {
            return false;
        }
        $alive = !flock($handle, LOCK_EX | LOCK_NB);
        fclose($handle);
        return $alive;
    }

    public function state(): array
    {
        try {
            $state = $this->read();
        } catch (\Throwable $error) {
            return ['active' => true, 'status' => 'reverting', 'error' => $error->getMessage(),
                'last_revert_error' => $error->getMessage(), 'revert_attempts' => 1, 'revert_running' => false];
        }
        $notice = is_file($this->path('commit_rollback_notice.json'));
        if (!$state) {
            $reverted = $notice ? json_decode($this->contents($this->path('commit_rollback_notice.json')), true) : [];
            return ['active' => false, 'has_notice' => $notice, 'reverted_session_id' => $reverted['session_id'] ?? null];
        }
        $now = ($this->clock)();
        return $state + [
            'active' => true, 'has_notice' => $notice, 'watchdog_alive' => $this->workerAlive(),
            'reverting' => $state['status'] === 'reverting',
            'remaining_seconds' => $state['countdown_active'] ? max(0, (int)ceil($state['deadline'] - $now)) : 0,
            'session_limit_remaining_seconds' => isset($state['limit_deadline'])
                ? max(0, (int)ceil($state['limit_deadline'] - $now)) : null,
            'next_retry_seconds' => max(0, (int)ceil(($state['retry_at'] ?? $now) - $now))
        ];
    }

    public function start(string $username, string $source, array $settings, callable $guiUrl): array
    {
        $applyLock = $this->lock('apply');
        $config = fopen($this->path('config.xml'), 'rbe');
        if (!$config || !flock($config, LOCK_EX)) {
            throw new \RuntimeException('Cannot lock configuration for snapshot');
        }
        try {
            return $this->locked(function () use ($config, $username, $source, $settings, $guiUrl) {
                if ($this->active()) {
                    throw new \RuntimeException(gettext('A protected change session is already active.'));
                }
                $snapshot = stream_get_contents($config);
                $this->xml($snapshot);
                $this->atomic($this->path('commit_rollback/snapshot.xml'), $snapshot);
                $dnsblExists = is_file($this->dnsblFile);
                if ($dnsblExists) {
                    $this->copy($this->dnsblFile, $this->path('commit_rollback/dnsbl.json'));
                }
                $wall = ($this->wallclock)();
                $state = [
                    'format' => 1, 'session_id' => bin2hex(random_bytes(16)), 'username' => $username,
                    'source' => $source, 'created_at' => $wall, 'created_at_iso' => date('c', (int)$wall),
                    'gui_url' => $guiUrl($snapshot), 'status' => 'pending', 'countdown_active' => false,
                    'countdown_seconds' => $settings['countdown'] * 60, 'extension_seconds' => $settings['extension'] * 60,
                    'max_extensions' => $settings['max_extensions'], 'max_session_seconds' => $settings['max_session'] * 60,
                    'extensions_count' => 0, 'saves_count' => 0, 'revisions' => [],
                    'idle_deadline' => ($this->clock)() + 3600, 'dnsbl_snapshot_exists' => $dnsblExists,
                    'services' => [], 'unsupported' => false, 'revert_attempts' => 0, 'revert_running' => false
                ];
                $this->write($state);
                return $state;
            });
        } finally {
            fclose($config);
            fclose($applyLock);
        }
    }

    /** Journal before touching runtime state; keep GUI Apply and recovery from crossing each other. */
    public function apply(string $operation, callable $apply)
    {
        $lease = $this->lock('apply');
        try {
            $this->locked(function () use ($operation) {
                $state = $this->read();
                if (!$state) {
                    return;
                }
                $now = ($this->clock)();
                if ($state['status'] === 'reverting' || ($state['deadline'] ?? INF) <= $now) {
                    throw new \RuntimeException(gettext('Configuration rollback is due; wait for recovery to finish.'));
                }
                if (!in_array($operation, self::APPLY_ORDER, true)) {
                    throw new \RuntimeException(sprintf(gettext('Protected rollback does not support Apply operation: %s'), $operation));
                }
                $state['services'][$operation] = true;
                if ($operation === 'wireguard') {
                    $state['services']['filter'] = true;
                }
                if (!$state['countdown_active']) {
                    $state['countdown_active'] = true;
                    $state['limit_deadline'] = $now + $state['max_session_seconds'];
                    $state['deadline'] = min($now + $state['countdown_seconds'], $state['limit_deadline']);
                }
                $this->write($state);
            });
            return $apply();
        } finally {
            fclose($lease);
        }
    }

    /** Caller holds config.xml's lock. Return a lease held through the write and backup creation. */
    public function beginSave(string $proposed, bool $userAction)
    {
        if (!$this->active()) {
            return null;
        }
        $lease = $this->lock();
        try {
            $state = $this->read();
            if (!$state) {
                fclose($lease);
                return null;
            }
            $now = ($this->clock)();
            if ($state['status'] === 'reverting' || ($state['deadline'] ?? INF) <= $now) {
                throw new \RuntimeException(gettext('Configuration rollback is due; wait for recovery to finish.'));
            }
            $plan = $this->plan($proposed);
            if ($plan['unsupported']) {
                throw new \RuntimeException(gettext(
                    'These settings have no supported live rollback operation. End the protected session before changing them.'
                ));
            }
            $state['services'] += $plan['services'];
            if (!$state['countdown_active']) {
                $state['countdown_active'] = true;
                $state['limit_deadline'] = $now + $state['max_session_seconds'];
            }
            if (!isset($state['deadline']) || $userAction) {
                $state['deadline'] = min($now + $state['countdown_seconds'], $state['limit_deadline']);
            }
            $state['saves_count']++;
            // The countdown and cumulative Apply plan must reach disk BEFORE config.xml changes.
            $this->write($state);
            return $lease;
        } catch (\Throwable $error) {
            fclose($lease);
            throw $error;
        }
    }

    public function finishSave($lease, ?string $backup): void
    {
        if (!$lease) {
            return;
        }
        try {
            if ($backup !== null) {
                $state = $this->read();
                $state['revisions'][] = basename($backup);
                $this->write($state);
            }
        } catch (\Throwable $error) {
            syslog(LOG_ERR, 'commit-session: revision recording failed: ' . $error->getMessage());
        } finally {
            fclose($lease);
        }
    }

    public function change(string $action): array
    {
        $applyLock = $action === 'confirm' ? $this->lock('apply', false) : null;
        if ($action === 'confirm' && !$applyLock) {
            throw new \RuntimeException(gettext('An Apply operation is running. Wait for it to finish.'));
        }
        try {
            return $this->locked(function () use ($action) {
                $added = 0;
                $state = $this->read();
                if (!$state) {
                    throw new \RuntimeException(gettext('No active protected change session.'));
                }
                $now = ($this->clock)();
                if ($action === 'revert') {
                    $state['status'] = 'reverting';
                    $state['countdown_active'] = false;
                    $state['revert_reason'] = $state['revert_reason'] ?? 'manual';
                    if (!$state['revert_running']) {
                        $state['retry_at'] = $now;
                    }
                } elseif ($action === 'confirm') {
                    $failed = $state['status'] === 'reverting' && !empty($state['last_revert_error']);
                    if ($state['revert_running']) {
                        throw new \RuntimeException(gettext('Configuration rollback is running. Wait for it to finish.'));
                    }
                    if (!$failed && ($state['status'] === 'reverting' || ($state['deadline'] ?? INF) <= $now)) {
                        throw new \RuntimeException(gettext('The countdown has expired; rollback is due.'));
                    }
                    $this->audit($state, 'Confirmed');
                    $this->cleanup();
                    return [];
                } elseif ($action === 'extend') {
                    if (!$state['countdown_active'] || ($state['deadline'] ?? 0) <= $now || $state['status'] === 'reverting') {
                        throw new \RuntimeException(gettext('No running countdown can be extended.'));
                    }
                    $deadline = min($state['deadline'] + $state['extension_seconds'], $state['limit_deadline']);
                    if ($state['extensions_count'] >= $state['max_extensions'] || $deadline <= $state['deadline']) {
                        throw new \RuntimeException(gettext('The extension or maximum session limit has been reached.'));
                    }
                    $added = $deadline - $state['deadline'];
                    $state['extensions_count']++;
                    $state['deadline'] = $deadline;
                } else {
                    throw new \InvalidArgumentException('Unknown session action');
                }
                $this->write($state);
                return $this->state() + ['added_seconds' => $added];
            });
        } finally {
            if ($applyLock) {
                fclose($applyLock);
            }
        }
    }

    private function cleanup(): void
    {
        if (!unlink($this->path('commit_rollback_pending.json'))) {
            throw new \RuntimeException('Cannot remove protected session marker');
        }
        $this->syncDirectory($this->configDir);
        foreach (['snapshot.xml', 'dnsbl.json'] as $file) {
            @unlink($this->path('commit_rollback/' . $file));
        }
    }

    /** History is best effort; failures must not prevent restoring network access. Caller holds state lock. */
    private function audit(array $state, string $tag): void
    {
        try {
            $revisions = $state['revisions'];
            if ($tag === 'Reverted') {
                $backup = 'config-' . sprintf('%.6f', ($this->wallclock)()) . '.xml';
                $this->atomic($this->path('backup/' . $backup), $this->contents($this->path('commit_rollback/snapshot.xml')));
                $revisions[] = $backup;
                $this->atomic($this->path('commit_rollback_notice.json'), json_encode([
                    'session_id' => $state['session_id'],
                    'reverted_at' => ($this->wallclock)(), 'reverted_at_iso' => date('c', (int)($this->wallclock)()),
                    'username' => $state['username'], 'source' => $state['source'],
                    'reason' => $state['revert_reason'], 'backup_id' => $backup, 'gui_url' => $state['gui_url']
                ], JSON_THROW_ON_ERROR));
            }
            $path = $this->path('backup/session_tags.json');
            $tags = is_file($path) ? json_decode($this->contents($path), true, 512, JSON_THROW_ON_ERROR) : [];
            foreach ($revisions as $revision) {
                $tags[basename($revision)] = $tag;
            }
            $this->atomic($path, json_encode($tags, JSON_THROW_ON_ERROR));
        } catch (\Throwable $error) {
            syslog(LOG_ERR, 'commit-session: history update failed: ' . $error->getMessage());
        }
    }

    private function restore(): array
    {
        // Preserve the inode used by existing Config objects and serialize with in-flight saves.
        $config = fopen($this->path('config.xml'), 'c+be');
        if (!$config || !flock($config, LOCK_EX)) {
            throw new \RuntimeException('Cannot lock configuration for recovery');
        }
        try {
            return $this->locked(function () use ($config) {
                $state = $this->read();
                if (empty($state['snapshot_restored'])) {
                    $snapshot = $this->contents($this->path('commit_rollback/snapshot.xml'));
                    $this->xml($snapshot);
                    if (array_intersect(['unbound.general', 'unbound.reconfigure', 'unbound.dnsbl'], array_keys($state['services']))) {
                        if ($state['dnsbl_snapshot_exists']) {
                            $this->copy($this->path('commit_rollback/dnsbl.json'), $this->dnsblFile, 0644);
                        } elseif (is_file($this->dnsblFile)) {
                            if (!unlink($this->dnsblFile)) {
                                throw new \RuntimeException('Cannot remove session DNS blocklist');
                            }
                            $this->syncDirectory(dirname($this->dnsblFile));
                        }
                    }
                    if (!chmod($this->path('config.xml'), 0640) || !ftruncate($config, 0) || fseek($config, 0) !== 0) {
                        throw new \RuntimeException('Cannot restore configuration');
                    }
                    $this->writeStream($config, $snapshot);
                    $state['snapshot_restored'] = true;
                    $this->write($state);
                }
                return $state;
            });
        } finally {
            fclose($config);
        }
    }

    /** Called only by the worker holding worker.lock, or the early boot hook before services start. */
    public function tick(callable $apply, bool $boot = false): void
    {
        $lease = $this->lock('apply', $boot);
        if (!$lease) {
            return; // A GUI Apply still owns runtime state. Try again on the next worker tick.
        }
        try {
            $this->recover($apply, $boot);
        } finally {
            fclose($lease);
        }
    }

    private function recover(callable $apply, bool $boot): void
    {
        if (!$this->active()) {
            return;
        }
        $state = $this->locked(function () use ($boot) {
            $state = $this->read();
            if (!$state) {
                return null;
            }
            $now = ($this->clock)();
            if (!$boot && $state['status'] !== 'reverting') {
                if (!$state['countdown_active']) {
                    if ($now >= $state['idle_deadline']) {
                        $this->cleanup();
                    }
                    return null;
                }
                if ($now < $state['deadline']) {
                    return null;
                }
            }
            if (!$boot && ($state['retry_at'] ?? 0) > $now) {
                return null;
            }
            $state['status'] = 'reverting';
            $state['countdown_active'] = false;
            $state['revert_reason'] = $boot ? 'unconfirmed_at_boot' : ($state['revert_reason'] ?? 'countdown_expired');
            $state['revert_running'] = true;
            $state['revert_attempts']++;
            if ($boot) {
                $state['snapshot_restored'] = false;
            }
            $this->write($state);
            return $state;
        });
        if (!$state) {
            return;
        }
        try {
            $state = $this->restore();
            if (!$boot) {
                $operations = array_values(array_intersect(self::APPLY_ORDER, array_keys($state['services'])));
                if (in_array('unbound.general', $operations, true)) {
                    $operations = array_values(array_diff($operations, ['unbound.reconfigure']));
                }
                $apply($operations);
                if ($state['unsupported']) {
                    throw new \RuntimeException(gettext('This saved session contains changes without a live rollback operation. Manual recovery is required.'));
                }
            }
            $this->locked(function () use ($state) {
                $this->audit($state, 'Reverted');
                $this->cleanup();
            });
        } catch (\Throwable $error) {
            $this->locked(function () use ($error) {
                $state = $this->read();
                $state['last_revert_error'] = $error->getMessage();
                $state['revert_running'] = false;
                $state['retry_at'] = ($this->clock)() + min(300, 15 * 2 ** min(5, $state['revert_attempts'] - 1));
                $this->write($state);
            });
            syslog(LOG_ERR, 'commit-session: ' . $error->getMessage());
            if ($boot) {
                throw $error;
            }
        }
    }
}
