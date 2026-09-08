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

class AdminTest extends \PHPUnit\Framework\TestCase
{
    private static $testConfigDir = null;
    private static $savedConfigDir = null;

    public static function setUpBeforeClass(): void
    {
        self::$savedConfigDir = (new AppConfig())->application->configDir;
        self::$testConfigDir = sys_get_temp_dir() . '/admin_model_unit_test';
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

    protected function setUp(): void
    {
        file_put_contents(self::$testConfigDir . '/config.xml', '<opnsense><system/></opnsense>');
        (new AppConfig())->update('application.configDir', self::$testConfigDir);
        (new AppConfig())->update('globals.simulate_mode', true);
        Config::getInstance()->forceReload();
    }

    /**
     * Test Admin model can be instantiated and has correct version
     */
    public function testCanBeCreated()
    {
        $model = new Admin();
        $this->assertInstanceOf(Admin::class, $model);
        $this->assertContains($model->getVersion(), ['0.0.0', '1.0.0']);
    }

    /**
     * Test Admin model default values
     */
    public function testDefaultValues()
    {
        $model = new Admin();

        $this->assertEquals('https', (string)$model->protocol);
        $this->assertEquals('', (string)$model->port);
        $this->assertEquals('', (string)$model->session_timeout);
        $this->assertEquals('', (string)$model->compression);
        $this->assertEquals('0', (string)$model->ssh_enabled);
        $this->assertEquals('', (string)$model->ssh_port);
        $this->assertEquals('0', (string)$model->passwordauth);
        $this->assertEquals('no', (string)$model->permitrootlogin);
        $this->assertEquals('video', (string)$model->primaryconsole);
        $this->assertEquals('', (string)$model->secondaryconsole);
        $this->assertEquals('115200', (string)$model->serialspeed);
        $this->assertEquals('1', (string)$model->usevirtualterminal);
        $this->assertEquals('0', (string)$model->disableconsolemenu);
        $this->assertEquals('', (string)$model->sudo_allow_wheel);
        $this->assertEquals('', (string)$model->deployment);
        $this->assertEquals('1', (string)$model->loglighttpd);

        $messages = $model->performValidation();
        $this->assertCount(0, $messages);
    }

    /**
     * Test valid WebGUI port numbers
     */
    public function testWebGuiPortValidRanges()
    {
        $validPorts = ['1', '80', '443', '8080', '65535'];
        foreach ($validPorts as $port) {
            $model = new Admin();
            $model->port = $port;
            $messages = $model->performValidation();
            $this->assertCount(0, $messages, "Port {$port} should be valid");
        }
    }

    /**
     * Test invalid WebGUI port numbers
     */
    public function testWebGuiPortInvalid()
    {
        $invalidPorts = ['0', '-1', '65536', '70000', 'abc', '80.5'];
        foreach ($invalidPorts as $port) {
            $model = new Admin();
            $model->port = $port;
            $messages = $model->performValidation();
            $this->assertGreaterThan(0, count($messages), "Port {$port} should be invalid");
        }
    }

    /**
     * Test WebGUI protocol validation
     */
    public function testWebGuiProtocolValidation()
    {
        $model = new Admin();
        $model->protocol = 'http';
        $this->assertCount(0, $model->performValidation());

        $model->protocol = 'https';
        $this->assertCount(0, $model->performValidation());

        $invalidProtos = ['', 'ftp', 'ssh', 'ws'];
        foreach ($invalidProtos as $proto) {
            $m = new Admin();
            $m->protocol = $proto;
            $this->assertGreaterThan(0, count($m->performValidation()), "Protocol {$proto} should be invalid");
        }
    }

    /**
     * Test session timeout validation
     */
    public function testSessionTimeoutValidation()
    {
        $validTimeouts = ['', '1', '60', '240'];
        foreach ($validTimeouts as $timeout) {
            $model = new Admin();
            $model->session_timeout = $timeout;
            $this->assertCount(0, $model->performValidation(), "Timeout {$timeout} should be valid");
        }

        $invalidTimeouts = ['0', '-1', '1.5', 'fast'];
        foreach ($invalidTimeouts as $timeout) {
            $model = new Admin();
            $model->session_timeout = $timeout;
            $this->assertGreaterThan(0, count($model->performValidation()), "Timeout {$timeout} should be invalid");
        }
    }

    /**
     * Test alternate hostnames validation
     */
    public function testAlternateHostnamesValidation()
    {
        $validHostnames = ['', 'opnsense.local', 'fw1.internal backup.internal', 'router.domain.com'];
        foreach ($validHostnames as $hosts) {
            $model = new Admin();
            $model->althostnames = $hosts;
            $this->assertCount(0, $model->performValidation(), "Hostnames {$hosts} should be valid");
        }

        $invalidHostnames = ['host_with_underscore.local', 'invalid/name'];
        foreach ($invalidHostnames as $hosts) {
            $model = new Admin();
            $model->althostnames = $hosts;
            $this->assertGreaterThan(0, count($model->performValidation()), "Hostnames {$hosts} should be invalid");
        }
    }

    /**
     * Test OpenSSH port validation
     */
    public function testOpenSshPortValidation()
    {
        $validPorts = ['', '22', '2222', '65535'];
        foreach ($validPorts as $port) {
            $model = new Admin();
            $model->ssh_port = $port;
            $this->assertCount(0, $model->performValidation(), "SSH port {$port} should be valid");
        }

        $invalidPorts = ['0', '70000', '-22', 'ssh', '22.5'];
        foreach ($invalidPorts as $port) {
            $model = new Admin();
            $model->ssh_port = $port;
            $this->assertGreaterThan(0, count($model->performValidation()), "SSH port {$port} should be invalid");
        }
    }

    /**
     * Test PermitRootLogin options
     */
    public function testPermitRootLoginOptions()
    {
        $validOptions = ['yes', 'no', 'without-password'];
        foreach ($validOptions as $opt) {
            $model = new Admin();
            $model->permitrootlogin = $opt;
            $this->assertCount(0, $model->performValidation(), "PermitRootLogin {$opt} should be valid");
        }

        $invalidOptions = ['invalid', '2', 'true'];
        foreach ($invalidOptions as $opt) {
            $model = new Admin();
            $model->ssh->permitrootlogin = $opt;
            $this->assertGreaterThan(0, count($model->performValidation()), "PermitRootLogin {$opt} should be invalid");
        }
    }

    /**
     * Test PasswordAuth validation
     */
    public function testPasswordAuthValidation()
    {
        $model = new Admin();
        $model->passwordauth = '0';
        $this->assertCount(0, $model->performValidation());

        $model->passwordauth = '1';
        $this->assertCount(0, $model->performValidation());
    }

    /**
     * Test Serial speed baud rates
     */
    public function testSerialSpeedBaudRates()
    {
        $validSpeeds = ['1500000', '115200', '57600', '38400', '19200', '14400', '9600'];
        foreach ($validSpeeds as $speed) {
            $model = new Admin();
            $model->serialspeed = $speed;
            $this->assertCount(0, $model->performValidation(), "Serial speed {$speed} should be valid");
        }

        $invalidSpeeds = ['1200', '99999', 'fast'];
        foreach ($invalidSpeeds as $speed) {
            $model = new Admin();
            $model->serialspeed = $speed;
            $this->assertGreaterThan(0, count($model->performValidation()), "Serial speed {$speed} should be invalid");
        }
    }

    /**
     * Test Console devices validation
     */
    public function testConsoleDevicesValidation()
    {
        $validDevices = ['video', 'serial', 'efi', 'null'];
        foreach ($validDevices as $dev) {
            $model = new Admin();
            $model->primaryconsole = $dev;
            $this->assertCount(0, $model->performValidation(), "Primary console {$dev} should be valid");
        }

        $validSecondary = ['', 'video', 'serial', 'efi', 'null'];
        foreach ($validSecondary as $dev) {
            $model = new Admin();
            $model->secondaryconsole = $dev;
            $this->assertCount(0, $model->performValidation(), "Secondary console {$dev} should be valid");
        }

        $model = new Admin();
        $model->console->primaryconsole = 'unsupported_device';
        $this->assertGreaterThan(0, count($model->performValidation()));
    }

    /**
     * Test HTTP compression options
     */
    public function testCompressionValues()
    {
        $validCompressions = ['', '1', '5', '9'];
        foreach ($validCompressions as $val) {
            $model = new Admin();
            $model->compression = $val;
            $this->assertCount(0, $model->performValidation(), "Compression {$val} should be valid");
        }

        $invalidCompressions = ['2', '7', 'invalid'];
        foreach ($invalidCompressions as $val) {
            $model = new Admin();
            $model->compression = $val;
            $this->assertGreaterThan(0, count($model->performValidation()), "Compression {$val} should be invalid");
        }
    }

    /**
     * Test Sudo allow wheel options
     */
    public function testSudoAllowWheelOptions()
    {
        $validWheel = ['', '1', '2'];
        foreach ($validWheel as $opt) {
            $model = new Admin();
            $model->sudo_allow_wheel = $opt;
            $this->assertCount(0, $model->performValidation(), "Wheel option {$opt} should be valid");
        }

        $invalidWheel = ['3', 'wheel', 'all'];
        foreach ($invalidWheel as $opt) {
            $model = new Admin();
            $model->sudo_allow_wheel = $opt;
            $this->assertGreaterThan(0, count($model->performValidation()), "Wheel option {$opt} should be invalid");
        }
    }

    /**
     * Test OpenSSH RekeyLimit predefined choices
     */
    public function testRekeyLimitPredefinedChoices()
    {
        $validRekey = ['', 'default 60s', 'default 600s', '512M 60s', '512M 600s', '512M 1h', '1G 60s', '1G 1h'];
        foreach ($validRekey as $opt) {
            $model = new Admin();
            $model->rekeylimit = $opt;
            $this->assertCount(0, $model->performValidation(), "Rekey limit {$opt} should be valid");
        }

        $model = new Admin();
        $model->rekeylimit = 'arbitrary_limit';
        $this->assertGreaterThan(0, count($model->performValidation()));
    }

    /**
     * Test syncToLegacyConfig synchronizes model into legacy $config['system'] nodes
     */
    public function testSyncToLegacyConfig()
    {
        $model = new Admin();
        $model->protocol = 'https';
        $model->port = '8443';
        $model->session_timeout = '120';
        $model->ssh_enabled = '1';
        $model->ssh_port = '2222';
        $model->permitrootlogin = 'without-password';
        $model->passwordauth = '1';
        $model->serialspeed = '57600';
        $model->primaryconsole = 'serial';
        $model->deployment = 'development';
        $model->loglighttpd = '0';

        $model->syncToLegacyConfig();

        $config = Config::getInstance()->object();

        $this->assertEquals('https', (string)$config->system->webgui->protocol);
        $this->assertEquals('8443', (string)$config->system->webgui->port);
        $this->assertEquals('120', (string)$config->system->webgui->session_timeout);
        $this->assertEquals('enabled', (string)$config->system->ssh->enabled);
        $this->assertEquals('2222', (string)$config->system->ssh->port);
        $this->assertEquals('without-password', (string)$config->system->ssh->permitrootlogin);
        $this->assertEquals('true', (string)$config->system->ssh->passwordauth);
        $this->assertEquals('57600', (string)$config->system->serialspeed);
        $this->assertEquals('serial', (string)$config->system->primaryconsole);
        $this->assertEquals('development', (string)$config->system->deployment);
        $this->assertEquals('true', (string)$config->syslog->nologlighttpd);
    }
}
