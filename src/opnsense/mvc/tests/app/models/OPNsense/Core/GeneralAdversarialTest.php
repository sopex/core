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

use OPNsense\Core\AppConfig;
use OPNsense\Core\Config;
use OPNsense\Core\General;
use OPNsense\Core\Migrations\M1_0_0;

class GeneralAdversarialTest extends \PHPUnit\Framework\TestCase
{
    private static $testConfigDir = __DIR__ . '/GeneralAdversarialConfig';
    private static $savedConfigDir = null;

    public static function setUpBeforeClass(): void
    {
        self::$savedConfigDir = (new AppConfig())->application->configDir;
        if (!is_dir(self::$testConfigDir)) {
            mkdir(self::$testConfigDir, 0777, true);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (file_exists(self::$testConfigDir . '/config.xml')) {
            @unlink(self::$testConfigDir . '/config.xml');
        }
        if (is_dir(self::$testConfigDir)) {
            @rmdir(self::$testConfigDir);
        }
        if (self::$savedConfigDir !== null) {
            (new AppConfig())->update('application.configDir', self::$savedConfigDir);
            Config::getInstance()->forceReload();
        }
    }

    private function writeMockConfig(string $xmlContent)
    {
        file_put_contents(self::$testConfigDir . '/config.xml', $xmlContent);
        (new AppConfig())->update('application.configDir', self::$testConfigDir);
        Config::getInstance()->forceReload();
        (new \OPNsense\Base\FieldTypes\InterfaceField())->resetStaticOptions();
    }

    /**
     * Test hostname boundary conditions and RFC compliance
     */
    public function testHostnameValidRFCValues()
    {
        $validHostnames = [
            'router',
            'fw-01',
            'a',                    // Single character letter
            '1',                    // Single character digit (RFC 1123 allows numeric labels)
            '12345',                // All numeric (RFC 1123)
            'fw--01',               // Consecutive hyphens allowed inside
            'r-o-u-t-e-r',
            str_repeat('a', 63),    // Exact 63-character maximum RFC label length
        ];

        foreach ($validHostnames as $val) {
            $model = new General();
            $model->hostname = $val;
            $messages = $model->performValidation();
            $hostnameErrors = [];
            foreach ($messages as $msg) {
                if (strpos($msg->getField(), 'hostname') !== false) {
                    $hostnameErrors[] = $msg->getMessage();
                }
            }
            $this->assertCount(0, $hostnameErrors, "Valid hostname '{$val}' (len " . strlen($val) . ") should pass validation.");
        }
    }

    /**
     * Test invalid hostname inputs
     */
    public function testHostnameInvalidValues()
    {
        $invalidHostnames = [
            '' => 'empty string',
            '   ' => 'whitespace only',
            str_repeat('a', 64) => 'exceeding 63-character RFC limit',
            str_repeat('b', 255) => 'massive 255-character string',
            'my_firewall' => 'underscore (RFC 952 violation)',
            '-router' => 'leading hyphen',
            'router-' => 'trailing hyphen',
            'router.domain' => 'contains dot (FQDN in hostname field)',
            'router@home' => 'special character @',
            'router!01' => 'special character !',
            'router#01' => 'special character #',
            'router*01' => 'wildcard *',
            'router 01' => 'space inside',
            "router\n01" => 'newline inside',
            '192.168.1.1' => 'IPv4 address as hostname',
            '127.0.0.1' => 'IPv4 loopback as hostname',
            '::1' => 'IPv6 loopback as hostname',
            '2001:db8::1' => 'IPv6 address as hostname',
            'fïrewall' => 'non-ASCII Unicode character',
            'hôte' => 'non-ASCII accent',
        ];

        foreach ($invalidHostnames as $val => $description) {
            $model = new General();
            $model->hostname = $val;
            $messages = $model->performValidation();
            $hostnameErrors = [];
            foreach ($messages as $msg) {
                if (strpos($msg->getField(), 'hostname') !== false) {
                    $hostnameErrors[] = $msg->getMessage();
                }
            }
            $this->assertGreaterThan(0, count($hostnameErrors), "Invalid hostname '{$val}' ({$description}) should fail validation.");
        }
    }

    /**
     * Test domain valid and invalid inputs
     */
    public function testDomainValidation()
    {
        $validDomains = [
            'localdomain',
            'internal',
            'home.arpa',
            'corp.internal',
            'sub.corp.internal',
            str_repeat('a', 63) . '.com',
            'a.b.c.d.e.f.org',
        ];

        foreach ($validDomains as $val) {
            $model = new General();
            $model->domain = $val;
            $messages = $model->performValidation();
            $domainErrors = [];
            foreach ($messages as $msg) {
                if (strpos($msg->getField(), 'domain') !== false) {
                    $domainErrors[] = $msg->getMessage();
                }
            }
            $this->assertCount(0, $domainErrors, "Valid domain '{$val}' should pass validation.");
        }

        $invalidDomains = [
            '' => 'empty string',
            '   ' => 'whitespace only',
            '10.0.0.1' => 'IPv4 address as domain',
            '192.168.1.1' => 'IPv4 address as domain',
            '::1' => 'IPv6 address as domain',
            str_repeat('a', 64) . '.com' => 'label exceeding 63 characters',
            '.corp.internal' => 'leading dot',
            'corp..internal' => 'double dot',
            '-corp.internal' => 'leading hyphen in label',
            'corp-.internal' => 'trailing hyphen in label',
            'corp_internal.com' => 'underscore in domain',
            'corp internal.com' => 'space in domain',
            'corp$internal.com' => 'special character $',
            str_repeat('a.bb', 70) => 'total domain length exceeding 253 characters',
        ];

        foreach ($invalidDomains as $val => $description) {
            $model = new General();
            $model->domain = $val;
            $messages = $model->performValidation();
            $domainErrors = [];
            foreach ($messages as $msg) {
                if (strpos($msg->getField(), 'domain') !== false) {
                    $domainErrors[] = $msg->getMessage();
                }
            }
            $this->assertGreaterThan(0, count($domainErrors), "Invalid domain '{$val}' ({$description}) should fail validation.");
        }
    }

    /**
     * Test timezone validation with edge cases and injection attempts
     */
    public function testTimezoneValidation()
    {
        $validTimezones = [
            'UTC',
            'Etc/UTC',
            'Europe/Berlin',
            'America/New_York',
            'Asia/Tokyo',
            'Australia/Sydney',
            'Etc/GMT+5',
        ];

        foreach ($validTimezones as $val) {
            $model = new General();
            $model->timezone = $val;
            $messages = $model->performValidation();
            $tzErrors = [];
            foreach ($messages as $msg) {
                if (strpos($msg->getField(), 'timezone') !== false) {
                    $tzErrors[] = $msg->getMessage();
                }
            }
            $this->assertCount(0, $tzErrors, "Valid timezone '{$val}' should pass validation.");
        }

        $invalidTimezones = [
            '' => 'empty timezone',
            'Invalid/Zone' => 'non-existent timezone',
            'Mars/Olympus' => 'fictional timezone',
            '../../etc/passwd' => 'directory traversal',
            '<script>alert(1)</script>' => 'XSS attempt',
            'UTC; rm -rf /' => 'command injection attempt',
            'Europe/Atlantis' => 'non-existent European city',
        ];

        foreach ($invalidTimezones as $val => $description) {
            $model = new General();
            $model->timezone = $val;
            $messages = $model->performValidation();
            $tzErrors = [];
            foreach ($messages as $msg) {
                if (strpos($msg->getField(), 'timezone') !== false) {
                    $tzErrors[] = $msg->getMessage();
                }
            }
            $this->assertGreaterThan(0, count($tzErrors), "Invalid timezone '{$val}' ({$description}) should fail validation.");
        }
    }

    /**
     * Test DNS search domain edge cases (RFC, dot override, CSV lists)
     */
    public function testDnsSearchDomainValidation()
    {
        $validSearchDomains = [
            '.' => 'single dot root override',
            'corp.internal' => 'single domain',
            'corp.internal, home.arpa' => 'multiple comma-separated domains',
            'corp.internal, home.arpa, .' => 'multiple domains with dot',
            '  sub.corp.internal , home.arpa  ' => 'spaces around commas',
            '' => 'empty search domain (optional field)',
        ];

        foreach ($validSearchDomains as $val => $description) {
            $model = new General();
            $model->dnssearchdomain = $val;
            $messages = $model->performValidation();
            $searchErrors = [];
            foreach ($messages as $msg) {
                if (strpos($msg->getField(), 'dnssearchdomain') !== false) {
                    $searchErrors[] = $msg->getMessage();
                }
            }
            $this->assertCount(0, $searchErrors, "Valid search domain '{$val}' ({$description}) should pass validation.");
        }

        $invalidSearchDomains = [
            'corp_internal.com' => 'underscore in search domain',
            '-invalid.domain' => 'leading hyphen in search domain',
            'invalid-.domain' => 'trailing hyphen in search domain',
            'corp.internal, bad_domain, other.org' => 'one invalid among valid domains',
            'corp..internal' => 'double dot in search domain',
            'domain$name.com' => 'special character $ in search domain',
            'domain@name.com' => 'special character @ in search domain',
        ];

        foreach ($invalidSearchDomains as $val => $description) {
            $model = new General();
            $model->dnssearchdomain = $val;
            $messages = $model->performValidation();
            $searchErrors = [];
            foreach ($messages as $msg) {
                if (strpos($msg->getField(), 'dnssearchdomain') !== false) {
                    $searchErrors[] = $msg->getMessage();
                }
            }
            $this->assertGreaterThan(0, count($searchErrors), "Invalid search domain '{$val}' ({$description}) should fail validation.");
        }
    }

    /**
     * Test DNS servers: IPv4, IPv6, mix, NetMask violations, and invalid addresses
     */
    public function testDnsServersValidation()
    {
        // 1. Valid mixed IPv4 and IPv6 DNS list
        $model = new General();
        $servers = [
            '1.1.1.1',
            '2606:4700:4700::1111',
            '8.8.8.8',
            '2001:4860:4860::8888',
            '9.9.9.9',
            '2620:fe::fe',
            '127.0.0.1',
            '::1',
        ];
        foreach ($servers as $srv) {
            $entry = $model->dnsservers->Add();
            $entry->server = $srv;
            $entry->gateway = 'none';
        }
        $messages = $model->performValidation();
        $this->assertCount(0, $messages, 'Mixed 8 IPv4/IPv6 DNS servers with none gateway should be 100% valid.');

        // 2. DNS servers with netmasks (disallowed by NetMaskAllowed=N)
        $invalidNetmaskServers = [
            '8.8.8.8/24',
            '1.1.1.1/32',
            '2001:4860:4860::8888/64',
            '::1/128',
        ];
        foreach ($invalidNetmaskServers as $srv) {
            $mdl = new General();
            $entry = $mdl->dnsservers->Add();
            $entry->server = $srv;
            $entry->gateway = 'none';
            $messages = $mdl->performValidation();
            $this->assertGreaterThan(0, count($messages), "DNS server with netmask '{$srv}' must fail validation.");
        }

        // 3. Hostnames or non-IPs as DNS servers
        $nonIpServers = [
            'dns.google',
            'one.one.one.one',
            'localhost',
            '999.999.999.999',
            '256.1.1.1',
            '1.1.1.1.1',
            '2001:db8:::1',
            'http://1.1.1.1',
            'not an ip',
        ];
        foreach ($nonIpServers as $srv) {
            $mdl = new General();
            $entry = $mdl->dnsservers->Add();
            $entry->server = $srv;
            $entry->gateway = 'none';
            $messages = $mdl->performValidation();
            $this->assertGreaterThan(0, count($messages), "Non-IP DNS server '{$srv}' must fail validation.");
        }

        // 4. Empty server node in dnsservers array
        $mdl = new General();
        $entry = $mdl->dnsservers->Add();
        $entry->server = '';
        $entry->gateway = 'none';
        $messages = $mdl->performValidation();
        $this->assertGreaterThan(0, count($messages), 'Empty DNS server item in array must fail validation.');
    }

    /**
     * Test DNS server gateway protocol mismatch (IPv4 DNS with IPv6 gateway and vice-versa)
     */
    public function testDnsServerGatewayProtocolMismatch()
    {
        $mockXml = <<<XML
<?xml version="1.0"?>
<opnsense>
  <interfaces>
    <wan>
      <enable>1</enable>
      <if>vtnet0</if>
      <descr>WAN</descr>
    </wan>
  </interfaces>
  <gateways>
    <gateway_item>
      <name>WAN_GW4</name>
      <gateway>198.51.100.1</gateway>
      <ipprotocol>inet</ipprotocol>
      <interface>wan</interface>
    </gateway_item>
    <gateway_item>
      <name>WAN_GW6</name>
      <gateway>2001:db8:1::1</gateway>
      <ipprotocol>inet6</ipprotocol>
      <interface>wan</interface>
    </gateway_item>
  </gateways>
</opnsense>
XML;
        $this->writeMockConfig($mockXml);

        // Case A: IPv4 DNS assigned to IPv6 gateway
        $modelA = new General();
        $eA = $modelA->dnsservers->Add();
        $eA->server = '1.1.1.1';
        $eA->gateway = 'WAN_GW6';
        $msgsA = $modelA->performValidation();
        $this->assertGreaterThan(0, count($msgsA), 'IPv4 DNS server assigned to IPv6 gateway must fail.');
        $errTextA = '';
        foreach ($msgsA as $m) {
            $errTextA .= $m->getMessage();
        }
        $this->assertStringContainsString('IPv6 gateway', $errTextA);

        // Case B: IPv6 DNS assigned to IPv4 gateway
        $modelB = new General();
        $eB = $modelB->dnsservers->Add();
        $eB->server = '2606:4700:4700::1111';
        $eB->gateway = 'WAN_GW4';
        $msgsB = $modelB->performValidation();
        $this->assertGreaterThan(0, count($msgsB), 'IPv6 DNS server assigned to IPv4 gateway must fail.');
        $errTextB = '';
        foreach ($msgsB as $m) {
            $errTextB .= $m->getMessage();
        }
        $this->assertStringContainsString('IPv4 gateway', $errTextB);

        // Case C: Matching protocols
        $modelC = new General();
        $eC1 = $modelC->dnsservers->Add();
        $eC1->server = '1.1.1.1';
        $eC1->gateway = 'WAN_GW4';
        $eC2 = $modelC->dnsservers->Add();
        $eC2->server = '2606:4700:4700::1111';
        $eC2->gateway = 'WAN_GW6';
        $msgsC = $modelC->performValidation();
        $this->assertCount(0, $msgsC, 'Matching gateway protocols must pass validation.');
    }

    /**
     * Test string assignment to dnsservers property (__set overload)
     */
    public function testStringAssignmentToDnsServersVariations()
    {
        $model = new General();
        // Initial servers
        $model->dnsservers = '1.1.1.1, 8.8.8.8';
        $this->assertCount(2, iterator_to_array($model->dnsservers->iterateItems()));

        // Overwrite with single IPv6
        $model->dnsservers = '2001:4860:4860::8888';
        $items = iterator_to_array($model->dnsservers->iterateItems());
        $this->assertCount(1, $items);
        $this->assertEquals('2001:4860:4860::8888', (string)reset($items)->server);

        // Overwrite with empty string to clear
        $model->dnsservers = '';
        $this->assertCount(0, iterator_to_array($model->dnsservers->iterateItems()));
    }

    /**
     * Test corrupt, large, and cleared picture data
     */
    public function testPictureHandling()
    {
        $model = new General();
        // Valid small base64
        $model->picture = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
        $model->picture_filename = 'pixel.png';
        $messages = $model->performValidation();
        $this->assertCount(0, $messages);

        // Clear picture
        $model->picture = '';
        $model->picture_filename = '';
        $messages = $model->performValidation();
        $this->assertCount(0, $messages);
        $this->assertEquals('', (string)$model->picture);
        $this->assertEquals('', (string)$model->picture_filename);

        // Arbitrary non-image string (TextField allows string storage safely without executing)
        $model->picture = 'SGVsbG8gV29ybGQ=';
        $model->picture_filename = 'hello.txt';
        $messages = $model->performValidation();
        $this->assertCount(0, $messages);
    }

    /**
     * Extreme migration test: Completely empty configuration without <system> tag
     */
    public function testMigrationEmptyConfigNoSystemTag()
    {
        $mockXml = <<<XML
<?xml version="1.0"?>
<opnsense>
</opnsense>
XML;
        $this->writeMockConfig($mockXml);

        $model = new General();
        $migration = new M1_0_0();
        $migration->run($model);

        // Must populate all schema defaults
        $this->assertEquals('OPNsense', (string)$model->hostname);
        $this->assertEquals('localdomain', (string)$model->domain);
        $this->assertEquals('Etc/UTC', (string)$model->timezone);
        $this->assertEquals('en_US', (string)$model->language);
        $this->assertEquals('opnsense', (string)$model->theme);
        $this->assertEquals('0', (string)$model->prefer_ipv4);
        $this->assertEquals('0', (string)$model->gw_switch_default);
        $this->assertEquals('1', (string)$model->dnsallowoverride);
        $this->assertEquals('0', (string)$model->dnslocalhost);
        $this->assertEquals('', (string)$model->dnssearchdomain);
        $this->assertEquals('', (string)$model->picture);
        $this->assertCount(0, iterator_to_array($model->dnsservers->iterateItems()));

        $messages = $model->performValidation();
        $this->assertCount(0, $messages, 'Default values from completely empty config must pass all model validations.');
    }

    /**
     * Extreme migration test: Empty <system/> tag
     */
    public function testMigrationEmptySystemTag()
    {
        $mockXml = <<<XML
<?xml version="1.0"?>
<opnsense>
  <system/>
</opnsense>
XML;
        $this->writeMockConfig($mockXml);

        $model = new General();
        $migration = new M1_0_0();
        $migration->run($model);

        $this->assertEquals('OPNsense', (string)$model->hostname);
        $this->assertEquals('localdomain', (string)$model->domain);
        $this->assertEquals('Etc/UTC', (string)$model->timezone);
        $this->assertEquals('en_US', (string)$model->language);
        $this->assertEquals('opnsense', (string)$model->theme);
        $this->assertEquals('0', (string)$model->prefer_ipv4);
        $this->assertEquals('0', (string)$model->gw_switch_default);
        $this->assertEquals('1', (string)$model->dnsallowoverride);
        $this->assertEquals('0', (string)$model->dnslocalhost);

        $messages = $model->performValidation();
        $this->assertCount(0, $messages);
    }

    /**
     * Extreme migration test: 8 DNS servers with mixed IPv4, IPv6, and gateways
     */
    public function testMigrationEightDnsServersWithGateways()
    {
        $mockXml = <<<XML
<?xml version="1.0"?>
<opnsense>
  <system>
    <hostname>core-fw</hostname>
    <domain>datacenter.net</domain>
    <dnsserver>1.1.1.1</dnsserver>
    <dnsserver>1.0.0.1</dnsserver>
    <dnsserver>8.8.8.8</dnsserver>
    <dnsserver>8.8.4.4</dnsserver>
    <dnsserver>2606:4700:4700::1111</dnsserver>
    <dnsserver>2606:4700:4700::1001</dnsserver>
    <dnsserver>2001:4860:4860::8888</dnsserver>
    <dnsserver>2001:4860:4860::8844</dnsserver>
    <dns1gw>WAN_DHCP</dns1gw>
    <dns2gw>WAN_DHCP</dns2gw>
    <dns3gw>WAN2_STATIC</dns3gw>
    <dns4gw>none</dns4gw>
    <dns5gw>WAN_DHCP6</dns5gw>
    <dns6gw>WAN_DHCP6</dns6gw>
    <dns7gw>none</dns7gw>
    <dns8gw>none</dns8gw>
  </system>
</opnsense>
XML;
        $this->writeMockConfig($mockXml);

        $model = new General();
        $migration = new M1_0_0();
        $migration->run($model);

        $items = iterator_to_array($model->dnsservers->iterateItems());
        $this->assertCount(8, $items, 'All 8 DNS servers must be migrated.');

        $expected = [
            ['1.1.1.1', 'WAN_DHCP'],
            ['1.0.0.1', 'WAN_DHCP'],
            ['8.8.8.8', 'WAN2_STATIC'],
            ['8.8.4.4', 'none'],
            ['2606:4700:4700::1111', 'WAN_DHCP6'],
            ['2606:4700:4700::1001', 'WAN_DHCP6'],
            ['2001:4860:4860::8888', 'none'],
            ['2001:4860:4860::8844', 'none'],
        ];

        $idx = 0;
        foreach ($items as $item) {
            $this->assertEquals($expected[$idx][0], (string)$item->server);
            $this->assertEquals($expected[$idx][1], (string)$item->gateway);
            $idx++;
        }
    }

    /**
     * Extreme migration test: More than 8 DNS servers (> 8)
     */
    public function testMigrationMoreThanEightDnsServers()
    {
        $mockXml = <<<XML
<?xml version="1.0"?>
<opnsense>
  <system>
    <hostname>large-dns-host</hostname>
    <domain>test.net</domain>
    <dnsserver>1.1.1.1</dnsserver>
    <dnsserver>1.0.0.1</dnsserver>
    <dnsserver>8.8.8.8</dnsserver>
    <dnsserver>8.8.4.4</dnsserver>
    <dnsserver>9.9.9.9</dnsserver>
    <dnsserver>149.112.112.112</dnsserver>
    <dnsserver>208.67.222.222</dnsserver>
    <dnsserver>208.67.220.220</dnsserver>
    <dnsserver>2606:4700:4700::1111</dnsserver>
    <dnsserver>2001:4860:4860::8888</dnsserver>
  </system>
</opnsense>
XML;
        $this->writeMockConfig($mockXml);

        $model = new General();
        $migration = new M1_0_0();
        $migration->run($model);

        $items = iterator_to_array($model->dnsservers->iterateItems());
        $this->assertCount(10, $items, 'All 10 DNS servers must be migrated into dnsservers array.');
    }

    /**
     * Extreme migration test: Whitespace-padded and empty <dnsserver> entries
     */
    public function testMigrationSkipsEmptyDnsServerTags()
    {
        $mockXml = <<<XML
<?xml version="1.0"?>
<opnsense>
  <system>
    <hostname>sparse-dns</hostname>
    <domain>test.net</domain>
    <dnsserver>8.8.8.8</dnsserver>
    <dnsserver></dnsserver>
    <dnsserver>   </dnsserver>
    <dnsserver> 1.1.1.1 </dnsserver>
    <dns1gw>GW_A</dns1gw>
    <dns2gw>GW_B</dns2gw>
  </system>
</opnsense>
XML;
        $this->writeMockConfig($mockXml);

        $model = new General();
        $migration = new M1_0_0();
        $migration->run($model);

        $items = iterator_to_array($model->dnsservers->iterateItems());
        $this->assertCount(2, $items, 'Empty and whitespace-only dnsserver nodes must be ignored.');

        $servers = [];
        foreach ($items as $item) {
            $servers[] = (string)$item->server;
        }
        $this->assertEquals(['8.8.8.8', '1.1.1.1'], $servers);
    }

    /**
     * Test legacy boolean flag representations
     */
    public function testMigrationBooleanVariations()
    {
        $mockXml = <<<XML
<?xml version="1.0"?>
<opnsense>
  <system>
    <hostname>bool-test</hostname>
    <domain>test.net</domain>
    <prefer_ipv4>true</prefer_ipv4>
    <gw_switch_default>1</gw_switch_default>
    <dnslocalhost>yes</dnslocalhost>
    <dnsallowoverride>0</dnsallowoverride>
  </system>
</opnsense>
XML;
        $this->writeMockConfig($mockXml);

        $model = new General();
        $migration = new M1_0_0();
        $migration->run($model);

        $this->assertEquals('1', (string)$model->prefer_ipv4);
        $this->assertEquals('1', (string)$model->gw_switch_default);
        $this->assertEquals('1', (string)$model->dnslocalhost);
        $this->assertEquals('0', (string)$model->dnsallowoverride);
    }

    /**
     * Test round-trip legacy synchronization and clean unset of removed DNS servers
     */
    public function testLegacySyncCleanRemoval()
    {
        $model = new General();
        $model->hostname = 'sync-host';
        $model->domain = 'sync.domain';
        $model->timezone = 'Etc/UTC';
        $model->theme = 'vicuna';

        // Add 3 servers
        $e1 = $model->dnsservers->Add();
        $e1->server = '1.1.1.1';
        $e1->gateway = 'GW1';
        $e2 = $model->dnsservers->Add();
        $e2->server = '8.8.8.8';
        $e2->gateway = 'none';

        $model->syncToLegacyConfig();

        $config = Config::getInstance()->object();
        $this->assertCount(2, $config->system->dnsserver);
        $this->assertEquals('1.1.1.1', (string)$config->system->dnsserver[0]);
        $this->assertEquals('8.8.8.8', (string)$config->system->dnsserver[1]);
        $this->assertEquals('GW1', (string)$config->system->dns1gw);
        $this->assertEquals('none', (string)$config->system->dns2gw);

        // Now remove all DNS servers and sync again
        $delKeys = [];
        foreach ($model->dnsservers->iterateItems() as $key => $node) {
            $delKeys[] = $key;
        }
        foreach ($delKeys as $key) {
            $model->dnsservers->del($key);
        }

        $model->syncToLegacyConfig();

        $config = Config::getInstance()->object();
        $this->assertFalse(isset($config->system->dnsserver), 'Legacy dnsserver nodes must be completely unset when model list is cleared.');
        $this->assertFalse(isset($config->system->dns1gw), 'Legacy dns1gw must be unset.');
        $this->assertFalse(isset($config->system->dns2gw), 'Legacy dns2gw must be unset.');
    }

    /**
     * Test real-world complex production config migration and round-trip serialization
     */
    public function testComplexRealWorldProductionConfigMigration()
    {
        $mockXml = <<<XML
<?xml version="1.0"?>
<opnsense>
  <theme>tukan</theme>
  <interfaces>
    <wan>
      <enable>1</enable>
      <if>vtnet0</if>
      <descr>WAN</descr>
    </wan>
    <opt1>
      <enable>1</enable>
      <if>vtnet1</if>
      <descr>OPT1</descr>
    </opt1>
    <opt2>
      <enable>1</enable>
      <if>vtnet2</if>
      <descr>OPT2</descr>
    </opt2>
  </interfaces>
  <system>
    <hostname>edge-gw-01</hostname>
    <domain>company.corp</domain>
    <timezone>America/Chicago</timezone>
    <language>es_ES</language>
    <prefer_ipv4>1</prefer_ipv4>
    <gw_switch_default>1</gw_switch_default>
    <dnslocalhost>1</dnslocalhost>
    <dnsallowoverride>1</dnsallowoverride>
    <dnsallowoverride_exclude>opt1,opt2</dnsallowoverride_exclude>
    <dnssearchdomain>company.corp,internal.corp,mgmt.corp,.</dnssearchdomain>
    <dnsserver>1.1.1.1</dnsserver>
    <dnsserver>1.0.0.1</dnsserver>
    <dnsserver>8.8.8.8</dnsserver>
    <dnsserver>2606:4700:4700::1111</dnsserver>
    <dnsserver>2001:4860:4860::8888</dnsserver>
    <dnsserver>9.9.9.9</dnsserver>
    <dns1gw>WAN_DHCP</dns1gw>
    <dns2gw>WAN_DHCP</dns2gw>
    <dns3gw>none</dns3gw>
    <dns4gw>WAN_DHCP6</dns4gw>
    <dns5gw>none</dns5gw>
    <dns6gw>none</dns6gw>
    <picture>iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==</picture>
    <picture_filename>corp_logo.png</picture_filename>
  </system>
</opnsense>
XML;
        $this->writeMockConfig($mockXml);

        $model = new General();
        $migration = new M1_0_0();
        $migration->run($model);

        // Verification of migrated fields
        $this->assertEquals('edge-gw-01', (string)$model->hostname);
        $this->assertEquals('company.corp', (string)$model->domain);
        $this->assertEquals('America/Chicago', (string)$model->timezone);
        $this->assertEquals('es_ES', (string)$model->language);
        $this->assertEquals('tukan', (string)$model->theme);
        $this->assertEquals('1', (string)$model->prefer_ipv4);
        $this->assertEquals('1', (string)$model->gw_switch_default);
        $this->assertEquals('1', (string)$model->dnslocalhost);
        $this->assertEquals('1', (string)$model->dnsallowoverride);
        $this->assertEquals('opt1,opt2', (string)$model->dnsallowoverride_exclude);
        $this->assertEquals('company.corp,internal.corp,mgmt.corp,.', (string)$model->dnssearchdomain);
        $this->assertEquals('corp_logo.png', (string)$model->picture_filename);

        $items = iterator_to_array($model->dnsservers->iterateItems());
        $this->assertCount(6, $items);
        $servers = [];
        $gws = [];
        foreach ($items as $item) {
            $servers[] = (string)$item->server;
            $gws[] = (string)$item->gateway;
        }
        $this->assertEquals(['1.1.1.1', '1.0.0.1', '8.8.8.8', '2606:4700:4700::1111', '2001:4860:4860::8888', '9.9.9.9'], $servers);
        $this->assertEquals(['WAN_DHCP', 'WAN_DHCP', 'none', 'WAN_DHCP6', 'none', 'none'], $gws);

        // Model validation must succeed
        $messages = $model->performValidation();
        $msgList = [];
        foreach ($messages as $m) {
            $msgList[] = $m->getField() . ': ' . $m->getMessage();
        }
        $this->assertCount(0, $messages, 'Validation failed with: ' . implode('; ', $msgList));

        // Round-trip serialize back to config
        $serializeResult = $model->serializeToConfig();
        $this->assertTrue($serializeResult, 'serializeToConfig must succeed on valid model.');

        $config = Config::getInstance()->object();
        $this->assertEquals('edge-gw-01', (string)$config->system->hostname);
        $this->assertEquals('company.corp', (string)$config->system->domain);
        $this->assertEquals('America/Chicago', (string)$config->system->timezone);
        $this->assertEquals('es_ES', (string)$config->system->language);
        $this->assertEquals('tukan', (string)$config->theme);
        $this->assertEquals('true', (string)$config->system->prefer_ipv4);
        $this->assertEquals('true', (string)$config->system->gw_switch_default);
        $this->assertEquals('true', (string)$config->system->dnslocalhost);
        $this->assertEquals('1', (string)$config->system->dnsallowoverride);
        $this->assertEquals('opt1,opt2', (string)$config->system->dnsallowoverride_exclude);
        $this->assertEquals('company.corp,internal.corp,mgmt.corp,.', (string)$config->system->dnssearchdomain);
        $this->assertEquals('corp_logo.png', (string)$config->system->picture_filename);
        $this->assertCount(6, $config->system->dnsserver);
        $this->assertEquals('1.1.1.1', (string)$config->system->dnsserver[0]);
        $this->assertEquals('WAN_DHCP', (string)$config->system->dns1gw);
        $this->assertEquals('WAN_DHCP6', (string)$config->system->dns4gw);
    }

    /**
     * Test that serializeToConfig blocks and refuses to sync invalid model data by throwing ValidationException
     */
    public function testSerializeValidationGuard()
    {
        $model = new General();
        $model->hostname = 'invalid_hostname_with_underscore';

        $this->expectException(\OPNsense\Base\ValidationException::class);
        $model->serializeToConfig(true);
    }
}

