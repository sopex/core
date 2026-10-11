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

require_once('script/load_phalcon.php');

use OPNsense\Core\ServiceApply;
use OPNsense\Unbound\Unbound;

try {
    // The parent worker owns the Apply lock and has already restored the snapshot.
    $apply = new ServiceApply(null, fn($operation, $run) => $run());
    $standard = [
        'unbound.reconfigure' => ['OPNsense\Unbound\Unbound', 'general.enabled', null, true],
        'dnsmasq.reconfigure' => ['OPNsense\Dnsmasq\Dnsmasq', 'enable', null, true],
        'kea.reconfigure' => ['OPNsense\Kea\KeaDhcpv4', 'general.enabled', 'OPNsense/Kea', true],
        'syslog.reconfigure' => ['OPNsense\Syslog\Syslog', 'general.enabled', 'OPNsense/Syslog', false],
        'monit.reconfigure' => ['OPNsense\Monit\Monit', 'general.enabled', 'OPNsense/Monit', false],
        'hostwatch.reconfigure' => ['OPNsense\Hostdiscovery\Hostwatch', 'general.enabled', 'OPNsense/Hostdiscovery', true]
    ];
    $dispatch = function (string $service) use ($apply, $standard): void {
        if (isset($standard[$service])) {
            [$class, $enabledField, $template, $restart] = $standard[$service];
            $enabled = (string)(new $class())->getNodeByReference($enabledField) === '1';
            if ($service === 'kea.reconfigure') {
                $enabled = $enabled || (string)(new \OPNsense\Kea\KeaDhcpv6())->general->enabled === '1';
            }
            $apply->reconfigure(explode('.', $service)[0], $enabled, $template, $restart,
                null, null, $service === 'dnsmasq.reconfigure');
            return;
        }
        switch ($service) {
            case 'wireguard':
                $apply->wireguard();
                break;
            case 'unbound.general':
                $apply->unbound((string)(new Unbound())->general->enabled === '1');
                break;
            case 'unbound.dnsbl':
                $apply->unboundDnsbl((string)(new Unbound())->general->enabled === '1', true);
                break;
            case 'filter':
                $apply->firewall(true);
                break;
            default:
                throw new \InvalidArgumentException('Unsupported Apply operation: ' . $service);
        }
    };
    $operations = array_slice($argv, 1);
    $unbound = array_values(array_intersect(['unbound.general', 'unbound.reconfigure'], $operations));
    if ($unbound && in_array('dnsmasq.reconfigure', $operations, true) &&
        (string)(new Unbound())->general->enabled !== '1') {
        // Release DNS port 53 before restoring Dnsmasq when the snapshot disables Unbound.
        $operations = array_values(array_diff($operations, $unbound));
        array_splice($operations, array_search('dnsmasq.reconfigure', $operations, true), 0, $unbound);
    }
    $errors = [];
    foreach ($operations as $operation) {
        try {
            $dispatch($operation);
        } catch (\Throwable $error) {
            // A failed DNS or VPN action must not prevent restoring the firewall rules too.
            $errors[] = $operation . ': ' . $error->getMessage();
        }
    }
    if ($errors) {
        throw new \RuntimeException(implode("\n", $errors));
    }
} catch (\Throwable $e) {
    openlog('commit-session', LOG_PID, LOG_AUTH);
    syslog(LOG_ERR, $e->getMessage());
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
