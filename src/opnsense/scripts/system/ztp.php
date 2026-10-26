#!/usr/local/bin/php
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

/*
 * Zero-Touch Provisioning (ZTP) helper script.
 *
 * Documented Local Seed Schema (ztp.json):
 * {
 *   "hostname": "string (optional)",
 *   "domain": "string (optional)",
 *   "timezone": "string (optional)",
 *   "dns_servers": ["ip1", "ip2"] | "ip1,ip2 (optional)",
 *   "config_file": "relative/path/to/config.xml (optional)",
 *   "config_sha256": "64-char hex SHA-256 of config.xml (optional)",
 *   "config_xml": "inline XML or base64-encoded XML (optional)",
 *   "firmware_version": "release version or 'sync' (optional)",
 *   "provisioning_url": "https://... enrollment endpoint (optional)",
 *   "enrollment_token": "secret token bound to provisioning_url (optional)",
 *   "trust_pubkey": "inline PEM public key or relative path on seed (optional)",
 *   "verify_repo": "opnsense-verify -r repository name (optional)",
 *   "ca_cert": "inline PEM CA certificate or relative path on seed (optional)",
 *   "wan": {
 *     "if": "device name",
 *     "mac": "hardware MAC address",
 *     "vlan": 100,
 *     "pcp": 0,
 *     "ipaddr": "dhcp | pppoe | IPv4/prefix",
 *     "subnet": 24,
 *     "gateway": "IPv4 gateway",
 *     "ipaddrv6": "IPv6 address",
 *     "subnetv6": 64,
 *     "pppoe_username": "string",
 *     "pppoe_password": "string",
 *     "dhcphostname": "string",
 *     "spoofmac": "string",
 *     "mtu": 1500,
 *     "descr": "string"
 *   },
 *   "lan": { ... same interface structure ... },
 *   "credentials": {
 *     "root_password": "plaintext or bcrypt/sha512 hash",
 *     "root_password_hash": "bcrypt/sha512 hash",
 *     "ssh_keys": ["ssh-ed25519 ..."],
 *     "enable_ssh": true,
 *     "api_key": "string",
 *     "api_secret": "string"
 *   }
 * }
 *
 * Documented Network Provisioning Response Schema (JSON):
 * {
 *   "config_xml": "inline XML or base64-encoded XML (optional if config_url set)",
 *   "config_url": "https://... URL to config.xml (optional)",
 *   "signature": "cryptographic signature for config.xml (required unless signature_url or .sig provided)",
 *   "signature_url": "https://... URL to config.xml.sig (optional)",
 *   "config_sha256": "64-char hex SHA-256 of config.xml (optional)",
 *   "firmware_version": "release version or 'sync' (optional)"
 * }
 */

function ztp_run_cmd($command)
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = @proc_open($command, $descriptors, $pipes);
    if (!is_resource($proc)) {
        return ['rc' => 1, 'stdout' => '', 'stderr' => ''];
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $rc = proc_close($proc);
    return [
        'rc' => $rc,
        'stdout' => is_string($stdout) ? $stdout : '',
        'stderr' => is_string($stderr) ? $stderr : '',
    ];
}

function ztp_load_xml_string($xml_str)
{
    if (!is_string($xml_str) || trim($xml_str) === '') {
        return false;
    }
    $prev = libxml_use_internal_errors(true);
    $dom = new DOMDocument('1.0', 'UTF-8');
    $ok = $dom->loadXML($xml_str, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$ok || !$dom->documentElement || $dom->documentElement->nodeName !== 'opnsense') {
        return false;
    }
    return $dom;
}

function ztp_save_dom(DOMDocument $dom, $target_path)
{
    $dom->formatOutput = true;
    $xml_out = $dom->saveXML();
    if (!is_string($xml_out) || $xml_out === '') {
        return false;
    }
    if (ztp_load_xml_string($xml_out) === false) {
        return false;
    }
    $expected_hash = hash('sha256', $xml_out);
    $tmp_path = $target_path . '.ztp.tmp.' . getmypid();
    $written = @file_put_contents($tmp_path, $xml_out, LOCK_EX);
    if ($written === false || $written !== strlen($xml_out)) {
        @unlink($tmp_path);
        return false;
    }
    if (!@chmod($tmp_path, 0640)) {
        @unlink($tmp_path);
        return false;
    }
    $tmp_hash = @hash_file('sha256', $tmp_path);
    if (!is_string($tmp_hash) || !hash_equals($expected_hash, $tmp_hash)) {
        @unlink($tmp_path);
        return false;
    }
    if (!@rename($tmp_path, $target_path)) {
        @unlink($tmp_path);
        return false;
    }
    $final_hash = @hash_file('sha256', $target_path);
    if (!is_string($final_hash) || !hash_equals($expected_hash, $final_hash)) {
        return false;
    }
    return true;
}

function ztp_verify_with_pubkey($data_file, $sig_file, $pubkey_file)
{
    if (!is_file($data_file) || !is_file($sig_file) || !is_file($pubkey_file)) {
        return false;
    }
    $payload_bytes = file_get_contents($data_file);
    $sig_raw = file_get_contents($sig_file);
    $pubkey_pem = file_get_contents($pubkey_file);
    if (
        $payload_bytes === false ||
        $payload_bytes === '' ||
        $sig_raw === false ||
        trim($sig_raw) === '' ||
        $pubkey_pem === false ||
        trim($pubkey_pem) === ''
    ) {
        return false;
    }

    $candidates = [$sig_raw];
    $trimmed = trim($sig_raw);
    $b64 = base64_decode(preg_replace('/\s+/', '', $trimmed), true);
    $sig_sources = [$sig_raw];
    if ($b64 !== false && $b64 !== '') {
        $sig_sources[] = $b64;
        $candidates[] = $b64;
    }
    foreach ($sig_sources as $sig_src) {
        if (preg_match('/SIGNATURE\s*\r?\n(.*?)\r?\nCERT/s', $sig_src, $m)) {
            $candidates[] = $m[1];
            $sig_block = trim($m[1]);
            $candidates[] = $sig_block;
            $b64_block = base64_decode(preg_replace('/\s+/', '', $sig_block), true);
            if ($b64_block !== false && $b64_block !== '') {
                $candidates[] = $b64_block;
            }
        }
    }
    if (ctype_xdigit($trimmed) && strlen($trimmed) % 2 === 0) {
        $hex = hex2bin($trimmed);
        if ($hex !== false && $hex !== '') {
            $candidates[] = $hex;
        }
    }

    if (function_exists('openssl_pkey_get_public') && function_exists('openssl_verify')) {
        $pkey = @openssl_pkey_get_public($pubkey_pem);
        if ($pkey !== false) {
            foreach ($candidates as $cand) {
                foreach ([OPENSSL_ALGO_SHA256, OPENSSL_ALGO_SHA512, OPENSSL_ALGO_SHA384] as $algo) {
                    if (@openssl_verify($payload_bytes, $cand, $pkey, $algo) === 1) {
                        return true;
                    }
                }
            }
        }
    }

    foreach ($candidates as $cand) {
        $bin_sig_path = $sig_file . '.bin.' . getmypid();
        if (@file_put_contents($bin_sig_path, $cand) !== false) {
            $dgst_cmd = 'openssl dgst -sha256 -verify ' . escapeshellarg($pubkey_file)
                . ' -signature ' . escapeshellarg($bin_sig_path)
                . ' ' . escapeshellarg($data_file);
            $res = ztp_run_cmd($dgst_cmd);
            if ($res['rc'] === 0) {
                @unlink($bin_sig_path);
                return true;
            }
            $ed_cmd = 'openssl pkeyutl -verify -pubin -inkey ' . escapeshellarg($pubkey_file)
                . ' -rawin -in ' . escapeshellarg($data_file)
                . ' -sigfile ' . escapeshellarg($bin_sig_path);
            $res_ed = ztp_run_cmd($ed_cmd);
            @unlink($bin_sig_path);
            if ($res_ed['rc'] === 0) {
                return true;
            }
        }
    }

    return false;
}

function ztp_verify_file_signature(
    $data_file,
    $sig_file,
    $verify_cmd = 'opnsense-verify',
    $pubkey_file = '',
    $verify_repo = ''
) {
    if (!is_file($data_file) || filesize($data_file) === 0 || !is_file($sig_file) || filesize($sig_file) === 0) {
        return false;
    }

    if ($pubkey_file !== '' && is_file($pubkey_file) && filesize($pubkey_file) > 0) {
        return ztp_verify_with_pubkey($data_file, $sig_file, $pubkey_file);
    }

    $repo_arg = '';
    if ($verify_repo !== '') {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $verify_repo)) {
            return false;
        }
        $repo_arg = ' -r ' . escapeshellarg($verify_repo);
    }

    $expected_sig_path = $data_file . '.sig';
    $cleanup_sig = false;
    if (realpath($sig_file) !== realpath($expected_sig_path)) {
        if (!@copy($sig_file, $expected_sig_path)) {
            return false;
        }
        $cleanup_sig = true;
    }

    $sig_content = @file_get_contents($expected_sig_path);
    $orig_sig_content = null;
    if (is_string($sig_content) && strpos(ltrim($sig_content), 'SIGNATURE') !== 0) {
        $decoded_sig = base64_decode(preg_replace('/\s+/', '', trim($sig_content)), true);
        if (is_string($decoded_sig) && strpos(ltrim($decoded_sig), 'SIGNATURE') === 0) {
            $orig_sig_content = $sig_content;
            @file_put_contents($expected_sig_path, $decoded_sig, LOCK_EX);
        }
    }

    $cmd = $verify_cmd . $repo_arg . ' -q ' . escapeshellarg($data_file);
    $res = ztp_run_cmd($cmd);

    if ($cleanup_sig) {
        @unlink($expected_sig_path);
    } elseif ($orig_sig_content !== null) {
        @file_put_contents($expected_sig_path, $orig_sig_content, LOCK_EX);
    }

    return $res['rc'] === 0;
}

function ztp_resolve_contained_file($base_dir, $rel_path)
{
    if (!is_string($base_dir) || $base_dir === '' || !is_string($rel_path) || trim($rel_path) === '') {
        return false;
    }
    $real_base = realpath($base_dir);
    if ($real_base === false || !is_dir($real_base)) {
        return false;
    }
    $candidate = rtrim($base_dir, '/') . '/' . ltrim($rel_path, '/');
    $real_cand = realpath($candidate);
    if ($real_cand === false || !is_file($real_cand)) {
        return false;
    }
    $prefix = rtrim($real_base, '/') . '/';
    if (strpos($real_cand, $prefix) !== 0) {
        return false;
    }
    return $real_cand;
}

function ztp_get_or_create_child(DOMDocument $dom, DOMElement $parent, $name)
{
    foreach ($parent->childNodes as $child) {
        if ($child instanceof DOMElement && $child->nodeName === $name) {
            return $child;
        }
    }
    $el = $dom->createElement($name);
    $parent->appendChild($el);
    return $el;
}

function ztp_set_child_text(DOMDocument $dom, DOMElement $parent, $name, $value)
{
    $el = ztp_get_or_create_child($dom, $parent, $name);
    while ($el->firstChild) {
        $el->removeChild($el->firstChild);
    }
    if ($value !== null && $value !== '') {
        $el->appendChild($dom->createTextNode((string)$value));
    }
    return $el;
}

function ztp_remove_children(DOMElement $parent, $name)
{
    $to_remove = [];
    foreach ($parent->childNodes as $child) {
        if ($child instanceof DOMElement && $child->nodeName === $name) {
            $to_remove[] = $child;
        }
    }
    foreach ($to_remove as $node) {
        $parent->removeChild($node);
    }
}

function ztp_netmask_to_prefix($mask)
{
    $mask = trim((string)$mask);
    if (ctype_digit($mask)) {
        $p = (int)$mask;
        return ($p >= 0 && $p <= 128) ? (string)$p : '24';
    }
    $long = ip2long($mask);
    if ($long === false) {
        return '24';
    }
    $bin = decbin($long);
    return (string)substr_count($bin, '1');
}

function ztp_resolve_if_by_mac($mac, array $mac_map)
{
    $mac = strtolower(trim((string)$mac));
    if ($mac === '') {
        return '';
    }
    foreach ($mac_map as $ifname => $ifmac) {
        if (strtolower(trim((string)$ifmac)) === $mac) {
            return (string)$ifname;
        }
    }
    return '';
}

function ztp_generate_uuid()
{
    $uuid_bytes = random_bytes(16);
    $uuid_bytes[6] = chr((ord($uuid_bytes[6]) & 0x0f) | 0x40);
    $uuid_bytes[8] = chr((ord($uuid_bytes[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($uuid_bytes), 4));
}

function ztp_extract_firmware_version_from_dom(DOMDocument $dom)
{
    $xpath = new DOMXPath($dom);
    $paths = [
        '/opnsense/firmware_version',
        '/opnsense/system/firmware_version',
        '/opnsense/system/firmware/version',
    ];
    foreach ($paths as $q) {
        $nodes = $xpath->query($q);
        if ($nodes && $nodes->length > 0) {
            $val = trim($nodes->item(0)->textContent);
            if ($val !== '') {
                return $val;
            }
        }
    }
    return '';
}

function ztp_get_child_text(DOMElement $parent, $name)
{
    foreach ($parent->childNodes as $child) {
        if ($child instanceof DOMElement && $child->nodeName === $name) {
            return trim($child->textContent);
        }
    }
    return '';
}

function ztp_apply_interface(
    DOMDocument $dom,
    DOMElement $root,
    $ifkey,
    array $ifdata,
    array $mac_map,
    $exclude_dev = ''
) {
    $interfaces = ztp_get_or_create_child($dom, $root, 'interfaces');
    $ifnode = ztp_get_or_create_child($dom, $interfaces, $ifkey);

    $dev = $ifdata['if'] ?? $ifdata['interface'] ?? $ifdata['device'] ?? '';
    if ($dev === '' && !empty($ifdata['mac'])) {
        $dev = ztp_resolve_if_by_mac($ifdata['mac'], $mac_map);
    } elseif ($dev === '' && !empty($ifdata['hwaddr'])) {
        $dev = ztp_resolve_if_by_mac($ifdata['hwaddr'], $mac_map);
    }

    if ($dev === '') {
        $existing_if = ztp_get_child_text($ifnode, 'if');
        if ($existing_if !== '' && strpos($existing_if, 'mismatch') !== 0) {
            $dev = $existing_if;
        } elseif (!empty($mac_map)) {
            foreach (array_keys($mac_map) as $cand_if) {
                if ($cand_if !== $exclude_dev) {
                    $dev = $cand_if;
                    break;
                }
            }
            if ($dev === '') {
                $ifnames = array_keys($mac_map);
                $dev = $ifnames[0];
            }
        }
    }

    $vlan_parent = '';
    $vlan_tag = (string)($ifdata['vlan'] ?? $ifdata['vlan_tag'] ?? '');
    if ($vlan_tag !== '' && $dev !== '' && strpos($dev, '_vlan') === false) {
        $vlan_parent = $dev;
        $vlans = ztp_get_or_create_child($dom, $root, 'vlans');
        $vlan_if = $dev . '_vlan' . $vlan_tag;
        $found_vlan = false;
        foreach ($vlans->childNodes as $vnode) {
            if ($vnode instanceof DOMElement && $vnode->nodeName === 'vlan') {
                if (ztp_get_child_text($vnode, 'vlanif') === $vlan_if) {
                    $found_vlan = true;
                    break;
                }
            }
        }
        if (!$found_vlan) {
            $vlan_el = $dom->createElement('vlan');
            $vlan_el->setAttribute('uuid', ztp_generate_uuid());
            ztp_set_child_text($dom, $vlan_el, 'if', $dev);
            ztp_set_child_text($dom, $vlan_el, 'tag', $vlan_tag);
            ztp_set_child_text($dom, $vlan_el, 'pcp', (string)($ifdata['pcp'] ?? '0'));
            ztp_set_child_text($dom, $vlan_el, 'proto', '');
            ztp_set_child_text($dom, $vlan_el, 'descr', '');
            ztp_set_child_text($dom, $vlan_el, 'vlanif', $vlan_if);
            $vlans->appendChild($vlan_el);
        }
        $dev = $vlan_if;
    }

    if ($dev !== '') {
        ztp_set_child_text($dom, $ifnode, 'if', $dev);
    }
    ztp_set_child_text($dom, $ifnode, 'enable', '1');
    ztp_set_child_text($dom, $ifnode, 'lock', '1');

    $ipaddr = $ifdata['ipaddr'] ?? $ifdata['ip'] ?? $ifdata['address'] ?? $ifdata['ipv4'] ?? '';
    $subnet = $ifdata['subnet'] ?? $ifdata['netmask'] ?? $ifdata['prefixlen'] ?? '';
    $gateway = $ifdata['gateway'] ?? $ifdata['gw'] ?? '';
    $pppoe_user = $ifdata['pppoe_username'] ?? $ifdata['username'] ?? '';
    $pppoe_pass = $ifdata['pppoe_password'] ?? $ifdata['password'] ?? '';

    if ($ipaddr === 'pppoe' || ($ifkey === 'wan' && $pppoe_user !== '' && ($ipaddr === '' || $ipaddr === 'pppoe'))) {
        $ppps = ztp_get_or_create_child($dom, $root, 'ppps');
        $ppp = ztp_get_or_create_child($dom, $ppps, 'ppp');
        ztp_set_child_text($dom, $ppp, 'ptpid', '1');
        ztp_set_child_text($dom, $ppp, 'type', 'pppoe');
        ztp_set_child_text($dom, $ppp, 'if', 'pppoe1');
        if ($dev !== '') {
            ztp_set_child_text($dom, $ppp, 'ports', $dev);
        }
        ztp_set_child_text($dom, $ppp, 'username', $pppoe_user);
        ztp_set_child_text($dom, $ppp, 'password', base64_encode((string)$pppoe_pass));
        ztp_set_child_text($dom, $ifnode, 'if', 'pppoe1');
        ztp_set_child_text($dom, $ifnode, 'ipaddr', 'pppoe');
    } elseif ($ipaddr === 'dhcp') {
        ztp_set_child_text($dom, $ifnode, 'ipaddr', 'dhcp');
        ztp_remove_children($ifnode, 'subnet');
        ztp_remove_children($ifnode, 'gateway');
    } elseif ($ipaddr !== '') {
        if (strpos($ipaddr, '/') !== false) {
            $parts = explode('/', $ipaddr, 2);
            $ipaddr = $parts[0];
            $subnet = $parts[1];
        }
        if ($subnet === '') {
            $subnet = '24';
        } else {
            $subnet = ztp_netmask_to_prefix($subnet);
        }
        ztp_set_child_text($dom, $ifnode, 'ipaddr', $ipaddr);
        ztp_set_child_text($dom, $ifnode, 'subnet', $subnet);
    }

    if ($gateway !== '' && $ipaddr !== 'dhcp') {
        $gw_name = strtoupper($ifkey) . '_GW';
        ztp_set_child_text($dom, $ifnode, 'gateway', $gw_name);
        $gateways = ztp_get_or_create_child($dom, $root, 'gateways');
        $gw_el = null;
        foreach ($gateways->childNodes as $gnode) {
            if ($gnode instanceof DOMElement && $gnode->nodeName === 'gateway_item') {
                if (ztp_get_child_text($gnode, 'name') === $gw_name) {
                    $gw_el = $gnode;
                    break;
                }
            }
        }
        if ($gw_el === null) {
            $gw_el = $dom->createElement('gateway_item');
            $gateways->appendChild($gw_el);
        }
        ztp_set_child_text($dom, $gw_el, 'interface', $ifkey);
        ztp_set_child_text($dom, $gw_el, 'gateway', $gateway);
        ztp_set_child_text($dom, $gw_el, 'name', $gw_name);
        ztp_set_child_text($dom, $gw_el, 'weight', '1');
        ztp_set_child_text($dom, $gw_el, 'ipprotocol', 'inet');
        if ($ifkey === 'wan' || !empty($ifdata['defaultgw'])) {
            ztp_set_child_text($dom, $gw_el, 'defaultgw', '1');
        }
    }

    $ipaddrv6 = (string)($ifdata['ipaddrv6'] ?? $ifdata['ipv6'] ?? '');
    $subnetv6 = (string)($ifdata['subnetv6'] ?? '');
    if ($ipaddrv6 !== '') {
        if (strpos($ipaddrv6, '/') !== false) {
            $parts6 = explode('/', $ipaddrv6, 2);
            $ipaddrv6 = $parts6[0];
            $subnetv6 = $parts6[1];
        }
        ztp_set_child_text($dom, $ifnode, 'ipaddrv6', $ipaddrv6);
    }
    if ($subnetv6 !== '') {
        ztp_set_child_text($dom, $ifnode, 'subnetv6', $subnetv6);
    }

    foreach (['dhcphostname', 'spoofmac', 'mtu', 'descr'] as $prop) {
        if (isset($ifdata[$prop]) && $ifdata[$prop] !== '') {
            ztp_set_child_text($dom, $ifnode, $prop, (string)$ifdata[$prop]);
        }
    }

    return [
        'if' => $dev,
        'ipaddr' => $ipaddr,
        'subnet' => $subnet !== '' ? ztp_netmask_to_prefix($subnet) : '',
        'gateway' => $gateway,
        'vlan_parent' => $vlan_parent,
        'vlan_tag' => $vlan_tag,
    ];
}

function ztp_cleanup_mismatch_interfaces(DOMDocument $dom, DOMElement $root, array $mac_map)
{
    $interfaces = ztp_get_or_create_child($dom, $root, 'interfaces');
    $used_devs = [];
    foreach ($interfaces->childNodes as $child) {
        if ($child instanceof DOMElement) {
            $ifval = ztp_get_child_text($child, 'if');
            if ($ifval !== '' && strpos($ifval, 'mismatch') !== 0) {
                $used_devs[$ifval] = true;
                $base = preg_replace('/_vlan[0-9]+$/', '', $ifval);
                $used_devs[$base] = true;
            }
        }
    }

    $avail_devs = [];
    foreach (array_keys($mac_map) as $ifname) {
        if (!isset($used_devs[$ifname])) {
            $avail_devs[] = $ifname;
        }
    }

    $to_remove = [];
    foreach ($interfaces->childNodes as $child) {
        if ($child instanceof DOMElement) {
            $ifval = ztp_get_child_text($child, 'if');
            if (strpos($ifval, 'mismatch') === 0) {
                if (!empty($avail_devs)) {
                    $assigned = array_shift($avail_devs);
                    ztp_set_child_text($dom, $child, 'if', $assigned);
                    ztp_set_child_text($dom, $child, 'lock', '1');
                } elseif (!empty($mac_map)) {
                    $to_remove[] = $child;
                } else {
                    ztp_set_child_text($dom, $child, 'lock', '1');
                }
            }
        }
    }
    foreach ($to_remove as $node) {
        $interfaces->removeChild($node);
    }
}

function ztp_set_root_credentials(DOMDocument $dom, DOMElement $system, array $creds)
{
    $root_user = null;
    foreach ($system->childNodes as $child) {
        if ($child instanceof DOMElement && $child->nodeName === 'user') {
            if (ztp_get_child_text($child, 'name') === 'root') {
                $root_user = $child;
                break;
            }
        }
    }
    if ($root_user === null) {
        $root_user = $dom->createElement('user');
        ztp_set_child_text($dom, $root_user, 'name', 'root');
        ztp_set_child_text($dom, $root_user, 'descr', 'System Administrator');
        ztp_set_child_text($dom, $root_user, 'scope', 'system');
        ztp_set_child_text($dom, $root_user, 'groupname', 'admins');
        ztp_set_child_text($dom, $root_user, 'uid', '0');
        $system->appendChild($root_user);
    }

    $pass_hash = $creds['password_hash'] ?? '';
    $pass_plain = $creds['password'] ?? '';
    if ($pass_hash !== '') {
        ztp_set_child_text($dom, $root_user, 'password', $pass_hash);
    } elseif ($pass_plain !== '') {
        if (preg_match('/^\$(2[ayb]|6|argon2i|argon2id)\$/', $pass_plain)) {
            $hash = $pass_plain;
        } else {
            $hash = password_hash($pass_plain, PASSWORD_BCRYPT);
        }
        ztp_set_child_text($dom, $root_user, 'password', $hash);
    }

    $ssh_keys = $creds['ssh_keys'] ?? null;
    if (!empty($ssh_keys)) {
        if (is_array($ssh_keys)) {
            $ssh_keys_str = implode("\n", $ssh_keys);
        } else {
            $ssh_keys_str = (string)$ssh_keys;
        }
        ztp_set_child_text($dom, $root_user, 'authorizedkeys', base64_encode(trim($ssh_keys_str)));
        $ssh_el = ztp_get_or_create_child($dom, $system, 'ssh');
        ztp_set_child_text($dom, $ssh_el, 'enabled', 'enabled');
        ztp_set_child_text($dom, $ssh_el, 'permitrootlogin', '1');
    }

    if (!empty($creds['enable_ssh'])) {
        $ssh_el = ztp_get_or_create_child($dom, $system, 'ssh');
        ztp_set_child_text($dom, $ssh_el, 'enabled', 'enabled');
        ztp_set_child_text($dom, $ssh_el, 'permitrootlogin', '1');
        ztp_set_child_text($dom, $ssh_el, 'passwordauth', '1');
    }

    if (!empty($creds['api_key']) && !empty($creds['api_secret'])) {
        $apikeys = ztp_get_or_create_child($dom, $root_user, 'apikeys');
        $item = $dom->createElement('item');
        ztp_set_child_text($dom, $item, 'key', (string)$creds['api_key']);
        $secret = (string)$creds['api_secret'];
        if (!preg_match('/^\$(2[ayb]|6|argon2i|argon2id)\$/', $secret)) {
            $secret = password_hash($secret, PASSWORD_BCRYPT);
        }
        ztp_set_child_text($dom, $item, 'secret', $secret);
        $apikeys->appendChild($item);
    }
}

function ztp_decode_hex_option($raw)
{
    $raw = trim((string)$raw);
    if ($raw === '') {
        return '';
    }
    if (strpos($raw, '0x') === 0 || strpos($raw, '0X') === 0) {
        $hex = substr($raw, 2);
        if ($hex !== '' && strlen($hex) % 2 === 0 && ctype_xdigit($hex)) {
            return hex2bin($hex);
        }
    }
    if (preg_match('/^[0-9a-fA-F]{1,2}(:[0-9a-fA-F]{1,2})+$/', $raw)) {
        $bytes = '';
        foreach (explode(':', $raw) as $part) {
            $bytes .= chr(hexdec($part));
        }
        return $bytes;
    }
    if (preg_match('/\\\\([0-3][0-7]{2}|x[0-9a-fA-F]{2})/', $raw)) {
        return stripcslashes($raw);
    }
    return $raw;
}

function ztp_parse_tlv_suboptions($bytes)
{
    $len = strlen($bytes);
    if ($len < 3) {
        return null;
    }
    $pos = 0;
    $url = '';
    $token = '';
    $fw = '';
    $parsed_any = false;

    while ($pos < $len) {
        $code = ord($bytes[$pos]);
        if ($code === 0x00) {
            $pos++;
            continue;
        }
        if ($code === 0xff) {
            break;
        }
        if ($pos + 1 >= $len) {
            return null;
        }
        $sublen = ord($bytes[$pos + 1]);
        if ($pos + 2 + $sublen > $len) {
            return null;
        }
        $val = substr($bytes, $pos + 2, $sublen);
        $pos += 2 + $sublen;
        $parsed_any = true;

        if ($code === 1) {
            $url = trim($val);
        } elseif ($code === 2) {
            $token = trim($val);
        } elseif ($code === 3) {
            $fw = trim($val);
        }
    }

    if ($parsed_any && preg_match('#^https?://#i', $url)) {
        return ['url' => $url, 'token' => $token, 'firmware_version' => $fw];
    }
    return null;
}

function ztp_parse_option_string($raw)
{
    $result = ['url' => '', 'token' => '', 'firmware_version' => ''];
    $raw = trim((string)$raw, " \t\n\r\"'");
    if ($raw === '') {
        return $result;
    }

    $decoded = ztp_decode_hex_option($raw);
    $tlv = ztp_parse_tlv_suboptions($decoded);
    if ($tlv !== null) {
        return $tlv;
    }
    if ($decoded !== $raw) {
        $raw = trim($decoded, "\x00.. \t\n\r\"'");
    }

    if ($raw === '') {
        return $result;
    }

    if ($raw[0] === '{') {
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $url_cand = $json['provisioning_url'] ?? $json['url']
                ?? $json['endpoint'] ?? $json['claim_url'] ?? $json['config_url'] ?? '';
            $result['url'] = trim((string)$url_cand);
            $result['token'] = trim((string)($json['enrollment_token'] ?? $json['token'] ?? $json['secret'] ?? ''));
            $result['firmware_version'] = trim((string)($json['firmware_version'] ?? $json['version'] ?? ''));
            return $result;
        }
    }

    $kv_pattern = '/(?:^|[\s;|,"\'`])(?:provisioning_url|url|endpoint|claim_url|enrollment_token|token|secret)=/i';
    if (preg_match($kv_pattern, $raw)) {
        if (preg_match_all('/([A-Za-z0-9_-]+)=("[^"]*"|\'[^\']*\'|[^\s;|,"\'`]+)/', $raw, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $k = strtolower(trim($m[1]));
                $v = trim($m[2], "\"'");
                if (in_array($k, ['url', 'provisioning_url', 'endpoint', 'claim_url'], true)) {
                    $result['url'] = $v;
                } elseif (in_array($k, ['token', 'enrollment_token', 'secret'], true)) {
                    $result['token'] = $v;
                } elseif (in_array($k, ['firmware_version', 'version'], true)) {
                    $result['firmware_version'] = $v;
                }
            }
            if ($result['url'] !== '') {
                return $result;
            }
        }
    }

    if (preg_match('#^(https?://[^\s|,;]+)(?:[|,;\s]+(.+))?$#i', $raw, $m)) {
        $result['url'] = trim($m[1]);
        if (isset($m[2])) {
            $rest = trim($m[2]);
            foreach (preg_split('/[|,;\s]+/', $rest) as $piece) {
                $piece = trim($piece, "\"'");
                if ($piece === '') {
                    continue;
                }
                if (stripos($piece, 'token=') === 0) {
                    $result['token'] = substr($piece, 6);
                } elseif (stripos($piece, 'enrollment_token=') === 0) {
                    $result['token'] = substr($piece, 17);
                } elseif (stripos($piece, 'firmware_version=') === 0) {
                    $result['firmware_version'] = substr($piece, 17);
                } elseif ($result['token'] === '') {
                    $result['token'] = $piece;
                }
            }
        }
        if ($result['token'] === '' && strpos($result['url'], '?') !== false) {
            $q = parse_url($result['url'], PHP_URL_QUERY);
            if (is_string($q)) {
                parse_str($q, $qp);
                $result['token'] = (string)($qp['enrollment_token'] ?? $qp['token'] ?? '');
            }
        }
        return $result;
    }

    return $result;
}

function ztp_has_lease_opts(array $entry)
{
    return $entry['opt43'] !== ''
        || $entry['opt224'] !== ''
        || $entry['opt66'] !== ''
        || $entry['opt67'] !== ''
        || $entry['opt_url'] !== '';
}

function ztp_parse_lease_file($filepath)
{
    $empty = [
        'opt43' => '',
        'opt224' => '',
        'opt66' => '',
        'opt67' => '',
        'opt_url' => '',
    ];
    $opts = $empty;
    $cur = $empty;
    $in_lease = false;

    if (!is_file($filepath)) {
        return $opts;
    }
    $content = file_get_contents($filepath);
    if ($content === false) {
        return $opts;
    }
    $url_opt_names = ['url', 'default-url', 'option-114', 'option-143', 'unknown-114', 'unknown-143'];
    foreach (explode("\n", $content) as $line) {
        if (preg_match('/^\s*lease\s*\{/i', $line)) {
            $cur = $empty;
            $in_lease = true;
            continue;
        }
        if ($in_lease && preg_match('/^\s*\}\s*$/', $line)) {
            if (ztp_has_lease_opts($cur)) {
                $opts = $cur;
            }
            $in_lease = false;
            continue;
        }
        if (!preg_match('/^\s*option\s+([A-Za-z0-9_-]+)\s+(.+);\s*$/i', $line, $m)) {
            continue;
        }
        $opt_name = strtolower($m[1]);
        $opt_val = trim($m[2], " \t\n\r\"'");
        if (!preg_match('/\\\\([0-3][0-7]{2}|x[0-9a-fA-F]{2})/', $opt_val)) {
            $opt_val = str_replace(['\"', '\\\'', '\\\\'], ['"', '\'', '\\'], $opt_val);
        }
        if ($opt_name === 'vendor-encapsulated-options' || $opt_name === 'option-43' || $opt_name === 'unknown-43') {
            $cur['opt43'] = $opt_val;
        } elseif ($opt_name === 'option-224' || $opt_name === 'unknown-224') {
            $cur['opt224'] = $opt_val;
        } elseif ($opt_name === 'tftp-server-name' || $opt_name === 'option-66') {
            $cur['opt66'] = $opt_val;
        } elseif ($opt_name === 'bootfile-name' || $opt_name === 'option-67') {
            $cur['opt67'] = $opt_val;
        } elseif (in_array($opt_name, $url_opt_names, true)) {
            $cur['opt_url'] = $opt_val;
        }
    }
    if (ztp_has_lease_opts($cur)) {
        $opts = $cur;
    }
    return $opts;
}

function ztp_safe_url($url)
{
    $url = trim((string)$url);
    if ($url === '') {
        return '';
    }
    if (!preg_match('#^https://[A-Za-z0-9._~:/?\#\[\]@!$&()*+,;=%-]+$#', $url)) {
        return '';
    }
    return $url;
}

function ztp_parse_mdns_packet($pkt)
{
    $len = strlen($pkt);
    if ($len < 12) {
        return '';
    }
    $qdcount = unpack('n', substr($pkt, 4, 2))[1];
    $ancount = unpack('n', substr($pkt, 6, 2))[1];
    $nscount = unpack('n', substr($pkt, 8, 2))[1];
    $arcount = unpack('n', substr($pkt, 10, 2))[1];
    $total_rr = $ancount + $nscount + $arcount;
    if ($total_rr === 0) {
        return '';
    }

    $skip_name = function ($pos) use ($pkt, $len) {
        while ($pos < $len) {
            $l = ord($pkt[$pos]);
            if ($l === 0) {
                return $pos + 1;
            }
            if (($l & 0xc0) === 0xc0) {
                return $pos + 2;
            }
            $pos += 1 + $l;
        }
        return $len;
    };

    $pos = 12;
    for ($i = 0; $i < $qdcount && $pos < $len; $i++) {
        $pos = $skip_name($pos) + 4;
    }

    $txt_tokens = [];
    $srv_port = 0;
    $a_ip = '';

    for ($i = 0; $i < $total_rr && $pos < $len; $i++) {
        $pos = $skip_name($pos);
        if ($pos + 10 > $len) {
            break;
        }
        $hdr = unpack('ntype/nclass/Nttl/nrdlen', substr($pkt, $pos, 10));
        $pos += 10;
        $rdlen = $hdr['rdlen'];
        if ($pos + $rdlen > $len) {
            break;
        }
        $rdata = substr($pkt, $pos, $rdlen);
        $pos += $rdlen;

        if ($hdr['type'] === 16) {
            $tpos = 0;
            while ($tpos < $rdlen) {
                $tlen = ord($rdata[$tpos]);
                $tpos++;
                if ($tlen > 0 && $tpos + $tlen <= $rdlen) {
                    $txt_tokens[] = substr($rdata, $tpos, $tlen);
                }
                $tpos += $tlen;
            }
        } elseif ($hdr['type'] === 33 && $rdlen >= 6) {
            $srv_port = unpack('n', substr($rdata, 4, 2))[1];
        } elseif ($hdr['type'] === 1 && $rdlen === 4) {
            $a_ip = inet_ntop($rdata) ?: '';
        }
    }

    if (!empty($txt_tokens)) {
        $joined = implode(' ', $txt_tokens);
        $parsed = ztp_parse_option_string($joined);
        if ($parsed['url'] !== '') {
            $out = $parsed['url'];
            if ($parsed['token'] !== '') {
                $out .= '|token=' . $parsed['token'];
            }
            if ($parsed['firmware_version'] !== '') {
                $out .= '|firmware_version=' . $parsed['firmware_version'];
            }
            return $out;
        }
    }
    if ($a_ip !== '' && $srv_port > 0) {
        $path = '/ztp';
        $tok = '';
        foreach ($txt_tokens as $t) {
            if (stripos($t, 'path=') === 0) {
                $path = '/' . ltrim(substr($t, 5), '/');
            } elseif (stripos($t, 'token=') === 0 || stripos($t, 'enrollment_token=') === 0) {
                $tok = explode('=', $t, 2)[1];
            }
        }
        $url = 'https://' . $a_ip . ':' . $srv_port . $path;
        return $tok !== '' ? ($url . '|token=' . $tok) : $url;
    }
    return '';
}

$cmd = $argv[1] ?? '';

if ($cmd === 'validate_xml') {
    $xml_file = $argv[2] ?? '';
    if (!is_file($xml_file) || filesize($xml_file) === 0) {
        exit(1);
    }
    $dom = ztp_load_xml_string(file_get_contents($xml_file));
    if ($dom === false) {
        exit(1);
    }
    $fw = ztp_extract_firmware_version_from_dom($dom);
    if ($fw !== '' && preg_match('/^[A-Za-z0-9._-]+$/', $fw)) {
        echo $fw . "\n";
    }
    exit(0);
} elseif ($cmd === 'verify_sig') {
    $data_file = $argv[2] ?? '';
    $sig_file = $argv[3] ?? '';
    $verify_cmd = $argv[4] ?? 'opnsense-verify';
    $pubkey_file = $argv[5] ?? '';
    $verify_repo = $argv[6] ?? '';
    if (ztp_verify_file_signature($data_file, $sig_file, $verify_cmd, $pubkey_file, $verify_repo)) {
        exit(0);
    }
    exit(1);
} elseif ($cmd === 'resolve_seed_file') {
    $seed_dir = $argv[2] ?? '';
    $rel_path = $argv[3] ?? '';
    $resolved = ztp_resolve_contained_file($seed_dir, $rel_path);
    if ($resolved === false) {
        exit(1);
    }
    echo $resolved . "\n";
    exit(0);
} elseif ($cmd === 'extract_seed_trust') {
    $json_file = $argv[2] ?? '';
    $seed_dir = $argv[3] ?? '';
    $mode = $argv[4] ?? 'full';
    if (!is_file($json_file)) {
        exit(0);
    }
    $seed_cfg = json_decode((string)file_get_contents($json_file), true);
    if (!is_array($seed_cfg)) {
        exit(0);
    }
    $seed_repo = trim((string)($seed_cfg['verify_repo'] ?? ''));
    if ($seed_repo !== '' && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $seed_repo)) {
        echo 'VERIFY_REPO=' . $seed_repo . "\n";
    }
    if ($mode === 'full') {
        $trust_pubkey_spec = trim((string)($seed_cfg['trust_pubkey'] ?? $seed_cfg['public_key'] ?? ''));
        if ($trust_pubkey_spec !== '') {
            $pub_content = '';
            if (strpos($trust_pubkey_spec, '-----BEGIN ') !== false) {
                $pub_content = $trust_pubkey_spec . "\n";
            } elseif ($seed_dir !== '') {
                $resolved_pub = ztp_resolve_contained_file($seed_dir, $trust_pubkey_spec);
                if ($resolved_pub === false) {
                    exit(1);
                }
                $pub_content = (string)file_get_contents($resolved_pub);
            } else {
                exit(1);
            }
            $tmp_pub = $json_file . '.pinned.pub';
            if (file_put_contents($tmp_pub, $pub_content) === false) {
                exit(1);
            }
            echo 'PUBKEY_FILE=' . $tmp_pub . "\n";
        }
    }
    exit(0);
} elseif ($cmd === 'check_seed_xml_hash') {
    $json_file = $argv[2] ?? '';
    $xml_file = $argv[3] ?? '';
    if (!is_file($json_file) || !is_file($xml_file)) {
        echo "NONE\n";
        exit(0);
    }
    $seed_cfg = json_decode(file_get_contents($json_file), true);
    if (!is_array($seed_cfg)) {
        echo "NONE\n";
        exit(0);
    }
    $expected = strtolower(trim((string)($seed_cfg['config_sha256'] ?? $seed_cfg['config_checksum'] ?? '')));
    if ($expected === '') {
        echo "NONE\n";
        exit(0);
    }
    $actual = strtolower(hash_file('sha256', $xml_file));
    echo ($actual !== '' && hash_equals($expected, $actual)) ? "MATCH\n" : "MISMATCH\n";
    exit(0);
} elseif ($cmd === 'get_wan_if') {
    $xml_file = $argv[2] ?? '';
    if (!is_file($xml_file)) {
        exit(0);
    }
    $dom = ztp_load_xml_string(file_get_contents($xml_file));
    if ($dom === false) {
        exit(0);
    }
    $xpath = new DOMXPath($dom);
    $nodes = $xpath->query('/opnsense/interfaces/wan/if');
    if ($nodes && $nodes->length > 0) {
        $ifname = trim($nodes->item(0)->textContent);
        if ($ifname !== '' && strpos($ifname, 'mismatch') !== 0) {
            echo $ifname . "\n";
        }
    }
    exit(0);
} elseif ($cmd === 'is_ztp_enabled_xml') {
    $xml_file = $argv[2] ?? '';
    if (!is_file($xml_file)) {
        exit(1);
    }
    $dom = ztp_load_xml_string(file_get_contents($xml_file));
    if ($dom === false) {
        exit(1);
    }
    $xpath = new DOMXPath($dom);
    foreach (['/opnsense/ztp/enable', '/opnsense/system/ztp/enable', '/opnsense/system/ztp_enable'] as $q) {
        $nodes = $xpath->query($q);
        if ($nodes && $nodes->length > 0) {
            $val = strtolower(trim($nodes->item(0)->textContent));
            if ($val === '1' || $val === 'true' || $val === 'yes' || $val === 'enabled') {
                exit(0);
            }
        }
    }
    exit(1);
} elseif ($cmd === 'get_ztp_xml_config') {
    $xml_file = $argv[2] ?? '';
    if (!is_file($xml_file)) {
        exit(1);
    }
    $dom = ztp_load_xml_string(file_get_contents($xml_file));
    if ($dom === false) {
        exit(1);
    }
    $xpath = new DOMXPath($dom);
    $enable = '0';
    foreach (['/opnsense/ztp/enable', '/opnsense/system/ztp/enable', '/opnsense/system/ztp_enable'] as $q) {
        $nodes = $xpath->query($q);
        if ($nodes && $nodes->length > 0) {
            $val = strtolower(trim($nodes->item(0)->textContent));
            if ($val === '1' || $val === 'true' || $val === 'yes' || $val === 'enabled') {
                $enable = '1';
                break;
            }
        }
    }
    $url = '';
    $url_queries = [
        '/opnsense/ztp/url',
        '/opnsense/ztp/provisioning_url',
        '/opnsense/system/ztp/url',
        '/opnsense/system/ztp/provisioning_url',
    ];
    foreach ($url_queries as $q) {
        $nodes = $xpath->query($q);
        if ($nodes && $nodes->length > 0) {
            $cand = ztp_safe_url($nodes->item(0)->textContent);
            if ($cand !== '') {
                $url = $cand;
                break;
            }
        }
    }
    $token = '';
    $tok_queries = [
        '/opnsense/ztp/token',
        '/opnsense/ztp/enrollment_token',
        '/opnsense/system/ztp/token',
        '/opnsense/system/ztp/enrollment_token',
    ];
    foreach ($tok_queries as $q) {
        $nodes = $xpath->query($q);
        if ($nodes && $nodes->length > 0) {
            $cand = str_replace(["\n", "\r"], '', trim($nodes->item(0)->textContent));
            if ($cand !== '') {
                $token = $cand;
                break;
            }
        }
    }
    $repo = '';
    foreach (['/opnsense/ztp/verify_repo', '/opnsense/system/ztp/verify_repo'] as $q) {
        $nodes = $xpath->query($q);
        if ($nodes && $nodes->length > 0) {
            $cand = trim($nodes->item(0)->textContent);
            if ($cand !== '' && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $cand)) {
                $repo = $cand;
                break;
            }
        }
    }
    $pubkey = '';
    $pub_queries = [
        '/opnsense/ztp/pubkey',
        '/opnsense/ztp/trust_pubkey',
        '/opnsense/system/ztp/pubkey',
        '/opnsense/system/ztp/trust_pubkey',
    ];
    foreach ($pub_queries as $q) {
        $nodes = $xpath->query($q);
        if ($nodes && $nodes->length > 0) {
            $cand = trim($nodes->item(0)->textContent);
            if ($cand !== '') {
                $pubkey = $cand;
                break;
            }
        }
    }
    echo 'XML_ZTP_ENABLE=' . $enable . "\n";
    echo 'XML_ZTP_URL=' . $url . "\n";
    echo 'XML_ZTP_TOKEN=' . $token . "\n";
    echo 'XML_ZTP_VERIFY_REPO=' . $repo . "\n";
    if ($pubkey !== '') {
        echo 'XML_ZTP_PUBKEY_B64=' . base64_encode($pubkey) . "\n";
    }
    exit(0);
} elseif ($cmd === 'same_origin') {
    $u1 = ztp_safe_url($argv[2] ?? '');
    $u2 = ztp_safe_url($argv[3] ?? '');
    if ($u1 === '' || $u2 === '') {
        exit(1);
    }
    $p1 = parse_url($u1);
    $p2 = parse_url($u2);
    if (!is_array($p1) || !is_array($p2)) {
        exit(1);
    }
    $h1 = strtolower((string)($p1['host'] ?? ''));
    $h2 = strtolower((string)($p2['host'] ?? ''));
    $port1 = (int)($p1['port'] ?? 443);
    $port2 = (int)($p2['port'] ?? 443);
    if ($h1 !== '' && $h1 === $h2 && $port1 === $port2) {
        exit(0);
    }
    exit(1);
} elseif ($cmd === 'check_fw_version') {
    $fw_ver = trim((string)($argv[2] ?? ''));
    $cur_ver = trim((string)($argv[3] ?? ''));
    $allow_downgrade = trim((string)($argv[4] ?? '0'));
    if ($fw_ver === '') {
        exit(1);
    }
    if ($fw_ver === 'sync' || $fw_ver === 'latest') {
        exit(0);
    }
    if (!preg_match('/^[0-9]+\.[0-9]+(?:\.[0-9A-Za-z]+)?(?:_[0-9]+)?$/', $fw_ver)) {
        exit(1);
    }
    if ($cur_ver === '' || $allow_downgrade === '1') {
        exit(0);
    }
    if (!preg_match('/^[0-9]+\.[0-9]+/', $cur_ver)) {
        exit(0);
    }
    $norm_fw = str_replace('_', '.', $fw_ver);
    $norm_cur = str_replace('_', '.', $cur_ver);
    if (version_compare($norm_fw, $norm_cur, '<')) {
        exit(1);
    }
    exit(0);
} elseif ($cmd === 'apply_ztp_json') {
    $json_file = $argv[2] ?? '';
    $target_xml = $argv[3] ?? '';
    $seed_dir = $argv[4] ?? '';
    $mac_json = $argv[5] ?? '{}';
    $verify_cmd = $argv[6] ?? 'opnsense-verify';
    $pubkey_file = $argv[7] ?? '';
    $verify_repo = $argv[8] ?? '';
    $has_seed_xml = $argv[9] ?? '0';
    $crypt_cmd = $argv[10] ?? 'opnsense-crypt';

    if (!is_file($json_file) || $target_xml === '') {
        exit(1);
    }
    $seed_cfg = json_decode(file_get_contents($json_file), true);
    if (!is_array($seed_cfg)) {
        exit(1);
    }
    $mac_map = json_decode($mac_json, true);
    if (!is_array($mac_map)) {
        $mac_map = [];
    }

    $seed_repo = trim((string)($seed_cfg['verify_repo'] ?? ''));
    if ($seed_repo !== '' && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $seed_repo)) {
        $verify_repo = $seed_repo;
    }

    $pinned_pubkey_content = '';
    $trust_pubkey_spec = trim((string)($seed_cfg['trust_pubkey'] ?? $seed_cfg['public_key'] ?? ''));
    if ($trust_pubkey_spec !== '') {
        if (strpos($trust_pubkey_spec, '-----BEGIN ') !== false) {
            $pinned_pubkey_content = $trust_pubkey_spec . "\n";
        } elseif ($seed_dir !== '') {
            $resolved_pub = ztp_resolve_contained_file($seed_dir, $trust_pubkey_spec);
            if ($resolved_pub === false) {
                exit(1);
            }
            $pinned_pubkey_content = (string)file_get_contents($resolved_pub);
        } else {
            exit(1);
        }
        $tmp_pub = $json_file . '.pinned.pub';
        if (file_put_contents($tmp_pub, $pinned_pubkey_content) === false) {
            exit(1);
        }
        $pubkey_file = $tmp_pub;
    }

    $pinned_ca_content = '';
    $ca_spec = trim((string)($seed_cfg['ca_cert'] ?? ''));
    if ($ca_spec !== '') {
        if (strpos($ca_spec, '-----BEGIN CERTIFICATE-----') !== false) {
            $pinned_ca_content = $ca_spec . "\n";
        } elseif ($seed_dir !== '') {
            $resolved_ca = ztp_resolve_contained_file($seed_dir, $ca_spec);
            if ($resolved_ca === false) {
                exit(1);
            }
            $pinned_ca_content = (string)file_get_contents($resolved_ca);
        } else {
            exit(1);
        }
    }

    $dom = false;
    $cfg_ref = $seed_cfg['config_file'] ?? $seed_cfg['config_path'] ?? '';
    if ($cfg_ref !== '' && $seed_dir !== '') {
        $real_cand = ztp_resolve_contained_file($seed_dir, (string)$cfg_ref);
        if ($real_cand === false) {
            exit(1);
        }
        $expected_sha = strtolower(trim((string)($seed_cfg['config_sha256'] ?? $seed_cfg['config_checksum'] ?? '')));
        $verified_ref = false;
        if ($expected_sha !== '') {
            $actual_sha = strtolower(hash_file('sha256', $real_cand));
            if ($actual_sha !== '' && hash_equals($expected_sha, $actual_sha)) {
                $verified_ref = true;
            }
        } elseif (is_file($real_cand . '.sig')) {
            $sig_path = $real_cand . '.sig';
            if (ztp_verify_file_signature($real_cand, $sig_path, $verify_cmd, $pubkey_file, $verify_repo)) {
                $verified_ref = true;
            }
        }
        $cand_content = (string)file_get_contents($real_cand);
        if (strpos($cand_content, '---- BEGIN config.xml ----') === 0) {
            $sec_rel = basename((string)$cfg_ref, '.xml') . '.secret';
            $sec_file = ztp_resolve_contained_file($seed_dir, $sec_rel);
            if ($sec_file === false) {
                $sec_file = ztp_resolve_contained_file($seed_dir, 'config.secret');
            }
            if ($sec_file === false || $crypt_cmd === '') {
                exit(1);
            }
            $dec_file = $json_file . '.decrypted.xml';
            $dec_sec = $json_file . '.decrypted.secret';
            if (!copy($real_cand, $dec_file) || !copy($sec_file, $dec_sec)) {
                @unlink($dec_file);
                @unlink($dec_sec);
                exit(1);
            }
            $decrypt_cli = escapeshellarg($crypt_cmd) . ' '
                . escapeshellarg($dec_file) . ' 2>/dev/null';
            $dec_out = [];
            $dec_rc = 1;
            @exec($decrypt_cli, $dec_out, $dec_rc);
            @unlink($dec_sec);
            if ($dec_rc !== 0 || !is_file($dec_file)) {
                @unlink($dec_file);
                exit(1);
            }
            if (!$verified_ref && $expected_sha !== '') {
                $dec_sha = strtolower(hash_file('sha256', $dec_file));
                if ($dec_sha !== '' && hash_equals($expected_sha, $dec_sha)) {
                    $verified_ref = true;
                }
            }
            $cand_content = (string)file_get_contents($dec_file);
            @unlink($dec_file);
        }
        if (!$verified_ref) {
            exit(1);
        }
        $dom = ztp_load_xml_string($cand_content);
        if ($dom === false) {
            exit(1);
        }
    }

    $inline_xml = $seed_cfg['config_xml'] ?? $seed_cfg['config'] ?? '';
    if ($dom === false && is_string($inline_xml) && trim($inline_xml) !== '') {
        $trimmed = trim($inline_xml);
        if (strpos($trimmed, '<') === 0) {
            $dom = ztp_load_xml_string($trimmed);
        } else {
            $b64 = base64_decode($trimmed, true);
            if ($b64 !== false) {
                $dom = ztp_load_xml_string($b64);
            }
        }
        if ($dom === false) {
            exit(1);
        }
    }

    if ($dom === false) {
        if (!is_file($target_xml)) {
            exit(1);
        }
        $dom = ztp_load_xml_string(file_get_contents($target_xml));
        if ($dom === false) {
            exit(1);
        }
    }

    $root = $dom->documentElement;
    $system = ztp_get_or_create_child($dom, $root, 'system');

    $sys_data = is_array($seed_cfg['system'] ?? null) ? $seed_cfg['system'] : [];
    $hostname = $seed_cfg['hostname']
        ?? $seed_cfg['local-hostname']
        ?? $seed_cfg['local_hostname']
        ?? ($sys_data['hostname'] ?? '');
    if ($hostname !== '') {
        ztp_set_child_text($dom, $system, 'hostname', (string)$hostname);
    }
    $domain = $seed_cfg['domain'] ?? ($sys_data['domain'] ?? '');
    if ($domain !== '') {
        ztp_set_child_text($dom, $system, 'domain', (string)$domain);
    }
    $timezone = $seed_cfg['timezone'] ?? ($sys_data['timezone'] ?? '');
    if ($timezone !== '') {
        ztp_set_child_text($dom, $system, 'timezone', (string)$timezone);
    }

    $dns_out_list = [];
    $dns = $seed_cfg['dns_servers']
        ?? $seed_cfg['dns']
        ?? $seed_cfg['nameservers']
        ?? ($sys_data['dnsserver'] ?? null);
    if (!empty($dns)) {
        ztp_remove_children($system, 'dnsserver');
        $dns_list = is_array($dns) ? $dns : preg_split('/[\s,]+/', (string)$dns);
        foreach ($dns_list as $ns) {
            $ns = trim((string)$ns);
            if ($ns !== '' && preg_match('/^[0-9a-fA-F.:]+$/', $ns)) {
                $el = $dom->createElement('dnsserver');
                $el->appendChild($dom->createTextNode($ns));
                $system->appendChild($el);
                $dns_out_list[] = $ns;
            }
        }
    }

    $wan_cfg = $seed_cfg['wan'] ?? ($seed_cfg['interfaces']['wan'] ?? []);
    if (!is_array($wan_cfg)) {
        $wan_cfg = [];
    }
    $flat_wan_map = [
        'wan_if' => 'if',
        'wan_interface' => 'if',
        'wan_mac' => 'mac',
        'wan_ipaddr' => 'ipaddr',
        'wan_ip' => 'ipaddr',
        'wan_subnet' => 'subnet',
        'wan_netmask' => 'netmask',
        'wan_gateway' => 'gateway',
        'wan_vlan' => 'vlan',
        'pppoe_username' => 'pppoe_username',
        'pppoe_password' => 'pppoe_password',
    ];
    foreach ($flat_wan_map as $src_k => $dst_k) {
        if (!empty($seed_cfg[$src_k]) && empty($wan_cfg[$dst_k])) {
            $wan_cfg[$dst_k] = $seed_cfg[$src_k];
        }
    }
    $lan_cfg = $seed_cfg['lan']
        ?? $seed_cfg['management']
        ?? $seed_cfg['mgmt']
        ?? ($seed_cfg['interfaces']['lan'] ?? []);
    $lan_explicit_dev = '';
    if (is_array($lan_cfg)) {
        $lan_explicit_dev = $lan_cfg['if'] ?? $lan_cfg['interface'] ?? $lan_cfg['device'] ?? '';
        if ($lan_explicit_dev === '' && !empty($lan_cfg['mac'])) {
            $lan_explicit_dev = ztp_resolve_if_by_mac($lan_cfg['mac'], $mac_map);
        } elseif ($lan_explicit_dev === '' && !empty($lan_cfg['hwaddr'])) {
            $lan_explicit_dev = ztp_resolve_if_by_mac($lan_cfg['hwaddr'], $mac_map);
        }
    }

    $wan_applied = [
        'if' => '',
        'ipaddr' => '',
        'subnet' => '',
        'gateway' => '',
        'vlan_parent' => '',
        'vlan_tag' => '',
    ];
    if (!empty($wan_cfg)) {
        $wan_applied = ztp_apply_interface($dom, $root, 'wan', $wan_cfg, $mac_map, $lan_explicit_dev);
    }

    $has_lan_fields = is_array($lan_cfg) && (
        !empty($lan_cfg['if']) ||
        !empty($lan_cfg['interface']) ||
        !empty($lan_cfg['device']) ||
        !empty($lan_cfg['mac']) ||
        !empty($lan_cfg['ipaddr']) ||
        !empty($lan_cfg['ip']) ||
        !empty($lan_cfg['address'])
    );
    if ($has_lan_fields) {
        $wan_base_dev = $wan_applied['vlan_parent'] !== ''
            ? $wan_applied['vlan_parent']
            : $wan_applied['if'];
        ztp_apply_interface($dom, $root, 'lan', $lan_cfg, $mac_map, $wan_base_dev);
    }

    ztp_cleanup_mismatch_interfaces($dom, $root, $mac_map);

    $cred_src = [];
    if (is_array($seed_cfg['credentials'] ?? null)) {
        $cred_src = $seed_cfg['credentials'];
    } elseif (is_array($seed_cfg['management'] ?? null)) {
        $cred_src = $seed_cfg['management'];
    }
    $creds = [
        'password' => $seed_cfg['root_password']
            ?? $seed_cfg['password']
            ?? ($cred_src['root_password'] ?? $cred_src['password'] ?? ''),
        'password_hash' => $seed_cfg['root_password_hash']
            ?? $seed_cfg['password_hash']
            ?? ($cred_src['root_password_hash'] ?? $cred_src['password_hash'] ?? ''),
        'ssh_keys' => $seed_cfg['ssh_keys']
            ?? $seed_cfg['authorized_keys']
            ?? ($cred_src['ssh_keys'] ?? $cred_src['authorized_keys'] ?? null),
        'enable_ssh' => $seed_cfg['enable_ssh']
            ?? $seed_cfg['ssh_enabled']
            ?? ($cred_src['enable_ssh'] ?? $cred_src['ssh'] ?? false),
        'api_key' => $seed_cfg['api_key'] ?? ($cred_src['api_key'] ?? ''),
        'api_secret' => $seed_cfg['api_secret'] ?? ($cred_src['api_secret'] ?? ''),
    ];

    $has_creds = $creds['password'] !== ''
        || $creds['password_hash'] !== ''
        || !empty($creds['ssh_keys'])
        || !empty($creds['enable_ssh'])
        || $creds['api_key'] !== '';
    if ($has_creds) {
        ztp_set_root_credentials($dom, $system, $creds);
    }

    $raw_prov_url = $seed_cfg['provisioning_url']
        ?? $seed_cfg['url']
        ?? $seed_cfg['endpoint']
        ?? $seed_cfg['claim_url']
        ?? '';
    $prov_url = ztp_safe_url($raw_prov_url);
    $raw_token = $seed_cfg['enrollment_token']
        ?? $seed_cfg['token']
        ?? $seed_cfg['secret']
        ?? ($cred_src['enrollment_token'] ?? $cred_src['token'] ?? '');
    $prov_token = trim((string)$raw_token);
    $fw_ver = trim((string)($seed_cfg['firmware_version'] ?? ($seed_cfg['firmware']['version'] ?? '')));
    if ($fw_ver === '') {
        $fw_ver = ztp_extract_firmware_version_from_dom($dom);
    }
    if ($fw_ver !== '' && !preg_match('/^[A-Za-z0-9._-]+$/', $fw_ver)) {
        $fw_ver = '';
    }

    $net_ztp_explicit_off = (array_key_exists('network_ztp', $seed_cfg) && $seed_cfg['network_ztp'] === false)
        || (array_key_exists('ztp_enable', $seed_cfg) && $seed_cfg['ztp_enable'] === false);
    $net_ztp = !empty($seed_cfg['network_ztp']) || !empty($seed_cfg['ztp_enable']) || ($prov_url !== '');
    if (
        !$net_ztp &&
        !$net_ztp_explicit_off &&
        $pinned_pubkey_content !== '' &&
        $cfg_ref === '' &&
        $inline_xml === '' &&
        $has_seed_xml !== '1'
    ) {
        $net_ztp = true;
    }

    if (!$net_ztp) {
        ztp_remove_children($root, 'trigger_initial_wizard');
    }

    if (!ztp_save_dom($dom, $target_xml)) {
        exit(1);
    }

    $net_cfg_sha = strtolower(trim((string)($seed_cfg['network_config_sha256'] ?? '')));
    if ($net_cfg_sha === '' && $cfg_ref === '' && $has_seed_xml !== '1') {
        $net_cfg_sha = strtolower(trim((string)($seed_cfg['config_sha256'] ?? $seed_cfg['config_checksum'] ?? '')));
    }
    if ($net_cfg_sha !== '' && !preg_match('/^[0-9a-f]{64}$/', $net_cfg_sha)) {
        $net_cfg_sha = '';
    }

    echo 'FIRMWARE_VERSION=' . $fw_ver . "\n";
    echo 'PROVISIONING_URL=' . $prov_url . "\n";
    echo 'NETWORK_ZTP=' . ($net_ztp ? '1' : '0') . "\n";
    echo 'ENROLLMENT_TOKEN=' . str_replace(["\n", "\r"], '', $prov_token) . "\n";
    echo 'VERIFY_REPO=' . $verify_repo . "\n";
    echo 'CONFIG_SHA256=' . $net_cfg_sha . "\n";
    if ($pinned_pubkey_content !== '') {
        echo 'PINNED_PUBKEY_B64=' . base64_encode($pinned_pubkey_content) . "\n";
    }
    if ($pinned_ca_content !== '') {
        echo 'PINNED_CA_B64=' . base64_encode($pinned_ca_content) . "\n";
    }
    echo 'WAN_IF=' . preg_replace('/[^A-Za-z0-9._-]/', '', $wan_applied['if']) . "\n";
    echo 'WAN_IPADDR=' . preg_replace('/[^A-Za-z0-9.:_-]/', '', $wan_applied['ipaddr']) . "\n";
    echo 'WAN_SUBNET=' . preg_replace('/[^0-9]/', '', $wan_applied['subnet']) . "\n";
    echo 'WAN_GATEWAY=' . preg_replace('/[^A-Za-z0-9.:_-]/', '', $wan_applied['gateway']) . "\n";
    echo 'WAN_VLAN_PARENT=' . preg_replace('/[^A-Za-z0-9._-]/', '', $wan_applied['vlan_parent']) . "\n";
    echo 'WAN_VLAN_TAG=' . preg_replace('/[^0-9]/', '', $wan_applied['vlan_tag']) . "\n";
    echo 'DNS_SERVERS=' . implode(' ', $dns_out_list) . "\n";
    exit(0);
} elseif ($cmd === 'parse_dhcp') {
    $opt43 = $argv[2] ?? '';
    $opt224 = $argv[3] ?? '';
    $opt66 = $argv[4] ?? '';
    $opt67 = $argv[5] ?? '';
    $seed_url = $argv[6] ?? '';
    $seed_token = $argv[7] ?? '';
    $extra_args = array_slice($argv, 8);

    $dhcp_url = '';
    $lease_files = [];
    foreach ($extra_args as $idx => $arg) {
        $is_url_arg = $idx === 0
            && $arg !== ''
            && !is_file($arg)
            && (
                stripos($arg, 'http') === 0 ||
                stripos($arg, 'url=') !== false ||
                $arg[0] === '{' ||
                stripos($arg, '0x') === 0
            );
        if ($is_url_arg) {
            $dhcp_url = $arg;
        } else {
            $lease_files[] = $arg;
        }
    }

    /*
     * Authoritative local seed precedence:
     * When a locally seeded provisioning URL is present, it always wins over
     * DHCP options and lease files, and the local enrollment token is bound
     * exclusively to that seeded endpoint.
     */
    if (trim($seed_url) !== '') {
        $seed_res = ztp_parse_option_string($seed_url);
        $safe_seed_url = ztp_safe_url($seed_res['url']);
        if ($safe_seed_url !== '') {
            $bound_token = $seed_res['token'] !== '' ? $seed_res['token'] : trim($seed_token);
            $fw = $seed_res['firmware_version'];
            if ($fw !== '' && !preg_match('/^[A-Za-z0-9._-]+$/', $fw)) {
                $fw = '';
            }
            echo 'PROV_URL=' . $safe_seed_url . "\n";
            echo 'PROV_TOKEN=' . str_replace(["\n", "\r"], '', $bound_token) . "\n";
            echo 'PROV_FW_VER=' . $fw . "\n";
            echo "PROV_SOURCE=seed\n";
            exit(0);
        }
    }

    if ($opt43 === '' || $opt224 === '' || $opt66 === '' || $opt67 === '' || $dhcp_url === '') {
        foreach ($lease_files as $lf) {
            $lopts = ztp_parse_lease_file($lf);
            if ($opt43 === '' && $lopts['opt43'] !== '') {
                $opt43 = $lopts['opt43'];
            }
            if ($opt224 === '' && $lopts['opt224'] !== '') {
                $opt224 = $lopts['opt224'];
            }
            if ($opt66 === '' && $lopts['opt66'] !== '') {
                $opt66 = $lopts['opt66'];
            }
            if ($opt67 === '' && $lopts['opt67'] !== '') {
                $opt67 = $lopts['opt67'];
            }
            if ($dhcp_url === '' && $lopts['opt_url'] !== '') {
                $dhcp_url = $lopts['opt_url'];
            }
        }
    }

    $parsed = ['url' => '', 'token' => '', 'firmware_version' => ''];

    foreach ([$opt43, $opt224, $dhcp_url] as $candidate) {
        if ($candidate !== '') {
            $res = ztp_parse_option_string($candidate);
            if ($res['url'] !== '') {
                $parsed = $res;
                break;
            }
        }
    }

    if ($parsed['url'] === '' && ($opt66 !== '' || $opt67 !== '')) {
        $o66 = trim(ztp_decode_hex_option($opt66), " \t\n\r\"'");
        $o67 = trim(ztp_decode_hex_option($opt67), " \t\n\r\"'");

        if (preg_match('#^https?://#i', $o67)) {
            $res = ztp_parse_option_string($o67);
            $parsed['url'] = $res['url'];
            $parsed['token'] = $res['token'] !== '' ? $res['token'] : $o66;
            $parsed['firmware_version'] = $res['firmware_version'];
        } elseif (preg_match('#^https?://#i', $o66)) {
            $res = ztp_parse_option_string($o66);
            $parsed['url'] = $res['url'];
            $parsed['token'] = $res['token'];
            $parsed['firmware_version'] = $res['firmware_version'];
            if ($o67 !== '') {
                if (stripos($o67, 'token=') === 0) {
                    $parsed['token'] = substr($o67, 6);
                } elseif (strpos($o67, '/') !== false || strpos($o67, '.') !== false) {
                    $parsed['url'] = rtrim($parsed['url'], '/') . '/' . ltrim($o67, '/');
                } elseif ($parsed['token'] === '') {
                    $parsed['token'] = $o67;
                }
            }
        } elseif ($o66 !== '' && preg_match('/^[A-Za-z0-9._:-]+$/', $o66)) {
            $path = $o67 !== '' ? ltrim($o67, '/') : 'ztp';
            $combined = 'https://' . $o66 . '/' . $path;
            $res = ztp_parse_option_string($combined);
            $parsed = $res;
        }
    }

    /*
     * Never attach $seed_token to a URL derived from DHCP or lease files.
     */
    $safe_url = ztp_safe_url($parsed['url']);
    $fw = $parsed['firmware_version'];
    if ($fw !== '' && !preg_match('/^[A-Za-z0-9._-]+$/', $fw)) {
        $fw = '';
    }

    echo 'PROV_URL=' . $safe_url . "\n";
    echo 'PROV_TOKEN=' . str_replace(["\n", "\r"], '', $parsed['token']) . "\n";
    echo 'PROV_FW_VER=' . $fw . "\n";
    echo "PROV_SOURCE=dhcp\n";
    exit(0);
} elseif ($cmd === 'query_mdns') {
    $addr = $argv[2] ?? '224.0.0.251';
    $port = (int)($argv[3] ?? 5353);
    $timeout = max(1, (int)($argv[4] ?? 1));

    $qname = "\x0d_opnsense-ztp\x04_tcp\x05local\x00";
    $pkt = "\x00\x00\x00\x00\x00\x02\x00\x00\x00\x00\x00\x00"
         . $qname . "\x00\x0c\x80\x01"
         . $qname . "\x00\xff\x80\x01";

    $sock = @stream_socket_server('udp://0.0.0.0:0', $errno, $errstr, STREAM_SERVER_BIND);
    if (!$sock) {
        exit(1);
    }
    @stream_socket_sendto($sock, $pkt, 0, "{$addr}:{$port}");

    $deadline = microtime(true) + $timeout;
    while (microtime(true) < $deadline) {
        $r = [$sock];
        $w = null;
        $e = null;
        $rem = max(0, $deadline - microtime(true));
        $sec = (int)floor($rem);
        $usec = (int)(($rem - $sec) * 1000000);
        if (@stream_select($r, $w, $e, $sec, $usec) > 0) {
            $peer = '';
            $resp = @stream_socket_recvfrom($sock, 4096, 0, $peer);
            if (is_string($resp) && $resp !== '') {
                $found = ztp_parse_mdns_packet($resp);
                if ($found !== '') {
                    fclose($sock);
                    echo $found . "\n";
                    exit(0);
                }
            }
        } else {
            break;
        }
    }
    fclose($sock);
    exit(1);
} elseif ($cmd === 'parse_ifconfig') {
    $input = file_get_contents('php://stdin');
    $macs = [];
    $cur = '';
    $mac_re = '/^\s+(?:ether|lladdr|hwaddr)\s+([0-9a-fA-F]{2}(?::[0-9a-fA-F]{2}){5})/i';
    foreach (explode("\n", (string)$input) as $line) {
        if (preg_match('/^([a-zA-Z0-9._-]+):\s+flags=/', $line, $m)) {
            $cur = $m[1];
        } elseif ($cur !== '' && preg_match($mac_re, $line, $m)) {
            $is_pseudo = strpos($cur, 'lo') === 0
                || strpos($cur, 'pflog') === 0
                || strpos($cur, 'pfsync') === 0
                || strpos($cur, 'enc') === 0;
            if (!$is_pseudo) {
                $macs[$cur] = strtolower($m[1]);
            }
        }
    }
    echo json_encode($macs, JSON_UNESCAPED_SLASHES);
    exit(0);
} elseif ($cmd === 'build_payload') {
    $out_file = $argv[2] ?? '';
    $uuid = trim((string)($argv[3] ?? ''));
    $serial = trim((string)($argv[4] ?? ''));
    $mac_json = $argv[5] ?? '{}';
    $token = trim((string)($argv[6] ?? ''));
    $fw_ver = trim((string)($argv[7] ?? ''));

    $macs = json_decode($mac_json, true);
    if (!is_array($macs)) {
        $macs = [];
    }

    $payload = [
        'uuid' => $uuid,
        'system_uuid' => $uuid,
        'serial' => $serial,
        'serial_number' => $serial,
        'mac_inventory' => $macs,
        'interfaces' => $macs,
    ];
    if ($token !== '') {
        $payload['enrollment_token'] = $token;
        $payload['token'] = $token;
    }
    if ($fw_ver !== '') {
        $payload['firmware_version'] = $fw_ver;
    }

    if (file_put_contents($out_file, json_encode($payload, JSON_UNESCAPED_SLASHES)) === false) {
        exit(1);
    }
    exit(0);
} elseif ($cmd === 'parse_response') {
    $resp_file = $argv[2] ?? '';
    $out_xml = $argv[3] ?? '';
    if (!is_file($resp_file) || $out_xml === '') {
        exit(1);
    }
    $raw = file_get_contents($resp_file);
    if ($raw === false || trim($raw) === '') {
        exit(1);
    }

    $trimmed = trim($raw);
    if (strpos($trimmed, '<') === 0) {
        $dom = ztp_load_xml_string($trimmed);
        if ($dom === false) {
            exit(1);
        }
        if (file_put_contents($out_xml, $raw, LOCK_EX) !== strlen($raw)) {
            exit(1);
        }
        $fw = ztp_extract_firmware_version_from_dom($dom);
        if ($fw !== '' && preg_match('/^[A-Za-z0-9._-]+$/', $fw)) {
            echo 'RESP_FW_VER=' . $fw . "\n";
        }
        exit(0);
    }

    $resp_json = json_decode($trimmed, true);
    if (!is_array($resp_json)) {
        exit(1);
    }

    $resp_data = is_array($resp_json['data'] ?? null) ? $resp_json['data'] : [];
    $fw = trim((string)($resp_json['firmware_version'] ?? ($resp_data['firmware_version'] ?? '')));
    if ($fw !== '' && !preg_match('/^[A-Za-z0-9._-]+$/', $fw)) {
        $fw = '';
    }

    $sig = $resp_json['signature']
        ?? $resp_json['config_sig']
        ?? ($resp_data['signature'] ?? ($resp_data['config_sig'] ?? ''));
    if (is_string($sig) && trim($sig) !== '') {
        if (file_put_contents($out_xml . '.sig', trim($sig) . "\n", LOCK_EX) === false) {
            exit(1);
        }
    }

    $raw_sig_url = $resp_json['signature_url']
        ?? $resp_json['sig_url']
        ?? ($resp_data['signature_url'] ?? '');
    $sig_url = ztp_safe_url($raw_sig_url);
    $raw_cfg_sha = $resp_json['config_sha256']
        ?? $resp_json['config_checksum']
        ?? ($resp_data['config_sha256'] ?? '');
    $cfg_sha = strtolower(trim((string)$raw_cfg_sha));
    if ($cfg_sha !== '' && !preg_match('/^[0-9a-f]{64}$/', $cfg_sha)) {
        $cfg_sha = '';
    }

    $cfg_str = $resp_json['config_xml']
        ?? $resp_json['config']
        ?? ($resp_data['config_xml'] ?? ($resp_data['config'] ?? ''));
    if (is_string($cfg_str) && trim($cfg_str) !== '') {
        $cfg_trim = trim($cfg_str);
        if (strpos($cfg_trim, '<') !== 0) {
            $decoded = base64_decode($cfg_trim, true);
            if ($decoded !== false) {
                $cfg_trim = $decoded;
            }
        }
        $dom = ztp_load_xml_string($cfg_trim);
        if ($dom === false) {
            exit(1);
        }
        if (file_put_contents($out_xml, $cfg_trim, LOCK_EX) !== strlen($cfg_trim)) {
            exit(1);
        }
        $dom_fw = ztp_extract_firmware_version_from_dom($dom);
        if ($dom_fw !== '' && preg_match('/^[A-Za-z0-9._-]+$/', $dom_fw)) {
            $fw = $dom_fw;
        }
        echo 'RESP_FW_VER=' . $fw . "\n";
        if ($sig_url !== '') {
            echo 'RESP_SIG_URL=' . $sig_url . "\n";
        }
        if ($cfg_sha !== '') {
            echo 'RESP_CONFIG_SHA256=' . $cfg_sha . "\n";
        }
        exit(0);
    }

    $raw_cfg_url = $resp_json['config_url']
        ?? $resp_json['download_url']
        ?? $resp_json['url']
        ?? ($resp_data['config_url'] ?? '');
    $cfg_url = ztp_safe_url($raw_cfg_url);
    if ($cfg_url !== '') {
        echo 'RESP_CONFIG_URL=' . $cfg_url . "\n";
        echo 'RESP_FW_VER=' . $fw . "\n";
        if ($sig_url !== '') {
            echo 'RESP_SIG_URL=' . $sig_url . "\n";
        }
        if ($cfg_sha !== '') {
            echo 'RESP_CONFIG_SHA256=' . $cfg_sha . "\n";
        }
        exit(0);
    }

    exit(1);
}

exit(1);
