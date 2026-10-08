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

namespace OPNsense\Firewall\FieldTypes;

use OPNsense\Base\FieldTypes\BaseField;
use OPNsense\Base\Validators\CallbackValidator;

/**
 * Class ScheduleNameField
 * @package OPNsense\Firewall\FieldTypes
 */
class ScheduleNameField extends BaseField
{
    protected $internalIsContainer = false;

    public function getValidators()
    {
        $validators = parent::getValidators();
        $validators[] = new CallbackValidator(
            [
                "callback" => function ($value) {
                    $result = [];
                    if (empty($value)) {
                        $result[] = gettext('Schedule may not use a blank name.');
                        return $result;
                    }
                    if (in_array(strtolower($value), ['lan', 'wan'])) {
                        $result[] = sprintf(gettext('Schedule may not be named %s.'), strtoupper($value));
                    }
                    if (!preg_match('/^[a-zA-Z0-9_\-]{1,32}$/', $value)) {
                        $result[] = sprintf(
                            gettext('The schedule name must be less than 32 characters long and may only consist of the following characters: %s'),
                            'a-z, A-Z, 0-9, _, -'
                        );
                    }
                    return $result;
                }
            ]
        );
        return $validators;
    }
}
