<?php

/*
 * Copyright (C) 2026 Konstantinos Spartalis
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

use OPNsense\Core\CommitSession;

class CommitSessionTest extends \PHPUnit\Framework\TestCase
{
    public static function setUpBeforeClass(): void
    {
        $app = new \OPNsense\Core\AppConfig();
        if (file_exists('/conf/config.xml')) {
            $app->update('application.configDir', '/conf');
            $app->update('application.configDefault', '/conf/config.xml');
            \OPNsense\Core\Config::getInstance()->forceReload();
        }
    }

    public function testInitialInactive()
    {
        $cs = CommitSession::getInstance();
        if (file_exists(CommitSession::MARKER_FILE)) {
            @unlink(CommitSession::MARKER_FILE);
        }
        $this->assertFalse($cs->isActive());
    }

    public function testDefaultSettings()
    {
        $cs = CommitSession::getInstance();
        $settings = $cs->getSettings();
        $this->assertIsArray($settings);
        $this->assertArrayHasKey('countdown', $settings);
        $this->assertArrayHasKey('extension', $settings);
        $this->assertArrayHasKey('max_extensions', $settings);
        $this->assertGreaterThanOrEqual(1, $settings['countdown']);
        $this->assertLessThanOrEqual(30, $settings['countdown']);
        $this->assertGreaterThanOrEqual(1, $settings['extension']);
        $this->assertLessThanOrEqual(30, $settings['extension']);
        $this->assertGreaterThanOrEqual(1, $settings['max_extensions']);
        $this->assertLessThanOrEqual(60, $settings['max_extensions']);
        $this->assertArrayHasKey('max_session', $settings);
        $this->assertGreaterThanOrEqual($settings['countdown'], $settings['max_session']);
        $this->assertLessThanOrEqual(480, $settings['max_session']);
    }

    public function testDefaultCountdownIsTenMinutes()
    {
        $this->assertEquals(10, CommitSession::DEFAULT_COUNTDOWN);
        $this->assertEquals(120, CommitSession::DEFAULT_MAX_SESSION);
    }

    public function testNamedAccountIsUserAction()
    {
        $cs = CommitSession::getInstance();
        unset($_SESSION['Username'], $_SERVER['PHP_AUTH_USER']);
        $this->assertTrue($cs->isUserAction(['username' => 'admin@192.168.1.10'], 'gui'));
    }

    public function testProcessIdentityIsNotUserAction()
    {
        if (function_exists('posix_isatty') && @posix_isatty(STDIN)) {
            $this->markTestSkipped('interactive terminal counts as a user session');
        }
        $cs = CommitSession::getInstance();
        unset($_SESSION['Username'], $_SERVER['PHP_AUTH_USER']);
        $this->assertFalse($cs->isUserAction(['username' => '(root)'], 'console'));
        $this->assertFalse($cs->isUserAction(['username' => '(root)@10.0.0.1'], 'console'));
        $this->assertFalse($cs->isUserAction(null, 'console'));
    }

    public function testGetGuiUrlFromXml()
    {
        $cs = CommitSession::getInstance();
        unset($_SERVER['HTTP_HOST'], $_SERVER['SERVER_ADDR']);
        $xmlStr = '<opnsense><system><webgui><protocol>https</protocol><port>8443</port></webgui></system><interfaces><lan><ipaddr>192.168.1.1</ipaddr></lan></interfaces></opnsense>';
        $xml = simplexml_load_string($xmlStr);
        $url = $cs->getGuiUrl($xml);
        $this->assertEquals('https://192.168.1.1:8443', $url);

        $xmlHttpStr = '<opnsense><system><webgui><protocol>http</protocol><port></port></webgui></system><interfaces><lan><ipaddr>10.0.0.1</ipaddr></lan></interfaces></opnsense>';
        $xmlHttp = simplexml_load_string($xmlHttpStr);
        $urlHttp = $cs->getGuiUrl($xmlHttp);
        $this->assertEquals('http://10.0.0.1', $urlHttp);
    }

    public function testGetGuiUrlPrefersHttpHostOverLanIp()
    {
        $cs = CommitSession::getInstance();
        $_SERVER['HTTP_HOST'] = 'firewall.wan.example.com:443';
        $xmlStr = '<opnsense><system><webgui><protocol>https</protocol><port>443</port></webgui></system><interfaces><lan><ipaddr>192.168.1.1</ipaddr></lan></interfaces></opnsense>';
        $xml = simplexml_load_string($xmlStr);
        $url = $cs->getGuiUrl($xml);
        $this->assertEquals('https://firewall.wan.example.com', $url);
        unset($_SERVER['HTTP_HOST']);
    }

    public function testGetGuiUrlIgnoresDhcpLanIp()
    {
        $cs = CommitSession::getInstance();
        unset($_SERVER['HTTP_HOST'], $_SERVER['SERVER_ADDR']);
        $xmlDhcp = '<opnsense><system><webgui><protocol>https</protocol><port></port></webgui></system><interfaces><lan><ipaddr>dhcp</ipaddr></lan></interfaces></opnsense>';
        $xml = simplexml_load_string($xmlDhcp);
        $url = $cs->getGuiUrl($xml);
        $this->assertEquals('https://127.0.0.1', $url);
        $this->assertStringNotContainsString('dhcp', $url);
    }

    public function testNoticeLifecycle()
    {
        $cs = CommitSession::getInstance();
        $noticeFile = CommitSession::NOTICE_FILE;

        @unlink($noticeFile);
        $this->assertFalse($cs->hasRevertNotice());
        $this->assertNull($cs->getRevertNotice());

        $testData = [
            'reverted_at' => 1728460000,
            'reverted_at_iso' => '2026-10-09T08:00:00Z',
            'username' => 'testuser',
            'reason' => 'countdown_expired',
        ];
        file_put_contents($noticeFile, json_encode($testData));

        $this->assertTrue($cs->hasRevertNotice());
        $notice = $cs->getRevertNotice();
        $this->assertIsArray($notice);
        $this->assertEquals('testuser', $notice['username']);
        $this->assertEquals('countdown_expired', $notice['reason']);

        $dismissed = $cs->dismissRevertNotice();
        $this->assertTrue($dismissed);
        $this->assertFalse($cs->hasRevertNotice());
    }

    public function testGetGuiUrlWithIpv6HttpHost()
    {
        $cs = CommitSession::getInstance();
        $_SERVER['HTTP_HOST'] = '[2001:db8::1]:8443';
        $xmlStr = '<opnsense><system><webgui><protocol>https</protocol><port>8443</port></webgui></system></opnsense>';
        $xml = simplexml_load_string($xmlStr);
        $url = $cs->getGuiUrl($xml);
        $this->assertEquals('https://[2001:db8::1]:8443', $url);

        $_SERVER['HTTP_HOST'] = '[2001:db8::1]';
        $url2 = $cs->getGuiUrl($xml);
        $this->assertEquals('https://[2001:db8::1]:8443', $url2);
        unset($_SERVER['HTTP_HOST']);
    }

    public function testTagRevisionsInHistoryUpdatesSidecarWithoutTouchingBackups()
    {
        $cs = CommitSession::getInstance();
        $backupDir = '/conf/backup';
        if (!file_exists($backupDir)) {
            @mkdir($backupDir, 0750, true);
        }
        $testFile = $backupDir . '/config-9999999999.1234.xml';
        $originalXml = '<?xml version="1.0"?><opnsense><revision><time>9999999999.1234</time><session_tag>Pending</session_tag></revision></opnsense>';
        file_put_contents($testFile, $originalXml);

        $cs->tagRevisionsInHistory(['config-9999999999.1234.xml'], 'Confirmed');

        // Backup file itself must remain untouched!
        $this->assertEquals($originalXml, file_get_contents($testFile));

        // Sidecar tags file must have Confirmed tag
        $tagsFile = $backupDir . '/session_tags.json';
        $this->assertTrue(file_exists($tagsFile));
        $tags = json_decode(file_get_contents($tagsFile), true);
        $this->assertEquals('Confirmed', $tags['config-9999999999.1234.xml']);

        @unlink($testFile);
    }

    public function testOnConfigSaveInactiveDoesNothing()
    {
        $cs = CommitSession::getInstance();
        @unlink(CommitSession::MARKER_FILE);
        $rev = ['username' => 'root'];
        $cs->onConfigSave('config-test.xml', $rev);
        $this->assertFalse($cs->isActive());
    }

    public function testIsUserActionDetection()
    {
        if (function_exists('posix_isatty') && @posix_isatty(STDIN)) {
            $this->markTestSkipped('interactive terminal counts as a user session');
        }
        $cs = CommitSession::getInstance();
        unset($_SESSION['Username']);
        unset($_SERVER['REQUEST_URI']);
        unset($_SERVER['PHP_AUTH_USER']);

        // Process identities are not user actions; named accounts are.
        $this->assertFalse($cs->isUserAction(['username' => '(root)'], 'console'));
        $this->assertFalse($cs->isUserAction(['username' => '(root)@192.168.1.1'], 'console'));
        $this->assertTrue($cs->isUserAction(['username' => 'root'], 'console'));
        $this->assertTrue($cs->isUserAction(['username' => 'admin'], 'console'));

        // Saves without an identity or authenticated context are not user actions
        $this->assertFalse($cs->isUserAction(null, 'console'));
        $this->assertFalse($cs->isUserAction([], 'console'));

        // Automated system saves must NOT count as user actions
        $this->assertFalse($cs->isUserAction(['username' => 'system'], 'console'));
        $this->assertFalse($cs->isUserAction(['username' => '(system)'], 'console'));
        $this->assertFalse($cs->isUserAction(['username' => 'system'], 'gui'));
        $this->assertFalse($cs->isUserAction(['username' => '(system)'], 'gui'));

        // Non-console saves without username or session must not count as user action
        $this->assertFalse($cs->isUserAction(null, 'gui'));
        $this->assertFalse($cs->isUserAction([], 'gui'));

        // Logged in web session must count as user action
        $_SESSION['Username'] = 'admin';
        $this->assertTrue($cs->isUserAction(null, 'gui'));
        unset($_SESSION['Username']);

        // HTTP auth user must count as user action
        $_SERVER['PHP_AUTH_USER'] = 'root';
        $this->assertTrue($cs->isUserAction(null, 'api'));
        unset($_SERVER['PHP_AUTH_USER']);
    }

    public function testOnConfigSaveConsoleRootUserDetection()
    {
        if (function_exists('posix_isatty') && @posix_isatty(STDIN)) {
            $this->markTestSkipped('interactive terminal counts as a user session');
        }
        $cs = CommitSession::getInstance();
        file_put_contents(CommitSession::MARKER_FILE, json_encode(['session_id' => 'test']));
        $this->assertTrue($cs->isActive());

        @unlink(CommitSession::SOCKET_FILE);
        unset($_SESSION['Username']);
        unset($_SERVER['REQUEST_URI'], $_SERVER['PHP_AUTH_USER']);

        $rev = ['username' => '(root)'];
        $cs->onConfigSave('config-test.xml', $rev);
        $this->assertFalse($cs->isUserAction($rev, 'console'));

        $revSystem = ['username' => '(system)'];
        $cs->onConfigSave('config-test.xml', $revSystem);
        $this->assertFalse($cs->isUserAction($revSystem, 'console'));

        @unlink(CommitSession::MARKER_FILE);
    }

    public function testIsRevertingAndGetState()
    {
        $cs = CommitSession::getInstance();
        $markerData = [
            'session_id' => 'test-revert',
            'status' => 'reverting',
            'reverting' => true,
            'revert_reason' => 'manual',
            'gui_url' => 'https://192.168.1.1',
        ];
        file_put_contents(CommitSession::MARKER_FILE, json_encode($markerData));

        $this->assertTrue($cs->isActive());
        $this->assertTrue($cs->isReverting());

        $state = $cs->getState();
        $this->assertTrue($state['active']);
        $this->assertEquals('reverting', $state['status']);
        $this->assertTrue($state['reverting']);
        $this->assertEquals('manual', $state['revert_reason']);
        $this->assertEquals(0, $state['remaining_seconds']);
        $this->assertFalse($state['countdown_active']);

        // Diff must be empty when reverting
        $diff = $cs->getDiff();
        $this->assertIsArray($diff);
        $this->assertEmpty($diff);

        @unlink(CommitSession::MARKER_FILE);
    }
}
