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
 * before the timer expires, the baseline configuration is restored and applied again
 * (see protect_revert()) so the running state follows the restored configuration.
 *
 * usage: config_protect.php status|enable|disable|confirm|extend|revert|check|watch
 */

require_once('script/load_phalcon.php');

use OPNsense\Core\AppConfig;
use OPNsense\Core\Backend;
use OPNsense\Core\Config;
use OPNsense\Core\File;

const PROTECT_STATE = '/conf/config_protect.json';
const PROTECT_BASELINE = '/conf/config_protect.xml';
const PROTECT_JOURNAL = '/conf/config_protect.journal';
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
 * read a config file under the lock Config::save() writes it with, so a save in progress is never seen half written
 * @param string $filename config file
 * @return string|null file content when it contains a valid configuration
 */
function protect_read_config($filename)
{
    $fhandle = @fopen($filename, 'r');
    if ($fhandle === false) {
        return null;
    }
    flock($fhandle, LOCK_SH);
    $content = stream_get_contents($fhandle);
    flock($fhandle, LOCK_UN);
    fclose($fhandle);
    return protect_parse_config($content) !== null ? $content : null;
}

/**
 * @param string|null $content config content
 * @return SimpleXMLElement|null parsed configuration
 */
function protect_parse_config($content)
{
    if ($content === null) {
        return null;
    }
    $xml = @simplexml_load_string($content, 'SimpleXMLElement', LIBXML_NOERROR | LIBXML_ERR_NONE | LIBXML_NOBLANKS);
    return $xml !== false && $xml->getName() == 'opnsense' ? $xml : null;
}

/**
 * @param SimpleXMLElement $xml parsed configuration (revision is removed)
 * @return string config content without revision information
 */
function protect_config_content($xml)
{
    unset($xml->revision);
    return $xml->asXML();
}

/**
 * accept the current configuration as the one to return to, apply actions recorded
 * (by OPNsense\Core\Backend) from here on are replayed on revert
 * @return bool baseline written
 */
function protect_set_baseline()
{
    $content = protect_read_config('/conf/config.xml');
    $tmp_filename = PROTECT_BASELINE . '.tmp';
    if (
        $content === null || File::file_put_contents($tmp_filename, $content, 0640) === false ||
        !rename($tmp_filename, PROTECT_BASELINE)
    ) {
        syslog(LOG_ERR, 'config protection: unable to store the current configuration as baseline');
        return false;
    }
    /* the GUI may run as non-root, it needs to be able to append to the journal */
    File::file_put_contents(PROTECT_JOURNAL, '', 0640, 0, (new AppConfig())->globals->owner);
    return true;
}

/**
 * spawn the watcher in the background, it exits by itself when another one is running
 */
function protect_spawn_watcher()
{
    exec('/usr/sbin/daemon -f ' . escapeshellarg(__FILE__) . ' watch');
}

/**
 * restore the baseline configuration and apply it: regenerate all templates, press the
 * apply buttons used since the baseline again (they may do more than a service reload,
 * e.g. rebuild Unbound blocklists) and finally reload all services for legacy pages
 * which apply without configd.
 * @return bool configuration restored and all actions succeeded
 */
function protect_revert()
{
    syslog(LOG_WARNING, 'config protection: reverting unconfirmed changes');
    $cnf = Config::getInstance();
    if (!$cnf->restoreBackup(PROTECT_BASELINE)) {
        syslog(LOG_ERR, 'config protection: unable to restore ' . PROTECT_BASELINE);
        return false;
    }
    $cnf->save(['description' => 'config protection: reverted unconfirmed changes']);

    $applied = @file(PROTECT_JOURNAL, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    if (file_exists(PROTECT_JOURNAL)) {
        file_put_contents(PROTECT_JOURNAL, '');
    }

    $backend = new Backend();
    $success = true;
    /* configd reports failed scripts as "Error (n)", the fixed actions below always return output */
    $run = function ($event, $timeout = 120, $require_output = false) use ($backend, &$success) {
        $response = trim($backend->configdRun($event, false, $timeout));
        if (strpos($response, 'Error') === 0 || ($require_output && $response === '')) {
            syslog(LOG_ERR, "config protection: '{$event}' failed: {$response}");
            $success = false;
        }
    };

    /* actions below use the generated files, wait for them to be written */
    $run('template reload *', 120, true);
    foreach (array_unique($applied) as $line) {
        /* the journal is writable by the GUI, only replay what it is allowed to record */
        $event = Backend::protectJournalEvent($line);
        if ($event === null) {
            syslog(LOG_WARNING, "config protection: ignoring unexpected journal entry '{$line}'");
            continue;
        }
        syslog(LOG_NOTICE, "config protection: replaying '{$event}'");
        $run($event);
    }
    /* wait for the reload to finish, the GUI reloads the page when the revert is reported as done */
    $run('service reload all', 900, true);
    syslog($success ? LOG_NOTICE : LOG_ERR, 'config protection: revert finished' . ($success ? '' : ' with errors'));
    return $success;
}

openlog('config', LOG_ODELAY, LOG_USER);

$action = $argv[1] ?? 'status';

switch ($action) {
    case 'enable':
        protect_update(function ($state) {
            return protect_set_baseline() ? ['enabled' => true, 'deadline' => null] : $state;
        });
        break;
    case 'disable':
        protect_update(function ($state) {
            @unlink(PROTECT_BASELINE);
            @unlink(PROTECT_JOURNAL);
            return ['enabled' => false, 'deadline' => null];
        });
        break;
    case 'confirm':
        protect_update(function ($state) {
            if ($state['enabled'] && protect_set_baseline()) {
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
    case 'revert':
        /* expire the timer now, the watcher performs the revert */
        $state = protect_update(function ($state) {
            if ($state['enabled'] && $state['deadline'] !== null) {
                $state['deadline'] = time();
            }
            return $state;
        });
        if ($state['deadline'] !== null) {
            protect_spawn_watcher();
        }
        break;
    case 'check':
        /* called on config change (and boot), start the timer when the config deviates from the baseline */
        $state = protect_update(function ($state) {
            if (!$state['enabled'] || $state['deadline'] !== null) {
                return $state;
            }
            $baseline = protect_parse_config(protect_read_config(PROTECT_BASELINE));
            $current = protect_parse_config(protect_read_config('/conf/config.xml'));
            if ($baseline === null || $current === null) {
                return $state;
            }
            /* GUI and API users are registered as "user@address", scripts and cron jobs as "(user)" */
            $username = (string)$current->revision->username;
            if (protect_config_content($baseline) === protect_config_content($current)) {
                return $state;
            } elseif (strpos($username, '(') === 0) {
                syslog(LOG_NOTICE, "config protection: accepting automatic change by {$username}");
                protect_set_baseline();
            } else {
                $state['deadline'] = time() + PROTECT_TIMEOUT;
                syslog(LOG_NOTICE, 'config protection: unconfirmed changes, reverting in ' . PROTECT_TIMEOUT . ' seconds');
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
                    $state['reverting'] = true;
                    $revert = true;
                }
                return $state;
            });
            if ($revert) {
                $success = protect_revert();
                /* changes saved while reverting started a new timer, the loop keeps watching it */
                $state = protect_update(function ($state) use ($success) {
                    $state['reverting'] = false;
                    $state['reverted'] = time();
                    $state['revert_failed'] = !$success;
                    return $state;
                });
            } elseif ($state['enabled'] && $state['deadline'] !== null) {
                sleep(max(1, min(5, $state['deadline'] - time())));
            }
        } while ($state['enabled'] && $state['deadline'] !== null);
        /* a timer started while exiting may have found the lock still taken, hand over */
        flock($lock, LOCK_UN);
        $state = protect_read();
        if ($state['enabled'] && $state['deadline'] !== null) {
            protect_spawn_watcher();
        }
        exit(0);
    case 'status':
        break;
    default:
        echo "usage: config_protect.php status|enable|disable|confirm|extend|revert|check|watch\n";
        exit(1);
}

$state = protect_read();
echo json_encode([
    'enabled' => $state['enabled'],
    'pending' => $state['deadline'] !== null,
    'remaining' => $state['deadline'] !== null ? max(0, $state['deadline'] - time()) : null,
    'reverting' => !empty($state['reverting']),
    'timeout' => PROTECT_TIMEOUT,
    'reverted' => $state['reverted'] ?? null,
    'revert_failed' => !empty($state['revert_failed']),
]) . PHP_EOL;
