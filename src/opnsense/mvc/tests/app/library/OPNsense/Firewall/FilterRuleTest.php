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
 *  THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
 *  INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
 *  AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 *  AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
 *  OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 *  SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 *  INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 *  CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 *  ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 *  POSSIBILITY OF SUCH DAMAGE.
 */

namespace tests\OPNsense\Firewall;

use OPNsense\Firewall\FilterRule;

class FilterRuleTest extends \PHPUnit\Framework\TestCase
{
    public static $ifmap = [];
    public static $gwmap = [];

    /**
     * get the stored test ouput
     */
    public function getConf($func)
    {
        $class = str_replace(__NAMESPACE__ . '\\', '', __CLASS__);
        $file = sprintf('%s/%s/%s.conf', __DIR__, $class, $func);

        $this->assertFileExists($file);

        return file_get_contents(sprintf('%s/%s/%s.conf', __DIR__, $class, $func));
    }

    /**
     * test direction
     */
    public function testDirection()
    {
        $rules = [];

        $rules[] = new FilterRule(self::$ifmap, self::$gwmap, []);
        $rules[] = new FilterRule(self::$ifmap, self::$gwmap, ['direction' => '']);
        $rules[] = new FilterRule(self::$ifmap, self::$gwmap, ['direction' => null]);
        $rules[] = new FilterRule(self::$ifmap, self::$gwmap, ['direction' => 'in']);
        $rules[] = new FilterRule(self::$ifmap, self::$gwmap, ['direction' => 'out']);
        $rules[] = new FilterRule(self::$ifmap, self::$gwmap, ['direction' => 'any']);

        $this->assertEquals(join('', $rules), $this->getConf(__FUNCTION__));
    }

    /**
     * test icmp
     */
    public function testIcmp()
    {
        $rules = [];

        $rules[] = new FilterRule(self::$ifmap, self::$gwmap, ['icmp-type' => 'mobregreq,skip,2']);
        $rules[] = new FilterRule(self::$ifmap, self::$gwmap, ['icmp-type' => 'skip,2']);
        $rules[] = new FilterRule(self::$ifmap, self::$gwmap, ['icmp-type' => '2']);
        $rules[] = new FilterRule(self::$ifmap, self::$gwmap, ['icmp-type' => '']);

        $this->assertEquals(join('', $rules), $this->getConf(__FUNCTION__));
    }

    /**
     * test protocol
     */
    public function testProtocol()
    {
        $rules = [];

        $rules[] = new FilterRule(self::$ifmap, self::$gwmap, ['protocol' => '']);
        $rules[] = new FilterRule(self::$ifmap, self::$gwmap, ['protocol' => 'tcp']);
        $rules[] = new FilterRule(self::$ifmap, self::$gwmap, ['protocol' => 'tcp/udp']);
        $rules[] = new FilterRule(self::$ifmap, self::$gwmap, ['protocol' => 'skip']);
        $rules[] = new FilterRule(self::$ifmap, self::$gwmap, ['protocol' => 'a/n']);

        $this->assertEquals(join('', $rules), $this->getConf(__FUNCTION__));
    }

    /**
     * test application control, rules matching applications divert candidate flows to appidd
     */
    public function testApplication()
    {
        $rules = [];

        /* block rule diverted to its assigned port, pf passes and keeps state */
        $rules[] = new FilterRule(self::$ifmap, self::$gwmap, [
            'type' => 'block', 'statetype' => 'none', 'application' => 'bittorrent', 'appid_port' => 8100
        ]);
        /* category match on a pass rule keeps the configured state type */
        $rules[] = new FilterRule(self::$ifmap, self::$gwmap, [
            'type' => 'pass', 'statetype' => 'sloppy', 'application_category' => 'p2p', 'appid_port' => 8101
        ]);
        /* fail open, pass without diversion */
        $rules[] = new FilterRule(self::$ifmap, self::$gwmap, [
            'type' => 'reject', 'application' => 'bittorrent', 'appid_fallback' => true
        ]);
        /* application control unavailable, the rule can not be enforced */
        $rules[] = new FilterRule(self::$ifmap, self::$gwmap, ['type' => 'block', 'application' => 'bittorrent']);
        /* no application selected, the rule is rendered untouched */
        $rules[] = new FilterRule(self::$ifmap, self::$gwmap, ['type' => 'block', 'appid_port' => 8102]);

        $this->assertEquals(join('', $rules), $this->getConf(__FUNCTION__));
    }
}
