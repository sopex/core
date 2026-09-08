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
use OPNsense\Routing\Gateways;

class General extends BaseModel
{
    /**
     * Populate dynamic option lists on init
     */
    protected function init()
    {
        parent::init();

        /* Populate timezone options */
        $zones = [];
        if (function_exists('get_zoneinfo')) {
            foreach (get_zoneinfo() as $zone) {
                $zones[$zone] = $zone;
            }
        } else {
            foreach (\DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC) as $zone) {
                $zones[$zone] = $zone;
            }
        }
        $zones['UTC'] = 'UTC';
        $zones['Etc/UTC'] = 'Etc/UTC';
        $this->timezone->setOptionValues($zones);

        /* Populate language options */
        if (function_exists('get_locale_list')) {
            $this->language->setOptionValues(get_locale_list());
        } else {
            $this->language->setOptionValues([
                'en_US' => 'English',
                'de_DE' => 'German',
                'es_ES' => 'Spanish',
                'fr_FR' => 'French',
                'it_IT' => 'Italian',
                'nl_NL' => 'Dutch',
                'pt_BR' => 'Portuguese (Brazil)'
            ]);
        }

        /* Populate theme options */
        $themes = [];
        $themeDirs = glob('/usr/local/opnsense/www/themes/*', GLOB_ONLYDIR);
        if (!empty($themeDirs)) {
            foreach ($themeDirs as $themedir) {
                $themeName = basename($themedir);
                $themes[$themeName] = $themeName;
            }
        } else {
            $themes = [
                'opnsense' => 'opnsense',
                'tukan' => 'tukan',
                'vicuna' => 'vicuna'
            ];
        }
        $this->theme->setOptionValues($themes);
    }

    /**
     * Support setting dnsservers via comma-separated string
     * @param string $name
     * @param mixed $value
     */
    public function __set($name, $value)
    {
        if ($name === 'dnsservers' && is_string($value)) {
            $delKeys = [];
            foreach ($this->dnsservers->iterateItems() as $key => $node) {
                $delKeys[] = $key;
            }
            foreach ($delKeys as $key) {
                $this->dnsservers->del($key);
            }
            if (!empty($value)) {
                foreach (explode(',', $value) as $server) {
                    $server = trim($server);
                    if (!empty($server)) {
                        $entry = $this->dnsservers->Add();
                        $entry->server = $server;
                        $entry->gateway = 'none';
                    }
                }
            }
            return;
        }
        parent::__set($name, $value);
    }

    /**
     * Perform deep model validations matching system_general.php
     * @param bool $validateFullModel
     * @return \OPNsense\Base\Validation\Group
     */
    public function performValidation($validateFullModel = false)
    {
        $messages = parent::performValidation($validateFullModel);

        /* 1. Hostname single label check (no dots, no leading/trailing hyphens, valid chars) */
        if (!empty((string)$this->hostname)) {
            $h = (string)$this->hostname;
            if (strpos($h, '.') !== false ||
                !preg_match('/^[a-z0-9](?:[a-z0-9\-]*[a-z0-9])?$/i', $h)) {
                $messages->appendMessage(new Message(
                    gettext("The hostname may only contain the characters a-z, 0-9 and '-'."),
                    'hostname'
                ));
            }
        }

        /* 2. DNS Search domain validation */
        if (!empty((string)$this->dnssearchdomain)) {
            foreach (explode(',', (string)$this->dnssearchdomain) as $searchdomain) {
                $searchdomain = trim($searchdomain);
                if (!empty($searchdomain)) {
                    $isValidDomain = false;
                    if (function_exists('is_domain')) {
                        $isValidDomain = is_domain($searchdomain, true);
                    } else {
                        $isValidDomain = ($searchdomain === '.') ||
                            (preg_match('/^(?:(?:[a-z0-9]|[a-z0-9][a-z0-9\-]*[a-z0-9])\.)*(?:[a-z0-9]|[a-z0-9][a-z0-9\-]*[a-z0-9])$/i', $searchdomain) === 1);
                    }
                    if (!$isValidDomain) {
                        $messages->appendMessage(new Message(
                            gettext("A search domain may only contain the characters a-z, 0-9, '-' and '.'."),
                            'dnssearchdomain'
                        ));
                        break;
                    }
                }
            }
        }

        /* 3. Timezone validation */
        if (!empty((string)$this->timezone)) {
            $validZones = function_exists('get_zoneinfo') ? get_zoneinfo() : \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC);
            if (!in_array((string)$this->timezone, $validZones) && (string)$this->timezone !== 'Etc/UTC' && (string)$this->timezone !== 'UTC') {
                $messages->appendMessage(new Message(
                    gettext('The selected time zone is invalid.'),
                    'timezone'
                ));
            }
        }

        /* 4. DNS Servers and Gateways Validation */
        $gateways = class_exists('OPNsense\Routing\Gateways') ? (new Gateways())->gatewaysIndexedByName() : [];
        $all_intf_details = function_exists('legacy_interfaces_details') ? legacy_interfaces_details() : [];
        $direct_networks_list = [];

        foreach ($all_intf_details as $ifname => $ifcnf) {
            foreach ($ifcnf['ipv4'] ?? [] as $addr) {
                if (function_exists('gen_subnet')) {
                    $direct_networks_list[] = gen_subnet($addr['ipaddr'], $addr['subnetbits']) . "/{$addr['subnetbits']}";
                }
            }
            foreach ($ifcnf['ipv6'] ?? [] as $addr) {
                if (function_exists('gen_subnetv6')) {
                    $direct_networks_list[] = gen_subnetv6($addr['ipaddr'], $addr['subnetbits']) . "/{$addr['subnetbits']}";
                }
            }
        }
        if (function_exists('get_staticroutes')) {
            $direct_networks_list = array_merge($direct_networks_list, get_staticroutes(true));
        }

        foreach ($this->dnsservers->iterateItems() as $key => $dnsItem) {
            $dnsServer = (string)$dnsItem->server;
            $dnsGw = (string)$dnsItem->gateway;

            $is_ip = function_exists('is_ipaddr') ? is_ipaddr($dnsServer) : (filter_var($dnsServer, FILTER_VALIDATE_IP) !== false);
            $is_ipv4 = function_exists('is_ipaddrv4') ? is_ipaddrv4($dnsServer) : (filter_var($dnsServer, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false);
            $is_ipv6 = function_exists('is_ipaddrv6') ? is_ipaddrv6($dnsServer) : (filter_var($dnsServer, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false);

            if (!empty($dnsServer) && !$is_ip) {
                $messages->appendMessage(new Message(
                    sprintf(gettext('A valid IP address must be specified for DNS server "%s".'), $dnsServer),
                    "dnsservers.{$key}.server"
                ));
                continue;
            }

            if (!empty($dnsGw) && $dnsGw !== 'none') {
                if (empty($dnsServer) || !$is_ip) {
                    $messages->appendMessage(new Message(
                        gettext('A valid IP address must be specified when assigning a gateway to a DNS server.'),
                        "dnsservers.{$key}.server"
                    ));
                    continue;
                }

                if (isset($gateways[$dnsGw])) {
                    if ($is_ipv4 && ($gateways[$dnsGw]['ipprotocol'] ?? '') !== 'inet') {
                        $messages->appendMessage(new Message(
                            sprintf(gettext("You can not specify IPv6 gateway '%s' for IPv4 DNS server '%s'"), $dnsGw, $dnsServer),
                            "dnsservers.{$key}.gateway"
                        ));
                        continue;
                    }
                    if ($is_ipv6 && ($gateways[$dnsGw]['ipprotocol'] ?? '') !== 'inet6') {
                        $messages->appendMessage(new Message(
                            sprintf(gettext("You can not specify IPv4 gateway '%s' for IPv6 DNS server '%s'"), $dnsGw, $dnsServer),
                            "dnsservers.{$key}.gateway"
                        ));
                        continue;
                    }
                }

                $af = $is_ipv6 ? 'inet6' : 'inet';
                foreach ($direct_networks_list as $direct_network) {
                    if ($af === 'inet' && function_exists('is_subnetv4') && !is_subnetv4($direct_network)) {
                        continue;
                    } elseif ($af === 'inet6' && function_exists('is_subnetv6') && !is_subnetv6($direct_network)) {
                        continue;
                    }
                    if (function_exists('ip_in_subnet') && ip_in_subnet($dnsServer, $direct_network)) {
                        $messages->appendMessage(new Message(
                            sprintf(gettext('You can not assign a gateway to DNS server "%s" which is on a directly connected network.'), $dnsServer),
                            "dnsservers.{$key}.gateway"
                        ));
                        break;
                    }
                }
            }
        }

        return $messages;
    }

    /**
     * Backward-compatibility synchronizer: writes model state to legacy $config['system']
     * and $config['theme'] to guarantee zero disruption to existing system services.
     */
    public function syncToLegacyConfig()
    {
        $config = Config::getInstance()->object();
        if (!isset($config->system)) {
            $config->addChild('system');
        }

        $config->system->hostname = (string)$this->hostname;
        $config->system->domain = (string)$this->domain;
        $config->system->timezone = (string)$this->timezone;
        $config->system->language = (string)$this->language;
        $config->theme = (string)$this->theme;

        if ((string)$this->prefer_ipv4 === '1') {
            $config->system->prefer_ipv4 = 'true';
        } else {
            unset($config->system->prefer_ipv4);
        }

        if ((string)$this->dnslocalhost === '1') {
            $config->system->dnslocalhost = 'true';
        } else {
            unset($config->system->dnslocalhost);
        }

        if ((string)$this->gw_switch_default === '1') {
            $config->system->gw_switch_default = 'true';
        } else {
            unset($config->system->gw_switch_default);
        }

        $config->system->dnsallowoverride = (string)$this->dnsallowoverride;
        if (!empty((string)$this->dnsallowoverride_exclude)) {
            $config->system->dnsallowoverride_exclude = (string)$this->dnsallowoverride_exclude;
        } else {
            unset($config->system->dnsallowoverride_exclude);
        }

        if (!empty((string)$this->dnssearchdomain)) {
            $config->system->dnssearchdomain = (string)$this->dnssearchdomain;
        } else {
            unset($config->system->dnssearchdomain);
        }

        if (!empty((string)$this->picture)) {
            $config->system->picture = (string)$this->picture;
            $config->system->picture_filename = (string)$this->picture_filename;
        } else {
            unset($config->system->picture);
            unset($config->system->picture_filename);
        }

        /* Sync DNS servers and gateway keys */
        unset($config->system->dnsserver);
        for ($i = 1; $i <= 8; $i++) {
            unset($config->system->{"dns{$i}gw"});
        }
        $idx = 1;
        foreach ($this->dnsservers->iterateItems() as $dnsItem) {
            $server = trim((string)$dnsItem->server);
            if (!empty($server)) {
                $config->system->addChild('dnsserver', $server);
                $gw = (string)$dnsItem->gateway;
                $config->system->{"dns{$idx}gw"} = !empty($gw) ? $gw : 'none';
                $idx++;
            }
        }
    }

    /**
     * Override serializeToConfig to include legacy synchronization
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
