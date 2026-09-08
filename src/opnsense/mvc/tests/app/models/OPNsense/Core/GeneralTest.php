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

namespace tests\OPNsense\Core;

use OPNsense\Core\Config;
use OPNsense\Core\General;

class GeneralTest extends \PHPUnit\Framework\TestCase
{
    private static $model = null;

    public function testCanBeCreated()
    {
        self::$model = new General();
        $this->assertInstanceOf('OPNsense\Core\General', self::$model);
        $this->assertContains(self::$model->getVersion(), ['0.0.0', '1.0.0']);
    }

    /**
     * @depends testCanBeCreated
     */
    public function testDefaultValues()
    {
        $this->assertEquals('OPNsense', (string)self::$model->hostname);
        $this->assertEquals('localdomain', (string)self::$model->domain);
        $this->assertEquals('Etc/UTC', (string)self::$model->timezone);
        $this->assertEquals('en_US', (string)self::$model->language);
        $this->assertEquals('opnsense', (string)self::$model->theme);
        $this->assertEquals('0', (string)self::$model->prefer_ipv4);
        $this->assertEquals('0', (string)self::$model->gw_switch_default);
        $this->assertEquals('1', (string)self::$model->dnsallowoverride);
        $this->assertEquals('0', (string)self::$model->dnslocalhost);
        $this->assertEquals('', (string)self::$model->dnssearchdomain);
        $this->assertEquals('', (string)self::$model->picture);
        $this->assertEquals('', (string)self::$model->picture_filename);
        $this->assertCount(0, iterator_to_array(self::$model->dnsservers->iterateItems()));

        $messages = self::$model->performValidation();
        $this->assertCount(0, $messages);
    }

    /**
     * @depends testCanBeCreated
     */
    public function testValidInputAssignments()
    {
        $model = new General();
        $model->hostname = 'my-firewall-01';
        $model->domain = 'home.arpa';
        $model->timezone = 'Europe/Amsterdam';
        $model->language = 'en_US';
        $model->theme = 'opnsense';
        $model->prefer_ipv4 = '1';
        $model->gw_switch_default = '1';
        $model->dnsallowoverride = '0';
        $model->dnslocalhost = '1';
        $model->dnssearchdomain = 'internal.domain';
        $model->picture = 'dGVzdA==';
        $model->picture_filename = 'logo.png';

        $entry1 = $model->dnsservers->Add();
        $entry1->server = '1.1.1.1';
        $entry1->gateway = 'none';

        $entry2 = $model->dnsservers->Add();
        $entry2->server = '2001:4860:4860::8888';
        $entry2->gateway = 'none';

        $messages = $model->performValidation();
        $this->assertCount(0, $messages);

        $this->assertEquals('my-firewall-01', (string)$model->hostname);
        $this->assertEquals('home.arpa', (string)$model->domain);
        $this->assertEquals('Europe/Amsterdam', (string)$model->timezone);
        $this->assertEquals('1', (string)$model->prefer_ipv4);
        $this->assertEquals('1', (string)$model->gw_switch_default);
        $this->assertEquals('0', (string)$model->dnsallowoverride);
        $this->assertEquals('1', (string)$model->dnslocalhost);
        $this->assertEquals('internal.domain', (string)$model->dnssearchdomain);
        $this->assertEquals('dGVzdA==', (string)$model->picture);
        $this->assertEquals('logo.png', (string)$model->picture_filename);
        $this->assertCount(2, iterator_to_array($model->dnsservers->iterateItems()));
    }

    /**
     * @depends testCanBeCreated
     */
    public function testStringAssignmentToDnsServers()
    {
        $model = new General();
        $model->dnsservers = '8.8.8.8, 8.8.4.4, 2001:4860:4860::8888';
        $items = iterator_to_array($model->dnsservers->iterateItems());
        $this->assertCount(3, $items);

        $servers = [];
        foreach ($items as $item) {
            $servers[] = (string)$item->server;
        }
        $this->assertEquals(['8.8.8.8', '8.8.4.4', '2001:4860:4860::8888'], $servers);

        $messages = $model->performValidation();
        $this->assertCount(0, $messages);
    }

    /**
     * @depends testCanBeCreated
     */
    public function testInvalidHostnameValidation()
    {
        $invalidHostnames = [
            '',                     // Required empty
            'host_with_underscore', // Underscore not allowed by RFC 952/1123 hostname
            '-leadinghyphen',       // Leading hyphen
            'trailinghyphen-',      // Trailing hyphen
            '192.168.1.1',          // IP address when IpAllowed=N
            'host name',            // Space
            'host@name',            // Special characters
            'host.with.dots',       // Dot not allowed for single label hostname
        ];

        foreach ($invalidHostnames as $invalid) {
            $model = new General();
            $model->hostname = $invalid;
            $messages = $model->performValidation();
            $this->assertGreaterThan(0, count($messages), "Hostname '{$invalid}' should fail validation.");
        }
    }

    /**
     * @depends testCanBeCreated
     */
    public function testInvalidDomainValidation()
    {
        $invalidDomains = [
            '',               // Required empty
            '10.0.0.1',       // IP address
            'domain name',    // Spaces
            'domain!name',    // Special character
        ];

        foreach ($invalidDomains as $invalid) {
            $model = new General();
            $model->domain = $invalid;
            $messages = $model->performValidation();
            $this->assertGreaterThan(0, count($messages), "Domain '{$invalid}' should fail validation.");
        }
    }

    /**
     * @depends testCanBeCreated
     */
    public function testInvalidDnsServersValidation()
    {
        $invalidDns = [
            'not_an_ip',
            '999.999.999.999',
            '1.1.1.1/24',     // Subnet mask when NetMaskAllowed=N
        ];

        foreach ($invalidDns as $invalid) {
            $model = new General();
            $entry = $model->dnsservers->Add();
            $entry->server = $invalid;
            $entry->gateway = 'none';
            $messages = $model->performValidation();
            $this->assertGreaterThan(0, count($messages), "DNS server '{$invalid}' should fail validation.");
        }
    }

    /**
     * @depends testCanBeCreated
     */
    public function testEdgeCaseSearchDomainWithSingleDot()
    {
        $model = new General();
        $model->dnssearchdomain = '.';
        $messages = $model->performValidation();
        $this->assertCount(0, $messages, "Single dot search domain override should be valid.");
        $this->assertEquals('.', (string)$model->dnssearchdomain);
    }

    /**
     * @depends testCanBeCreated
     */
    public function testSyncToLegacyConfig()
    {
        $model = new General();
        $model->hostname = 'sync-test-host';
        $model->domain = 'test.domain';
        $model->timezone = 'Etc/UTC';
        $model->language = 'en_US';
        $model->theme = 'opnsense';
        $model->prefer_ipv4 = '1';
        $model->gw_switch_default = '1';
        $model->dnslocalhost = '1';
        $model->dnsallowoverride = '0';
        $model->dnsallowoverride_exclude = 'wan';
        $model->dnssearchdomain = 'search.test';
        $model->picture = 'dGVzdA==';
        $model->picture_filename = 'logo.png';

        $entry = $model->dnsservers->Add();
        $entry->server = '1.1.1.1';
        $entry->gateway = 'WAN_DHCP';

        $model->syncToLegacyConfig();

        $config = Config::getInstance()->object();
        $this->assertEquals('sync-test-host', (string)$config->system->hostname);
        $this->assertEquals('test.domain', (string)$config->system->domain);
        $this->assertEquals('Etc/UTC', (string)$config->system->timezone);
        $this->assertEquals('en_US', (string)$config->system->language);
        $this->assertEquals('opnsense', (string)$config->theme);
        $this->assertEquals('true', (string)$config->system->prefer_ipv4);
        $this->assertEquals('true', (string)$config->system->gw_switch_default);
        $this->assertEquals('true', (string)$config->system->dnslocalhost);
        $this->assertEquals('0', (string)$config->system->dnsallowoverride);
        $this->assertEquals('wan', (string)$config->system->dnsallowoverride_exclude);
        $this->assertEquals('search.test', (string)$config->system->dnssearchdomain);
        $this->assertEquals('dGVzdA==', (string)$config->system->picture);
        $this->assertEquals('logo.png', (string)$config->system->picture_filename);
        $this->assertEquals('1.1.1.1', (string)$config->system->dnsserver[0]);
        $this->assertEquals('WAN_DHCP', (string)$config->system->dns1gw);
    }
}
