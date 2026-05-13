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

namespace {
    if (!function_exists('gettext')) {
        function gettext($message)
        {
            return $message;
        }
    }
}

namespace tests\OPNsense\Firewall {
    require_once __DIR__ . '/../../../../../../../etc/inc/plugins.inc.d/pf.inc';

    class PfSyncTest extends \PHPUnit\Framework\TestCase
    {
        private function getSyncItem($id)
        {
            foreach (\pf_xmlrpc_sync() as $item) {
                if ($item['id'] == $id) {
                    return $item;
                }
            }
            $this->fail(sprintf('Sync item %s not found', $id));
        }

        public function testRulesSyncIncludesLegacyRuleList()
        {
            $item = $this->getSyncItem('rules');
            $this->assertContains('filter.rule', explode(',', $item['section']));
        }

        public function testNatSyncIncludesLegacyRuleLists()
        {
            $item = $this->getSyncItem('nat');
            $sections = explode(',', $item['section']);

            $this->assertContains('nat.rule', $sections);
            $this->assertContains('nat.outbound.rule', $sections);
        }
    }
}