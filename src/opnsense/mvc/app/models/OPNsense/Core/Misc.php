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

namespace OPNsense\Core;

use OPNsense\Base\BaseModel;
use OPNsense\Base\Messages\Message;
use OPNsense\Core\Config;

class Misc extends BaseModel
{
    /**
     * Populate options dynamically
     */
    protected function init()
    {
        parent::init();

        $cryptoModules = [
            'hifn' => gettext('Hifn 7751/7951/7811/7955/7956 Crypto Accelerator'),
            'padlock' => gettext('Crypto and RNG in VIA C3, C7 and Eden Processors'),
            'qat' => gettext('Intel QuickAssist Technology'),
            'safe' => gettext('SafeNet Crypto Accelerator'),
        ];
        $this->crypto_hardware->setOptionValues($cryptoModules);

        $thermalModules = [
            'none' => gettext('None/ACPI'),
            'amdtemp' => gettext('AMD K8, K10 and K11 CPU on-die thermal sensor'),
            'coretemp' => gettext('Intel Core* CPU on-die thermal sensor'),
        ];
        $this->thermal_hardware->setOptionValues($thermalModules);
    }

    /**
     * Deep validation matching system_advanced_misc.php
     * @param bool $validateFullModel
     * @return \OPNsense\Base\Validation\Group
     */
    public function performValidation($validateFullModel = false)
    {
        $messages = parent::performValidation($validateFullModel);

        $maxVar = (string)$this->max_mfs_var;
        if ($maxVar !== '') {
            if (!is_numeric($maxVar) || (int)$maxVar < 0 || (int)$maxVar > 100) {
                $messages->appendMessage(new Message(
                    gettext('Memory usage percentage out of bounds.'),
                    'max_mfs_var'
                ));
            }
        }

        $maxTmp = (string)$this->max_mfs_tmp;
        if ($maxTmp !== '') {
            if (!is_numeric($maxTmp) || (int)$maxTmp < 0 || (int)$maxTmp > 100) {
                $messages->appendMessage(new Message(
                    gettext('Memory usage percentage out of bounds.'),
                    'max_mfs_tmp'
                ));
            }
        }

        return $messages;
    }

    /**
     * Backward-compatibility synchronizer: writes model state to legacy $config['system']
     */
    public function syncToLegacyConfig()
    {
        $config = Config::getInstance()->object();
        if (!isset($config->system)) {
            $config->addChild('system');
        }

        if ((string)$this->powerd_enable === '1') {
            $config->system->powerd_enable = 'true';
        } else {
            unset($config->system->powerd_enable);
        }

        $config->system->powerd_ac_mode = (string)$this->powerd_ac_mode;
        $config->system->powerd_battery_mode = (string)$this->powerd_battery_mode;
        $config->system->powerd_normal_mode = (string)$this->powerd_normal_mode;

        if (!empty((string)$this->crypto_hardware)) {
            $config->system->crypto_hardware = (string)$this->crypto_hardware;
        } else {
            unset($config->system->crypto_hardware);
        }

        $thermal = (string)$this->thermal_hardware;
        if (!empty($thermal) && $thermal !== 'none') {
            $config->system->thermal_hardware = $thermal;
        } else {
            unset($config->system->thermal_hardware);
        }

        if ((string)$this->use_mfs_var === '1') {
            $config->system->use_mfs_var = 'true';
        } else {
            unset($config->system->use_mfs_var);
        }

        if ((string)$this->max_mfs_var !== '') {
            $config->system->max_mfs_var = (string)$this->max_mfs_var;
        } else {
            unset($config->system->max_mfs_var);
        }

        if ((string)$this->use_mfs_tmp === '1') {
            $config->system->use_mfs_tmp = 'true';
        } else {
            unset($config->system->use_mfs_tmp);
        }

        if ((string)$this->max_mfs_tmp !== '') {
            $config->system->max_mfs_tmp = (string)$this->max_mfs_tmp;
        } else {
            unset($config->system->max_mfs_tmp);
        }

        if ((string)$this->use_swap_file === '1') {
            $config->system->use_swap_file = '2048';
        } else {
            unset($config->system->use_swap_file);
        }

        if ((string)$this->disablebeep === '1') {
            $config->system->disablebeep = 'true';
        } else {
            unset($config->system->disablebeep);
        }
    }

    /**
     * Override serializeToConfig to persist legacy sync
     */
    public function serializeToConfig($validateFullModel = false, $disable_validation = false)
    {
        $result = parent::serializeToConfig($validateFullModel, $disable_validation);
        if ($result) {
            $this->syncToLegacyConfig();
        }
        return $result;
    }
}
