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
        /* consumed token time step state persisted by the TOTP trait */
        foreach (glob(sys_get_temp_dir() . '/otp_consumed_*') as $filename) {
            @unlink($filename);
        }
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

    public function testAuthenticateStep2()
    {
        $authFactory = new AuthenticationFactory();

        // make the TOTP authenticator the step 1 winner, as in the WebGui flow a token
        // step only exists when the authenticator which accepted the password requires one
        Config::getInstance()->object()->system->webgui->authmode = 'Local TOTP';
        $this->assertTrue($authFactory->authenticateStep1("WebGui", "user_with_otp", "password123"));
        $otp_authname = $authFactory->pendingOTPAuthenticator("user_with_otp");
        $this->assertEquals("Local TOTP", $otp_authname);
        $this->assertEquals($authFactory->lastUsedAuthName, $otp_authname);

        // Get correct OTP code using testToken method on the TOTP authenticator
        $correct_otp = $authFactory->get($otp_authname)->testToken('ORSXG5BRGIZTINJWG4======');
        $this->assertNotEmpty($correct_otp);

        // authenticateStep2: incorrect OTP
        $this->assertFalse($authFactory->authenticateStep2("WebGui", "user_with_otp", "000000", $otp_authname));

        // authenticateStep2: correct OTP, but bound to an authenticator unable to verify tokens
        $this->assertFalse($authFactory->authenticateStep2("WebGui", "user_with_otp", $correct_otp, "Local Database"));

        // authenticateStep2: user without a token seed can never pass step 2
        $this->assertFalse($authFactory->authenticateStep2("WebGui", "user_no_otp", $correct_otp));

        // authenticateStep2: correct OTP, bound to the authenticator which accepted the
        // password and pinned to the account holding the seed
        $subject_id = $authFactory->get($otp_authname)->otpSubjectId("user_with_otp");
        $this->assertEquals("2002", $subject_id);
        $this->assertTrue($authFactory->authenticateStep2("WebGui", "user_with_otp", $correct_otp, $otp_authname, $subject_id));

        // a validated token may not validate again within its time window (RFC 6238)
        $this->assertFalse($authFactory->authenticateStep2("WebGui", "user_with_otp", $correct_otp, $otp_authname, $subject_id));
    }

    public function testStep2SubjectPinning()
    {
        Config::getInstance()->object()->system->webgui->authmode = 'Local TOTP';
        $authFactory = new AuthenticationFactory();
        $correct_otp = $authFactory->get("Local TOTP")->testToken('ORSXG5BRGIZTINJWG4======');

        // a stale subject id is refused
        $this->assertFalse($authFactory->authenticateStep2("WebGui", "user_with_otp", $correct_otp, "Local TOTP", "9999"));

        // simulate a rename race: the account validated in step 1 is renamed away and a
        // different account, holding its own seed, now answers to the stashed username
        foreach (Config::getInstance()->object()->system->user as $user) {
            if ((string)$user->name == 'user_with_otp') {
                $user->name = 'user_renamed';
            } elseif ((string)$user->name == 'user_no_otp') {
                $user->name = 'user_with_otp';
                $user->otp_seed = 'ORSXG5BRGIZTINJWG4======';
            }
        }

        // the username now resolves to uid 2001, the pinned subject (2002) refuses the token
        $this->assertFalse($authFactory->authenticateStep2("WebGui", "user_with_otp", $correct_otp, "Local TOTP", "2002"));
    }

    public function testOTPRequirementFollowsStep1Winner()
    {
        $authFactory = new AuthenticationFactory();

        // when a plain authenticator wins step 1, no token step is pending, whichever
        // configured server accepts the user first completes the login. A token step is
        // never spliced onto a different backend holding a seed for the same username.
        $this->assertTrue($authFactory->authenticateStep1("WebGui", "user_with_otp", "password123"));
        $this->assertEquals("Local Database", $authFactory->lastUsedAuthName);
        $this->assertNull($authFactory->pendingOTPAuthenticator("user_with_otp"));

        // when the TOTP authenticator wins step 1, the token step is bound to it
        Config::getInstance()->object()->system->webgui->authmode = 'Local TOTP';
        $this->assertTrue($authFactory->authenticateStep1("WebGui", "user_with_otp", "password123"));
        $this->assertEquals("Local TOTP", $authFactory->pendingOTPAuthenticator("user_with_otp"));

        // users without a token seed never leave a pending token step
        Config::getInstance()->object()->system->webgui->authmode = 'Local Database,Local TOTP';
        $this->assertTrue($authFactory->authenticateStep1("WebGui", "user_no_otp", "password123"));
        $this->assertNull($authFactory->pendingOTPAuthenticator("user_no_otp"));
    }

    public function testOTPInputCanonicalization()
    {
        Config::getInstance()->object()->system->webgui->authmode = 'Local TOTP';
        $authFactory = new AuthenticationFactory();
        $correct_otp = $authFactory->get("Local TOTP")->testToken('ORSXG5BRGIZTINJWG4======');

        // noncanonical presentations of a valid token are rejected
        $this->assertFalse($authFactory->authenticateStep2("WebGui", "user_with_otp", $correct_otp . "\n"));
        $this->assertFalse($authFactory->authenticateStep2("WebGui", "user_with_otp", " " . $correct_otp));
        $this->assertFalse($authFactory->authenticateStep2("WebGui", "user_with_otp", "+" . substr($correct_otp, 1)));

        // while the canonical form validates
        $this->assertTrue($authFactory->authenticateStep2("WebGui", "user_with_otp", $correct_otp));
    }

    public function testStep1RefusesUserWithoutSeedOnTOTPOnly()
    {
        // when only a TOTP authenticator serves the WebGui, a user without a token
        // seed is refused instead of downgraded to password-only authentication
        Config::getInstance()->object()->system->webgui->authmode = 'Local TOTP';
        $authFactory = new AuthenticationFactory();

        $this->assertFalse($authFactory->authenticateStep1("WebGui", "user_no_otp", "password123"));
        $this->assertTrue($authFactory->authenticateStep1("WebGui", "user_with_otp", "password123"));
    }

    public function testFailedAttemptPenalty()
    {
        $authFactory = new AuthenticationFactory();
        $authenticator = $authFactory->get("Local TOTP");

        // failed step 1 (password) and step 2 (token) attempts spend at least the
        // constant ~2 second sequence time Base::authenticate() enforces, allow a
        // small margin for platform timer resolution
        foreach (['authenticateFirstFactor' => 'wrongpassword', 'authenticateOTP' => '000000'] as $method => $secret) {
            $tstart = microtime(true);
            $this->assertFalse($authenticator->$method("user_with_otp", $secret));
            $this->assertGreaterThanOrEqual(1.9, microtime(true) - $tstart);
        }

        // a user without a token seed is refused in the same constant time, response
        // time may not reveal seed provisioning state
        $tstart = microtime(true);
        $this->assertFalse($authenticator->authenticateFirstFactor("user_no_otp", "password123"));
        $this->assertGreaterThanOrEqual(1.9, microtime(true) - $tstart);
    }

    public function testAuthenticateComposedSecret()
    {
        // single request flow, token composed into the secret (default order: token first)
        Config::getInstance()->object()->system->webgui->authmode = 'Local TOTP';
        $authFactory = new AuthenticationFactory();
        $correct_otp = $authFactory->get("Local TOTP")->testToken('ORSXG5BRGIZTINJWG4======');

        // a failed password attempt must not consume a valid token, the user may retry with it
        $this->assertFalse($authFactory->authenticate("WebGui", "user_with_otp", $correct_otp . "wrongpassword"));
        $this->assertTrue($authFactory->authenticate("WebGui", "user_with_otp", $correct_otp . "password123"));

        // a validated token is consumed on this path too and may not be replayed
        $this->assertFalse($authFactory->authenticate("WebGui", "user_with_otp", $correct_otp . "password123"));
        $this->assertFalse($authFactory->authenticate("WebGui", "user_with_otp", "000000password123"));
    }

    public function testShouldChangePasswordCompliance()
    {
        // fixture users carry bcrypt hashes, compliance requires SHA-512 crypt
        $webgui = Config::getInstance()->object()->system->webgui;
        $webgui->enable_password_policy_constraints = '1';
        $webgui->password_policy_compliance = '1';
        $authFactory = new AuthenticationFactory();

        foreach (["Local Database", "Local TOTP"] as $authname) {
            $authenticator = $authFactory->get($authname);
            $this->assertTrue($authFactory->shouldChangePassword($authenticator, "user_with_otp", "password123"));
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::cleanupTestFiles();
    }
}
