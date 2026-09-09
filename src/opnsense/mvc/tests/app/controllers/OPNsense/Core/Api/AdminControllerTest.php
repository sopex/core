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

namespace tests\OPNsense\Core\Api;

use OPNsense\Core\Api\AdminController;
use OPNsense\Core\AppConfig;
use OPNsense\Core\Config;
use OPNsense\Core\Admin;
use OPNsense\Mvc\Request;
use OPNsense\Mvc\Response;
use OPNsense\Mvc\Session;
use Phalcon\Di\FactoryDefault;

class AdminControllerTest extends \PHPUnit\Framework\TestCase
{
    private $request;
    private $response;
    private static $testConfigDir = null;
    private static $savedConfigDir = null;

    public static function setUpBeforeClass(): void
    {
        self::$savedConfigDir = (new AppConfig())->application->configDir;
        self::$testConfigDir = sys_get_temp_dir() . '/admin_controller_unit_test';
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
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_POST = [];
        $_FILES = [];

        $xml = '<opnsense><system><user><name>root</name><priv>page-all</priv></user><webgui><protocol>https</protocol><port>443</port></webgui></system></opnsense>';
        file_put_contents(self::$testConfigDir . '/config.xml', $xml);

        $di = new FactoryDefault();
        \Phalcon\Di\Di::setDefault($di);
        $this->request = new Request();
        $di->setShared('request', $this->request);
        $this->response = new Response();
        $di->setShared('response', $this->response);
        (new AppConfig())->update('application.configDir', self::$testConfigDir);
        (new AppConfig())->update('globals.simulate_mode', true);
        Config::getInstance()->forceReload();

        if (file_exists('/tmp/.admin_webgui_restart.json')) {
            @unlink('/tmp/.admin_webgui_restart.json');
        }
    }

    protected function tearDown(): void
    {
        $_POST = [];
        if (file_exists('/tmp/.admin_webgui_restart.json')) {
            @unlink('/tmp/.admin_webgui_restart.json');
        }
    }

    private function getController(): AdminController
    {
        $controller = new AdminController();
        $controller->request = $this->request;
        $controller->response = $this->response;
        $session = $this->createMock(Session::class);
        $session->method('has')->with('Username')->willReturn(true);
        $session->method('get')->with('Username')->willReturn('root');
        $controller->session = $session;
        $ref = new \ReflectionProperty(\OPNsense\Base\ControllerRoot::class, 'logged_in_user');
        $ref->setAccessible(true);
        $ref->setValue($controller, 'root');
        $controller->initialize();
        return $controller;
    }

    /**
     * Test getAction returns expected model structure and dynamic dictionaries
     */
    public function testGetActionStructure()
    {
        $controller = $this->getController();
        $result = $controller->getAction();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('admin', $result);
        $this->assertArrayHasKey('webgui', $result['admin']);
        $this->assertArrayHasKey('ssh', $result['admin']);
        $this->assertArrayHasKey('console', $result['admin']);
        $this->assertArrayHasKey('development', $result['admin']);

        $this->assertArrayHasKey('certificates', $result);
        $this->assertArrayHasKey('interfaces', $result);
        $this->assertArrayHasKey('authservers', $result);
        $this->assertArrayHasKey('ciphers', $result);
        $this->assertArrayHasKey('sshoptions', $result);
        $this->assertArrayHasKey('groups', $result);
        $this->assertArrayHasKey('consoles', $result);
    }

    /**
     * Test setAction saves configuration successfully
     */
    public function testSetActionSuccess()
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['admin'] = [
            'webgui' => [
                'protocol' => 'https',
                'port' => '8443',
                'session_timeout' => '60'
            ],
            'ssh' => [
                'enabled' => '1',
                'port' => '2222',
                'permitrootlogin' => 'yes'
            ]
        ];

        $controller = $this->getController();
        $result = $controller->setAction();

        $this->assertIsArray($result);
        $this->assertEquals('saved', $result['result']);
    }

    /**
     * Test sequential saves: port change sets restart marker; subsequent SSH save does NOT delete it
     */
    public function testSubsequentSavePreservesWebguiRestartMarker()
    {
        $markerFile = '/tmp/.admin_webgui_restart.json';

        // 1. First save: change WebGUI port -> restart marker must be created
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['admin'] = [
            'webgui' => [
                'protocol' => 'https',
                'port' => '9443'
            ]
        ];

        $controller1 = $this->getController();
        $result1 = $controller1->setAction();
        $this->assertEquals('saved', $result1['result']);
        $this->assertFileExists($markerFile, 'WebGUI restart marker should be created after port change');

        // 2. Second save: change only SSH setting -> restart marker must STILL exist
        $_POST = [];
        $_POST['admin'] = [
            'ssh' => [
                'enabled' => '1',
                'port' => '2222',
                'permitrootlogin' => 'yes'
            ]
        ];

        $controller2 = $this->getController();
        $result2 = $controller2->setAction();
        $this->assertEquals('saved', $result2['result']);
        $this->assertFileExists($markerFile, 'WebGUI restart marker must NOT be removed by subsequent non-webgui save');

        // 3. Reconfigure consumes the restart marker
        $resultReconfig = $controller2->reconfigureAction();
        $this->assertEquals('ok', $resultReconfig['status']);
        $this->assertFileDoesNotExist($markerFile, 'Reconfigure must consume and unlink restart marker');
    }

    /**
     * Test validation failure on invalid webgui port
     */
    public function testValidationFailureInvalidPort()
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['admin'] = [
            'webgui' => [
                'port' => '70000'
            ]
        ];

        $controller = $this->getController();
        $result = $controller->setAction();

        $this->assertEquals('failed', $result['result']);
        $this->assertArrayHasKey('validations', $result);
        $this->assertArrayHasKey('admin.webgui.port', $result['validations']);
    }
}
