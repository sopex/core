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

class ApplyTestBackend extends Backend
{
    public array $commands = [];
    public ?string $failAt = null;
    public string $failure = '';

    public function configdRun($event, $detach = false, $timeout = 120, $connect_timeout = 10)
    {
        $this->commands[] = $event;
        if ($event === $this->failAt) {
            return $this->failure;
        }
        return $event === '!interface list assign-opts' ? '{}' : "OK\n";
    }
}

function checkApply(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$cases = [
    'wireguard' => [[], [
        'interface invoke registration', '!interface list assign-opts',
        'template reload OPNsense/Wireguard', 'wireguard configure'
    ]],
    'unbound' => [[true], ['dns reload', 'unbound stop', 'unbound start', 'dhcpd restart']],
    'firewall' => [[true], ['filter reload']],
];
$checks = 0;
foreach ($cases as $method => [$arguments, $expected]) {
    $backend = new ApplyTestBackend();
    (new ServiceApply($backend))->$method(...$arguments);
    checkApply($backend->commands === $expected, $method . ': wrong Apply order');
    $checks++;
    // A transport failure or failed action must stop the sequence before later services run.
    foreach ($expected as $index => $action) {
        if ($action[0] === '!') {
            continue; // cache refresh returns data, not an exit status
        }
        foreach (['', 'FAILED'] as $failure) {
            $backend = new ApplyTestBackend();
            $backend->failAt = $action;
            $backend->failure = $failure;
            $thrown = false;
            try {
                (new ServiceApply($backend))->$method(...$arguments);
            } catch (RuntimeException $e) {
                $thrown = true;
                checkApply(str_contains($e->getMessage(), $action), 'Failure omitted action name');
            }
            checkApply($thrown, $method . ': failure was swallowed');
            checkApply($backend->commands === array_slice($expected, 0, $index + 1), 'Continued after failure');
            $checks++;
        }
    }
}
$backend = new ApplyTestBackend();
(new ServiceApply($backend))->unbound(false);
checkApply(!in_array('unbound start', $backend->commands), 'Disabled Unbound was started');
checkApply(end($backend->commands) === 'dhcpd restart', 'Resolver dependencies were skipped');
$checks++;
$backend = new ApplyTestBackend();
(new ServiceApply($backend))->firewall();
checkApply($backend->commands === ['filter reload skip_alias'], 'GUI rule Apply behavior changed');
$checks++;
printf("%d shared Apply checks passed\n", $checks);
