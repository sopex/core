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

namespace tests\OPNsense\Firewall;

use OPNsense\Core\AppConfig;
use OPNsense\Core\Config;
use OPNsense\Firewall\Filter;
use OPNsense\Firewall\Migrations\MFP1_0_10;

class FilterSettingsTest extends \PHPUnit\Framework\TestCase
{
    private static $testConfigDir = null;
    private static $savedConfigDir = null;

    public static function setUpBeforeClass(): void
    {
        self::$savedConfigDir = (new AppConfig())->application->configDir;
        self::$testConfigDir = sys_get_temp_dir() . '/filter_settings_unit_test';
        if (!is_dir(self::$testConfigDir)) {
            mkdir(self::$testConfigDir, 0777, true);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$testConfigDir && is_dir(self::$testConfigDir)) {
            if (file_exists(self::$testConfigDir . '/config.xml')) {
                @unlink(self::$testConfigDir . '/config.xml');
            }
            @rmdir(self::$testConfigDir);
        }
        if (self::$savedConfigDir !== null) {
            (new AppConfig())->update('application.configDir', self::$savedConfigDir);
            (new AppConfig())->update('globals.simulate_mode', false);
            Config::getInstance()->forceReload();
        }
    }

    private function setMockConfig(string $xmlContent): void
    {
        file_put_contents(self::$testConfigDir . '/config.xml', $xmlContent);
        (new AppConfig())->update('application.configDir', self::$testConfigDir);
        (new AppConfig())->update('globals.simulate_mode', true);
        Config::getInstance()->forceReload();
    }

    protected function setUp(): void
    {
        $this->setMockConfig('<opnsense><interfaces><lan><descr>LAN</descr></lan></interfaces></opnsense>');
    }

    public function testCanBeCreated()
    {
        $model = new Filter();
        $this->assertInstanceOf(Filter::class, $model);
        $this->assertContains($model->getVersion(), ['0.0.0', '1.0.10']);
    }

    public function testDefaultValues()
    {
        $model = new Filter();
        $s = $model->settings;

        $this->assertEquals('1', (string)$s->filter->scrub_enabled);
        $this->assertEquals('0', (string)$s->filter->scrub_no_df);
        $this->assertEquals('0', (string)$s->filter->scrub_random_id);
        $this->assertEquals('0', (string)$s->filter->disablefilter);
        $this->assertEquals('normal', (string)$s->filter->optimization);
        $this->assertEquals('monthly', (string)$s->filter->bogonsinterval);
        $this->assertEquals('urgent', (string)$s->filter->pfdebug);
        $this->assertEquals('never', (string)$s->filter->syncookies);
        $this->assertEquals('enable', (string)$s->nat->natreflection);
        $this->assertEquals('1', (string)$s->logging->logdefaultblock);
        $this->assertEquals('0', (string)$s->logging->logdefaultpass);
        $this->assertEquals('0', (string)$s->logging->logoutboundnat);
        $this->assertEquals('1', (string)$s->logging->logbogons);
        $this->assertEquals('1', (string)$s->logging->logprivatenets);
    }

    public function testAdaptiveValidation()
    {
        $model = new Filter();
        $model->settings->filter->adaptivestart = '10000';
        $model->settings->filter->adaptiveend = '';
        $messages = $model->performValidation();
        $this->assertGreaterThan(0, count($messages));

        $model->settings->filter->adaptiveend = '20000';
        $messages = $model->performValidation();
        $this->assertCount(0, $messages);
    }

    public function testSyncookiesAdaptiveValidation()
    {
        $model = new Filter();
        $model->settings->filter->syncookies = 'adaptive';
        $model->settings->filter->syncookies_adaptstart = '50';
        $model->settings->filter->syncookies_adaptend = '60'; // start must be higher than end
        $messages = $model->performValidation();
        $this->assertGreaterThan(0, count($messages));

        $model->settings->filter->syncookies_adaptstart = '75';
        $model->settings->filter->syncookies_adaptend = '25';
        $messages = $model->performValidation();
        $this->assertCount(0, $messages);
    }

    public function testMigrationMFP1_0_10()
    {
        $xml = <<<EOF
<opnsense>
    <system>
        <disablefilter>1</disablefilter>
        <optimization>aggressive</optimization>
        <state-policy>if-bound</state-policy>
        <maximumstates>500000</maximumstates>
        <maximumfrags>10000</maximumfrags>
        <maximumtableentries>400000</maximumtableentries>
        <adaptivestart>60000</adaptivestart>
        <adaptiveend>120000</adaptiveend>
        <aliasesresolveinterval>600</aliasesresolveinterval>
        <checkaliasesurlcert>1</checkaliasesurlcert>
        <disablereplyto>1</disablereplyto>
        <bogons>
            <interval>daily</interval>
        </bogons>
        <schedule_states>1</schedule_states>
        <skip_rules_gw_down>1</skip_rules_gw_down>
        <lb_use_sticky>1</lb_use_sticky>
        <pf_share_forward>1</pf_share_forward>
        <pf_disable_force_gw>1</pf_disable_force_gw>
        <srctrack>300</srctrack>
        <keepcounters>1</keepcounters>
        <pfdebug>loud</pfdebug>
        <webgui>
            <noantilockout>1</noantilockout>
        </webgui>
        <no_ipv6_rfc4890_req>1</no_ipv6_rfc4890_req>
        <no_port0_block>1</no_port0_block>
        <no_sshlockout>1</no_sshlockout>
        <no_virusprot>1</no_virusprot>
        <syncookies>adaptive</syncookies>
        <syncookies_adaptstart>80</syncookies_adaptstart>
        <syncookies_adaptend>40</syncookies_adaptend>
        <disablenatreflection>purenat</disablenatreflection>
        <enablebinatreflection>1</enablebinatreflection>
        <enablenatreflectionhelper>1</enablenatreflectionhelper>
        <reflectiontimeout>3600</reflectiontimeout>
    </system>
    <filter>
        <bypassstaticroutes>1</bypassstaticroutes>
    </filter>
    <syslog>
        <nologdefaultblock>1</nologdefaultblock>
        <nologdefaultpass>1</nologdefaultpass>
        <logoutboundnat>1</logoutboundnat>
        <nologbogons>1</nologbogons>
        <nologprivatenets>1</nologprivatenets>
    </syslog>
    <interfaces>
        <lan>
            <descr>LAN</descr>
        </lan>
    </interfaces>
</opnsense>
EOF;
        $this->setMockConfig($xml);

        $model = new Filter();
        $migration = new MFP1_0_10();
        $migration->run($model);

        $s = $model->settings;
        $this->assertEquals('1', (string)$s->filter->disablefilter);
        $this->assertEquals('aggressive', (string)$s->filter->optimization);
        $this->assertEquals('if-bound', (string)$s->filter->{'state-policy'});
        $this->assertEquals('500000', (string)$s->filter->maximumstates);
        $this->assertEquals('10000', (string)$s->filter->maximumfrags);
        $this->assertEquals('400000', (string)$s->filter->maximumtableentries);
        $this->assertEquals('60000', (string)$s->filter->adaptivestart);
        $this->assertEquals('120000', (string)$s->filter->adaptiveend);
        $this->assertEquals('600', (string)$s->filter->aliasesresolveinterval);
        $this->assertEquals('1', (string)$s->filter->checkaliasesurlcert);
        $this->assertEquals('1', (string)$s->filter->disablereplyto);
        $this->assertEquals('daily', (string)$s->filter->bogonsinterval);
        $this->assertEquals('1', (string)$s->filter->schedule_states);
        $this->assertEquals('1', (string)$s->filter->skip_rules_gw_down);
        $this->assertEquals('1', (string)$s->filter->lb_use_sticky);
        $this->assertEquals('1', (string)$s->filter->pf_share_forward);
        $this->assertEquals('1', (string)$s->filter->pf_disable_force_gw);
        $this->assertEquals('300', (string)$s->filter->srctrack);
        $this->assertEquals('1', (string)$s->filter->keepcounters);
        $this->assertEquals('loud', (string)$s->filter->pfdebug);
        $this->assertEquals('1', (string)$s->filter->noantilockout);
        $this->assertEquals('1', (string)$s->filter->no_ipv6_rfc4890_req);
        $this->assertEquals('1', (string)$s->filter->no_port0_block);
        $this->assertEquals('1', (string)$s->filter->no_sshlockout);
        $this->assertEquals('1', (string)$s->filter->no_virusprot);
        $this->assertEquals('1', (string)$s->filter->bypassstaticroutes);
        $this->assertEquals('adaptive', (string)$s->filter->syncookies);
        $this->assertEquals('80', (string)$s->filter->syncookies_adaptstart);
        $this->assertEquals('40', (string)$s->filter->syncookies_adaptend);
        $this->assertEquals('purenat', (string)$s->nat->natreflection);
        $this->assertEquals('1', (string)$s->nat->enablebinatreflection);
        $this->assertEquals('1', (string)$s->nat->enablenatreflectionhelper);
        $this->assertEquals('3600', (string)$s->nat->reflectiontimeout);
        $this->assertEquals('0', (string)$s->logging->logdefaultblock);
        $this->assertEquals('0', (string)$s->logging->logdefaultpass);
        $this->assertEquals('1', (string)$s->logging->logoutboundnat);
        $this->assertEquals('0', (string)$s->logging->logbogons);
        $this->assertEquals('0', (string)$s->logging->logprivatenets);

        // Test syncToLegacyConfig
        $model->syncToLegacyConfig();
        $config = Config::getInstance()->object();
        $this->assertEquals('true', (string)$config->system->disablefilter);
        $this->assertEquals('aggressive', (string)$config->system->optimization);
        $this->assertEquals('purenat', (string)$config->system->disablenatreflection);
        $this->assertEquals('true', (string)$config->syslog->nologdefaultblock);
        $this->assertEquals('true', (string)$config->syslog->logoutboundnat);
    }
}
