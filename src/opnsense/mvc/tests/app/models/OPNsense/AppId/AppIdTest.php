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

namespace tests\OPNsense\AppId;

// @CodingStandardsIgnoreStart
require_once 'JsonKeyValueStoreSeed.php';
// @CodingStandardsIgnoreEnd

use OPNsense\AppId\AppId;
use OPNsense\Base\FieldTypes\InterfaceField;
use OPNsense\Base\FieldTypes\NetworkAliasField;
use OPNsense\Base\FieldTypes\ProtocolField;
use OPNsense\Core\AppConfig;
use OPNsense\Core\Config;
use OPNsense\Firewall\Filter;

class AppIdTest extends \PHPUnit\Framework\TestCase
{
    /**
     * Loading the firewall model caches option lists per field type, flush them to keep field tests independent.
     */
    public static function tearDownAfterClass(): void
    {
        foreach ([new NetworkAliasField(), new ProtocolField(), new InterfaceField()] as $field) {
            $field->resetStaticOptions();
        }
    }

    /**
     * test construct
     */
    public function testCanBeCreated()
    {
        // switch config to test set for this type
        (new AppConfig())->update('application.configDir', __DIR__ . '/AppIdTest');
        Config::getInstance()->forceReload();
        // option lists normally offered by configd
        foreach (['filter list diverts', 'interface gateways list -g', 'interface gateways list'] as $action) {
            JsonKeyValueStoreSeed::seed($action, []);
        }
        JsonKeyValueStoreSeed::seed('/tmp/appid_applications.json', [
            'Unclassified' => ['unknown' => 'Unknown'],
            'Peer to Peer' => ['bittorrent' => 'BitTorrent'],
            'Proxy' => ['tor' => 'Tor'],
        ]);
        JsonKeyValueStoreSeed::seed('/tmp/appid_categories.json', ['p2p' => 'Peer to Peer', 'proxy' => 'Proxy']);
        $this->assertInstanceOf('\OPNsense\AppId\AppId', new AppId());
    }

    /**
     * enabled rules matching applications are ordered by sequence and uuid, ports are assigned in that order
     * @depends testCanBeCreated
     */
    public function testRulePolicy()
    {
        $policy = (new AppId())->getRulePolicy(new Filter());

        $this->assertSame([
            'c0000000-0000-4000-8000-000000000001',
            'c0000000-0000-4000-8000-000000000002',
            'c0000000-0000-4000-8000-000000000003',
        ], array_keys($policy));

        $this->assertSame([
            'port' => 8101,
            'applications' => ['bittorrent', 'tor'],
            'categories' => [],
            'negate' => false,
            'action' => 'block',
            'otherwise' => 'pass',
            'log' => true,
            'description' => 'block bittorrent and tor',
        ], $policy['c0000000-0000-4000-8000-000000000002']);

        $this->assertSame([
            'port' => 8102,
            'applications' => [],
            'categories' => ['p2p'],
            'negate' => true,
            'action' => 'pass',
            'otherwise' => 'block',
            'log' => false,
            'description' => 'allow everything but peer to peer',
        ], $policy['c0000000-0000-4000-8000-000000000003']);
    }

    /**
     * @depends testCanBeCreated
     */
    public function testRulePorts()
    {
        $this->assertSame([
            'c0000000-0000-4000-8000-000000000001' => 8100,
            'c0000000-0000-4000-8000-000000000002' => 8101,
            'c0000000-0000-4000-8000-000000000003' => 8102,
        ], (new AppId())->getRulePorts(new Filter()));
    }

    /**
     * no rules are diverted when application control is disabled
     * @depends testCanBeCreated
     */
    public function testDisabled()
    {
        $mdl = new AppId();
        $mdl->general->enabled = '0';
        $this->assertSame([], $mdl->getRulePolicy(new Filter()));
    }

    /**
     * collect validation messages for a new firewall rule
     * @param array $nodes rule properties
     * @return array messages
     */
    private function validateRule($nodes)
    {
        $filter = new Filter();
        $rule = $filter->rules->rule->Add();
        $rule->setNodes(array_merge(['interface' => 'lan'], $nodes));
        $messages = [];
        foreach ($filter->performValidation() as $message) {
            $messages[] = $message->getMessage();
        }
        return $messages;
    }

    /**
     * @depends testCanBeCreated
     */
    public function testFilterValidationValid()
    {
        $this->assertSame([], $this->validateRule(['action' => 'block', 'application' => 'bittorrent']));
        $this->assertSame([], $this->validateRule([
            'action' => 'pass', 'protocol' => 'TCP', 'application_category' => 'p2p', 'application_not' => '1'
        ]));
    }

    /**
     * @depends testCanBeCreated
     */
    public function testFilterValidationInvalid()
    {
        $messages = $this->validateRule([
            'action' => 'match', 'protocol' => 'ICMP', 'direction' => 'out', 'application' => 'bittorrent',
            'divert-to' => '', 'quick' => '0'
        ]);
        $this->assertContains('Applications can only be matched by pass, block or reject rules.', $messages);
        $this->assertContains('Applications can only be matched for tcp or udp traffic.', $messages);
        $this->assertContains('Applications can only be matched on inbound rules.', $messages);

        $messages = $this->validateRule(['action' => 'pass', 'statetype' => 'none', 'application' => 'tor']);
        $this->assertContains('Application matching requires state tracking.', $messages);

        $messages = $this->validateRule(['action' => 'pass', 'application_not' => '1']);
        $this->assertContains('Inverting applications requires at least one application or category.', $messages);
    }

    /**
     * @depends testCanBeCreated
     */
    public function testIsApplicationRule()
    {
        $rules = iterator_to_array((new Filter())->rules->rule->iterateItems());
        $this->assertTrue(AppId::isApplicationRule($rules['c0000000-0000-4000-8000-000000000003']));
        $this->assertFalse(AppId::isApplicationRule($rules['c0000000-0000-4000-8000-000000000005']));
    }
}
