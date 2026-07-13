<?php

/*
 * Copyright (C) 2026 Konstantinos Spartalis <cspartalis@potatonetworks.com>
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

namespace tests\OPNsense\Auth;

use OPNsense\Core\AppConfig;
use OPNsense\Core\Config;
use OPNsense\Auth\AuthenticationFactory;

class AuthenticationFactoryTest extends \PHPUnit\Framework\TestCase
{
    private static $configDir = __DIR__ . '/AuthenticationFactoryConfig';

    public static function cleanupTestFiles()
    {
        @unlink(self::$configDir . '/config.xml');
    }

    protected function setUp(): void
    {
        self::cleanupTestFiles();
        (new AppConfig())->update('application.configDir', self::$configDir);
        (new AppConfig())->update('application.configDefault', self::$configDir . '/backup/auth.xml');
        Config::getInstance()->forceReload();
    }

    protected function tearDown(): void
    {
        self::cleanupTestFiles();
    }

    public function testCanLoadConfig()
    {
        $this->assertNotEmpty(Config::getInstance()->object());
    }

    public function testAuthenticateStep1()
    {
        $authFactory = new AuthenticationFactory();

        // user_no_otp: correct password
        $this->assertTrue($authFactory->authenticateStep1("WebGui", "user_no_otp", "password123"));

        // user_no_otp: incorrect password
        $this->assertFalse($authFactory->authenticateStep1("WebGui", "user_no_otp", "wrongpassword"));

        // user_with_otp: correct password
        $this->assertTrue($authFactory->authenticateStep1("WebGui", "user_with_otp", "password123"));

        // user_with_otp: incorrect password
        $this->assertFalse($authFactory->authenticateStep1("WebGui", "user_with_otp", "wrongpassword"));
    }

    public function testUserUsesOTP()
    {
        $authFactory = new AuthenticationFactory();

        // user_no_otp: does not use OTP
        $this->assertFalse($authFactory->userUsesOTP("WebGui", "user_no_otp"));

        // user_with_otp: uses OTP
        $this->assertTrue($authFactory->userUsesOTP("WebGui", "user_with_otp"));
    }

    public function testAuthenticateStep2()
    {
        $authFactory = new AuthenticationFactory();

        // user_with_otp: authenticateStep1 first
        $this->assertTrue($authFactory->authenticateStep1("WebGui", "user_with_otp", "password123"));
        $authenticator = $authFactory->lastUsedAuth;
        $this->assertNotNull($authenticator);

        // Get correct OTP code using testToken method on authenticator
        $correct_otp = $authenticator->testToken('ORSXG5BRGIZTINJWG4======');
        $this->assertNotEmpty($correct_otp);

        // authenticateStep2: correct OTP
        $this->assertTrue($authFactory->authenticateStep2("WebGui", "user_with_otp", $correct_otp));

        // authenticateStep2: incorrect OTP
        $this->assertFalse($authFactory->authenticateStep2("WebGui", "user_with_otp", "000000"));
    }

    public static function tearDownAfterClass(): void
    {
        self::cleanupTestFiles();
    }
}
