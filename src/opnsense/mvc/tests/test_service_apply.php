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

// Standalone regression test: php test_service_apply.php (no running configd or Phalcon required).
require_once __DIR__ . '/../app/library/OPNsense/Core/Backend.php';
require_once __DIR__ . '/../app/library/OPNsense/Core/ServiceApply.php';

use OPNsense\Core\Backend;
use OPNsense\Core\ServiceApply;

// Model actual configd action contracts, including stdout-returning actions.
class ApplyTestBackend extends Backend
{
    public array $commands = [];
    public ?string $failAt = null;
    public string $failure = '';
    public array $responses = [];
    private array $types = [];

    public function __construct()
    {
        foreach (glob(__DIR__ . '/../../service/conf/actions.d/actions_*.conf') as $file) {
            $topic = substr(basename($file), 8, -5);
            foreach (preg_split('/(?=^\[)/m', file_get_contents($file)) as $section) {
                if (preg_match('/^\[([^]]+)\]/', $section, $name) && preg_match('/^type:(.+)$/m', $section, $type)) {
                    $this->types[$topic . ' ' . str_replace('.', ' ', $name[1])] = trim($type[1]);
                }
            }
        }
    }

    public function configdRun($event, $detach = false, $timeout = 120, $connect_timeout = 10)
    {
        $event = str_replace("'", '', $event);
        $this->commands[] = $event;
        if ($event === $this->failAt) {
            return $this->failure;
        }
        if (isset($this->responses[$event])) {
            return $this->responses[$event];
        }
        $action = ltrim($event, '!');
        while (!isset($this->types[$action]) && str_contains($action, ' ')) {
            $action = substr($action, 0, strrpos($action, ' '));
        }
        if (!isset($this->types[$action])) {
            throw new RuntimeException('Unregistered configd action: ' . $event);
        }
        if ($this->types[$action] === 'script' || ($action === 'template reload' && $this->types[$action] === 'inline')) {
            return "OK\n";
        }
        if (str_ends_with($action, ' status')) {
            return explode(' ', $action)[0] . ' is running as pid 123.';
        }
        return $action === 'interface list assign-opts' ? '{}' : "Generating /etc/resolv.conf...done.\n";
    }
}

$checks = 0;
function checkApply(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function serviceApply(ApplyTestBackend $backend): ServiceApply
{
    return new ServiceApply($backend, fn($operation, $run) => $run());
}

$cases = [
    ['routing', [], ['interface routes configure']],
    ['wireguard', [], [
        'interface invoke registration', '!interface list assign-opts',
        'template reload OPNsense/Wireguard', 'wireguard configure'
    ]],
    ['unbound', [true], ['dns reload_status', 'unbound stop', 'unbound start']],
    ['unboundDnsbl', [true], ['template reload OPNsense/Unbound/*', 'unbound dnsbl_update', 'unbound dnsbl_reload']],
    ['unboundDnsbl', [true, true], ['template reload OPNsense/Unbound/*', 'unbound dnsbl_reload']],
    ['unboundDnsbl', [false, true], ['template reload OPNsense/Unbound/*']],
    ['reconfigure', ['unbound', true], ['unbound stop', 'unbound start']],
    ['reconfigure', ['monit', true, 'OPNsense/Monit', false],
        ['template reload OPNsense/Monit', 'monit status', 'monit reload']],
    ['reconfigure', ['kea', false, 'OPNsense/Kea'], ['kea stop', 'template reload OPNsense/Kea']],
    ['reconfigure', ['dnsmasq', true, null, true, null, null, true],
        ['dnsmasq stop', 'filter reload skip_alias', 'dnsmasq start']],
    ['firewall', [true], ['filter reload']],
];
foreach ($cases as [$method, $arguments, $expected]) {
    $backend = new ApplyTestBackend();
    serviceApply($backend)->$method(...$arguments);
    checkApply($backend->commands === $expected, $method . ': wrong Apply operation');
    foreach ($expected as $index => $action) {
        if ($action[0] === '!' || str_ends_with($action, ' status')) {
            continue; // these return data; their contract is not OK
        }
        foreach (['', 'Error (1)'] as $failure) {
            $backend = new ApplyTestBackend();
            $backend->failAt = $action;
            $backend->failure = $failure;
            $thrown = false;
            try {
                serviceApply($backend)->$method(...$arguments);
            } catch (RuntimeException $e) {
                $thrown = true;
                checkApply(str_contains(str_replace("'", '', $e->getMessage()), $action), 'Failure omitted action name');
            }
            checkApply($thrown, $method . ': failure was swallowed');
            $failedCommands = array_slice($expected, 0, $index + 1);
            if (str_ends_with($action, ' stop')) {
                $failedCommands[] = substr($action, 0, -5) . ' status';
            }
            checkApply($backend->commands === $failedCommands, 'Continued after failure');
        }
    }
}
$backend = new ApplyTestBackend();
serviceApply($backend)->unbound(false);
checkApply(!in_array('unbound start', $backend->commands), 'Disabled Unbound was started');
checkApply(!in_array('dhcpd restart', $backend->commands), 'Required an absent optional ISC DHCP action');
$backend = new ApplyTestBackend();
serviceApply($backend)->firewall();
checkApply($backend->commands === ['filter reload skip_alias'], 'GUI rule Apply behavior changed');

foreach ([false, true] as $enabled) {
    $backend = new ApplyTestBackend();
    $backend->responses = ['dnsmasq stop' => 'Error (1)', 'dnsmasq status' => 'dnsmasq is not running.'];
    serviceApply($backend)->reconfigure('dnsmasq', $enabled);
    checkApply(in_array('dnsmasq start', $backend->commands) === $enabled, 'Already-stopped service could not be restored');
}

$backend = new ApplyTestBackend();
$guarded = [];
$apply = new ServiceApply($backend, function ($operation, $run) use ($backend, &$guarded) {
    checkApply(!$backend->commands, 'Runtime modified before the session guard');
    $guarded[] = $operation;
    return $run();
});
checkApply($apply->unboundDnsbl(true, true) === 'OK', 'DNSBL API response changed');
checkApply($guarded === ['unbound.dnsbl'], 'DNSBL operation journaled as General');
$backend = new ApplyTestBackend();
$guarded = [];
$apply = new ServiceApply($backend, function ($operation, $run) use ($backend, &$guarded) {
    checkApply(!$backend->commands, 'Routing applied before the session guard');
    $guarded[] = $operation;
    return $run();
});
checkApply($apply->routing() === 'OK', 'Routing Apply did not return its action status');
checkApply($guarded === ['routing'], 'Routing Apply was not journaled');
$backend = new ApplyTestBackend();
$apply = new ServiceApply($backend, fn() => throw new RuntimeException('rollback due'));
foreach (['unboundDnsbl', 'routing'] as $method) {
    $thrown = false;
    try {
        $apply->$method(...($method === 'unboundDnsbl' ? [true] : []));
    } catch (RuntimeException $error) {
        $thrown = true;
    }
    checkApply($thrown && !$backend->commands, 'Rejected Apply still changed runtime state');
}
printf("%d shared Apply checks passed\n", $checks);
