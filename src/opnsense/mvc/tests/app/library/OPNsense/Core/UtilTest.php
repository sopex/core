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

namespace tests\OPNsense\Core;

$includeDirs = [
    realpath(__DIR__ . '/../../../../../../../etc/inc'),
    realpath(__DIR__ . '/../../../../../../mvc'),
];
set_include_path(implode(PATH_SEPARATOR, array_merge([get_include_path()], $includeDirs)));
require_once 'util.inc';

class UtilTest extends \PHPUnit\Framework\TestCase
{
    /**
     * @dataProvider ipAddressV6Provider
     */
    public function testIsIpaddrv6($ip, bool $expected, string $description = '')
    {
        $this->assertSame(
            $expected,
            is_ipaddrv6($ip),
            $description ?: sprintf('Failed testing is_ipaddrv6 with value: %s', var_export($ip, true))
        );
    }

    public static function ipAddressV6Provider(): array
    {
        return [
            // Valid standard addresses
            'standard address' => ['2001:db8:85a3:0:0:8a2e:370:7334', true],
            'standard full address' => ['2001:0db8:85a3:0000:0000:8a2e:0370:7334', true],
            'standard zero-padded' => ['2001:0db8:0000:0000:0000:0000:0000:0001', true],
            'all zeros full' => ['0000:0000:0000:0000:0000:0000:0000:0000', true],
            'loopback full' => ['0000:0000:0000:0000:0000:0000:0000:0001', true],

            // Valid compressed addresses
            'unspecified address (::)' => ['::', true],
            'loopback address (::1)' => ['::1', true],
            'compressed leading zero' => ['::ffff', true],
            'compressed simple' => ['2001:db8::1', true],
            'compressed middle' => ['2001:db8::2:1', true],
            'compressed trailing' => ['1::', true],

            // Valid IPv4-mapped / IPv4-compatible
            'ipv4 mapped' => ['::ffff:192.0.2.1', true],
            'ipv4 mapped local' => ['::ffff:127.0.0.1', true],
            'ipv4 compatible' => ['::192.0.2.1', true],
            'ipv4 mapped full prefix' => ['0:0:0:0:0:ffff:192.168.1.1', true],
            'ipv4 mapped zero-padded octets' => ['::ffff:192.168.001.010', false],

            // Valid link-local with and without scope
            'link-local without scope' => ['fe80::1', true],
            'link-local with interface scope' => ['fe80::1%eth0', true],
            'link-local with mac-based scope' => ['fe80::20c:29ff:fe7d:50b7%em0', true],
            'link-local with numeric scope id' => ['fe80::1%1', true],

            // Trailing / leading spaces and newlines (fuzzed edge cases)
            'trailing space' => ['2001:db8::1 ', false],
            'leading space' => [' 2001:db8::1', false],
            'leading and trailing space' => [' 2001:db8::1 ', false],
            'trailing newline' => ["2001:db8::1\n", false],
            'leading newline' => ["\n2001:db8::1", false],
            'trailing newline compressed zero' => ["1:2:3:4:5:6:7::\n", false],
            'trailing crlf' => ["2001:db8::1\r\n", false],
            'leading crlf' => ["\r\n2001:db8::1", false],
            'trailing tab' => ["2001:db8::1\t", false],
            'leading tab' => ["\t2001:db8::1", false],
            'trailing nul byte' => ["2001:db8::1\0", false],
            'nul byte with trailing junk' => ["2001:db8::1\0junk", false],

            // Extra colons / invalid structures
            'trailing colon 8 groups' => ['1:2:3:4:5:6:7:8:', false],
            'leading colon 8 groups' => [':1:2:3:4:5:6:7:8', false],
            '8 groups with trailing double colon' => ['2001:db8:0:0:0:0:0:1::', false],
            'double compression' => ['2001::db8::1', false],
            'triple colon' => [':::', false],
            'invalid leading double colon single group' => [':1::', false],
            'invalid trailing double colon single group' => ['::1:', false],
            'too many groups (9)' => ['1:2:3:4:5:6:7:8:9', false],
            'too few groups without compression' => ['1:2:3:4:5:6:7', false],
            'triple colon in middle' => ['2001:db8:::1', false],
            'invalid hex char' => ['2001:db8::g', false],
            'group too long (5 hex digits)' => ['2001:054ff::1', false],
            'cidr subnet is not an address' => ['2001:db8::1/64', false],
            'non link-local with scope' => ['2001:db8::1%eth0', false],

            // Type safety with non-strings and empty strings
            'empty string' => ['', false],
            'null' => [null, false],
            'boolean false' => [false, false],
            'boolean true' => [true, false],
            'integer 0' => [0, false],
            'integer non-zero' => [123, false],
            'float' => [12.34, false],
            'empty array' => [[], false],
            'array with string' => [['2001:db8::1'], false],
            'empty object' => [(object)[], false],
        ];
    }

    /**
     * @dataProvider ipAddressV4Provider
     */
    public function testIsIpaddrv4($ip, bool $expected, string $description = '')
    {
        $this->assertSame(
            $expected,
            is_ipaddrv4($ip),
            $description ?: sprintf('Failed testing is_ipaddrv4 with value: %s', var_export($ip, true))
        );
    }

    public static function ipAddressV4Provider(): array
    {
        return [
            // Valid dotted IPv4
            'zero network' => ['0.0.0.0', true],
            'loopback' => ['127.0.0.1', true],
            'private class C' => ['192.168.1.1', true],
            'private class A' => ['10.0.0.1', true],
            'private class B' => ['172.16.0.1', true],
            'broadcast' => ['255.255.255.255', true],

            // Trailing / leading whitespace and newlines
            'trailing space' => ['192.168.1.1 ', false],
            'leading space' => [' 192.168.1.1', false],
            'leading and trailing space' => [' 192.168.1.1 ', false],
            'trailing newline' => ["192.168.1.1\n", false],
            'leading newline' => ["\n192.168.1.1", false],
            'trailing crlf' => ["192.168.1.1\r\n", false],
            'trailing tab' => ["192.168.1.1\t", false],
            'leading tab' => ["\t192.168.1.1", false],

            // Invalid structures
            'octet out of range' => ['192.168.1.256', false],
            '5 octets' => ['192.168.1.1.1', false],
            '3 octets' => ['192.168.1', false],
            'empty octet' => ['192.168..1', false],
            'leading zeros / octal notation' => ['01.02.03.04', false],
            'leading zero single octet' => ['010.0.0.1', false],
            'cidr subnet notation' => ['192.168.1.1/24', false],
            'ip with port' => ['192.168.1.1:80', false],
            'non-numeric octets' => ['a.b.c.d', false],
            'negative octet' => ['192.168.1.-1', false],
            'only dots' => ['...', false],
            'leading colon' => [':192.168.1.1', false],
            'trailing colon' => ['192.168.1.1:', false],
            'cidr host 32' => ['192.168.1.1/32', false],
            'cidr host 0' => ['192.168.1.1/0', false],
            'space between octets' => ['192. 168.1.1', false],

            // Type safety with non-strings and empty strings
            'empty string' => ['', false],
            'null' => [null, false],
            'boolean false' => [false, false],
            'boolean true' => [true, false],
            'integer 0' => [0, false],
            'integer non-zero' => [123, false],
            'float' => [12.34, false],
            'empty array' => [[], false],
            'array with string' => [['192.168.1.1'], false],
            'empty object' => [(object)[], false],
        ];
    }
}
