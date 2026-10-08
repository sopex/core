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

namespace OPNsense\Firewall\Migrations;

use OPNsense\Base\BaseModelMigration;
use OPNsense\Core\Config;
use OPNsense\Firewall\Schedule;

class MS1_0_0 extends BaseModelMigration
{
    public function run($model)
    {
        if ($model instanceof Schedule) {
            $config = Config::getInstance()->object();
            if (!empty($config->schedules) && !empty($config->schedules->schedule)) {
                foreach ($config->schedules->schedule as $legacy_sched) {
                    $name = (string)$legacy_sched->name;
                    if (empty($name)) {
                        continue;
                    }
                    // Guard against duplicating entries on rerun
                    $node = null;
                    foreach ($model->schedules->schedule->iterateItems() as $item) {
                        if ((string)$item->name === $name) {
                            $node = $item;
                            break;
                        }
                    }
                    if ($node === null) {
                        $node = $model->schedules->schedule->Add();
                    }
                    $node->enabled = '1';
                    $node->name = $name;
                    $node->descr = (string)($legacy_sched->descr ?? '');

                    $ranges = [];
                    if (!empty($legacy_sched->timerange)) {
                        foreach ($legacy_sched->timerange as $tr) {
                            $ranges[] = [
                                'position' => (string)($tr->position ?? ''),
                                'month' => (string)($tr->month ?? ''),
                                'day' => (string)($tr->day ?? ''),
                                'hour' => (string)($tr->hour ?? ''),
                                'rangedescr' => (string)($tr->rangedescr ?? ''),
                            ];
                        }
                    }
                    $node->timeranges->setValue($ranges);
                }
            }
        }

        parent::run($model);
    }

    public function post($model)
    {
        if ($model instanceof Schedule) {
            $config = Config::getInstance()->object();
            unset($config->schedules);
        }
    }
}
