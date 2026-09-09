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

use OPNsense\Core\Api\GeneralController;
use OPNsense\Core\AppConfig;
use OPNsense\Core\Config;
use OPNsense\Core\General;
use OPNsense\Mvc\Request;
use OPNsense\Mvc\Response;
use OPNsense\Mvc\Session;
use Phalcon\Di\FactoryDefault;
use Phalcon\Mvc\Dispatcher;

class GeneralControllerTest extends \PHPUnit\Framework\TestCase
{
    private $request;
    private $response;
    private static $testConfigDir = null;
    private static $savedConfigDir = null;

    public static function setUpBeforeClass(): void
    {
        self::$savedConfigDir = (new AppConfig())->application->configDir;
        self::$testConfigDir = sys_get_temp_dir() . '/general_controller_unit_test';
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

        file_put_contents(self::$testConfigDir . '/config.xml', '<opnsense><system/></opnsense>');

        $di = new FactoryDefault();
        \Phalcon\Di\Di::setDefault($di);
        $this->request = new Request();
        $di->setShared('request', $this->request);
        $this->response = new Response();
        $di->setShared('response', $this->response);
        (new AppConfig())->update('application.configDir', self::$testConfigDir);
        (new AppConfig())->update('globals.simulate_mode', true);
        Config::getInstance()->forceReload();
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_FILES = [];
        if (file_exists('/tmp/.general_staleroutes.json')) {
            @unlink('/tmp/.general_staleroutes.json');
        }
    }

    private function getController(): GeneralController
    {
        $controller = new GeneralController();
        $controller->request = $this->request;
        $controller->response = $this->response;
        $controller->session = $this->createMock(Session::class);
        $controller->initialize();
        return $controller;
    }

    private function getModelFromController(GeneralController $controller): General
    {
        $ref = new \ReflectionMethod(GeneralController::class, 'getModel');
        $ref->setAccessible(true);
        return $ref->invoke($controller);
    }

    // =========================================================================
    // 1. getAction() Tests
    // =========================================================================

    /**
     * Test getAction returns general settings, flat dns keys, and gateways dictionary
     */
    public function testGetActionStructure()
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $controller = $this->getController();

        $result = $controller->getAction();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('general', $result);
        $this->assertArrayHasKey('gateways', $result);
        $this->assertArrayHasKey('none', $result['gateways']);

        // Check flat DNS keys exist
        for ($i = 1; $i <= 8; $i++) {
            $this->assertArrayHasKey("dns{$i}", $result['general']);
            $this->assertArrayHasKey("dns{$i}gw", $result['general']);
        }
    }

    /**
     * Test that dns1gw..dns8gw are formatted as option dictionaries compatible with
     * setFormData() in opnsense.js
     */
    public function testGetActionGatewayDropdownOptionsFormat()
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $controller = $this->getController();

        $result = $controller->getAction();

        // dns1gw must be an options dictionary with 'none' selected
        $this->assertIsArray($result['general']['dns1gw']);
        $this->assertArrayHasKey('none', $result['general']['dns1gw']);
        $this->assertArrayHasKey('value', $result['general']['dns1gw']['none']);
        $this->assertArrayHasKey('selected', $result['general']['dns1gw']['none']);
        $this->assertEquals(1, $result['general']['dns1gw']['none']['selected']);

        // Verify all 8 dns gateway fields are dictionaries
        for ($i = 1; $i <= 8; $i++) {
            $this->assertIsArray($result['general']["dns{$i}gw"]);
            $this->assertArrayHasKey('none', $result['general']["dns{$i}gw"]);
        }
    }

    /**
     * Test getAction populates existing DNS servers and selected gateway in options
     */
    public function testGetActionPopulatedDnsServers()
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $controller = $this->getController();
        $model = $this->getModelFromController($controller);

        $e1 = $model->dnsservers->Add();
        $e1->server = '8.8.8.8';
        $e1->gateway = 'none';

        $result = $controller->getAction();

        $this->assertEquals('8.8.8.8', $result['general']['dns1']);
        $this->assertIsArray($result['general']['dns1gw']);
        $this->assertEquals(1, $result['general']['dns1gw']['none']['selected']);
        $this->assertEquals('', $result['general']['dns2']);
    }

    /**
     * Test getAction returns empty array on non-GET request
     */
    public function testGetActionNonGetRequest()
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $controller = $this->getController();

        $result = $controller->getAction();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    // =========================================================================
    // 2. setAction() Tests
    // =========================================================================

    /**
     * Test setAction saves valid general settings with flat DNS fields
     */
    public function testSetActionSuccessWithFlatDnsFields()
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['general'] = [
            'hostname' => 'fw-test-01',
            'domain' => 'test.lan',
            'timezone' => 'Etc/UTC',
            'language' => 'en_US',
            'theme' => 'opnsense',
            'dns1' => '8.8.8.8',
            'dns1gw' => 'none',
            'dns2' => '1.1.1.1',
            'dns2gw' => 'none'
        ];

        $controller = $this->getController();
        $result = $controller->setAction();

        $this->assertIsArray($result);
        $this->assertEquals('saved', $result['result']);

        $model = $this->getModelFromController($controller);
        $this->assertEquals('fw-test-01', (string)$model->hostname);
        $this->assertEquals('test.lan', (string)$model->domain);

        $items = iterator_to_array($model->dnsservers->iterateItems());
        $this->assertCount(2, $items);
        $this->assertEquals('8.8.8.8', (string)reset($items)->server);
    }

    /**
     * Test setAction saves with direct dnsservers array (API style)
     */
    public function testSetActionSuccessWithDirectDnsServersArray()
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['general'] = [
            'hostname' => 'fw-api-01',
            'domain' => 'example.org',
            'timezone' => 'Europe/Amsterdam',
            'dnsservers' => [
                ['server' => '9.9.9.9', 'gateway' => 'none'],
                ['server' => '149.112.112.112', 'gateway' => 'none'],
            ]
        ];

        $controller = $this->getController();
        $result = $controller->setAction();

        $this->assertEquals('saved', $result['result']);

        $model = $this->getModelFromController($controller);
        $this->assertEquals('fw-api-01', (string)$model->hostname);
        $items = iterator_to_array($model->dnsservers->iterateItems());
        $this->assertCount(2, $items);
    }

    /**
     * Test partial General update (e.g. only hostname) does not erase existing DNS servers
     */
    public function testPartialSaveDoesNotEraseDnsServers()
    {
        // 1. Initial save with DNS servers
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['general'] = [
            'hostname' => 'fw-initial',
            'dnsservers' => [
                ['server' => '8.8.8.8', 'gateway' => 'none'],
                ['server' => '1.1.1.1', 'gateway' => 'none'],
            ]
        ];

        $controller = $this->getController();
        $result = $controller->setAction();
        $this->assertEquals('saved', $result['result']);

        $model = $this->getModelFromController($controller);
        $this->assertCount(2, iterator_to_array($model->dnsservers->iterateItems()));

        // 2. Partial update with only hostname
        $_POST = [];
        $_POST['general'] = [
            'hostname' => 'fw-new-name'
        ];

        $controller2 = $this->getController();
        $result2 = $controller2->setAction();
        $this->assertEquals('saved', $result2['result']);

        $model2 = $this->getModelFromController($controller2);
        $this->assertEquals('fw-new-name', (string)$model2->hostname);
        $items = array_values(iterator_to_array($model2->dnsservers->iterateItems()));
        $this->assertCount(2, $items, 'Existing DNS servers must not be erased on partial save');
        $this->assertEquals('8.8.8.8', (string)$items[0]->server);
        $this->assertEquals('1.1.1.1', (string)$items[1]->server);
    }

    /**
     * Test setAction returns validation errors on invalid input
     */
    public function testSetActionValidationFailure()
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['general'] = [
            'hostname' => 'invalid_hostname_with_underscore',
            'domain' => 'test.lan',
        ];

        $controller = $this->getController();
        $result = $controller->setAction();

        $this->assertIsArray($result);
        $this->assertEquals('failed', $result['result']);
        $this->assertArrayHasKey('validations', $result);
        $this->assertArrayHasKey('general.hostname', $result['validations']);
    }

    /**
     * Test setAction deletes picture when del_picture signal is passed
     */
    public function testSetActionDeletePictureSignal()
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $controller = $this->getController();
        $model = $this->getModelFromController($controller);
        $model->picture = base64_encode('IMAGE_DATA');
        $model->picture_filename = 'logo.png';

        $_POST['general'] = [
            'hostname' => 'fw-test-delpic',
            'del_picture' => 'true'
        ];

        $result = $controller->setAction();

        $this->assertEquals('saved', $result['result']);
        $this->assertEquals('', (string)$model->picture);
        $this->assertEquals('', (string)$model->picture_filename);
    }

    /**
     * Test setAction returns failed on non-POST request
     */
    public function testSetActionNonPostRequest()
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $controller = $this->getController();

        $result = $controller->setAction();

        $this->assertEquals('failed', $result['result']);
    }

    // =========================================================================
    // 3. pictureAction() Tests
    // =========================================================================

    /**
     * Test pictureAction prepares inline image response and afterExecuteRoute sends cleanly
     */
    public function testPictureActionWithValidImage()
    {
        $controller = $this->getController();
        $model = $this->getModelFromController($controller);
        $imageData = 'RAW_PNG_BYTES_12345';
        $model->picture = base64_encode($imageData);
        $model->picture_filename = 'dashboard.png';

        $controller->pictureAction();

        $this->assertEquals('image/png', $controller->response->getHeaders()->get('Content-Type'));
        $this->assertEquals('inline; filename="dashboard.png"', $controller->response->getHeaders()->get('Content-Disposition'));
        $this->assertEquals($imageData, $controller->response->getContent());
        $this->assertFalse($controller->response->isSent(), 'Response must not be marked sent before dispatcher handles it');

        // Verify dispatcher afterExecuteRoute sends without exception
        $dispatcher = new Dispatcher();
        ob_start();
        $controller->afterExecuteRoute($dispatcher);
        $output = ob_get_clean();

        $this->assertTrue($controller->response->isSent());
        $this->assertEquals($imageData, $output);
    }

    /**
     * Test pictureAction handles various image mime types
     */
    public function testPictureActionMimeTypes()
    {
        $types = [
            'photo.jpg' => 'image/jpeg',
            'vector.svg' => 'image/svg+xml',
            'modern.webp' => 'image/webp',
            'icon.gif' => 'image/gif',
        ];

        foreach ($types as $filename => $expectedMime) {
            $this->setUp(); // reset response
            $controller = $this->getController();
            $model = $this->getModelFromController($controller);
            $model->picture = base64_encode('TEST_DATA');
            $model->picture_filename = $filename;

            $controller->pictureAction();

            $this->assertEquals($expectedMime, $controller->response->getHeaders()->get('Content-Type'));
        }
    }

    /**
     * Test pictureAction returns 404 when no picture is set
     */
    public function testPictureActionNotFoundWhenNoImage()
    {
        $controller = $this->getController();
        $model = $this->getModelFromController($controller);
        $model->picture = '';
        $model->picture_filename = '';

        $controller->pictureAction();

        $this->assertEquals(404, $controller->response->getStatusCode());

        $dispatcher = new Dispatcher();
        ob_start();
        $controller->afterExecuteRoute($dispatcher);
        ob_get_clean();

        $this->assertTrue($controller->response->isSent());
    }

    // =========================================================================
    // 4. uploadPictureAction() Tests
    // =========================================================================

    /**
     * Test uploadPictureAction processes uploaded file and saves picture to model
     */
    public function testUploadPictureActionSuccess()
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $tempFile = tempnam(sys_get_temp_dir(), 'test_upload_');
        $imageContent = 'FAKE_IMAGE_BINARY_CONTENT';
        file_put_contents($tempFile, $imageContent);

        $_FILES['picture'] = [
            'name' => 'custom_logo.png',
            'type' => 'image/png',
            'tmp_name' => $tempFile,
            'error' => UPLOAD_ERR_OK,
            'size' => strlen($imageContent)
        ];

        $controller = $this->getController();
        $result = $controller->uploadPictureAction();

        @unlink($tempFile);

        $this->assertIsArray($result);
        $this->assertEquals('saved', $result['result']);
        $this->assertEquals('custom_logo.png', $result['filename']);

        $model = $this->getModelFromController($controller);
        $this->assertEquals(base64_encode($imageContent), (string)$model->picture);
        $this->assertEquals('custom_logo.png', (string)$model->picture_filename);
    }

    /**
     * Test uploadPictureAction rejects files larger than 10MB
     */
    public function testUploadPictureActionFileTooLarge()
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $tempFile = tempnam(sys_get_temp_dir(), 'test_large_');

        $_FILES['picture'] = [
            'name' => 'huge_file.png',
            'type' => 'image/png',
            'tmp_name' => $tempFile,
            'error' => UPLOAD_ERR_OK,
            'size' => 11 * 1024 * 1024 // 11MB
        ];

        $controller = $this->getController();
        $result = $controller->uploadPictureAction();

        @unlink($tempFile);

        $this->assertEquals('failed', $result['result']);
        $this->assertStringContainsString('too large', $result['message']);
    }

    /**
     * Test uploadPictureAction fails when no file is uploaded
     */
    public function testUploadPictureActionNoFiles()
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_FILES = [];

        $controller = $this->getController();
        $result = $controller->uploadPictureAction();

        $this->assertEquals('failed', $result['result']);
    }

    /**
     * Test uploadPictureAction fails on non-POST request
     */
    public function testUploadPictureActionNonPost()
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $controller = $this->getController();

        $result = $controller->uploadPictureAction();

        $this->assertEquals('failed', $result['result']);
    }

    // =========================================================================
    // 5. delPictureAction() Tests
    // =========================================================================

    /**
     * Test delPictureAction clears picture in model and config
     */
    public function testDelPictureAction()
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $controller = $this->getController();

        $model = $this->getModelFromController($controller);
        $model->picture = base64_encode('DATA');
        $model->picture_filename = 'logo.png';
        $result = $controller->delPictureAction();

        $this->assertEquals(['result' => 'saved'], $result);
        $this->assertEquals('', (string)$model->picture);
        $this->assertEquals('', (string)$model->picture_filename);

        $config = Config::getInstance()->object();
        $this->assertEmpty((string)$config->system->picture);
    }

    /**
     * Test delPictureAction fails on non-POST request
     */
    public function testDelPictureActionNonPost()
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $controller = $this->getController();

        $result = $controller->delPictureAction();

        $this->assertEquals('failed', $result['result']);
    }

    // =========================================================================
    // 6. reconfigureAction() Tests
    // =========================================================================

    /**
     * Test reconfigureAction correctly unlinks stale routes file and invokes configd actions
     */
    public function testReconfigureActionFlushesStaleRoutes()
    {
        $staleFile = '/tmp/.general_staleroutes.json';
        file_put_contents($staleFile, json_encode(['9.9.9.9']));

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $controller = $this->getController();

        $result = $controller->reconfigureAction();

        $this->assertIsArray($result);
        $this->assertEquals('ok', $result['status']);
        $this->assertFileDoesNotExist($staleFile, 'Stale routes cache file must be unlinked after reconfigure');
    }

    /**
     * Test reconfigureAction fails on non-POST request
     */
    public function testReconfigureActionNonPost()
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $controller = $this->getController();

        $result = $controller->reconfigureAction();

        $this->assertEquals('failed', $result['status']);
    }
}
