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
use OPNsense\Core\Misc;
use OPNsense\Core\Migrations\M1_0_0;

class MiscTest extends \PHPUnit\Framework\TestCase
{
    private static $testConfigDir = null;
    private static $savedConfigDir = null;

    public static function setUpBeforeClass(): void
    {
        self::$savedConfigDir = (new AppConfig())->application->configDir;
        self::$testConfigDir = sys_get_temp_dir() . '/misc_unit_test';
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
        $this->setMockConfig('<opnsense><system/></opnsense>');
    }

    public function testCanBeCreated()
    {
        $model = new Misc();
        $this->assertInstanceOf(Misc::class, $model);
        $this->assertContains($model->getVersion(), ['0.0.0', '1.0.0']);
    }

    public function testDefaultValues()
    {
        $model = new Misc();
        $this->assertEquals('0', (string)$model->powerd_enable);
        $this->assertEquals('hadp', (string)$model->powerd_ac_mode);
        $this->assertEquals('hadp', (string)$model->powerd_battery_mode);
        $this->assertEquals('hadp', (string)$model->powerd_normal_mode);
        $this->assertEquals('', (string)$model->crypto_hardware);
        $this->assertEquals('', (string)$model->thermal_hardware);
        $this->assertEquals('0', (string)$model->use_mfs_var);
        $this->assertEquals('', (string)$model->max_mfs_var);
        $this->assertEquals('0', (string)$model->use_mfs_tmp);
        $this->assertEquals('', (string)$model->max_mfs_tmp);
        $this->assertEquals('0', (string)$model->use_swap_file);
        $this->assertEquals('0', (string)$model->disablebeep);

        $this->assertCount(0, $model->performValidation());
    }

    public function testValidationBounds()
    {
        $model = new Misc();
        $model->max_mfs_var = '150'; // out of bounds
        $messages = $model->performValidation();
        $this->assertGreaterThan(0, count($messages));

        $model->max_mfs_var = '50';
        $messages = $model->performValidation();
        $this->assertCount(0, $messages);

        $model->max_mfs_tmp = '-5'; // negative
        $messages = $model->performValidation();
        $this->assertGreaterThan(0, count($messages));

        $model->max_mfs_tmp = '75';
        $messages = $model->performValidation();
        $this->assertCount(0, $messages);
    }

    public function testLegacyMigrationAndSync()
    {
        $xml = <<<EOF
<opnsense>
    <system>
        <powerd_enable>1</powerd_enable>
        <powerd_ac_mode>max</powerd_ac_mode>
        <powerd_battery_mode>min</powerd_battery_mode>
        <powerd_normal_mode>adp</powerd_normal_mode>
        <crypto_hardware>qat</crypto_hardware>
        <thermal_hardware>coretemp</thermal_hardware>
        <use_mfs_var>1</use_mfs_var>
        <max_mfs_var>60</max_mfs_var>
        <use_mfs_tmp>1</use_mfs_tmp>
        <max_mfs_tmp>40</max_mfs_tmp>
        <use_swap_file>2048</use_swap_file>
        <disablebeep>1</disablebeep>
    </system>
</opnsense>
EOF;
        $this->setMockConfig($xml);

        $model = new Misc();
        $migration = new M1_0_0();
        $migration->run($model);

        $this->assertEquals('1', (string)$model->powerd_enable);
        $this->assertEquals('max', (string)$model->powerd_ac_mode);
        $this->assertEquals('min', (string)$model->powerd_battery_mode);
        $this->assertEquals('adp', (string)$model->powerd_normal_mode);
        $this->assertEquals('qat', (string)$model->crypto_hardware);
        $this->assertEquals('coretemp', (string)$model->thermal_hardware);
        $this->assertEquals('1', (string)$model->use_mfs_var);
        $this->assertEquals('60', (string)$model->max_mfs_var);
        $this->assertEquals('1', (string)$model->use_mfs_tmp);
        $this->assertEquals('40', (string)$model->max_mfs_tmp);
        $this->assertEquals('1', (string)$model->use_swap_file);
        $this->assertEquals('1', (string)$model->disablebeep);

        $this->assertCount(0, $model->performValidation());

        // Test syncToLegacyConfig
        $model->syncToLegacyConfig();
        $config = Config::getInstance()->object();
        $this->assertEquals('true', (string)$config->system->powerd_enable);
        $this->assertEquals('max', (string)$config->system->powerd_ac_mode);
        $this->assertEquals('qat', (string)$config->system->crypto_hardware);
        $this->assertEquals('coretemp', (string)$config->system->thermal_hardware);
        $this->assertEquals('true', (string)$config->system->use_mfs_var);
        $this->assertEquals('60', (string)$config->system->max_mfs_var);
        $this->assertEquals('2048', (string)$config->system->use_swap_file);
        $this->assertEquals('true', (string)$config->system->disablebeep);
    }

    public function testEmptyTagLegacyBooleanMigration()
    {
        $xml = <<<EOF
<opnsense>
    <system>
        <powerd_enable/>
        <use_mfs_var/>
        <use_mfs_tmp/>
        <use_swap_file/>
        <disablebeep/>
    </system>
</opnsense>
EOF;
        $this->setMockConfig($xml);

        $model = new Misc();
        $migration = new M1_0_0();
        $migration->run($model);

        $this->assertEquals('1', (string)$model->powerd_enable);
        $this->assertEquals('1', (string)$model->use_mfs_var);
        $this->assertEquals('1', (string)$model->use_mfs_tmp);
        $this->assertEquals('1', (string)$model->use_swap_file);
        $this->assertEquals('1', (string)$model->disablebeep);

        $model->syncToLegacyConfig();
        $config = Config::getInstance()->object();
        $this->assertTrue(isset($config->system->powerd_enable));
        $this->assertTrue(isset($config->system->use_mfs_var));
        $this->assertTrue(isset($config->system->use_mfs_tmp));
        $this->assertTrue(isset($config->system->use_swap_file));
        $this->assertTrue(isset($config->system->disablebeep));
    }
}
