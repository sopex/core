<?php

/*
 * Copyright (C) 2021-2026 Deciso B.V.
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

namespace OPNsense\Core\Migrations;

use OPNsense\Base\BaseModelMigration;
use OPNsense\Core\Admin;
use OPNsense\Core\Config;
use OPNsense\Core\Firmware;
use OPNsense\Core\General;
use OPNsense\Core\Misc;

class M1_0_0 extends BaseModelMigration
{
    /**
     * Migrate Core models to version 1.0.0
     * @param $model
     */
    public function run($model)
    {
        if ($model instanceof Firmware) {
            if ((empty((string)$model->type) || (string)$model->type == 'devel') && !empty((string)$model->mirror)) {
                $is_business = strpos((string)$model->mirror, 'opnsense-update.deciso.com') !== false;
                if ($is_business) {
                    $model->type = 'business';
                    $model->flavour = 'latest';
                }
            }
        } elseif ($model instanceof General) {
            $config = Config::getInstance()->object();

            if (isset($config->system)) {
                if (!empty((string)$config->system->hostname)) {
                    $model->hostname = (string)$config->system->hostname;
                }
                if (!empty((string)$config->system->domain)) {
                    $model->domain = (string)$config->system->domain;
                }
                if (!empty((string)$config->system->timezone)) {
                    $model->timezone = (string)$config->system->timezone;
                }
                if (!empty((string)$config->system->language)) {
                    $model->language = (string)$config->system->language;
                }
                if (!empty((string)$config->system->dnssearchdomain)) {
                    $model->dnssearchdomain = (string)$config->system->dnssearchdomain;
                }
                if (isset($config->system->dnsallowoverride)) {
                    $model->dnsallowoverride = !empty((string)$config->system->dnsallowoverride) ? '1' : '0';
                }
                if (!empty((string)$config->system->dnsallowoverride_exclude)) {
                    $model->dnsallowoverride_exclude = (string)$config->system->dnsallowoverride_exclude;
                }
                if (isset($config->system->dnslocalhost)) {
                    $model->dnslocalhost = '1';
                }
                if (isset($config->system->prefer_ipv4)) {
                    $model->prefer_ipv4 = '1';
                }
                if (isset($config->system->gw_switch_default)) {
                    $model->gw_switch_default = '1';
                }
                if (!empty((string)$config->system->picture)) {
                    $model->picture = (string)$config->system->picture;
                }
                if (!empty((string)$config->system->picture_filename)) {
                    $model->picture_filename = (string)$config->system->picture_filename;
                }

                // Migrate DNS servers and their corresponding gateways
                if (isset($config->system->dnsserver)) {
                    $idx = 1;
                    foreach ($config->system->dnsserver as $dns) {
                        $dnsip = trim((string)$dns);
                        if (!empty($dnsip)) {
                            $gwkey = "dns{$idx}gw";
                            $gw = !empty((string)$config->system->$gwkey) ? (string)$config->system->$gwkey : 'none';

                            $entry = $model->dnsservers->Add();
                            $entry->server = $dnsip;
                            $entry->gateway = $gw;
                            $idx++;
                        }
                    }
                }
            }

            // Top-level theme key
            if (!empty((string)$config->theme)) {
                $model->theme = (string)$config->theme;
            } elseif (!empty((string)$config->system->theme)) {
                $model->theme = (string)$config->system->theme;
            }

            parent::run($model);
        } elseif ($model instanceof Admin) {
            $config = Config::getInstance()->object();

            // 1. WebGUI settings migration
            if (isset($config->system->webgui)) {
                $webgui = $config->system->webgui;
                if (!empty((string)$webgui->protocol)) {
                    $model->webgui->protocol = (string)$webgui->protocol;
                }
                if (isset($webgui->port) && (string)$webgui->port !== '') {
                    $model->webgui->port = (string)$webgui->port;
                }
                if (!empty((string)$webgui->{'ssl-certref'})) {
                    $model->webgui->{'ssl-certref'} = (string)$webgui->{'ssl-certref'};
                }
                if (!empty((string)$webgui->{'ssl-ciphers'})) {
                    // Convert legacy colon-delimited or comma-delimited string to comma-separated CSVListField
                    $ciphers = explode(':', (string)$webgui->{'ssl-ciphers'});
                    if (count($ciphers) === 1 && strpos((string)$webgui->{'ssl-ciphers'}, ',') !== false) {
                        $ciphers = explode(',', (string)$webgui->{'ssl-ciphers'});
                    }
                    $model->webgui->{'ssl-ciphers'} = implode(',', array_filter(array_map('trim', $ciphers)));
                }
                if (isset($webgui->{'ssl-hsts'}) && !empty((string)$webgui->{'ssl-hsts'})) {
                    $model->webgui->{'ssl-hsts'} = '1';
                }
                if (isset($webgui->disablehttpredirect) && !empty((string)$webgui->disablehttpredirect)) {
                    $model->webgui->disablehttpredirect = '1';
                }
                if (isset($webgui->httpaccesslog) && !empty((string)$webgui->httpaccesslog)) {
                    $model->webgui->httpaccesslog = '1';
                }
                if (!empty((string)$webgui->session_timeout)) {
                    $model->webgui->session_timeout = (string)$webgui->session_timeout;
                }
                if (isset($webgui->compression) && (string)$webgui->compression !== '') {
                    $model->webgui->compression = (string)$webgui->compression;
                }
                if (isset($webgui->nodnsrebindcheck) && !empty((string)$webgui->nodnsrebindcheck)) {
                    $model->webgui->nodnsrebindcheck = '1';
                }
                if (isset($webgui->nohttpreferercheck) && !empty((string)$webgui->nohttpreferercheck)) {
                    $model->webgui->nohttpreferercheck = '1';
                }
                if (isset($webgui->noroot) && !empty((string)$webgui->noroot)) {
                    $model->webgui->noroot = '1';
                }
                if (!empty((string)$webgui->althostnames)) {
                    $model->webgui->althostnames = (string)$webgui->althostnames;
                }
                if (!empty((string)$webgui->interfaces)) {
                    $model->webgui->interfaces = (string)$webgui->interfaces;
                }
                if (!empty((string)$webgui->authmode)) {
                    $model->webgui->authmode = (string)$webgui->authmode;
                }
                if (isset($webgui->quietlogin) && !empty((string)$webgui->quietlogin)) {
                    $model->webgui->quietlogin = '1';
                }
            }

            // 2. OpenSSH settings migration
            if (isset($config->system->ssh)) {
                $ssh = $config->system->ssh;
                if (!empty((string)$ssh->enabled)) {
                    $model->ssh->enabled = '1';
                }
                if (isset($ssh->port) && (string)$ssh->port !== '') {
                    $model->ssh->port = (string)$ssh->port;
                }
                if (!empty((string)$ssh->interfaces)) {
                    $model->ssh->interfaces = (string)$ssh->interfaces;
                }
                if (!empty((string)$ssh->kex)) {
                    $model->ssh->kex = (string)$ssh->kex;
                }
                if (!empty((string)$ssh->ciphers)) {
                    $model->ssh->ciphers = (string)$ssh->ciphers;
                }
                if (!empty((string)$ssh->macs)) {
                    $model->ssh->macs = (string)$ssh->macs;
                }
                if (!empty((string)$ssh->keys)) {
                    $model->ssh->keys = (string)$ssh->keys;
                }
                if (!empty((string)$ssh->keysig)) {
                    $model->ssh->keysig = (string)$ssh->keysig;
                }
                if (!empty((string)$ssh->rekeylimit)) {
                    $model->ssh->rekeylimit = (string)$ssh->rekeylimit;
                }
                if (isset($ssh->passwordauth) && !empty((string)$ssh->passwordauth)) {
                    $model->ssh->passwordauth = '1';
                }
                if (isset($ssh->permitrootlogin)) {
                    $prl = (string)$ssh->permitrootlogin;
                    if (in_array($prl, ['yes', 'no', 'without-password', 'prohibit-password'])) {
                        $model->ssh->permitrootlogin = ($prl === 'prohibit-password') ? 'without-password' : $prl;
                    } else {
                        $model->ssh->permitrootlogin = !empty($prl) ? 'yes' : 'no';
                    }
                }
                if (isset($ssh->noauto)) {
                    $model->ssh->noauto = !empty((string)$ssh->noauto) ? '1' : '0';
                }
            }

            // 3. Console & Shell settings migration
            if (isset($config->system)) {
                $sys = $config->system;
                if (isset($sys->disableconsolemenu) && !empty((string)$sys->disableconsolemenu)) {
                    $model->console->disableconsolemenu = '1';
                }
                if (isset($sys->usevirtualterminal)) {
                    $model->console->usevirtualterminal = !empty((string)$sys->usevirtualterminal) ? '1' : '0';
                }
                if (isset($sys->sudo_allow_wheel) && (string)$sys->sudo_allow_wheel !== '') {
                    $model->console->sudo_allow_wheel = (string)$sys->sudo_allow_wheel;
                }
                if (!empty((string)$sys->sudo_allow_group)) {
                    $model->console->sudo_allow_group = (string)$sys->sudo_allow_group;
                }
                if (!empty((string)$sys->user_allow_gen_token)) {
                    $model->console->user_allow_gen_token = (string)$sys->user_allow_gen_token;
                }
                if (!empty((string)$sys->serialspeed)) {
                    $model->console->serialspeed = (string)$sys->serialspeed;
                }
                if (isset($sys->serialusb) && !empty((string)$sys->serialusb)) {
                    $model->console->serialusb = '1';
                }
                if (!empty((string)$sys->primaryconsole)) {
                    $model->console->primaryconsole = (string)$sys->primaryconsole;
                }
                if (!empty((string)$sys->secondaryconsole)) {
                    $model->console->secondaryconsole = (string)$sys->secondaryconsole;
                }
                if (!empty((string)$sys->autologout)) {
                    $model->console->autologout = (string)$sys->autologout;
                }
                if (!empty((string)$sys->deployment)) {
                    $model->development->deployment = (string)$sys->deployment;
                }
            }

            // 4. Syslog settings migration (inverted)
            if (isset($config->syslog->nologlighttpd) && !empty((string)$config->syslog->nologlighttpd)) {
                $model->development->nologlighttpd = '1';
            } else {
                $model->development->nologlighttpd = '0';
            }

            parent::run($model);
        } elseif ($model instanceof Misc) {
            $config = Config::getInstance()->object();
            if (isset($config->system)) {
                $sys = $config->system;
                if (isset($sys->powerd_enable)) {
                    $model->powerd_enable = !empty((string)$sys->powerd_enable) ? '1' : '0';
                }
                if (!empty((string)$sys->powerd_ac_mode)) {
                    $model->powerd_ac_mode = (string)$sys->powerd_ac_mode;
                }
                if (!empty((string)$sys->powerd_battery_mode)) {
                    $model->powerd_battery_mode = (string)$sys->powerd_battery_mode;
                }
                if (!empty((string)$sys->powerd_normal_mode)) {
                    $model->powerd_normal_mode = (string)$sys->powerd_normal_mode;
                }
                if (!empty((string)$sys->crypto_hardware)) {
                    $model->crypto_hardware = (string)$sys->crypto_hardware;
                }
                if (!empty((string)$sys->thermal_hardware)) {
                    $model->thermal_hardware = (string)$sys->thermal_hardware;
                }
                if (isset($sys->use_mfs_var)) {
                    $model->use_mfs_var = !empty((string)$sys->use_mfs_var) ? '1' : '0';
                }
                if (isset($sys->max_mfs_var) && (string)$sys->max_mfs_var !== '') {
                    $model->max_mfs_var = (string)$sys->max_mfs_var;
                }
                if (isset($sys->use_mfs_tmp)) {
                    $model->use_mfs_tmp = !empty((string)$sys->use_mfs_tmp) ? '1' : '0';
                }
                if (isset($sys->max_mfs_tmp) && (string)$sys->max_mfs_tmp !== '') {
                    $model->max_mfs_tmp = (string)$sys->max_mfs_tmp;
                }
                if (isset($sys->use_swap_file)) {
                    $model->use_swap_file = !empty((string)$sys->use_swap_file) ? '1' : '0';
                }
                if (isset($sys->disablebeep)) {
                    $model->disablebeep = !empty((string)$sys->disablebeep) ? '1' : '0';
                }
            }

            parent::run($model);
        }
    }
}
