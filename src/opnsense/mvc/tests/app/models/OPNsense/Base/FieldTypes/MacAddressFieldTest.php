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

namespace tests\OPNsense\Base\FieldTypes;

// @CodingStandardsIgnoreStart
require_once 'Field_Framework_TestCase.php';
// @CodingStandardsIgnoreEnd

use OPNsense\Base\FieldTypes\MacAddressField;

class MacAddressFieldTest extends Field_Framework_TestCase
{
    /**
     * test construct
     */
    public function testCanBeCreated()
    {
        $this->assertInstanceOf('\OPNsense\Base\FieldTypes\MacAddressField', new MacAddressField());
    }

    /**
     * generic property tests
     */
    public function testGeneric()
    {
        $field = new MacAddressField();

        $this->assertFalse($field->isContainer());
        $this->assertFalse($field->isList());
        $field->setAsList('Y');
        $this->assertTrue($field->isList());
    }

    /**
     * test single MAC address canonicalization across various representations
     */
    public function testCanonicalizationSingle()
    {
        $representations = [
            'aa:bb:cc:dd:ee:ff',
            'AA:BB:CC:DD:EE:FF',
            'aabb.ccdd.eeff',
            'aa-bb-cc-dd-ee-ff',
            'AA-BB-CC-DD-EE-FF',
            'AABB.CCDD.EEFF',
            '  aa:bb:cc:dd:ee:ff  ',
        ];

        foreach ($representations as $repr) {
            $field = new MacAddressField();
            $field->setValue($repr);
            $this->assertEquals('aa:bb:cc:dd:ee:ff', (string)$field);
            $this->assertEmpty($this->validate($field));
        }
    }

    /**
     * test list MAC address duplicate detection across different notations
     */
    public function testListDuplicateDetection()
    {
        $field = new MacAddressField();
        $field->setAsList('Y');

        // All variations of the same address should collapse into a single canonical entry
        $duplicates = 'aa:bb:cc:dd:ee:ff,AA:BB:CC:DD:EE:FF,aabb.ccdd.eeff,aa-bb-cc-dd-ee-ff';
        $field->setValue($duplicates);

        $this->assertEquals('aa:bb:cc:dd:ee:ff', (string)$field);
        $this->assertEquals(1, count($field->getValues()));
        $this->assertEquals('aa:bb:cc:dd:ee:ff', $field->getValues()[0]);
        $this->assertEmpty($this->validate($field));
    }

    /**
     * test multiple distinct MAC addresses with duplicates in list mode
     */
    public function testListMultipleDistinctWithDuplicates()
    {
        $field = new MacAddressField();
        $field->setAsList('Y');

        $input = 'aa:bb:cc:dd:ee:ff, 00-11-22-33-44-55, aabb.ccdd.eeff, 1122.3344.5566, AA:BB:CC:DD:EE:FF';
        $field->setValue($input);

        $expectedValues = ['aa:bb:cc:dd:ee:ff', '00:11:22:33:44:55', '11:22:33:44:55:66'];
        $expectedString = implode(',', $expectedValues);

        $this->assertEquals($expectedString, (string)$field);
        $this->assertEquals(3, count($field->getValues()));
        $this->assertEquals($expectedValues, $field->getValues());
        $this->assertEmpty($this->validate($field));
    }

    /**
     * test empty and whitespace handling
     */
    public function testEmptyAndWhitespace()
    {
        $field = new MacAddressField();
        $this->assertTrue($field->isEmpty());
        $this->assertEquals('', (string)$field);

        $field->setValue('   ');
        $this->assertTrue($field->isEmpty());
        $this->assertEquals('', (string)$field);

        $field->setAsList('Y');
        $field->setValue('aa:bb:cc:dd:ee:ff,, ,AA:BB:CC:DD:EE:FF');
        $this->assertEquals('aa:bb:cc:dd:ee:ff', (string)$field);
        $this->assertEquals(1, count($field->getValues()));
    }

    /**
     * test setValues array method
     */
    public function testSetValues()
    {
        $field = new MacAddressField();
        $field->setAsList('Y');
        $field->setValues(['AA:BB:CC:DD:EE:FF', 'aa-bb-cc-dd-ee-ff', '11:22:33:44:55:66']);

        $this->assertEquals('aa:bb:cc:dd:ee:ff,11:22:33:44:55:66', (string)$field);
        $this->assertEquals(['aa:bb:cc:dd:ee:ff', '11:22:33:44:55:66'], $field->getValues());
        $this->assertEmpty($this->validate($field));
    }

    /**
     * test invalid MAC addresses
     */
    public function testInvalidValues()
    {
        $field = new MacAddressField();
        $field->setValue('not-a-mac');
        $this->assertNotEmpty($this->validate($field));

        $field->setAsList('Y');
        $field->setValue('aa:bb:cc:dd:ee:ff,invalid_entry');
        $this->assertNotEmpty($this->validate($field));
    }

    /**
     * test single MAC field with comma-separated input fails validation
     */
    public function testSingleFieldWithMultipleFails()
    {
        $field = new MacAddressField();
        $this->assertFalse($field->isList());
        $field->setValue('aa:bb:cc:dd:ee:ff,00:11:22:33:44:55');
        $this->assertNotEmpty($this->validate($field));
    }
}
