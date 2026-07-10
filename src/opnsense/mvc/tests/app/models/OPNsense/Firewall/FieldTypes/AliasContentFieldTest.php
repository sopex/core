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

namespace tests\OPNsense\Firewall\FieldTypes;

require_once __DIR__ . '/../../Base/FieldTypes/Field_Framework_TestCase.php';

use tests\OPNsense\Base\FieldTypes\Field_Framework_TestCase;
use OPNsense\Firewall\FieldTypes\AliasContentField;

class AliasContentFieldTest extends Field_Framework_TestCase
{
    /**
     * build a content field attached to a minimal alias node of the requested type
     * @param string $type alias type
     * @return AliasContentField
     */
    private function createField(string $type): AliasContentField
    {
        $typeNode = new class extends \OPNsense\Base\FieldTypes\BaseField {
            protected $internalIsContainer = false;
        };
        $typeNode->setValue($type);

        $field = new AliasContentField();
        $parent = new class extends \OPNsense\Base\FieldTypes\BaseField{
        };
        $parent->addChildNode('type', $typeNode);
        $parent->addChildNode('content', $field);
        return $field;
    }

    /**
     * test construct
     */
    public function testCanBeCreated()
    {
        $this->assertInstanceOf(AliasContentField::class, new AliasContentField());
    }

    /**
     * type is not a container
     */
    public function testIsContainer()
    {
        $field = new AliasContentField();
        $this->assertFalse($field->isContainer());
    }

    /**
     * test normalization of dash ranges to colon ranges for port type aliases
     */
    public function testDashRangeNormalization()
    {
        $field = $this->createField('port');
        $field->setValue("80-100\n443\n8000:9000");
        $this->assertEquals("80:100\n443\n8000:9000", $field->getValue());
    }

    /**
     * values not recognized as a valid range are kept as-is for validation to report
     */
    public function testMalformedRangesNotRewritten()
    {
        $field = $this->createField('port');
        foreach (['80-90-100', '80-', '-100', ' 80-100'] as $value) {
            $field->setValue($value);
            $this->assertEquals($value, $field->getValue());
        }
    }

    /**
     * service names containing dashes may not be mangled into ranges
     */
    public function testDashedServiceNameNotRewritten()
    {
        $field = $this->createField('port');
        $field->setValue("radius-acct\n80-100");
        $this->assertEquals("radius-acct\n80:100", $field->getValue());
    }

    /**
     * only port type aliases are normalized
     */
    public function testOtherAliasTypesUntouched()
    {
        $field = $this->createField('host');
        $field->setValue("web-01.example.com\n80-100");
        $this->assertEquals("web-01.example.com\n80-100", $field->getValue());
    }

    /**
     * a field without an attached parent leaves the value untouched
     */
    public function testWithoutParentUntouched()
    {
        $field = new AliasContentField();
        $field->setValue("80-100");
        $this->assertEquals("80-100", $field->getValue());
    }
}
