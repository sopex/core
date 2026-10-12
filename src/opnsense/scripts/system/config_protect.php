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

/*
 * Configuration protection: when enabled, the first saved change compared to the
 * accepted (baseline) configuration starts a timer. Unless the changes are confirmed
 * before the timer expires, the baseline configuration is restored and all services
 * are reloaded so the running state follows the restored configuration.
 *
 * usage: config_protect.php status|enable|disable|confirm|extend|check|watch
 */

require_once('script/load_phalcon.php');

use OPNsense\Core\Backend;
use OPNsense\Core\Config;

const PROTECT_STATE = '/conf/config_protect.json';
const PROTECT_BASELINE = '/conf/config_protect.xml';
const PROTECT_WATCH_LOCK = '/var/run/config_protect.lock';
const PROTECT_TIMEOUT = 600;

/**
 * @param resource|false $fhandle locked state file handle
 * @return array state
 */
function protect_parse($fhandle)
{
    $state = $fhandle !== false ? json_decode(stream_get_contents($fhandle), true) : null;
    return array_merge(['enabled' => false, 'deadline' => null], is_array($state) ? $state : []);
}

/**
 * @return array current state
 */
function protect_read()
{
    $fhandle = @fopen(PROTECT_STATE, 'r');
    if ($fhandle !== false) {
        flock($fhandle, LOCK_SH);
    }
    $state = protect_parse($fhandle);
    if ($fhandle !== false) {
        fclose($fhandle);
    }
    return $state;
}

/**
 * run $callback with the (exclusively locked) state, persist the state it returns
 * @param callable $callback function (array $state): array
 * @return array new state
 */
function protect_update($callback)
{
    $fhandle = fopen(PROTECT_STATE, 'c+');
    flock($fhandle, LOCK_EX);
    $state = $callback(protect_parse($fhandle));
    ftruncate($fhandle, 0);
    rewind($fhandle);
    fwrite($fhandle, json_encode($state));
    fflush($fhandle);
    flock($fhandle, LOCK_UN);
    fclose($fhandle);
    chmod(PROTECT_STATE, 0640);
    return $state;
}

/**
 * @param string $filename config file
 * @return string|null config content without revision information
 */
function protect_config_content($filename)
{
    $xml = @simplexml_load_file($filename, 'SimpleXMLElement', LIBXML_NOERROR | LIBXML_ERR_NONE | LIBXML_NOBLANKS);
    if ($xml === false) {
        return null;
    }
    unset($xml->revision);
    return $xml->asXML();
}

/**
 * accept the current configuration as the one to return to
 */
function protect_set_baseline()
{
    copy('/conf/config.xml', PROTECT_BASELINE);
    chmod(PROTECT_BASELINE, 0640);
}

/**
 * spawn the watcher in the background, it exits by itself when another one is running
 */
function protect_spawn_watcher()
{
    exec('/usr/sbin/daemon -f ' . escapeshellarg(__FILE__) . ' watch');
}

/**
 * restore the baseline configuration and reload all services to apply it
 */
function protect_revert()
{
    syslog(LOG_WARNING, 'config protection: changes not confirmed in time, reverting configuration');
    $cnf = Config::getInstance();
    if (!$cnf->restoreBackup(PROTECT_BASELINE)) {
        syslog(LOG_ERR, 'config protection: unable to restore ' . PROTECT_BASELINE);
        return;
    }
    $cnf->save(['description' => 'config protection: reverted unconfirmed changes']);
    (new Backend())->configdRun('service reload all', true);
}

openlog('config', LOG_ODELAY, LOG_USER);

$action = $argv[1] ?? 'status';

switch ($action) {
    case 'enable':
        protect_update(function ($state) {
            protect_set_baseline();
            return ['enabled' => true, 'deadline' => null];
        });
        break;
    case 'disable':
        protect_update(function ($state) {
            @unlink(PROTECT_BASELINE);
            return ['enabled' => false, 'deadline' => null];
        });
        break;
    case 'confirm':
        protect_update(function ($state) {
            if ($state['enabled']) {
                protect_set_baseline();
                $state['deadline'] = null;
            }
            return $state;
        });
        break;
    case 'extend':
        protect_update(function ($state) {
            if ($state['enabled'] && $state['deadline'] !== null) {
                $state['deadline'] = max($state['deadline'], time()) + PROTECT_TIMEOUT;
            }
            return $state;
        });
        break;
    case 'check':
        /* called on config change (and boot), start the timer when the config deviates from the baseline */
        $state = protect_update(function ($state) {
            if ($state['enabled'] && $state['deadline'] === null && is_file(PROTECT_BASELINE)) {
                $baseline = protect_config_content(PROTECT_BASELINE);
                if ($baseline !== null && $baseline !== protect_config_content('/conf/config.xml')) {
                    $state['deadline'] = time() + PROTECT_TIMEOUT;
                    syslog(LOG_NOTICE, 'config protection: unconfirmed changes, reverting in ' . PROTECT_TIMEOUT . ' seconds');
                }
            }
            return $state;
        });
        if ($state['deadline'] !== null) {
            protect_spawn_watcher();
        }
        break;
    case 'watch':
        $lock = fopen(PROTECT_WATCH_LOCK, 'w+');
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            /* already watching */
            exit(0);
        }
        do {
            $revert = false;
            $state = protect_update(function ($state) use (&$revert) {
                if ($state['enabled'] && $state['deadline'] !== null && $state['deadline'] <= time()) {
                    $state['deadline'] = null;
                    $state['reverted'] = time();
                    $revert = true;
                }
                return $state;
            });
            if ($revert) {
                protect_revert();
            } elseif ($state['enabled'] && $state['deadline'] !== null) {
                sleep(max(1, min(5, $state['deadline'] - time())));
            }
        } while (!$revert && $state['enabled'] && $state['deadline'] !== null);
        exit(0);
    case 'status':
        break;
    default:
        echo "usage: config_protect.php status|enable|disable|confirm|extend|check|watch\n";
        exit(1);
}

$state = protect_read();
echo json_encode([
    'enabled' => $state['enabled'],
    'pending' => $state['deadline'] !== null,
    'remaining' => $state['deadline'] !== null ? max(0, $state['deadline'] - time()) : null,
    'timeout' => PROTECT_TIMEOUT,
    'reverted' => $state['reverted'] ?? null,
]) . PHP_EOL;
