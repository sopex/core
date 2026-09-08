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

class GeneralMigrationTest extends \PHPUnit\Framework\TestCase
{
    private static $testConfigDir = __DIR__ . '/GeneralMigrationConfig';
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
    }

    public function testFullLegacyConfigMigration()
    {
        $mockXml = <<<XML
<?xml version="1.0"?>
<opnsense>
  <theme>vicuna</theme>
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
  </interfaces>
  <system>
    <hostname>fw-production</hostname>
    <domain>corp.internal</domain>
    <timezone>Europe/Berlin</timezone>
    <language>de_DE</language>
    <prefer_ipv4>1</prefer_ipv4>
    <gw_switch_default>1</gw_switch_default>
    <dnslocalhost>1</dnslocalhost>
    <dnsallowoverride>0</dnsallowoverride>
    <dnsallowoverride_exclude>wan,opt1</dnsallowoverride_exclude>
    <dnssearchdomain>corp.internal,sub.corp.internal</dnssearchdomain>
    <dnsserver>9.9.9.9</dnsserver>
    <dnsserver>149.112.112.112</dnsserver>
    <dnsserver>2620:fe::fe</dnsserver>
    <dns1gw>WAN_DHCP</dns1gw>
    <dns2gw>none</dns2gw>
    <dns3gw>none</dns3gw>
    <picture>iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==</picture>
    <picture_filename>test_logo.png</picture_filename>
  </system>
</opnsense>
XML;
        $this->writeMockConfig($mockXml);

        $model = new General();
        $migration = new M1_0_0();
        $migration->run($model);

        // Verify exact schema population without data loss
        $this->assertEquals('fw-production', (string)$model->hostname);
        $this->assertEquals('corp.internal', (string)$model->domain);
        $this->assertEquals('Europe/Berlin', (string)$model->timezone);
        $this->assertEquals('de_DE', (string)$model->language);
        $this->assertEquals('vicuna', (string)$model->theme);
        $this->assertEquals('1', (string)$model->prefer_ipv4);
        $this->assertEquals('1', (string)$model->gw_switch_default);
        $this->assertEquals('1', (string)$model->dnslocalhost);
        $this->assertEquals('0', (string)$model->dnsallowoverride);
        $this->assertEquals('wan,opt1', (string)$model->dnsallowoverride_exclude);
        $this->assertEquals('corp.internal,sub.corp.internal', (string)$model->dnssearchdomain);
        $this->assertEquals('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', (string)$model->picture);
        $this->assertEquals('test_logo.png', (string)$model->picture_filename);

        $items = iterator_to_array($model->dnsservers->iterateItems());
        $this->assertCount(3, $items);
        $servers = [];
        $gateways = [];
        foreach ($items as $item) {
            $servers[] = (string)$item->server;
            $gateways[] = (string)$item->gateway;
        }
        $this->assertEquals(['9.9.9.9', '149.112.112.112', '2620:fe::fe'], $servers);
        $this->assertEquals(['WAN_DHCP', 'none', 'none'], $gateways);

        // Assert migrated model passes all validations
        $messages = $model->performValidation();
        $this->assertCount(0, $messages);
    }

    public function testMinimalLegacyConfigMigrationUsesDefaults()
    {
        $mockXml = <<<XML
<?xml version="1.0"?>
<opnsense>
  <system>
    <hostname>custom-host</hostname>
    <domain>custom.domain</domain>
  </system>
</opnsense>
XML;
        $this->writeMockConfig($mockXml);

        $model = new General();
        $migration = new M1_0_0();
        $migration->run($model);

        $this->assertEquals('custom-host', (string)$model->hostname);
        $this->assertEquals('custom.domain', (string)$model->domain);
        $this->assertEquals('Etc/UTC', (string)$model->timezone);
        $this->assertEquals('en_US', (string)$model->language);
        $this->assertEquals('opnsense', (string)$model->theme);
        $this->assertEquals('0', (string)$model->prefer_ipv4);
        $this->assertEquals('0', (string)$model->gw_switch_default);
        $this->assertEquals('1', (string)$model->dnsallowoverride);
        $this->assertEquals('0', (string)$model->dnslocalhost);
        $this->assertCount(0, iterator_to_array($model->dnsservers->iterateItems()));

        $messages = $model->performValidation();
        $this->assertCount(0, $messages);
    }

    public function testLegacyThemeUnderSystemNode()
    {
        $mockXml = <<<XML
<?xml version="1.0"?>
<opnsense>
  <system>
    <hostname>host-theme-test</hostname>
    <domain>test.domain</domain>
    <theme>tukan</theme>
  </system>
</opnsense>
XML;
        $this->writeMockConfig($mockXml);

        $model = new General();
        $migration = new M1_0_0();
        $migration->run($model);

        $this->assertEquals('tukan', (string)$model->theme);
        $messages = $model->performValidation();
        $this->assertCount(0, $messages);
    }
}
