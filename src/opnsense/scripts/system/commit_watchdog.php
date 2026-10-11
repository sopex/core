#!/usr/local/bin/php
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

// No MVC bootstrap: recovery must work before normal configuration loading at boot.
require_once '/usr/local/opnsense/mvc/app/library/OPNsense/Core/CommitSessionStore.php';

use OPNsense\Core\CommitSessionStore;

$store = new CommitSessionStore();
openlog('commit-session', LOG_PID, LOG_AUTH);
$mode = $argv[1] ?? '';
if ($mode === '--check') {
    if ($store->active() && !$store->workerAlive()) {
        passthru('/usr/sbin/daemon -f -r -P /var/run/commit_watchdog_sup.pid ' .
            '/usr/local/bin/php ' . escapeshellarg(__FILE__), $status);
        exit($status === 3 ? 0 : $status);
    }
    exit(0);
}
$lock = fopen('/var/run/commit_session_worker.lock', 'ce');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(1);
}
$run = function (array $command): bool {
    // timeout also bounds a hung bootstrap or backend, independently of PHP execution limits.
    exec('/usr/bin/timeout -k 5 180 ' . implode(' ', array_map('escapeshellarg', $command)) .
        ' > /dev/null 2>&1', $output, $status);
    return $status === 0;
};
$apply = function (array $services) use ($run): void {
    if ($services && !$run(array_merge(
        ['/usr/local/bin/php', '/usr/local/opnsense/scripts/system/commit_apply.php'], $services
    ))) {
        throw new RuntimeException('Apply failed: ' . implode(', ', $services));
    }
};
$reboot = fn() => $run(['/sbin/shutdown', '-r', 'now']) || $run(['/sbin/reboot']);
if ($mode === '--boot-revert') {
    try {
        $store->tick($apply, $reboot, true);
        exit(0);
    } catch (Throwable $error) {
        fwrite(STDERR, 'Protected session boot recovery failed: ' . $error->getMessage() . "\n");
        exit(1);
    }
}
// Keep one supervised worker. Session completion never kills or unlinks another process's pidfile.
while (true) {
    try {
        $store->tick($apply, $reboot);
    } catch (Throwable $error) {
        syslog(LOG_ERR, $error->getMessage());
    }
    sleep(1);
}
