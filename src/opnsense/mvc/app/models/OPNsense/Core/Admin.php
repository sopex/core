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

namespace OPNsense\Core;

use OPNsense\Base\BaseModel;
use OPNsense\Base\Messages\Message;
use OPNsense\Core\Config;

/**
 * Class Admin
 * Model for OPNsense System Administration settings.
 * @package OPNsense\Core
 */
class Admin extends BaseModel
{
    /**
     * Map of legacy and flat property aliases to internal container nodes
     */
    private static $aliasMap = [
        // WebGUI aliases
        'protocol'             => ['webgui', 'protocol'],
        'webguiproto'          => ['webgui', 'protocol'],
        'port'                 => ['webgui', 'port'],
        'webguiport'           => ['webgui', 'port'],
        'ssl_certref'          => ['webgui', 'ssl-certref'],
        'ssl-certref'          => ['webgui', 'ssl-certref'],
        'ciphers'              => ['webgui', 'ssl-ciphers'],
        'ssl_ciphers'          => ['webgui', 'ssl-ciphers'],
        'ssl-ciphers'          => ['webgui', 'ssl-ciphers'],
        'hsts'                 => ['webgui', 'ssl-hsts'],
        'ssl_hsts'             => ['webgui', 'ssl-hsts'],
        'ssl-hsts'             => ['webgui', 'ssl-hsts'],
        'disablehttpredirect'  => ['webgui', 'disablehttpredirect'],
        'httpaccesslog'        => ['webgui', 'httpaccesslog'],
        'session_timeout'      => ['webgui', 'session_timeout'],
        'compression'          => ['webgui', 'compression'],
        'nodnsrebindcheck'     => ['webgui', 'nodnsrebindcheck'],
        'nohttpreferercheck'   => ['webgui', 'nohttpreferercheck'],
        'noroot'               => ['webgui', 'noroot'],
        'althostnames'         => ['webgui', 'althostnames'],
        'interfaces'           => ['webgui', 'interfaces'],
        'webguiinterfaces'     => ['webgui', 'interfaces'],
        'authmode'             => ['webgui', 'authmode'],
        'quietlogin'           => ['webgui', 'quietlogin'],

        // OpenSSH aliases
        'ssh_enabled'          => ['ssh', 'enabled'],
        'enablesshd'           => ['ssh', 'enabled'],
        'ssh_port'             => ['ssh', 'port'],
        'sshport'              => ['ssh', 'port'],
        'ssh_interfaces'       => ['ssh', 'interfaces'],
        'sshinterfaces'        => ['ssh', 'interfaces'],
        'kex'                  => ['ssh', 'kex'],
        'ssh_kex'              => ['ssh', 'kex'],
        'ssh-kex'              => ['ssh', 'kex'],
        'ssh_ciphers'          => ['ssh', 'ciphers'],
        'ssh-ciphers'          => ['ssh', 'ciphers'],
        'macs'                 => ['ssh', 'macs'],
        'ssh_macs'             => ['ssh', 'macs'],
        'ssh-macs'             => ['ssh', 'macs'],
        'keys'                 => ['ssh', 'keys'],
        'ssh_keys'             => ['ssh', 'keys'],
        'ssh-keys'             => ['ssh', 'keys'],
        'keysig'               => ['ssh', 'keysig'],
        'ssh_keysig'           => ['ssh', 'keysig'],
        'ssh-keysig'           => ['ssh', 'keysig'],
        'rekeylimit'           => ['ssh', 'rekeylimit'],
        'ssh_rekeylimit'       => ['ssh', 'rekeylimit'],
        'ssh-rekeylimit'       => ['ssh', 'rekeylimit'],
        'passwordauth'         => ['ssh', 'passwordauth'],
        'sshpasswordauth'      => ['ssh', 'passwordauth'],
        'permitrootlogin'      => ['ssh', 'permitrootlogin'],
        'sshdpermitrootlogin'  => ['ssh', 'permitrootlogin'],
        'noauto'               => ['ssh', 'noauto'],

        // Console aliases
        'disableconsolemenu'   => ['console', 'disableconsolemenu'],
        'usevirtualterminal'   => ['console', 'usevirtualterminal'],
        'sudo_allow_wheel'     => ['console', 'sudo_allow_wheel'],
        'sudo_allow_group'     => ['console', 'sudo_allow_group'],
        'user_allow_gen_token' => ['console', 'user_allow_gen_token'],
        'serialspeed'          => ['console', 'serialspeed'],
        'serialusb'            => ['console', 'serialusb'],
        'primaryconsole'       => ['console', 'primaryconsole'],
        'secondaryconsole'     => ['console', 'secondaryconsole'],
        'autologout'           => ['console', 'autologout'],

        // Development aliases
        'deployment'           => ['development', 'deployment'],
        'nologlighttpd'        => ['development', 'nologlighttpd'],
    ];

    /**
     * Populate dynamic option lists on initialization
     */
    protected function init()
    {
        parent::init();

        /* Populate primary and secondary console choices */
        $consoleTypes = [];
        if (function_exists('system_console_types')) {
            foreach (system_console_types() as $key => $type) {
                $consoleTypes[$key] = $type['name'] ?? $key;
            }
        } else {
            $consoleTypes = [
                'video' => 'VGA Console',
                'serial' => 'Serial Console',
                'efi' => 'EFI Console',
                'null' => 'Mute Console'
            ];
        }
        $this->console->primaryconsole->setOptionValues($consoleTypes);

        $secondaryChoices = ['' => gettext('None')] + $consoleTypes;
        $this->console->secondaryconsole->setOptionValues($secondaryChoices);
    }

    /**
     * Magic getter supporting flat and legacy property aliases
     * @param string $name
     * @return mixed
     */
    public function __get($name)
    {
        if ($name === 'loglighttpd') {
            return (string)$this->development->nologlighttpd === '1' ? '0' : '1';
        }

        if (isset(self::$aliasMap[$name])) {
            [$container, $field] = self::$aliasMap[$name];
            return $this->$container->$field;
        }

        return parent::__get($name);
    }

    /**
     * Magic setter supporting flat and legacy property aliases
     * @param string $name
     * @param mixed $value
     */
    public function __set($name, $value)
    {
        if ($name === 'loglighttpd') {
            $this->development->nologlighttpd = (empty($value) || $value === '0') ? '1' : '0';
            return;
        }

        if (isset(self::$aliasMap[$name])) {
            [$container, $field] = self::$aliasMap[$name];

            if ($name === 'permitrootlogin' || $name === 'sshdpermitrootlogin') {
                if ($value === '1' || $value === 1) {
                    $value = 'yes';
                } elseif ($value === '0' || $value === 0 || $value === '') {
                    $value = 'no';
                }
            }

            $this->$container->$field = $value;
            return;
        }

        parent::__set($name, $value);
    }

    /**
     * Magic isset supporting flat and legacy property aliases
     * @param string $name
     * @return bool
     */
    public function __isset($name)
    {
        if ($name === 'loglighttpd' || isset(self::$aliasMap[$name])) {
            return true;
        }
        return parent::__isset($name);
    }

    /**
     * Perform deep model validations matching system_advanced_admin.php
     * @param bool $validateFullModel
     * @return \OPNsense\Base\Validation\Group
     */
    public function performValidation($validateFullModel = false)
    {
        $messages = parent::performValidation($validateFullModel);

        /* 1. WebGUI Port boundary check */
        $webguiPort = (string)$this->webgui->port;
        if (!empty($webguiPort)) {
            if (!is_numeric($webguiPort) || (int)$webguiPort < 1 || (int)$webguiPort > 65535 || strpos((string)$webguiPort, '.') !== false) {
                $msgText = gettext('You must specify a valid web GUI port number.');
                $messages->appendMessage(new Message($msgText, 'webgui.port'));
                $messages->appendMessage(new Message($msgText, 'port'));
            }
        }

        /* 2. WebGUI Protocol validation */
        $protocol = (string)$this->webgui->protocol;
        if (empty($protocol) || !in_array($protocol, ['http', 'https'])) {
            $msgText = gettext('You must specify a valid web GUI protocol.');
            $messages->appendMessage(new Message($msgText, 'webgui.protocol'));
            $messages->appendMessage(new Message($msgText, 'protocol'));
        }

        /* 3. Certificate Purpose check */
        $certRef = (string)$this->webgui->{'ssl-certref'};
        if ($protocol === 'https' && !empty($certRef)) {
            $config = Config::getInstance()->object();
            if (isset($config->cert)) {
                foreach ($config->cert as $cert) {
                    if ((string)$cert->refid === $certRef) {
                        if (function_exists('cert_get_purpose') && isset($cert->crt)) {
                            $purpose = cert_get_purpose((string)$cert->crt);
                            if (isset($purpose['server']) && $purpose['server'] === 'No') {
                                $descr = (string)($cert->descr ?? $certRef);
                                $msgText = sprintf(gettext('Certificate %s is not intended for server use.'), $descr);
                                $messages->appendMessage(new Message($msgText, 'webgui.ssl-certref'));
                                $messages->appendMessage(new Message($msgText, 'ssl_certref'));
                            }
                        }
                        break;
                    }
                }
            }
        }

        /* 4. Alternate hostnames validation */
        $altHostnames = (string)$this->webgui->althostnames;
        if (!empty($altHostnames)) {
            foreach (explode(' ', $altHostnames) as $host) {
                $host = trim($host);
                if (!empty($host)) {
                    $isValid = function_exists('is_hostname')
                        ? is_hostname($host)
                        : (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false && strpos($host, '_') === false && strpos($host, '/') === false);
                    if (!$isValid) {
                        $msgText = sprintf(gettext('Alternate hostname %s is not a valid hostname.'), htmlspecialchars($host));
                        $messages->appendMessage(new Message($msgText, 'webgui.althostnames'));
                        $messages->appendMessage(new Message($msgText, 'althostnames'));
                    }
                }
            }
        }

        /* 5. Session timeout validation (must be positive integer >= 1) */
        $sessionTimeout = (string)$this->webgui->session_timeout;
        if (!empty($sessionTimeout)) {
            if (!is_numeric($sessionTimeout) || (int)$sessionTimeout < 1 || strpos((string)$sessionTimeout, '.') !== false) {
                $msgText = gettext('Session timeout must be an integer value.');
                $messages->appendMessage(new Message($msgText, 'webgui.session_timeout'));
                $messages->appendMessage(new Message($msgText, 'session_timeout'));
            }
        }

        /* 6. WebGUI SSL Ciphers (RFC 8446 TLS 1.3 requirement) */
        $ciphersRaw = (string)$this->webgui->{'ssl-ciphers'};
        if (!empty($ciphersRaw)) {
            $selectedCiphers = explode(',', $ciphersRaw);
            $hasTls13 = false;
            foreach ($selectedCiphers as $c) {
                $c = trim($c);
                if (strpos($c, 'TLS_') === 0 || strpos($c, 'TLSv1.3') !== false) {
                    $hasTls13 = true;
                    break;
                }
            }
            if ($hasTls13 && !in_array('TLS_AES_128_GCM_SHA256', array_map('trim', $selectedCiphers))) {
                $msgText = gettext('A TLS 1.3-compliant application MUST implement the TLS_AES_128_GCM_SHA256 according to RFC 8446.');
                $messages->appendMessage(new Message($msgText, 'webgui.ssl-ciphers'));
                $messages->appendMessage(new Message($msgText, 'ciphers'));
            }
        }

        /* 7. SSH Port boundary check */
        $sshPort = (string)$this->ssh->port;
        if (!empty($sshPort)) {
            if (!is_numeric($sshPort) || (int)$sshPort < 1 || (int)$sshPort > 65535 || strpos((string)$sshPort, '.') !== false) {
                $msgText = gettext('You must specify a valid SSH port number.');
                $messages->appendMessage(new Message($msgText, 'ssh.port'));
                $messages->appendMessage(new Message($msgText, 'ssh_port'));
            }
        }

        /* 8. Rekey limit option validation */
        $rekey = (string)$this->ssh->rekeylimit;
        $validRekey = ['', 'default 60s', 'default 600s', '512M 60s', '512M 600s', '512M 1h', '1G 60s', '1G 1h'];
        if (!empty($rekey) && !in_array($rekey, $validRekey)) {
            $msgText = gettext('Invalid rekey limit option.');
            $messages->appendMessage(new Message($msgText, 'ssh.rekeylimit'));
            $messages->appendMessage(new Message($msgText, 'rekeylimit'));
        }

        /* 9. Console serial speed validation */
        $validSpeeds = ['1500000', '115200', '57600', '38400', '19200', '14400', '9600'];
        $speed = (string)$this->console->serialspeed;
        if (!empty($speed) && !in_array($speed, $validSpeeds)) {
            $msgText = gettext('Invalid serial speed.');
            $messages->appendMessage(new Message($msgText, 'console.serialspeed'));
            $messages->appendMessage(new Message($msgText, 'serialspeed'));
        }

        /* 10. Shell Inactivity timeout validation (autologout >= 1) */
        $autologout = (string)$this->console->autologout;
        if (!empty($autologout)) {
            if (!is_numeric($autologout) || (int)$autologout < 1 || strpos((string)$autologout, '.') !== false) {
                $msgText = gettext('Inactivity timeout must be an integer value.');
                $messages->appendMessage(new Message($msgText, 'console.autologout'));
                $messages->appendMessage(new Message($msgText, 'autologout'));
            }
        }

        /* 11. Compression validation */
        $compression = (string)$this->webgui->compression;
        $validCompression = ['', '1', '5', '9'];
        if (!in_array($compression, $validCompression)) {
            $msgText = gettext('Invalid compression value.');
            $messages->appendMessage(new Message($msgText, 'webgui.compression'));
            $messages->appendMessage(new Message($msgText, 'compression'));
        }

        /* 12. Sudo allow wheel validation */
        $sudoWheel = (string)$this->console->sudo_allow_wheel;
        $validWheel = ['', '1', '2'];
        if (!in_array($sudoWheel, $validWheel)) {
            $msgText = gettext('Invalid sudo allow wheel option.');
            $messages->appendMessage(new Message($msgText, 'console.sudo_allow_wheel'));
            $messages->appendMessage(new Message($msgText, 'sudo_allow_wheel'));
        }

        return $messages;
    }

    /**
     * Backward-compatibility synchronizer: writes model state to legacy
     * $config['system']['webgui'], $config['system']['ssh'], console keys, and $config['syslog'].
     */
    public function syncToLegacyConfig()
    {
        $config = Config::getInstance()->object();

        if (!isset($config->system)) {
            $config->addChild('system');
        }

        // ==========================================
        // 1. WebGUI Synchronization
        // ==========================================
        if (!isset($config->system->webgui)) {
            $config->system->addChild('webgui');
        }

        $config->system->webgui->protocol = (string)$this->webgui->protocol;

        if (!empty((string)$this->webgui->port)) {
            $config->system->webgui->port = (string)$this->webgui->port;
        } else {
            unset($config->system->webgui->port);
        }

        if (!empty((string)$this->webgui->{'ssl-certref'})) {
            $config->system->webgui->{'ssl-certref'} = (string)$this->webgui->{'ssl-certref'};
        } else {
            unset($config->system->webgui->{'ssl-certref'});
        }

        if (!empty((string)$this->webgui->{'ssl-ciphers'})) {
            // Legacy expects colon-delimited string
            $ciphers = explode(',', (string)$this->webgui->{'ssl-ciphers'});
            $config->system->webgui->{'ssl-ciphers'} = implode(':', array_filter(array_map('trim', $ciphers)));
        } else {
            unset($config->system->webgui->{'ssl-ciphers'});
        }

        if ((string)$this->webgui->{'ssl-hsts'} === '1') {
            $config->system->webgui->{'ssl-hsts'} = 'true';
        } else {
            unset($config->system->webgui->{'ssl-hsts'});
        }

        if ((string)$this->webgui->disablehttpredirect === '1') {
            $config->system->webgui->disablehttpredirect = 'true';
        } else {
            unset($config->system->webgui->disablehttpredirect);
        }

        if ((string)$this->webgui->httpaccesslog === '1') {
            $config->system->webgui->httpaccesslog = 'true';
        } else {
            unset($config->system->webgui->httpaccesslog);
        }

        if (!empty((string)$this->webgui->session_timeout)) {
            $config->system->webgui->session_timeout = (string)$this->webgui->session_timeout;
        } else {
            unset($config->system->webgui->session_timeout);
        }

        if ((string)$this->webgui->compression !== '') {
            $config->system->webgui->compression = (string)$this->webgui->compression;
        } else {
            unset($config->system->webgui->compression);
        }

        if ((string)$this->webgui->nodnsrebindcheck === '1') {
            $config->system->webgui->nodnsrebindcheck = 'true';
        } else {
            unset($config->system->webgui->nodnsrebindcheck);
        }

        if ((string)$this->webgui->nohttpreferercheck === '1') {
            $config->system->webgui->nohttpreferercheck = 'true';
        } else {
            unset($config->system->webgui->nohttpreferercheck);
        }

        if ((string)$this->webgui->noroot === '1') {
            $config->system->webgui->noroot = 'true';
        } else {
            unset($config->system->webgui->noroot);
        }

        if (!empty((string)$this->webgui->althostnames)) {
            $config->system->webgui->althostnames = (string)$this->webgui->althostnames;
        } else {
            unset($config->system->webgui->althostnames);
        }

        if (!empty((string)$this->webgui->interfaces)) {
            $config->system->webgui->interfaces = (string)$this->webgui->interfaces;
        } else {
            unset($config->system->webgui->interfaces);
        }

        if (!empty((string)$this->webgui->authmode)) {
            $config->system->webgui->authmode = (string)$this->webgui->authmode;
        } else {
            unset($config->system->webgui->authmode);
        }

        if ((string)$this->webgui->quietlogin === '1') {
            $config->system->webgui->quietlogin = 'true';
        } else {
            unset($config->system->webgui->quietlogin);
        }

        // ==========================================
        // 2. OpenSSH Synchronization
        // ==========================================
        if (!isset($config->system->ssh)) {
            $config->system->addChild('ssh');
        }

        if ((string)$this->ssh->enabled === '1') {
            $config->system->ssh->enabled = 'enabled';
        } else {
            unset($config->system->ssh->enabled);
        }

        if (!empty((string)$this->ssh->port)) {
            $config->system->ssh->port = (string)$this->ssh->port;
        } else {
            unset($config->system->ssh->port);
        }

        if (!empty((string)$this->ssh->interfaces)) {
            $config->system->ssh->interfaces = (string)$this->ssh->interfaces;
        } else {
            unset($config->system->ssh->interfaces);
        }

        if (!empty((string)$this->ssh->kex)) {
            $config->system->ssh->kex = (string)$this->ssh->kex;
        } else {
            unset($config->system->ssh->kex);
        }

        if (!empty((string)$this->ssh->ciphers)) {
            $config->system->ssh->ciphers = (string)$this->ssh->ciphers;
        } else {
            unset($config->system->ssh->ciphers);
        }

        if (!empty((string)$this->ssh->macs)) {
            $config->system->ssh->macs = (string)$this->ssh->macs;
        } else {
            unset($config->system->ssh->macs);
        }

        if (!empty((string)$this->ssh->keys)) {
            $config->system->ssh->keys = (string)$this->ssh->keys;
        } else {
            unset($config->system->ssh->keys);
        }

        if (!empty((string)$this->ssh->keysig)) {
            $config->system->ssh->keysig = (string)$this->ssh->keysig;
        } else {
            unset($config->system->ssh->keysig);
        }

        if (!empty((string)$this->ssh->rekeylimit)) {
            $config->system->ssh->rekeylimit = (string)$this->ssh->rekeylimit;
        } else {
            unset($config->system->ssh->rekeylimit);
        }

        if ((string)$this->ssh->passwordauth === '1') {
            $config->system->ssh->passwordauth = 'true';
        } else {
            unset($config->system->ssh->passwordauth);
        }

        $rootLogin = (string)$this->ssh->permitrootlogin;
        if ($rootLogin === 'yes' || $rootLogin === '1') {
            $config->system->ssh->permitrootlogin = 'true';
        } elseif ($rootLogin === 'without-password') {
            $config->system->ssh->permitrootlogin = 'without-password';
        } else {
            unset($config->system->ssh->permitrootlogin);
        }

        /* Prevent installer auto-start */
        $config->system->ssh->noauto = 1;

        // ==========================================
        // 3. Console & Shell Synchronization
        // ==========================================
        if ((string)$this->console->disableconsolemenu === '1') {
            $config->system->disableconsolemenu = 'true';
        } else {
            unset($config->system->disableconsolemenu);
        }

        if ((string)$this->console->usevirtualterminal === '1') {
            $config->system->usevirtualterminal = 'true';
        } else {
            unset($config->system->usevirtualterminal);
        }

        if ((string)$this->console->sudo_allow_wheel !== '') {
            $config->system->sudo_allow_wheel = (string)$this->console->sudo_allow_wheel;
        } else {
            unset($config->system->sudo_allow_wheel);
        }

        if (!empty((string)$this->console->sudo_allow_group)) {
            $config->system->sudo_allow_group = (string)$this->console->sudo_allow_group;
        } else {
            unset($config->system->sudo_allow_group);
        }

        if (!empty((string)$this->console->user_allow_gen_token)) {
            $config->system->user_allow_gen_token = (string)$this->console->user_allow_gen_token;
        } else {
            unset($config->system->user_allow_gen_token);
        }

        if (!empty((string)$this->console->serialspeed)) {
            $config->system->serialspeed = (string)$this->console->serialspeed;
        } else {
            unset($config->system->serialspeed);
        }

        if ((string)$this->console->serialusb === '1') {
            $config->system->serialusb = 'true';
        } else {
            unset($config->system->serialusb);
        }

        if (!empty((string)$this->console->primaryconsole)) {
            $config->system->primaryconsole = (string)$this->console->primaryconsole;
        } else {
            unset($config->system->primaryconsole);
        }

        if (!empty((string)$this->console->secondaryconsole)) {
            $config->system->secondaryconsole = (string)$this->console->secondaryconsole;
        } else {
            unset($config->system->secondaryconsole);
        }

        if (!empty((string)$this->console->autologout)) {
            $config->system->autologout = (string)$this->console->autologout;
        } else {
            unset($config->system->autologout);
        }

        // ==========================================
        // 4. Development & Syslog Synchronization
        // ==========================================
        if ((string)$this->development->deployment !== '') {
            $config->system->deployment = (string)$this->development->deployment;
        } else {
            unset($config->system->deployment);
        }

        if (!isset($config->syslog)) {
            $config->addChild('syslog');
        }

        if ((string)$this->development->nologlighttpd === '1') {
            $config->syslog->nologlighttpd = 'true';
        } else {
            unset($config->syslog->nologlighttpd);
        }
    }

    /**
     * Override serializeToConfig to guarantee legacy config synchronization
     * @param bool $validateFullModel
     * @param bool $disable_validation
     * @return bool
     */
    public function serializeToConfig($validateFullModel = false, $disable_validation = false)
    {
        $result = parent::serializeToConfig($validateFullModel, $disable_validation);
        if ($result) {
            $this->syncToLegacyConfig();
        }
        return $result;
    }
}
