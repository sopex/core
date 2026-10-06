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

function appid_services()
{
    $services = [];

    if (!(new \OPNsense\AppId\AppId())->general->enabled->isEmpty()) {
        $services[] = [
            'description' => gettext('Application Control'),
            'pidfile' => \OPNsense\AppId\AppId::PIDFILE,
            'configd' => [
                'restart' => ['appid restart'],
                'start' => ['appid start'],
                'stop' => ['appid stop'],
            ],
            'name' => 'appidd',
        ];
    }

    return $services;
}

function appid_syslog()
{
    return ['appidd' => ['facility' => ['appidd']]];
}

function appid_xmlrpc_sync()
{
    $result = [];

    $result['id'] = 'appid';
    $result['section'] = 'OPNsense.AppId';
    $result['description'] = gettext('Application Control');
    $result['services'] = ['appidd'];

    return [$result];
}

function appid_run()
{
    return [
        'filter_loaded' => 'appid_filter_loaded',
    ];
}

/**
 * Write the appidd rule policy (divert port to rule mapping) when its content changed.
 * @return bool true when the policy file was (re)written
 */
function appid_policy_write()
{
    $policy = ['rules' => []];

    foreach ((new \OPNsense\AppId\AppId())->getRulePolicy() as $uuid => $entry) {
        $policy['rules'][] = array_merge(['uuid' => $uuid], $entry);
    }

    $filename = \OPNsense\AppId\AppId::POLICY_FILE;
    $content = json_encode($policy, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

    if (is_file($filename) && file_get_contents($filename) === $content) {
        return false;
    }

    @mkdir(dirname($filename), 0755, true);
    /* write and move to prevent the daemon from reading a partial policy */
    file_put_contents("{$filename}.tmp", $content);
    chmod("{$filename}.tmp", 0644);
    rename("{$filename}.tmp", $filename);

    return true;
}

/**
 * The firewall ruleset has been (re)loaded, offer the matching rule policy to appidd.
 */
function appid_filter_loaded()
{
    if ((new \OPNsense\AppId\AppId())->general->enabled->isEmpty()) {
        return;
    }

    if (appid_policy_write() && isvalidpid(\OPNsense\AppId\AppId::PIDFILE)) {
        killbypid(\OPNsense\AppId\AppId::PIDFILE, 'HUP');
    }
}
