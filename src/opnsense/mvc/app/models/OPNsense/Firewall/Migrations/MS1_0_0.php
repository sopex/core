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
use OPNsense\Core\Syslog;
use OPNsense\Firewall\FieldTypes\ScheduleNameField;
use OPNsense\Firewall\FieldTypes\ScheduleRangesField;
use OPNsense\Firewall\Schedule;

class MS1_0_0 extends BaseModelMigration
{
    /* names of legacy schedules converted into the model */
    private $converted = [];

    /**
     * Normalize a legacy time range. Returns null for ranges the legacy filter code
     * could never match, so dropping them does not change rule behaviour.
     * @param array $range
     * @return array|null
     */
    private function normalizeRange(array $range): ?array
    {
        $hour = trim($range['hour']);
        if ($hour === '') {
            /* legacy: no time set allows the whole day */
            $hour = '0:00-24:00';
        } elseif (preg_match('/^([0-9]{1,2}):([0-9]{1,2})-([0-9]{1,2}):([0-9]{1,2})$/', $hour, $matches)) {
            $hour = sprintf('%d:%02d-%d:%02d', $matches[1], $matches[2], $matches[3], $matches[4]);
        }
        $minutes = ScheduleRangesField::parseHour($hour);
        if ($minutes === null || $minutes[0] >= $minutes[1]) {
            return null;
        }

        $result = ['position' => '', 'month' => '', 'day' => '', 'hour' => $hour, 'rangedescr' => $range['rangedescr']];

        if (trim($range['position']) !== '') {
            /* weekdays take precedence over dates in the legacy filter code */
            $days = array_filter(
                array_map('trim', explode(',', $range['position'])),
                fn($day) => ctype_digit($day) && (int)$day >= 1 && (int)$day <= 7
            );
            if (empty($days)) {
                return null;
            }
            $result['position'] = implode(',', array_map('intval', $days));
            return $result;
        }

        $months = array_map('trim', explode(',', $range['month']));
        $days = array_map('trim', explode(',', $range['day']));
        if (count($months) != count($days)) {
            return null;
        }
        $valid_months = [];
        $valid_days = [];
        foreach ($months as $i => $month) {
            if (ctype_digit($month) && ctype_digit($days[$i]) && checkdate((int)$month, (int)$days[$i], 2000)) {
                $valid_months[] = (int)$month;
                $valid_days[] = (int)$days[$i];
            }
        }
        if (empty($valid_months)) {
            return null;
        }
        $result['month'] = implode(',', $valid_months);
        $result['day'] = implode(',', $valid_days);
        return $result;
    }

    public function run($model)
    {
        parent::run($model);

        if (!($model instanceof Schedule)) {
            return;
        }

        $config = Config::getInstance()->object();
        if (!isset($config->schedules->schedule)) {
            return;
        }

        $logger = new Syslog('config', null, LOG_LOCAL2);

        foreach ($config->schedules->schedule as $legacy_sched) {
            $name = (string)$legacy_sched->name;
            if (isset($this->converted[$name])) {
                /* duplicate name, the legacy filter code only used the first one */
                continue;
            }

            $ranges = [];
            foreach (Schedule::legacyRanges($legacy_sched) as $range) {
                $range = $this->normalizeRange($range);
                if ($range !== null) {
                    $ranges[] = $range;
                }
            }

            $errors = array_merge(ScheduleNameField::validateName($name), ScheduleRangesField::validateRanges($ranges));
            if (!empty($errors)) {
                /* leave it in the legacy section, Schedule::getAllSchedules() keeps using it */
                $logger->warning(sprintf(
                    'schedule "%s" not migrated: %s',
                    $name,
                    implode(' ', $errors)
                ));
                continue;
            }

            $node = $model->schedules->schedule->Add();
            $node->name = $name;
            /* DescriptionField: single line, no control characters, max 255 bytes */
            $descr = preg_replace('/[\r\n\0\v\f]+/', ' ', trim((string)$legacy_sched->descr));
            $node->descr = mb_strcut($descr, 0, 255, 'UTF-8');
            $node->timeranges->setValue($ranges);
            $this->converted[$name] = true;
        }
    }

    public function post($model)
    {
        if (!($model instanceof Schedule)) {
            return;
        }

        $config = Config::getInstance()->object();
        if (!isset($config->schedules->schedule)) {
            return;
        }

        /* only remove what was converted, failed entries stay in place */
        $remove = [];
        foreach ($config->schedules->schedule as $legacy_sched) {
            if (isset($this->converted[(string)$legacy_sched->name])) {
                $remove[] = dom_import_simplexml($legacy_sched);
            }
        }
        foreach ($remove as $node) {
            $node->parentNode->removeChild($node);
        }

        if (count($config->schedules->children()) == 0) {
            unset($config->schedules);
        }
    }
}
