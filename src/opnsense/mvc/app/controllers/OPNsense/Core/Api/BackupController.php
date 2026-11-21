<?php

/*
 * Copyright (C) 2023 Deciso B.V.
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

namespace OPNsense\Core\Api;

use OPNsense\Base\ApiControllerBase;
use OPNsense\Core\Backend;
use OPNsense\Core\Config;
use OPNsense\Core\Shell;

/**
 * Class BackupController
 * @package OPNsense\Core\Api
 */
class BackupController extends ApiControllerBase
{
    /**
     * return available providers and their backup locations
     * @return array
     */
    private function providers()
    {
        $result = [];
        $result['this'] = ['description' => gettext('This Firewall'), 'dirname' => '/conf/backup'];
        if (class_exists('\Deciso\OPNcentral\Central')) {
            $central = new \Deciso\OPNcentral\Central();
            $central->setUserScope($this->getUserName());
            $ctrHosts = [];
            foreach ($central->hosts->host->getNodes() as $itemid => $item) {
                $ctrHosts[$itemid] = ['description' => $item['description']];
            }
            foreach (glob('/conf/remote.backups/*') as $filename) {
                $dirname = basename($filename);
                if (isset($ctrHosts[$dirname])) {
                    $result[$dirname] = $ctrHosts[$dirname];
                    $result[$dirname]['dirname'] = $filename;
                }
            }
        }
        return $result;
    }

    /**
     * list available providers
     * @return array
     */
    public function providersAction()
    {
        return ['items' => $this->providers()];
    }

    /**
     * list available backups for selected host
     */
    public function backupsAction($host)
    {
        $result = ['items' => []];
        $providers = $this->providers();
        if (!empty($providers[$host])) {
            foreach (glob($providers[$host]['dirname'] . "/config-*.xml") as $filename) {
                $xmlNode = @simplexml_load_file($filename, "SimpleXMLElement", LIBXML_NOERROR | LIBXML_ERR_NONE);
                if (isset($xmlNode->revision)) {
                    $cfg_item = [
                        'time' => (string)$xmlNode->revision->time,
                        'time_iso' => date('c', (int)$xmlNode->revision->time),
                        'description' => (string)$xmlNode->revision->description,
                        'username' => (string)$xmlNode->revision->username,
                        'filesize' => filesize($filename),
                        'id' => basename($filename)
                    ];
                    $result['items'][] = $cfg_item;
                }
            }
            // sort newest first
            usort($result['items'], function ($item1, $item2) {
                return ($item1['time'] < $item2['time']) ? 1 : -1;
            });
        }
        return $result;
    }

    /**
     * diff two backups for selected host
     */
    public function diffAction($host, $backup1, $backup2)
    {
        $result = ['items' => []];
        $providers = $this->providers();
        if (!empty($providers[$host])) {
            $bckfilename1 = null;
            $bckfilename2 = null;
            foreach (glob($providers[$host]['dirname'] . "/config-*.xml") as $filename) {
                $bckfilename = basename($filename);
                if ($backup1 == $bckfilename) {
                    $bckfilename1 = $filename;
                } elseif ($backup2 == $bckfilename) {
                    $bckfilename2 = $filename;
                }
            }
            if (!empty($bckfilename1) && !empty($bckfilename2)) {
                $diff = Shell::shell_safe('/usr/bin/diff -u %s %s', [$bckfilename2, $bckfilename1], true);
                if (!empty($diff)) {
                    foreach ($diff as $line) {
                        $result['items'][] = htmlspecialchars($line, ENT_QUOTES | ENT_HTML401);
                    }
                }
            }
        }
        return $result;
    }

    /**
     * delete local backup
     */
    public function deleteBackupAction($backup)
    {
        $this->throwReadOnly();
        if (!$this->request->isPost()) {
            return ['status' => 'failed'];
        }
        foreach (glob("/conf/backup/config-*.xml") as $filename) {
            $bckfilename = basename($filename);
            if ($backup === $bckfilename) {
                @unlink($filename);
                return ['status' => 'deleted'];
            }
        }
        return ['status' => 'not_found'];
    }

    /**
     * sanitize optional NIC remapping associative array into old=new,... string
     * @param mixed $raw
     * @return string
     */
    private function serializeRemap($raw): string
    {
        if (!is_array($raw)) {
            return '';
        }
        $pairs = [];
        foreach ($raw as $old => $new) {
            $old = trim((string)$old);
            $new = trim((string)$new);
            if (
                $new !== '' &&
                preg_match('/^[A-Za-z][A-Za-z0-9_.:-]{0,31}$/', $old) &&
                preg_match('/^[A-Za-z][A-Za-z0-9_.:-]{0,31}$/', $new)
            ) {
                $pairs[] = "{$old}={$new}";
            }
        }
        return implode(',', $pairs);
    }

    /**
     * run pre-flight validation on a local backup before reverting
     * @param string $backup
     * @return array
     */
    public function preflightBackupAction($backup)
    {
        foreach (glob("/conf/backup/config-*.xml") as $filename) {
            if ($backup === basename($filename)) {
                $remapStr = $this->request->isPost() ? $this->serializeRemap($this->request->getPost('remap')) : '';
                $raw = trim((string)(new Backend())->configdpRun(
                    'firmware preflight_restore',
                    [$filename, '', $remapStr, '']
                ));
                $report = json_decode($raw, true);
                if (is_array($report)) {
                    return $report;
                }
                return [
                    'status' => 'error',
                    'can_proceed' => false,
                    'summary' => ['blockers' => 1, 'warnings' => 0, 'info' => 0],
                    'findings' => [[
                        'id' => 'preflight_exec_error',
                        'severity' => 'BLOCKER',
                        'category' => 'system',
                        'title' => gettext('Pre-flight validation failed to execute'),
                        'message' => gettext('Backend pre-flight check returned an invalid response.'),
                    ]],
                ];
            }
        }
        return ['status' => 'not_found'];
    }

    /**
     * revert to local backup from history
     */
    public function revertBackupAction($backup)
    {
        $this->throwReadOnly();
        if (!$this->request->isPost()) {
            return ['status' => 'failed'];
        }
        foreach (glob("/conf/backup/config-*.xml") as $filename) {
            $bckfilename = basename($filename);
            if ($backup === $bckfilename) {
                $remapStr = $this->serializeRemap($this->request->getPost('remap'));
                $rawOverride = $this->request->getPost('override', 'string', '0');
                $override = is_scalar($rawOverride) && in_array(
                    strtolower((string)$rawOverride),
                    ['1', 'true', 'yes'],
                    true
                );
                $remappedPath = $remapStr !== ''
                    ? ('/var/lib/php/tmp/revert_remapped_' . bin2hex(random_bytes(16)) . '.xml')
                    : '';
                $raw = trim((string)(new Backend())->configdpRun(
                    'firmware preflight_restore',
                    [$filename, '', $remapStr, $remappedPath]
                ));
                $report = json_decode($raw, true);

                if (!is_array($report)) {
                    if ($remappedPath !== '') {
                        @unlink($remappedPath);
                    }
                    return [
                        'status' => 'error',
                        'message' => gettext('Pre-flight validation failed to execute; restore aborted.'),
                    ];
                }

                if (empty($report['can_proceed']) && !$override) {
                    if ($remappedPath !== '') {
                        @unlink($remappedPath);
                    }
                    return ['status' => 'blocked', 'preflight' => $report];
                }

                if ($remappedPath !== '') {
                    if (!file_exists($remappedPath) || !is_readable($remappedPath)) {
                        @unlink($remappedPath);
                        return [
                            'status' => 'error',
                            'message' => gettext('Remapped configuration file could not be read; restore aborted.'),
                        ];
                    }
                    $source = $remappedPath;
                } else {
                    $source = $filename;
                }

                $cnf = Config::getInstance();
                $ok = $cnf->restoreBackup($source);
                if ($remappedPath !== '') {
                    @unlink($remappedPath);
                }
                if (!$ok) {
                    return [
                        'status' => 'error',
                        'message' => gettext('Failed to restore configuration backup.'),
                    ];
                }
                $cnf->save();
                return ['status' => 'reverted', 'preflight' => $report];
            }
        }
        return ['status' => 'not_found'];
    }

    /**
     * download specified backup, when left empty the latest is offered
     */
    public function downloadAction($host, $backup = null)
    {
        $providers = $this->providers();
        if (!empty($providers[$host])) {
            foreach (array_reverse(glob($providers[$host]['dirname'] . "/config-*.xml")) as $filename) {
                if (empty($backup) || $backup == basename($filename)) {
                    $payload = @simplexml_load_file($filename);
                    $hostname = '';
                    if ($payload !== false && isset($payload->system) && isset($payload->system->hostname)) {
                        $hostname = $payload->system->hostname . "." . $payload->system->domain;
                    }
                    $target_filename = urlencode('config-' . $hostname . '-' . explode('/config-', $filename, 2)[1]);
                    $this->response->setContentType('application/octet-stream');
                    $this->response->setRawHeader("Content-Disposition: attachment; filename=" . $target_filename);
                    $this->response->setRawHeader("Content-length: " . filesize($filename));
                    $this->response->setRawHeader("Pragma: no-cache");
                    $this->response->setRawHeader("Expires: 0");
                    $this->response->setContent(fopen($filename, 'r'), true);
                    break;
                }
            }
        }
    }
}
