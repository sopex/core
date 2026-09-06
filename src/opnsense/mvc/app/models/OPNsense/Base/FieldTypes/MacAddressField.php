<?php

/*
 * Copyright (C) 2023 Deciso B.V.
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

namespace OPNsense\Base\FieldTypes;

use OPNsense\Base\Validators\CallbackValidator;

/**
 * Class MacAddressField
 */
class MacAddressField extends BaseSetField
{
    /**
     * Canonicalize MAC address to lowercase, colon-separated notation
     * @param string $address
     * @return string
     */
    protected function canonicalize(string $address): string
    {
        if (filter_var($address, FILTER_VALIDATE_MAC)) {
            $hex = strtolower(str_replace([':', '-', '.'], '', $address));
            return implode(':', str_split($hex, 2));
        }
        return $address;
    }

    /**
     * trim, canonicalize and deduplicate MAC addresses
     * @param string $value
     */
    public function setValue($value)
    {
        $result = [];
        foreach ($this->iterateInput(trim((string)$value)) as $address) {
            $address = trim($address);
            if ($address === '') {
                continue;
            }
            $address = $this->canonicalize($address);
            if (!in_array($address, $result, true)) {
                $result[] = $address;
            }
        }
        parent::setValue(implode($this->internalFieldSeparator, $result));
    }

    /**
     * {@inheritdoc}
     */
    public function setAsList($value)
    {
        parent::setAsList($value);
        if (!empty($this->internalValue)) {
            $this->setValue($this->internalValue);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function setFieldSeparator($value)
    {
        parent::setFieldSeparator($value);
        if (!empty($this->internalValue)) {
            $this->setValue($this->internalValue);
        }
    }

   /**
     * {@inheritdoc}
     */
    protected function defaultValidationMessage()
    {
        return gettext('Invalid MAC address.');
    }

    /**
     * {@inheritdoc}
     */
    public function getValidators()
    {
        $validators = parent::getValidators();
        if ($this->internalValue != null) {
            $validators[] = new CallbackValidator(["callback" => function ($data) {
                foreach ($this->iterateInput($data) as $address) {
                    if (empty(filter_var($address, FILTER_VALIDATE_MAC))) {
                        return [$this->getValidationMessage()];
                    }
                }
                return [];
            }
            ]);
        }
        return $validators;
    }
}
