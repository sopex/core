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

// Standalone: php test_commit_session.php. All state is under a new temporary directory, never /conf.
require_once __DIR__ . '/../app/library/OPNsense/Core/CommitSessionStore.php';
use OPNsense\Core\CommitSessionStore;

set_error_handler(function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    }
});
$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function rejected(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (Throwable $error) {
        check(true, $message);
        return;
    }
    check(false, $message);
}
function awaitFile(string $path): void
{
    $deadline = microtime(true) + 5;
    do {
        clearstatcache();
        if (is_file($path)) {
            return;
        }
        usleep(10000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('Timed out waiting for child: ' . basename($path));
}
function joinChild(int $pid): void
{
    pcntl_waitpid($pid, $status);
    check(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, 'Child failed');
}

class SessionFixture
{
    public string $directory;
    public float $now = 1000;
    public float $wall = 1700000000;
    public CommitSessionStore $store;
    public string $baseline = '<opnsense><system><hostname>demo</hostname></system><filter><rule>allow</rule></filter></opnsense>';
    public array $settings = ['countdown' => 1, 'extension' => 1, 'max_extensions' => 2, 'max_session' => 3];
    public array $applied = [];

    public function __construct()
    {
        $this->directory = sys_get_temp_dir() . '/commit-session-test-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        mkdir($this->directory . '/run', 0700);
        file_put_contents($this->path('config.xml'), $this->baseline);
        file_put_contents($this->path('dnsbl.json'), '{"before":true}');
        $this->restart();
    }
    public function path(string $name): string
    {
        return $this->directory . '/' . $name;
    }
    public function restart(): void
    {
        $this->store = new CommitSessionStore($this->directory, $this->directory . '/run',
            $this->path('dnsbl.json'), fn() => $this->now, fn() => $this->wall);
    }
    public function start(): void
    {
        $this->store->start('demo', 'gui', $this->settings, fn() => 'https://demo');
    }
    public function marker(): array
    {
        return json_decode(file_get_contents($this->path('commit_rollback_pending.json')), true, 512, JSON_THROW_ON_ERROR);
    }
    public function save(?string $contents = null, bool $user = true, ?string $backup = 'config-123.00.xml'): void
    {
        $contents = $contents ?? str_replace('allow', 'deny', $this->baseline);
        $handle = fopen($this->path('config.xml'), 'c+');
        flock($handle, LOCK_EX);
        $lease = null;
        try {
            $lease = $this->store->beginSave($contents, $user);
            if ($lease) {
                check($this->marker()['countdown_active'], 'Countdown not durable before write');
            }
            ftruncate($handle, 0);
            fwrite($handle, $contents);
            fflush($handle);
            fsync($handle);
        } finally {
            $this->store->finishSave($lease, $backup);
            fclose($handle);
        }
    }
    public function tick(bool $boot = false): void
    {
        $this->store->tick(function ($services) { $this->applied[] = $services; }, $boot);
    }
    public function remove(): void
    {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->directory,
            FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }
}

$cases = [];
$cases['pending and idle expiry'] = function ($f) {
    $f->start();
    check(!$f->store->state()['countdown_active'], 'Start armed timer without save');
    check(file_get_contents($f->path('commit_rollback/snapshot.xml')) === $f->baseline, 'Snapshot differs');
    check((fileperms($f->path('commit_rollback/snapshot.xml')) & 0777) === 0640, 'Unsafe snapshot mode');
    rejected(fn() => $f->store->change('extend'), 'Extended idle session');
    rejected(fn() => $f->start(), 'Overwrote active snapshot');
    $f->now += 3599;
    $f->tick();
    check($f->store->active(), 'Idle session ended early');
    $f->now++;
    $f->tick();
    check(!$f->store->active() && !$f->applied, 'Idle session applied');
};
$cases['save refresh and session ceiling'] = function ($f) {
    $f->start();
    $f->now += 100;
    $f->save(null, false, null);
    check($f->marker()['deadline'] === 1160 && $f->marker()['limit_deadline'] === 1280, 'First background save not armed');
    $f->now += 20;
    $f->save(null, false);
    check($f->marker()['deadline'] === 1160, 'Background save refreshed timer');
    $f->save();
    check($f->marker()['deadline'] === 1180, 'User save did not refresh');
    foreach ([1170, 1220, 1270] as $now) {
        $f->now = $now;
        $f->save();
    }
    check($f->marker()['deadline'] === 1280, 'Save exceeded session ceiling');
    $f->now = 1280;
    rejected(fn() => $f->save(), 'Expired save rearmed timer');
    rejected(fn() => $f->store->change('confirm'), 'Confirmed after expiry');
    rejected(fn() => $f->store->change('extend'), 'Extended after expiry');
    $f->tick();
    check(!$f->store->active(), 'Expiry did not recover');
};
$cases['extensions bounded and server time authoritative'] = function ($f) {
    $f->settings['max_session'] = 2.5;
    $f->start();
    $f->save();
    $f->store->change('extend');
    $state = $f->store->change('extend');
    check($state['remaining_seconds'] === 150, 'Partial extension exceeded ceiling');
    check($state['extensions_count'] === 2 && $state['added_seconds'] === 30, 'Actual extension amount lost');
    rejected(fn() => $f->store->change('extend'), 'Extension count/ceiling ignored');
    $f->store->change('confirm');
    $tags = json_decode(file_get_contents($f->path('backup/session_tags.json')), true);
    check($tags['config-123.00.xml'] === 'Confirmed', 'Actual backup filename not tagged');
    check(str_contains(file_get_contents($f->path('config.xml')), 'deny'), 'Confirm reverted configuration');
    check(!$f->store->active(), 'Confirm retained session');
    $f->settings['max_extensions'] = 0;
    $f->start();
    $f->save();
    rejected(fn() => $f->store->change('extend'), 'Disabled extensions accepted');
};
$cases['monotonic deadline survives process restart and wall clock jumps'] = function ($f) {
    $f->start();
    $f->save();
    $f->wall -= 86400;
    $f->now += 10;
    $f->restart();
    check($f->store->state()['remaining_seconds'] === 50, 'Wall clock rollback lengthened countdown');
    $f->wall += 172800;
    $f->restart();
    check($f->store->state()['remaining_seconds'] === 50, 'Wall clock advance changed countdown');
    $f->now += 50;
    $f->tick();
    check(!$f->store->active(), 'Restart lost expiry');
};
$cases['cumulative Apply plan and retained config inode'] = function ($f) {
    $f->start();
    $openConfig = fopen($f->path('config.xml'), 'r');
    $inode = fstat($openConfig)['ino'];
    $f->save(str_replace('</opnsense>', '<OPNsense><wireguard><enabled>1</enabled></wireguard>' .
        '<unboundplus><general><enabled>1</enabled></general></unboundplus></OPNsense></opnsense>', $f->baseline));
    $f->save($f->baseline); // XML matches snapshot, but the earlier services may still be running.
    file_put_contents($f->path('dnsbl.json'), '{"after":true}');
    $f->store->change('revert');
    rejected(fn() => $f->save(), 'Write allowed during recovery');
    $f->tick();
    check($f->applied === [['wireguard', 'unbound.general', 'filter']], 'Lost dirty service or dependency order');
    check(file_get_contents($f->path('dnsbl.json')) === '{"before":true}', 'Downloaded blocklist not restored');
    check(fstat($openConfig)['ino'] === $inode && stream_get_contents($openConfig) === $f->baseline, 'Replaced config inode');
    check((fileperms($f->path('config.xml')) & 0777) === 0640, 'Unsafe config mode');
    check(is_file($f->path('commit_rollback_notice.json')), 'Missing recovery notice');
    $tags = json_decode(file_get_contents($f->path('backup/session_tags.json')), true);
    check($tags['config-123.00.xml'] === 'Reverted', 'Missing reverted history tag');
    fclose($openConfig);
};
$cases['failed Apply retries without losing recovery data'] = function ($f) {
    $f->start();
    $f->save();
    $f->store->change('revert');
    $f->store->tick(fn() => throw new RuntimeException('Apply action failed: unbound dnsbl_reload'));
    $state = $f->store->state();
    check($state['snapshot_restored'] && !$state['revert_running'] && $state['next_retry_seconds'] === 15, 'No durable retry');
    check($state['last_revert_error'] === 'Apply action failed: unbound dnsbl_reload', 'Lost exact Apply failure');
    check(!isset($state['reboot_requested']), 'Apply failure scheduled a reboot');
    check(is_file($f->path('commit_rollback/snapshot.xml')), 'Deleted failed recovery snapshot');
    check(!is_file($f->path('commit_rollback_notice.json')), 'Reported success before Apply succeeded');
    $f->restart();
    $f->tick();
    check(!$f->applied, 'Ignored backoff');
    $f->now += 15;
    $f->tick();
    check($f->applied === [['filter']] && !$f->store->active(), 'Retry lost Apply plan');
};
$cases['unsupported changes rejected before saving; boot recovery still works'] = function ($f) {
    $f->start();
    rejected(fn() => $f->save(str_replace('demo', 'changed', $f->baseline)), 'Unknown settings accepted');
    check(file_get_contents($f->path('config.xml')) === $f->baseline, 'Unsupported save changed configuration');
    check(!$f->marker()['countdown_active'], 'Rejected save armed countdown');
    $f->save();
    $f->store->change('revert');
    $f->now = 5; // new boot, new monotonic clock origin
    $f->restart();
    $f->tick(true);
    check(!$f->store->active() && file_get_contents($f->path('config.xml')) === $f->baseline, 'Boot recovery failed');
};
$cases['revision metadata ignored, unknown attributes retained'] = function ($f) {
    $f->start();
    $f->save(str_replace('</opnsense>', '<revision><description>save</description></revision></opnsense>', $f->baseline));
    check(!$f->marker()['unsupported'] && !$f->marker()['services'], 'Revision metadata changed Apply plan');
    rejected(fn() => $f->save(str_replace('<opnsense>', '<opnsense future="1">', $f->baseline)),
        'Unknown root attribute silently ignored');
};
$cases['Apply comparison normalizes XML without dropping unknown settings'] = function ($f) {
    $f->baseline = '<opnsense><system/><filter><rule z="2" a="1">allow</rule></filter></opnsense>';
    file_put_contents($f->path('config.xml'), $f->baseline);
    $f->start();
    $f->save("<opnsense>\n <system/>\n <filter><rule a=\"1\" z=\"2\">allow</rule></filter>\n</opnsense>");
    check(!$f->marker()['services'] && !$f->marker()['unsupported'], 'Formatting/attribute order caused Apply');
    $f->save('<opnsense><system/><OPNsense><Firewall/></OPNsense></opnsense>');
    check(isset($f->marker()['services']['filter']) && !$f->marker()['unsupported'], 'Deleted rules or empty MVC containers misclassified');
    rejected(fn() => $f->save('<opnsense><system/><OPNsense><Firewall><futureModel>1</futureModel></Firewall></OPNsense></opnsense>'),
        'Unknown nested model silently ignored');
};
$cases['snapshot damage never overwrites config or discards evidence'] = function ($f) {
    $f->start();
    $f->save();
    $current = file_get_contents($f->path('config.xml'));
    file_put_contents($f->path('commit_rollback/snapshot.xml'), '<opnsense>');
    $f->store->change('revert');
    $f->tick();
    check(!$f->applied && $f->store->active(), 'Bad snapshot caused Apply/cleanup');
    check(file_get_contents($f->path('config.xml')) === $current, 'Bad snapshot overwrote config');
    rejected(fn() => $f->tick(true), 'Bad snapshot accepted at boot');
    $f->store->change('confirm');
    check(!$f->store->active(), 'Cannot acknowledge failed recovery');
};
$cases['state write failure occurs before destructive config write'] = function ($f) {
    $f->start();
    mkdir($f->path('commit_rollback_pending.json.tmp')); // deterministic I/O fault even when run as root
    rejected(fn() => $f->save(), 'Write continued after persistence failure');
    check(file_get_contents($f->path('config.xml')) === $f->baseline, 'Persistence failure damaged config');
    check(!$f->marker()['countdown_active'], 'Failed marker write was accepted');
    rmdir($f->path('commit_rollback_pending.json.tmp'));
    $f->save();
    check($f->marker()['countdown_active'], 'Failure leaked state lock');
};
$cases['missing DNSBL snapshot preserves recovery; initially absent DNSBL removed'] = function ($f) {
    $f->start();
    $changed = str_replace('</opnsense>', '<OPNsense><unboundplus><general><enabled>1</enabled>' .
        '</general></unboundplus></OPNsense></opnsense>', $f->baseline);
    $f->save($changed);
    unlink($f->path('commit_rollback/dnsbl.json'));
    $f->store->change('revert');
    $f->tick();
    check(!$f->applied && $f->store->active(), 'Missing DNSBL snapshot silently accepted');
    $f->store->change('confirm');
    unlink($f->path('dnsbl.json'));
    $f->start();
    $f->save($f->baseline);
    file_put_contents($f->path('dnsbl.json'), '{}');
    $f->store->change('revert');
    $f->tick();
    check(!is_file($f->path('dnsbl.json')) && !$f->store->active(), 'Session-created DNSBL retained');
};
$cases['corrupt marker fails closed and preserves snapshot'] = function ($f) {
    $f->start();
    file_put_contents($f->path('commit_rollback_pending.json'), '{');
    check($f->store->state()['active'], 'Corrupt marker treated as inactive');
    rejected(fn() => $f->save(), 'Save accepted corrupt marker');
    rejected(fn() => $f->store->change('confirm'), 'Corrupt marker deleted on confirm');
    rejected(fn() => $f->tick(true), 'Corrupt marker deleted at boot');
    check(is_file($f->path('commit_rollback/snapshot.xml')), 'Lost recovery evidence');
};
$cases['process crash after arming, before writing'] = function ($f) {
    $f->start();
    $pid = pcntl_fork();
    if ($pid === 0) {
        $handle = fopen($f->path('config.xml'), 'c+');
        flock($handle, LOCK_EX);
        $lease = $f->store->beginSave(str_replace('allow', 'deny', $f->baseline), true);
        ftruncate($handle, 0); // crash in the config write, before finishSave or backup
        fflush($handle);
        exit(0);
    }
    joinChild($pid);
    check($f->marker()['countdown_active'], 'Crash lost armed timer');
    $f->now += 60;
    $f->restart();
    $f->tick();
    check(file_get_contents($f->path('config.xml')) === $f->baseline, 'Truncated config not recovered');
    check($f->applied === [['filter']], 'Crash lost pre-write Apply plan');
};
$cases['confirm serializes with an in-flight save'] = function ($f) {
    $f->start();
    $handle = fopen($f->path('config.xml'), 'c+');
    flock($handle, LOCK_EX);
    $lease = $f->store->beginSave($f->baseline, true);
    $pid = pcntl_fork();
    if ($pid === 0) {
        fclose($lease);
        fclose($handle);
        file_put_contents($f->path('child-ready'), '1');
        $f->store->change('confirm');
        file_put_contents($f->path('child-confirmed'), '1');
        exit(0);
    }
    awaitFile($f->path('child-ready'));
    usleep(100000);
    check(!is_file($f->path('child-confirmed')), 'Confirm crossed active save lease');
    $f->store->finishSave($lease, 'config-123.00_99.xml');
    fclose($handle);
    joinChild($pid);
    $tags = json_decode(file_get_contents($f->path('backup/session_tags.json')), true);
    check($tags['config-123.00_99.xml'] === 'Confirmed', 'Concurrent confirmation missed actual backup');
};
$cases['restore lock order and confirmation during Apply'] = function ($f) {
    $f->start();
    $f->save();
    $f->store->change('revert');
    $handle = fopen($f->path('config.xml'), 'c+');
    flock($handle, LOCK_EX);
    $pid = pcntl_fork();
    if ($pid === 0) {
        fclose($handle);
        $f->store->tick(function () use ($f) {
            file_put_contents($f->path('applying'), '1');
            awaitFile($f->path('finish-apply'));
        });
        exit(0);
    }
    $deadline = microtime(true) + 5;
    while (!$f->store->state()['revert_running'] && microtime(true) < $deadline) {
        usleep(10000);
    }
    check($f->store->state()['revert_running'], 'Worker did not mark recovery before waiting for config');
    rejected(fn() => $f->store->beginSave($f->baseline, true), 'Writer blocked worker while holding config');
    fclose($handle);
    awaitFile($f->path('applying'));
    rejected(fn() => $f->store->change('confirm'), 'Confirm interrupted active Apply');
    file_put_contents($f->path('finish-apply'), '1');
    joinChild($pid);
    check(!$f->store->active(), 'Recovery did not complete after locks released');
};
$cases['worker crash during Apply replays persisted plan'] = function ($f) {
    $f->start();
    $f->save();
    $f->store->change('revert');
    $pid = pcntl_fork();
    if ($pid === 0) {
        $f->store->tick(function () { exit(0); });
        exit(1);
    }
    joinChild($pid);
    check($f->marker()['revert_running'] && $f->marker()['snapshot_restored'], 'Crash checkpoint lost');
    $f->restart();
    $f->tick();
    check($f->applied === [['filter']] && !$f->store->active(), 'Restart stuck on stale running flag');
};
$cases['concurrent starts have one winner'] = function ($f) {
    $children = [];
    for ($i = 0; $i < 2; $i++) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            try {
                $f->start();
                file_put_contents($f->path('start-' . $i), 'ok');
            } catch (RuntimeException $error) {
                file_put_contents($f->path('start-' . $i), 'busy');
            }
            exit(0);
        }
        $children[] = $pid;
    }
    foreach ($children as $pid) {
        joinChild($pid);
    }
    $results = [file_get_contents($f->path('start-0')), file_get_contents($f->path('start-1'))];
    sort($results);
    check($results === ['busy', 'ok'], 'Concurrent start overwrote snapshot');
};
$cases['worker liveness is an OS lock, not a stale PID'] = function ($f) {
    check(!$f->store->workerAlive(), 'Absent worker reported alive');
    $lock = fopen($f->path('run/commit_session_worker.lock'), 'c');
    flock($lock, LOCK_EX);
    check($f->store->workerAlive(), 'Live worker not recognized');
    fclose($lock);
    check(!$f->store->workerAlive(), 'Stale lock file reported alive');
};

$cases['DNSBL deletion restores cached data and selects only Blocklists Apply'] = function ($f) {
    $f->baseline = '<opnsense><OPNsense><unboundplus version="1.0.15"><general><enabled>1</enabled></general>' .
        '<dnsbl><blocklist uuid="demo"><enabled>1</enabled></blocklist></dnsbl></unboundplus></OPNsense></opnsense>';
    file_put_contents($f->path('config.xml'), $f->baseline);
    $f->start();
    $id = $f->marker()['session_id'];
    $f->save(str_replace('<blocklist uuid="demo"><enabled>1</enabled></blocklist>', '', $f->baseline));
    $f->store->apply('unbound.dnsbl', function () use ($f) {
        check($f->marker()['services'] === ['unbound.dnsbl' => true], 'DNSBL selected a broader Apply');
        file_put_contents($f->path('dnsbl.json'), '{"after":true}');
    });
    $f->store->change('revert');
    $f->store->tick(function ($operations) use ($f) {
        check($operations === ['unbound.dnsbl'], 'DNSBL rollback touched unrelated services');
        check(file_get_contents($f->path('config.xml')) === $f->baseline, 'Apply ran before restoring XML');
        check(file_get_contents($f->path('dnsbl.json')) === '{"before":true}', 'Apply ran before restoring DNSBL data');
    });
    check(!$f->store->active() && $f->store->state()['reverted_session_id'] === $id, 'Missing completed-session identity');
};
$cases['ordinary Unbound Apply and General coalescing'] = function ($f) {
    $f->baseline = '<opnsense><OPNsense><unboundplus><general><enabled>1</enabled></general>' .
        '<advanced><prefetch>0</prefetch></advanced></unboundplus></OPNsense></opnsense>';
    file_put_contents($f->path('config.xml'), $f->baseline);
    $f->start();
    $f->save(str_replace('<prefetch>0', '<prefetch>1', $f->baseline));
    check($f->marker()['services'] === ['unbound.reconfigure' => true], 'Advanced settings selected General Apply');
    $f->save(str_replace('<enabled>1', '<enabled>0', $f->baseline));
    $f->store->change('revert');
    $f->tick();
    check($f->applied === [['unbound.general']], 'Unbound was restarted twice');
};
$cases['standard services select shared reconfiguration operations'] = function ($f) {
    $f->start();
    $f->save(str_replace('</opnsense>', '<dnsmasq><enable>1</enable></dnsmasq><OPNsense>' .
        '<Kea><dhcp4><general><enabled>1</enabled></general></dhcp4></Kea>' .
        '<monit/><Syslog/><Hostwatch/></OPNsense></opnsense>', $f->baseline));
    $f->store->change('revert');
    $f->tick();
    check($f->applied === [['dnsmasq.reconfigure', 'kea.reconfigure', 'syslog.reconfigure',
        'monit.reconfigure', 'hostwatch.reconfigure']], 'Standard services lost their Apply operations');
};
$cases['Apply without Save is journaled; failures retain the operation'] = function ($f) {
    $f->start();
    rejected(fn() => $f->store->apply('unbound.dnsbl', function () use ($f) {
        check(isset($f->marker()['services']['unbound.dnsbl']), 'Apply was not durable before execution');
        file_put_contents($f->path('dnsbl.json'), '{"partial":true}');
        throw new RuntimeException('partial Apply');
    }), 'Apply failure was hidden');
    check($f->marker()['countdown_active'] && $f->marker()['saves_count'] === 0, 'Apply did not arm recovery');
    rejected(fn() => $f->store->apply('unknown', fn() => check(false, 'Unsupported Apply ran')),
        'Unknown Apply accepted');
    $f->now += 60;
    rejected(fn() => $f->store->apply('filter', fn() => check(false, 'Expired Apply ran')), 'Expired Apply accepted');
    $f->tick();
    check($f->applied === [['unbound.dnsbl']], 'Partial Apply lost recovery');
    check(file_get_contents($f->path('dnsbl.json')) === '{"before":true}', 'Apply-only DNSBL change not restored');
};
$cases['recovery and confirmation cannot cross an in-flight GUI Apply'] = function ($f) {
    $f->start();
    $f->save();
    $pid = pcntl_fork();
    if ($pid === 0) {
        $f->store->apply('filter', function () use ($f) {
            file_put_contents($f->path('applying'), '1');
            awaitFile($f->path('finish-apply'));
        });
        exit(0);
    }
    awaitFile($f->path('applying'));
    rejected(fn() => $f->store->change('confirm'), 'Confirmed while Apply still ran');
    $f->store->change('revert');
    $f->tick();
    check(!$f->applied && !isset($f->marker()['snapshot_restored']), 'Recovery crossed GUI Apply');
    file_put_contents($f->path('finish-apply'), '1');
    joinChild($pid);
    $f->tick();
    check($f->applied === [['filter']] && !$f->store->active(), 'Recovery did not resume after GUI Apply');
};
$cases['old retry deadline does not stall a new boot'] = function ($f) {
    $f->start();
    $f->save();
    $f->store->change('revert');
    $f->store->tick(fn() => throw new RuntimeException('Apply failed'));
    $f->now = 5;
    $f->restart();
    check($f->store->state()['next_retry_seconds'] === 0, 'Old uptime delayed recovery');
    $f->tick();
    check(!$f->store->active() && $f->applied === [['filter']], 'Worker did not recover in new boot');
};
$cases['legacy reboot marker retains recovery without scheduling another reboot'] = function ($f) {
    $f->start();
    $state = $f->marker();
    $state['services'] = ['unbound' => true];
    $state['reboot'] = true;
    $state['reboot_requested'] = true;
    file_put_contents($f->path('commit_rollback_pending.json'), json_encode($state));
    $f->store->change('revert');
    $f->tick();
    check($f->applied === [['unbound.general', 'unbound.dnsbl']], 'Legacy Apply plan lost');
    check($f->store->active() && !isset($f->marker()['reboot_requested']), 'Legacy session claimed recovery or requested reboot');
    $f->tick(true);
    check(!$f->store->active(), 'Legacy session could not recover at boot');
};

$cases['real Config save and restore hooks use the durable lease'] = function ($f) {
    // Only AppConfig's environment lookup is replaced. Exercise the real writer and session facade.
    if (!class_exists('SessionTestAppConfig', false)) {
        class SessionTestAppConfig
        {
            public static string $directory;
            public object $application;
            public object $globals;
            public function __construct()
            {
                $this->application = (object)['configDir' => self::$directory];
                $this->globals = (object)['owner' => ''];
            }
        }
        class_alias(SessionTestAppConfig::class, 'OPNsense\\Core\\AppConfig');
        foreach (['Singleton', 'ConfigException', 'File', 'Syslog', 'Config', 'CommitSession'] as $class) {
            require_once __DIR__ . '/../app/library/OPNsense/Core/' . $class . '.php';
        }
    }
    SessionTestAppConfig::$directory = $f->directory;
    $_SESSION['Username'] = 'demo';
    $config = \OPNsense\Core\Config::getInstance();
    $session = \OPNsense\Core\CommitSession::getInstance();
    // A detached worker must not inherit Core's config descriptor and keep its flock alive.
    $probe = '$target = stat($argv[1]); for ($fd = 3; $fd < 64; $fd++) {' .
        '$handle = @fopen("php://fd/" . $fd, "r"); if (!$handle) {continue;}' .
        '$stat = fstat($handle); fclose($handle);' .
        'if ($stat["ino"] === $target["ino"] && $stat["dev"] === $target["dev"]) {exit(1);}}';
    exec(escapeshellarg(PHP_BINARY) . ' -n -r ' . escapeshellarg($probe) . ' -- ' .
        escapeshellarg($f->path('config.xml')), $output, $exit);
    check($exit === 0, 'Config descriptor survived exec into detached worker');
    (new ReflectionProperty($session, 'store'))->setValue($session, $f->store);
    $worker = fopen($f->path('run/commit_session_worker.lock'), 'c');
    flock($worker, LOCK_EX);
    try {
        $f->start();
        $config->object()->filter->rule = 'deny';
        $config->save(['username' => 'demo']);
        $saved = simplexml_load_file($f->path('config.xml'));
        check((string)$saved->filter->rule === 'deny', 'Actual Config::save did not write');
        check((string)$saved->revision->session_tag === 'Pending', 'Config writer lost history tag');
        check($f->marker()['saves_count'] === 1 && count($f->marker()['revisions']) === 1, 'Config hook not armed/recorded');
        check(is_file($f->path('backup/' . $f->marker()['revisions'][0])), 'Recorded backup name does not exist');
        $before = file_get_contents($f->path('config.xml'));
        mkdir($f->path('commit_rollback_pending.json.tmp'));
        $config->object()->filter->rule = 'reject';
        rejected(fn() => $config->save(['username' => 'demo']), 'Real writer ignored state persistence failure');
        check(file_get_contents($f->path('config.xml')) === $before, 'Real writer truncated before durable arm');
        rmdir($f->path('commit_rollback_pending.json.tmp'));
        $config->save(['username' => 'demo'], false);
        check($f->marker()['saves_count'] === 2, 'Backup-free save lost countdown');
        $f->store->change('revert');
        rejected(fn() => $config->save(['username' => 'demo']), 'Actual writer accepted write during revert');
        rejected(fn() => $config->restoreBackup($f->path('commit_rollback/snapshot.xml')), 'Restore bypassed protection');
        $f->tick();
        check(file_get_contents($f->path('config.xml')) === $f->baseline, 'Actual save not recovered');
    } finally {
        fclose($worker);
    }
};

foreach ($cases as $name => $case) {
    $fixture = new SessionFixture();
    try {
        $case($fixture);
        echo 'PASS ' . $name . "\n";
    } finally {
        $fixture->remove();
    }
}
printf("%d scenarios, %d checks passed\n", count($cases), $checks);
