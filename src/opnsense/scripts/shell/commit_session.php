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

require_once("config.inc");
require_once("util.inc");

use OPNsense\Core\CommitSession;

$cs = CommitSession::getInstance();
$fp = fopen('php://stdin', 'r');

echo "\n------------------------------------------------------------\n";
echo " Protected Change Session (Commit-Confirmed Rollback)\n";
echo "------------------------------------------------------------\n";

if (!$cs->isActive()) {
    echo "No protected change session is currently active.\n\n";
    echo "A protected session captures a snapshot of the current configuration.\n";
    echo "Any configuration save made while the session is open will trigger an\n";
    echo "automatic rollback countdown. If not confirmed, the snapshot is restored\n";
    echo "automatically.\n\n";
    echo "Do you want to start a protected change session? [y/N]: ";
    $ans = trim(fgets($fp));
    if (strcasecmp($ans, 'y') === 0) {
        $result = $cs->start('root@console', 'console');
        if (!empty($result['status']) && $result['status'] === 'ok') {
            echo "\nProtected change session started successfully.\n";
            echo "Session ID: {$result['session_id']}\n";
            echo "Countdown: {$result['settings']['countdown']} minutes (starts on first save)\n";
            echo "Reconnect GUI at: {$result['gui_url']}\n";
        } else {
            echo "\nFailed to start session: " . ($result['message'] ?? 'unknown error') . "\n";
        }
    }
} else {
    $state = $cs->getState();
    $user = $state['username'] ?? 'admin';
    $source = $state['source'] ?? 'gui';
    $saves = $state['saves_count'] ?? 0;
    $extCount = $state['extensions_count'] ?? 0;
    $maxExt = $state['max_extensions'] ?? 6;

    echo "Session status: ACTIVE\n";
    echo "User:           {$user} (via {$source})\n";
    echo "Started at:     " . ($state['created_at_iso'] ?? 'unknown') . "\n";
    echo "Changes saved:  {$saves}\n";

    if (!empty($state['countdown_active'])) {
        $rem = $state['remaining_seconds'] ?? 0;
        $m = floor($rem / 60);
        $s = $rem % 60;
        echo sprintf("Countdown:      ROLLBACK IN %d:%02d\n", $m, $s);
        echo "Extensions:     {$extCount} of {$maxExt} used\n";
    } else {
        echo "Countdown:      Pending (no changes saved yet)\n";
    }

    echo "Reconnect URL:  " . ($state['gui_url'] ?? 'N/A') . "\n";
    echo "\nActions:\n";
    echo "  1) Confirm changes (end protected session)\n";
    echo "  2) Extend countdown (+5 minutes)\n";
    echo "  3) Revert configuration to snapshot now\n";
    echo "  4) View configuration diff\n";
    echo "  0) Return to main menu\n\n";
    echo "Enter an option: ";

    $choice = trim(fgets($fp));

    switch ($choice) {
        case '1':
            echo "\nConfirm all configuration changes and end session? [y/N]: ";
            if (strcasecmp(trim(fgets($fp)), 'y') === 0) {
                $res = $cs->confirm('root@console', 'console');
                echo "\n" . ($res['message'] ?? 'Session confirmed.') . "\n";
            }
            break;

        case '2':
            $res = $cs->extend('root@console', 'console');
            if (!empty($res['status']) && $res['status'] === 'ok') {
                echo "\nCountdown extended by " . ($res['extension_seconds'] / 60) . " minutes.\n";
                echo "Extensions used: {$res['extensions_count']} of {$res['max_extensions']}\n";
            } else {
                echo "\nFailed to extend countdown: " . ($res['message'] ?? 'max extensions reached or not counting') . "\n";
            }
            break;

        case '3':
            echo "\nWARNING: All unconfirmed changes will be discarded immediately!\n";
            echo "Are you sure you want to revert to the pre-session snapshot? [y/N]: ";
            if (strcasecmp(trim(fgets($fp)), 'y') === 0) {
                echo "\nReverting configuration and reloading services...\n";
                $res = $cs->revert('root@console', 'console', 'manual');
                echo "\n" . ($res['message'] ?? 'Configuration reverted.') . "\n";
                echo "Reconnect Web GUI at: " . ($res['gui_url'] ?? '') . "\n";
            }
            break;

        case '4':
            echo "\nCalculating configuration differences...\n";
            $diff = $cs->getDiff();
            if (empty($diff)) {
                echo "No configuration differences between snapshot and current config.\n";
            } else {
                echo "--- Snapshot vs Current Config ---\n";
                foreach ($diff as $line) {
                    echo html_entity_decode($line) . "\n";
                }
            }
            break;

        case '0':
        default:
            break;
    }
}

fclose($fp);
echo "\n";
