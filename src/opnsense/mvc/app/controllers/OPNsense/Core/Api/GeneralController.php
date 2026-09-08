<?php

/*
 * Copyright (C) 2026 Deciso B.V.
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

use OPNsense\Base\ApiMutableModelControllerBase;
use OPNsense\Core\Backend;
use OPNsense\Core\Config;
use OPNsense\Routing\Gateways;

/**
 * Class GeneralController
 * REST API controller for OPNsense System General settings.
 * @package OPNsense\Core\Api
 */
class GeneralController extends ApiMutableModelControllerBase
{
    protected static $internalModelName = 'general';
    protected static $internalModelClass = 'OPNsense\Core\General';

    /**
     * Stale routes tracking cache file
     */
    private static $staleRoutesFile = '/tmp/.general_staleroutes.json';

    /**
     * Retrieve general settings and available gateways
     * @return array
     */
    public function getAction()
    {
        $result = parent::getAction();

        if ($this->request->isGet() && isset($result[static::$internalModelName])) {
            // Populate available gateway options
            $gateways = ['none' => gettext('none')];
            if (class_exists('OPNsense\Routing\Gateways')) {
                foreach ((new Gateways())->gatewaysIndexedByName() as $gwname => $gwitem) {
                    $gateways[$gwname] = sprintf('%s - %s - %s', $gwname, $gwitem['interface'] ?? '', $gwitem['gateway'] ?? '');
                }
            }
            $result['gateways'] = $gateways;

            // Map dnsservers array entries to flat dns1..dns8 and dns1gw..dns8gw option structures
            $mdl = $this->getModel();
            $dnscounter = 1;
            if ($mdl->dnsservers !== null) {
                foreach ($mdl->dnsservers->iterateItems() as $dnsItem) {
                    if ($dnscounter <= 8) {
                        $selectedGw = (string)$dnsItem->gateway;
                        $gwOptions = [];
                        foreach ($gateways as $gwKey => $gwVal) {
                            $gwOptions[$gwKey] = [
                                'value' => $gwVal,
                                'selected' => ($gwKey === $selectedGw || (empty($selectedGw) && $gwKey === 'none')) ? 1 : 0
                            ];
                        }
                        $result[static::$internalModelName]["dns{$dnscounter}"] = (string)$dnsItem->server;
                        $result[static::$internalModelName]["dns{$dnscounter}gw"] = $gwOptions;
                        $dnscounter++;
                    }
                }
            }
            while ($dnscounter <= 8) {
                $gwOptions = [];
                foreach ($gateways as $gwKey => $gwVal) {
                    $gwOptions[$gwKey] = [
                        'value' => $gwVal,
                        'selected' => ($gwKey === 'none') ? 1 : 0
                    ];
                }
                $result[static::$internalModelName]["dns{$dnscounter}"] = '';
                $result[static::$internalModelName]["dns{$dnscounter}gw"] = $gwOptions;
                $dnscounter++;
            }
        }

        return $result;
    }

    /**
     * Update general settings
     * @return array status / validation errors
     */
    public function setAction()
    {
        $result = ['result' => 'failed'];
        if ($this->request->isPost()) {
            $postData = $this->request->getPost(static::$internalModelName);

            // Track existing DNS server routes before saving to identify stale routes
            $mdl = $this->getModel();
            $oldRoutes = [];
            if ($mdl->dnsservers !== null) {
                $idx = 1;
                foreach ($mdl->dnsservers->iterateItems() as $dnsItem) {
                    $server = (string)$dnsItem->server;
                    $gw = (string)$dnsItem->gateway;
                    if (!empty($server) && !empty($gw) && $gw !== 'none') {
                        $oldRoutes[$idx] = ['server' => $server, 'gateway' => $gw];
                    }
                    $idx++;
                }
            }

            if (is_array($postData)) {
                if (!isset($postData['dnsservers'])) {
                    $dnsservers = [];
                    for ($i = 1; $i <= 8; $i++) {
                        if (!empty($postData["dns{$i}"])) {
                            $dnsservers[] = [
                                'server' => trim($postData["dns{$i}"]),
                                'gateway' => !empty($postData["dns{$i}gw"]) ? $postData["dns{$i}gw"] : 'none'
                            ];
                        }
                    }
                    $postData['dnsservers'] = $dnsservers;
                }

                // Handle delete picture signal
                if (isset($postData['del_picture']) && ($postData['del_picture'] === 'true' || $postData['del_picture'] === true)) {
                    $postData['picture'] = '';
                    $postData['picture_filename'] = '';
                }

                // Flush existing dnsservers so new set is applied cleanly
                if (isset($postData['dnsservers']) && $mdl->dnsservers !== null) {
                    $delKeys = [];
                    foreach ($mdl->dnsservers->iterateItems() as $key => $node) {
                        $delKeys[] = $key;
                    }
                    foreach ($delKeys as $key) {
                        $mdl->dnsservers->del($key);
                    }
                }

                Config::getInstance()->lock();
                $mdl->setNodes($postData);
                $result = $this->validate();
                if (empty($result['result'])) {
                    $this->setActionHook();
                    $result = $this->save(false, true);

                    if ($result['result'] === 'saved') {
                        // Compute changed or removed DNS server routes
                        $newMdl = $this->getModel();
                        $staleroutes = [];
                        $newRoutes = [];
                        if ($newMdl->dnsservers !== null) {
                            $idx = 1;
                            foreach ($newMdl->dnsservers->iterateItems() as $dnsItem) {
                                $server = (string)$dnsItem->server;
                                $gw = (string)$dnsItem->gateway;
                                if (!empty($server) && !empty($gw) && $gw !== 'none') {
                                    $newRoutes[$idx] = ['server' => $server, 'gateway' => $gw];
                                }
                                $idx++;
                            }
                        }

                        foreach ($oldRoutes as $idx => $old) {
                            if (!isset($newRoutes[$idx]) ||
                                $newRoutes[$idx]['server'] !== $old['server'] ||
                                $newRoutes[$idx]['gateway'] !== $old['gateway']) {
                                $staleroutes[] = $old['server'];
                            }
                        }

                        if (!empty($staleroutes)) {
                            @file_put_contents(self::$staleRoutesFile, json_encode($staleroutes));
                        }
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Reconfigure backend services upon settings update
     * @return array
     */
    public function reconfigureAction()
    {
        $result = ['status' => 'failed'];
        if ($this->request->isPost()) {
            // Explicitly flush stale host routes prior to reloading DNS
            if (file_exists(self::$staleRoutesFile)) {
                $staleroutes = json_decode(@file_get_contents(self::$staleRoutesFile), true);
                @unlink(self::$staleRoutesFile);
                if (is_array($staleroutes)) {
                    if (file_exists('/usr/local/etc/inc/system.inc')) {
                        require_once 'system.inc';
                    }
                    if (function_exists('system_host_route')) {
                        foreach ($staleroutes as $staleroute) {
                            system_host_route($staleroute, null);
                        }
                    }
                }
            }

            $backend = new Backend();
            // 1. Time zone change first per legacy system_general.php
            $backend->configdRun('service restart timezone');
            // 2. Hostname and domain restart
            $backend->configdRun('service restart hostname');
            // 3. DNS reload (regenerates /etc/resolv.conf and applies active DNS host routes)
            $backend->configdRun('dns reload');
            // 4. Reconfigure DNS plugins (Unbound / Dnsmasq)
            $backend->configdRun('plugins configure dns');
            // 5. Reconfigure DHCP plugins
            $backend->configdRun('plugins configure dhcp');
            // 6. Reload packet filter
            $backend->configdRun('filter reload');

            $result = ['status' => 'ok'];
        }

        return $result;
    }

    /**
     * Retrieve stored picture as raw image stream for inline browser display
     */
    public function pictureAction()
    {
        $mdl = $this->getModel();
        $picture = (string)$mdl->picture;
        $filename = (string)$mdl->picture_filename;

        if (!empty($picture) && !empty($filename)) {
            $data = base64_decode($picture);
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            $mimeMap = [
                'jpg' => 'jpeg',
                'jpeg' => 'jpeg',
                'png' => 'png',
                'gif' => 'gif',
                'svg' => 'svg+xml',
                'webp' => 'webp',
            ];
            $mime = $mimeMap[$ext] ?? 'octet-stream';

            $this->response->setHeader('Content-Type', 'image/' . $mime);
            $this->response->setHeader('Content-Disposition', 'inline; filename="' . basename($filename) . '"');
            $this->response->setHeader('Content-Length', (string)strlen($data));
            $this->response->setContent($data, true);
            return null;
        }

        $this->response->setStatusCode(404, 'Not Found');
        $this->response->setHeader('Content-Type', 'text/plain');
        $this->response->setContent('Not Found', true);
        return null;
    }

    /**
     * Handle multipart image upload directly
     * @return array
     */
    public function uploadPictureAction()
    {
        $result = ['result' => 'failed'];
        if ($this->request->isPost()) {
            $file = $_FILES['picture'] ?? $_FILES['pictfile'] ?? null;
            if ($file !== null && is_array($file) && !empty($file['tmp_name'])) {
                if ($file['size'] > (10 * 1024 * 1024)) {
                    return [
                        'result' => 'failed',
                        'message' => gettext('The image file is too large. Please upload something smaller than 10MB.')
                    ];
                }
                if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || (!is_uploaded_file($file['tmp_name']) && !file_exists($file['tmp_name']))) {
                    return [
                        'result' => 'failed',
                        'message' => gettext('Could not read uploaded file.')
                    ];
                }
                $content = @file_get_contents($file['tmp_name']);
                if ($content === false) {
                    return [
                        'result' => 'failed',
                        'message' => gettext('Could not read uploaded file.')
                    ];
                }

                Config::getInstance()->lock();
                $mdl = $this->getModel();
                $mdl->picture = base64_encode($content);
                $mdl->picture_filename = basename($file['name']);

                $valMsgs = $this->validate();
                if (empty($valMsgs['result'])) {
                    $this->save();
                    return [
                        'result' => 'saved',
                        'filename' => basename($file['name'])
                    ];
                } else {
                    return $valMsgs;
                }
            }
        }

        return $result;
    }

    /**
     * Delete existing custom picture
     * @return array
     */
    public function delPictureAction()
    {
        $result = ['result' => 'failed'];
        if ($this->request->isPost()) {
            Config::getInstance()->lock();
            $mdl = $this->getModel();
            $mdl->picture = '';
            $mdl->picture_filename = '';
            $this->save();
            $result = ['result' => 'saved'];
        }

        return $result;
    }
}
