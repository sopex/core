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
    }

    public function testGetGuiUrlFromXml()
    {
        $cs = CommitSession::getInstance();
        $xmlStr = '<opnsense><system><webgui><protocol>https</protocol><port>8443</port></webgui></system><interfaces><lan><ipaddr>192.168.1.1</ipaddr></lan></interfaces></opnsense>';
        $xml = simplexml_load_string($xmlStr);
        $url = $cs->getGuiUrl($xml);
        $this->assertEquals('https://192.168.1.1:8443', $url);

        $xmlHttpStr = '<opnsense><system><webgui><protocol>http</protocol><port></port></webgui></system><interfaces><lan><ipaddr>10.0.0.1</ipaddr></lan></interfaces></opnsense>';
        $xmlHttp = simplexml_load_string($xmlHttpStr);
        $urlHttp = $cs->getGuiUrl($xmlHttp);
        $this->assertEquals('http://10.0.0.1', $urlHttp);
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
}
