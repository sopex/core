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

use OPNsense\Core\Admin;
use OPNsense\Core\AppConfig;
use OPNsense\Core\Config;
use OPNsense\Core\Migrations\M1_0_0;

class AdminMigrationTest extends \PHPUnit\Framework\TestCase
{
    private static $testConfigDir = null;
    private static $savedConfigDir = null;

    public static function setUpBeforeClass(): void
    {
        self::$savedConfigDir = (new AppConfig())->application->configDir;
        self::$testConfigDir = sys_get_temp_dir() . '/admin_migration_unit_test';
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

    protected function tearDown(): void
    {
        if (file_exists(self::$testConfigDir . '/config.xml')) {
            @unlink(self::$testConfigDir . '/config.xml');
        }
    }

    private function setMockConfig(string $xmlContent): void
    {
        file_put_contents(self::$testConfigDir . '/config.xml', $xmlContent);
        (new AppConfig())->update('application.configDir', self::$testConfigDir);
        (new AppConfig())->update('globals.simulate_mode', true);
        Config::getInstance()->forceReload();
    }

    /**
     * Test full migration of legacy admin configuration
     */
    public function testFullLegacyConfigMigration()
    {
        $xml = <<<EOF
<opnsense>
    <system>
        <webgui>
            <protocol>https</protocol>
            <port>8443</port>
            <ssl-certref>60a1b2c3d4e5f</ssl-certref>
            <ssl-ciphers>ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384</ssl-ciphers>
            <ssl-hsts>1</ssl-hsts>
            <disablehttpredirect>1</disablehttpredirect>
            <session_timeout>120</session_timeout>
            <compression>5</compression>
            <httpaccesslog>1</httpaccesslog>
            <nodnsrebindcheck>1</nodnsrebindcheck>
            <nohttpreferercheck>1</nohttpreferercheck>
            <noroot>1</noroot>
            <althostnames>alt1.example.org alt2.example.org</althostnames>
            <interfaces>lan,opt1</interfaces>
            <authmode>Local Database</authmode>
            <quietlogin>1</quietlogin>
        </webgui>
        <ssh>
            <enabled>enabled</enabled>
            <port>2222</port>
            <interfaces>lan</interfaces>
            <passwordauth>1</passwordauth>
            <permitrootlogin>1</permitrootlogin>
            <kex>curve25519-sha256</kex>
            <ciphers>chacha20-poly1305@openssh.com</ciphers>
            <macs>hmac-sha2-512-etm@openssh.com</macs>
            <keys>ssh-ed25519</keys>
            <keysig>ssh-ed25519</keysig>
            <rekeylimit>512M 1h</rekeylimit>
        </ssh>
        <usevirtualterminal>1</usevirtualterminal>
        <primaryconsole>serial</primaryconsole>
        <secondaryconsole>video</secondaryconsole>
        <serialspeed>57600</serialspeed>
        <serialusb>1</serialusb>
        <disableconsolemenu>1</disableconsolemenu>
        <autologout>30</autologout>
        <sudo_allow_wheel>2</sudo_allow_wheel>
        <sudo_allow_group>admins</sudo_allow_group>
        <user_allow_gen_token>admins</user_allow_gen_token>
        <deployment>development</deployment>
    </system>
    <syslog>
        <nologlighttpd>1</nologlighttpd>
    </syslog>
    <cert>
        <refid>60a1b2c3d4e5f</refid>
        <descr>WebGUI Certificate</descr>
    </cert>
    <interfaces>
        <lan>
            <enable>1</enable>
        </lan>
        <opt1>
            <enable>1</enable>
        </opt1>
    </interfaces>
</opnsense>
EOF;
        $this->setMockConfig($xml);

        $model = new Admin();
        $migration = new M1_0_0();
        $migration->run($model);

        // Verify WebGUI settings
        $this->assertEquals('https', (string)$model->webgui->protocol);
        $this->assertEquals('8443', (string)$model->webgui->port);
        $this->assertEquals('60a1b2c3d4e5f', (string)$model->webgui->{'ssl-certref'});
        $this->assertEquals('ECDHE-ECDSA-AES256-GCM-SHA384,ECDHE-RSA-AES256-GCM-SHA384', (string)$model->webgui->{'ssl-ciphers'});
        $this->assertEquals('1', (string)$model->webgui->{'ssl-hsts'});
        $this->assertEquals('1', (string)$model->webgui->disablehttpredirect);
        $this->assertEquals('120', (string)$model->webgui->session_timeout);
        $this->assertEquals('5', (string)$model->webgui->compression);
        $this->assertEquals('1', (string)$model->webgui->httpaccesslog);
        $this->assertEquals('1', (string)$model->webgui->nodnsrebindcheck);
        $this->assertEquals('1', (string)$model->webgui->nohttpreferercheck);
        $this->assertEquals('1', (string)$model->webgui->noroot);
        $this->assertEquals('alt1.example.org alt2.example.org', (string)$model->webgui->althostnames);
        $this->assertEquals('lan,opt1', (string)$model->webgui->interfaces);
        $this->assertEquals('Local Database', (string)$model->webgui->authmode);
        $this->assertEquals('1', (string)$model->webgui->quietlogin);

        // Verify SSH settings
        $this->assertEquals('1', (string)$model->ssh->enabled);
        $this->assertEquals('2222', (string)$model->ssh->port);
        $this->assertEquals('lan', (string)$model->ssh->interfaces);
        $this->assertEquals('1', (string)$model->ssh->passwordauth);
        $this->assertEquals('yes', (string)$model->ssh->permitrootlogin);
        $this->assertEquals('curve25519-sha256', (string)$model->ssh->kex);
        $this->assertEquals('chacha20-poly1305@openssh.com', (string)$model->ssh->ciphers);
        $this->assertEquals('hmac-sha2-512-etm@openssh.com', (string)$model->ssh->macs);
        $this->assertEquals('ssh-ed25519', (string)$model->ssh->keys);
        $this->assertEquals('ssh-ed25519', (string)$model->ssh->keysig);
        $this->assertEquals('512M 1h', (string)$model->ssh->rekeylimit);

        // Verify Console & Shell settings
        $this->assertEquals('1', (string)$model->console->usevirtualterminal);
        $this->assertEquals('serial', (string)$model->console->primaryconsole);
        $this->assertEquals('video', (string)$model->console->secondaryconsole);
        $this->assertEquals('57600', (string)$model->console->serialspeed);
        $this->assertEquals('1', (string)$model->console->serialusb);
        $this->assertEquals('1', (string)$model->console->disableconsolemenu);
        $this->assertEquals('30', (string)$model->console->autologout);
        $this->assertEquals('2', (string)$model->console->sudo_allow_wheel);
        $this->assertEquals('admins', (string)$model->console->sudo_allow_group);
        $this->assertEquals('admins', (string)$model->console->user_allow_gen_token);

        // Verify Development & Syslog settings
        $this->assertEquals('development', (string)$model->development->deployment);
        $this->assertEquals('1', (string)$model->development->nologlighttpd);

        $this->assertCount(0, $model->performValidation());
    }

    /**
     * Test minimal configuration migration falls back to defaults
     */
    public function testMinimalLegacyConfigMigrationUsesDefaults()
    {
        $this->setMockConfig('<opnsense><system/></opnsense>');

        $model = new Admin();
        $migration = new M1_0_0();
        $migration->run($model);

        $this->assertEquals('https', (string)$model->webgui->protocol);
        $this->assertEquals('115200', (string)$model->console->serialspeed);
        $this->assertEquals('video', (string)$model->console->primaryconsole);
        $this->assertEquals('no', (string)$model->ssh->permitrootlogin);
        $this->assertEquals('0', (string)$model->development->nologlighttpd);

        $this->assertCount(0, $model->performValidation());
    }

    /**
     * Test permitrootlogin without-password legacy migration
     */
    public function testLegacyPermitRootLoginWithoutPasswordMigration()
    {
        $xml = <<<EOF
<opnsense>
    <system>
        <ssh>
            <permitrootlogin>without-password</permitrootlogin>
        </ssh>
    </system>
</opnsense>
EOF;
        $this->setMockConfig($xml);

        $model = new Admin();
        $migration = new M1_0_0();
        $migration->run($model);

        $this->assertEquals('without-password', (string)$model->ssh->permitrootlogin);
        $this->assertCount(0, $model->performValidation());
    }

    /**
     * Test empty-tag legacy boolean migration (e.g. <disableconsolemenu/>, <ssl-hsts/>)
     */
    public function testEmptyTagLegacyBooleanMigration()
    {
        $xml = <<<EOF
<opnsense>
    <system>
        <webgui>
            <ssl-hsts/>
            <disablehttpredirect/>
            <httpaccesslog/>
            <nodnsrebindcheck/>
            <nohttpreferercheck/>
            <noroot/>
            <quietlogin/>
        </webgui>
        <ssh>
            <enabled/>
            <passwordauth/>
            <permitrootlogin/>
            <noauto/>
        </ssh>
        <disableconsolemenu/>
        <usevirtualterminal/>
        <serialusb/>
    </system>
    <syslog>
        <nologlighttpd/>
    </syslog>
</opnsense>
EOF;
        $this->setMockConfig($xml);

        $model = new Admin();
        $migration = new M1_0_0();
        $migration->run($model);

        $this->assertEquals('1', (string)$model->webgui->{'ssl-hsts'});
        $this->assertEquals('1', (string)$model->webgui->disablehttpredirect);
        $this->assertEquals('1', (string)$model->webgui->httpaccesslog);
        $this->assertEquals('1', (string)$model->webgui->nodnsrebindcheck);
        $this->assertEquals('1', (string)$model->webgui->nohttpreferercheck);
        $this->assertEquals('1', (string)$model->webgui->noroot);
        $this->assertEquals('1', (string)$model->webgui->quietlogin);

        $this->assertEquals('1', (string)$model->ssh->enabled);
        $this->assertEquals('1', (string)$model->ssh->passwordauth);
        $this->assertEquals('yes', (string)$model->ssh->permitrootlogin);
        $this->assertEquals('1', (string)$model->ssh->noauto);

        $this->assertEquals('1', (string)$model->console->disableconsolemenu);
        $this->assertEquals('1', (string)$model->console->usevirtualterminal);
        $this->assertEquals('1', (string)$model->console->serialusb);

        $this->assertEquals('1', (string)$model->development->nologlighttpd);

        // Verify that synchronizing preserves security settings in legacy XML
        $model->syncToLegacyConfig();
        $cfg = Config::getInstance()->object();
        $this->assertTrue(isset($cfg->system->disableconsolemenu));
        $this->assertTrue(isset($cfg->system->webgui->{'ssl-hsts'}));
        $this->assertTrue(isset($cfg->system->webgui->disablehttpredirect));
        $this->assertTrue(isset($cfg->system->ssh->enabled));
    }
}
