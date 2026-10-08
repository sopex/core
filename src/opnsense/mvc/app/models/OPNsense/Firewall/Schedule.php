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

namespace OPNsense\Firewall;

use OPNsense\Base\BaseModel;
use OPNsense\Core\Config;

/**
 * Class Schedule
 * @package OPNsense\Firewall
 */
class Schedule extends BaseModel
{
    /**
     * Iterate over filter rules that can reference a schedule
     * @return \Generator
     */
    private function ruleIterator()
    {
        $sources = [
            ['filter', 'rule'],
            ['OPNsense', 'Firewall', 'Filter', 'rules', 'rule'],
        ];

        foreach ($sources as $path) {
            $section = Config::getInstance()->object();
            foreach ($path as $tag) {
                if ($section !== null) {
                    $section = $section->$tag ?? null;
                }
            }
            if ($section !== null) {
                $idx = 0;
                foreach ($section as $node) {
                    $attrs = $node->attributes();
                    if (!empty($attrs) && !empty($attrs['uuid'])) {
                        yield sprintf('%s.%s', implode('.', $path), (string)$attrs['uuid']) => $node;
                    } else {
                        yield sprintf('%s.%d', implode('.', $path), $idx) => $node;
                    }
                    $idx++;
                }
            }
        }
    }

    /**
     * Find where a schedule is used by firewall rules
     * @param string $name
     * @return array
     */
    public function whereUsed($name)
    {
        $used = [];
        if (empty($name)) {
            return $used;
        }

        foreach ($this->ruleIterator() as $key => $node) {
            if (!empty($node->sched) && (string)$node->sched === $name) {
                $descr = !empty($node->descr) ? (string)$node->descr : (string)$node->description ?? '';
                $used[$key] = !empty($descr) ? $descr : gettext('Firewall Rule');
            }
        }

        return $used;
    }

    /**
     * Refactor schedule name across firewall rules
     * @param string $oldname
     * @param string $newname
     * @return bool
     */
    public function refactor($oldname, $newname)
    {
        $hasChanged = false;
        foreach ($this->ruleIterator() as $node) {
            if (!empty($node->sched) && (string)$node->sched === $oldname) {
                $node->sched = $newname;
                $hasChanged = true;
            }
        }
        return $hasChanged;
    }

    /**
     * Determine if a schedule configuration is active right now
     * @param array $schedule schedule array containing 'timerange'
     * @return bool
     */
    public static function isTimeBasedRuleActive(array $schedule): bool
    {
        if (empty($schedule) || empty($schedule['timerange'])) {
            return true;
        }

        $now = time();
        $thisWeekday = (int)date('w') === 0 ? 7 : (int)date('w');
        $today = date('dm');

        foreach ($schedule['timerange'] as $timeday) {
            $matchedTime = true;
            if (!empty($timeday['hour'])) {
                $parts = explode('-', $timeday['hour']);
                if (count($parts) === 2) {
                    $start = strtotime($parts[0]);
                    $stop = strtotime($parts[1]);
                    $matchedTime = ($now >= $start && $now < $stop);
                }
            }
            if ($matchedTime) {
                if (!empty($timeday['position'])) {
                    foreach (explode(',', $timeday['position']) as $day) {
                        if ((int)$day === $thisWeekday) {
                            return true;
                        }
                    }
                } else {
                    $months = explode(',', $timeday['month'] ?? '');
                    $days = explode(',', $timeday['day'] ?? '');
                    if (empty($months) || empty($days) || count($days) !== count($months)) {
                        continue;
                    }
                    for ($i = 0; $i < count($days); ++$i) {
                        if (sprintf('%02d%02d', (int)$days[$i], (int)$months[$i]) === $today) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    /**
     * Check if a specific schedule is active by name
     * @param string $name
     * @return bool
     */
    public function isScheduleActive(string $name): bool
    {
        foreach ($this->schedules->schedule->iterateItems() as $item) {
            if ((string)$item->name === $name) {
                if (empty((string)$item->enabled)) {
                    return false;
                }
                return self::isTimeBasedRuleActive([
                    'timerange' => $item->timeranges->asArray()
                ]);
            }
        }
        return false;
    }

    /**
     * Retrieve all schedules in a structured format for filter configuration,
     * with fallback to legacy config if model is unpopulated.
     * @return array
     */
    public static function getAllSchedules(): array
    {
        $schedules = [];
        $mdl = new static();
        foreach ($mdl->schedules->schedule->iterateItems() as $item) {
            if (!empty((string)$item->enabled)) {
                $name = (string)$item->name;
                $schedules[$name] = [
                    'name' => $name,
                    'descr' => (string)$item->descr,
                    'timerange' => $item->timeranges->asArray(),
                ];
            }
        }

        // Fallback to legacy config if model is empty (e.g. before migration runs)
        if (empty($schedules)) {
            $legacy = Config::getInstance()->object();
            if (!empty($legacy->schedules->schedule)) {
                foreach ($legacy->schedules->schedule as $item) {
                    $name = (string)$item->name;
                    $ranges = [];
                    if (!empty($item->timerange)) {
                        foreach ($item->timerange as $tr) {
                            $ranges[] = [
                                'position' => (string)($tr->position ?? ''),
                                'month' => (string)($tr->month ?? ''),
                                'day' => (string)($tr->day ?? ''),
                                'hour' => (string)($tr->hour ?? ''),
                                'rangedescr' => (string)($tr->rangedescr ?? ''),
                            ];
                        }
                    }
                    $schedules[$name] = [
                        'name' => $name,
                        'descr' => (string)($item->descr ?? ''),
                        'timerange' => $ranges,
                    ];
                }
            }
        }

        return $schedules;
    }
}
