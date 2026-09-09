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

/**
 * Class AdminController
 * REST API controller for OPNsense System Administration settings.
 * @package OPNsense\Core\Api
 */
class AdminController extends ApiMutableModelControllerBase
{
    protected static $internalModelName = 'admin';
    protected static $internalModelClass = 'OPNsense\Core\Admin';
    protected static $internalSaveRequiresAdmin = true;

    /**
     * Cache file to communicate WebGUI restart requirement between setAction and reconfigureAction
     */
    private static $webguiRestartStateFile = '/tmp/.admin_webgui_restart.json';

    /**
     * Retrieve admin settings along with dynamic dropdown dictionaries
     * @return array
     */
    public function getAction()
    {
        $result = parent::getAction();

        if ($this->request->isGet() && isset($result[static::$internalModelName])) {
            $mdl = $this->getModel();

            // 1. SSL Certificates with private keys
            $result['certificates'] = $this->getCertificates();

            // 2. Network Interfaces
            $interfaces = [];
            if (file_exists('/usr/local/etc/inc/interfaces.inc')) {
                require_once 'interfaces.inc';
            }
            if (function_exists('get_configured_interface_with_descr')) {
                $interfaces = get_configured_interface_with_descr();
            } elseif (function_exists('legacy_config_get_interfaces')) {
                foreach (legacy_config_get_interfaces(['enable' => true]) as $ifname => $ifdetail) {
                    $interfaces[$ifname] = $ifdetail['descr'] ?? strtoupper($ifname);
                }
            }
            $result['interfaces'] = $interfaces;

            // 3. Authentication Servers
            $authservers = [];
            if (file_exists('/usr/local/etc/inc/auth.inc')) {
                require_once 'auth.inc';
            }
            if (function_exists('auth_get_authserver_list')) {
                foreach (auth_get_authserver_list('WebGui') as $auth_key => $auth_server) {
                    $authservers[$auth_key] = $auth_server['name'] ?? $auth_key;
                }
            }
            $result['authservers'] = $authservers;

            // 4. SSL Ciphers
            $result['ciphers'] = $this->getSslCiphers();

            // 5. OpenSSH Queries and Rekey Limits
            $result['sshoptions'] = $this->getSshOptions();
            $rekeyChoices = $this->getSshRekeyLimitChoices();
            $result['ssh_rekeylimit_choices'] = $rekeyChoices;
            $result['rekeylimit_choices'] = $rekeyChoices;

            // 6. User Groups
            $groups = [];
            $configObj = Config::getInstance()->object();
            if (isset($configObj->system->group)) {
                foreach ($configObj->system->group as $group) {
                    $grpName = (string)$group->name;
                    $groups[$grpName] = $grpName;
                }
            }
            $result['groups'] = $groups;

            // 7. Console Devices
            $consoles = [];
            if (file_exists('/usr/local/etc/inc/system.inc')) {
                require_once 'system.inc';
            }
            if (function_exists('system_console_types')) {
                foreach (system_console_types() as $console_key => $console_type) {
                    $consoles[$console_key] = $console_type['name'] ?? $console_key;
                }
            } else {
                $consoles = [
                    'video'  => 'VGA Console',
                    'serial' => 'Serial Console',
                    'efi'    => 'EFI Console',
                    'null'   => 'Mute Console'
                ];
            }
            $result['consoles'] = $consoles;
            $result['console_types'] = $consoles;

            // 8. Inverted helper for webgui form
            $result[static::$internalModelName]['webgui']['loglighttpd'] = (string)$mdl->development->nologlighttpd === '1' ? '0' : '1';
        }

        return $result;
    }

    /**
     * Update admin settings
     * @return array status / validation errors
     */
    public function setAction()
    {
        $result = ['result' => 'failed'];
        if ($this->request->isPost()) {
            $postData = $this->request->getPost(static::$internalModelName);
            if (!is_array($postData)) {
                return $result;
            }

            $mdl = $this->getModel();

            // Capture previous state of the 13 connection parameters
            $oldWebguiParams = [
                'protocol'            => (string)($mdl->webgui->protocol ?? 'https'),
                'port'                => (string)($mdl->webgui->port ?? ''),
                'ssl-certref'         => (string)($mdl->webgui->{'ssl-certref'} ?? ''),
                'ssl-ciphers'         => (string)($mdl->webgui->{'ssl-ciphers'} ?? ''),
                'ssl-hsts'            => (string)($mdl->webgui->{'ssl-hsts'} ?? '0'),
                'disablehttpredirect' => (string)($mdl->webgui->disablehttpredirect ?? '0'),
                'session_timeout'     => (string)($mdl->webgui->session_timeout ?? ''),
                'compression'         => (string)($mdl->webgui->compression ?? ''),
                'httpaccesslog'       => (string)($mdl->webgui->httpaccesslog ?? '0'),
                'interfaces'          => (string)($mdl->webgui->interfaces ?? ''),
                'noroot'              => (string)($mdl->webgui->noroot ?? '0'),
                'deployment'          => (string)($mdl->development->deployment ?? ''),
                'nologlighttpd'       => (string)($mdl->development->nologlighttpd ?? '0'),
            ];

            // Normalize inverted loglighttpd flag: UI checked (1) means nologlighttpd = 0
            if (isset($postData['webgui']['loglighttpd'])) {
                $postData['development']['nologlighttpd'] = empty($postData['webgui']['loglighttpd']) ? '1' : '0';
                unset($postData['webgui']['loglighttpd']);
            } elseif (isset($postData['loglighttpd'])) {
                $postData['development']['nologlighttpd'] = empty($postData['loglighttpd']) ? '1' : '0';
                unset($postData['loglighttpd']);
            }

            // Normalize permitrootlogin values (e.g. 1 -> yes, 0 -> no)
            if (isset($postData['ssh']['permitrootlogin'])) {
                $prl = $postData['ssh']['permitrootlogin'];
                if ($prl === '1' || $prl === 1) {
                    $postData['ssh']['permitrootlogin'] = 'yes';
                } elseif ($prl === '0' || $prl === 0) {
                    $postData['ssh']['permitrootlogin'] = 'no';
                }
            }

            // Normalize CSV and multi-select fields if passed as arrays
            $multiSelects = [
                ['webgui', 'interfaces', ','],
                ['webgui', 'ssl-ciphers', ','],
                ['webgui', 'authmode', ','],
                ['ssh', 'interfaces', ','],
                ['ssh', 'kex', ','],
                ['ssh', 'ciphers', ','],
                ['ssh', 'macs', ','],
                ['ssh', 'keys', ','],
                ['ssh', 'keysig', ','],
                ['console', 'user_allow_gen_token', ','],
            ];
            foreach ($multiSelects as [$section, $field, $sep]) {
                if (isset($postData[$section][$field]) && is_array($postData[$section][$field])) {
                    $postData[$section][$field] = implode($sep, array_filter($postData[$section][$field]));
                }
            }

            // Lock configuration and apply nodes
            Config::getInstance()->lock();
            $mdl->setNodes($postData);
            $result = $this->validate();

            if (empty($result['result'])) {
                $this->setActionHook();
                $result = $this->save(false, true);

                if ($result['result'] === 'saved') {
                    $newMdl = $this->getModel();
                    $newWebguiParams = [
                        'protocol'            => (string)($newMdl->webgui->protocol ?? 'https'),
                        'port'                => (string)($newMdl->webgui->port ?? ''),
                        'ssl-certref'         => (string)($newMdl->webgui->{'ssl-certref'} ?? ''),
                        'ssl-ciphers'         => (string)($newMdl->webgui->{'ssl-ciphers'} ?? ''),
                        'ssl-hsts'            => (string)($newMdl->webgui->{'ssl-hsts'} ?? '0'),
                        'disablehttpredirect' => (string)($newMdl->webgui->disablehttpredirect ?? '0'),
                        'session_timeout'     => (string)($newMdl->webgui->session_timeout ?? ''),
                        'compression'         => (string)($newMdl->webgui->compression ?? ''),
                        'httpaccesslog'       => (string)($newMdl->webgui->httpaccesslog ?? '0'),
                        'interfaces'          => (string)($newMdl->webgui->interfaces ?? ''),
                        'noroot'              => (string)($newMdl->webgui->noroot ?? '0'),
                        'deployment'          => (string)($newMdl->development->deployment ?? ''),
                        'nologlighttpd'       => (string)($newMdl->development->nologlighttpd ?? '0'),
                    ];

                    $restart_webgui = false;
                    foreach ($oldWebguiParams as $paramKey => $oldVal) {
                        if ($oldVal !== $newWebguiParams[$paramKey]) {
                            $restart_webgui = true;
                            break;
                        }
                    }

                    if ($restart_webgui) {
                        @file_put_contents(self::$webguiRestartStateFile, json_encode([
                            'restart'   => true,
                            'timestamp' => time()
                        ]));
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
            $mdl = $this->getModel();
            $restart_webgui = false;

            if (file_exists(self::$webguiRestartStateFile)) {
                $restartData = json_decode(@file_get_contents(self::$webguiRestartStateFile), true);
                @unlink(self::$webguiRestartStateFile);
                if (!empty($restartData['restart'])) {
                    $restart_webgui = true;
                }
            } else {
                // Fallback detection: compare current model with persisted config
                $config = Config::getInstance()->object();
                $cfgPort = isset($config->system->webgui->port) ? (string)$config->system->webgui->port : '';
                $mdlPort = (string)$mdl->webgui->port;
                if ($cfgPort !== $mdlPort && (!empty($cfgPort) || !empty($mdlPort))) {
                    $restart_webgui = true;
                }

                $cfgProto = isset($config->system->webgui->protocol) ? (string)$config->system->webgui->protocol : 'https';
                $mdlProto = (string)$mdl->webgui->protocol;
                if ($cfgProto !== $mdlProto && (!empty($cfgProto) || !empty($mdlProto))) {
                    $restart_webgui = true;
                }
            }

            $backend = new Backend();

            // 1. Reload packet filter (updates anti-lockout rules for webgui and ssh ports)
            $backend->configdRun('filter reload');
            // 2. Reconfigure login environment (/etc/ttys, /boot/loader.conf, sudoers, cshrc)
            $backend->configdRun('service restart login');
            // 3. DNS reload
            $backend->configdRun('dns reload');
            // 4. DNS plugins reconfiguration
            $backend->configdRun('plugins configure dns');
            // 5. DHCP plugins reconfiguration
            $backend->configdRun('plugins configure dhcp');
            // 6. OpenSSH reload in background (detached)
            $backend->configdRun('openssh restart', true);

            $result = [
                'status' => 'ok',
                'restart_webgui' => $restart_webgui
            ];

            if ($restart_webgui) {
                // Trigger lighttpd restart in background with 3-second delay
                $backend->configdRun('webgui restart 3', true);

                // Compute destination redirect URL
                $prot = (string)($mdl->webgui->protocol ?: 'https');
                $port = (string)($mdl->webgui->port ?: '');

                // Parse client HTTP host handling IPv6 brackets
                $http_host = $this->request->getHeader('Host') ?: ($_SERVER['HTTP_HOST'] ?? 'localhost');
                if (strstr($http_host, ']')) {
                    $parts = explode(']', $http_host);
                    $host = $parts[0] . ']';
                } else {
                    $parts = explode(':', $http_host);
                    $host = $parts[0];
                }

                if (!empty($port)) {
                    $result['redirect_url'] = "{$prot}://{$host}:{$port}/ui/core/admin";
                } else {
                    $result['redirect_url'] = "{$prot}://{$host}/ui/core/admin";
                }
            }
        }

        return $result;
    }

    /**
     * Retrieve valid certificates with private keys for server usage
     * @return array
     */
    public function getCertificates()
    {
        if (class_exists('OPNsense\Core\LegacyConfig')) {
            return \OPNsense\Core\LegacyConfig::getCertificates();
        }

        $certificates = [];
        if (file_exists('/usr/local/etc/inc/certs.inc')) {
            require_once 'certs.inc';
        }

        $configObj = Config::getInstance()->object();
        if (isset($configObj->cert)) {
            foreach ($configObj->cert as $cert) {
                if (isset($cert->prv) && !empty((string)$cert->prv)) {
                    $refid = (string)$cert->refid;
                    $descr = (string)$cert->descr ?: $refid;
                    $crt = (string)$cert->crt;

                    if (function_exists('cert_get_purpose')) {
                        $purpose = cert_get_purpose($crt);
                        if (isset($purpose['server']) && $purpose['server'] === 'No') {
                            continue;
                        }
                    }

                    $certificates[$refid] = $descr;
                }
            }
        }

        natcasesort($certificates);
        return $certificates;
    }

    /**
     * Retrieve SSL ciphers from configd
     * @return array
     */
    private function getSslCiphers()
    {
        $ciphers = [];
        $raw = (new Backend())->configdRun('system ssl ciphers');
        $cipherList = json_decode($raw, true);
        if (is_array($cipherList)) {
            ksort($cipherList);
            foreach ($cipherList as $cipher => $data) {
                $ciphers[$cipher] = !empty($data['description']) ? $data['description'] : $cipher;
            }
        }
        return $ciphers;
    }

    /**
     * Retrieve OpenSSH queries from configd
     * @return array
     */
    private function getSshOptions()
    {
        $raw = (new Backend())->configdRun('openssh query');
        $options = json_decode($raw, true);
        return is_array($options) ? $options : [];
    }

    /**
     * OpenSSH rekey limit dictionary
     * @return array
     */
    private function getSshRekeyLimitChoices()
    {
        return [
            ''             => gettext('System defaults'),
            'default 60s'  => gettext('60 seconds'),
            'default 600s' => gettext('10 minutes'),
            '512M 60s'     => gettext('512MB, 60 seconds'),
            '512M 600s'    => gettext('512MB, 10 minutes'),
            '512M 1h'      => gettext('512MB, 1 hour'),
            '1G 60s'       => gettext('1GB, 60 seconds'),
            '1G 1h'        => gettext('1GB, 1 hour'),
        ];
    }
}
