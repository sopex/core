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
use OPNsense\Base\FieldTypes\IStructuredInput;
use OPNsense\Base\Validators\CallbackValidator;

/**
 * Class ScheduleRangesField
 * @package OPNsense\Firewall\FieldTypes
 */
class ScheduleRangesField extends BaseField implements IStructuredInput
{
    protected $internalIsContainer = false;

    /**
     * Normalize range items into uniform structure
     * @param array $items
     * @return array
     */
    private function normalizeRanges(array $items): array
    {
        $normalized = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $position = $item['position'] ?? '';
            if (is_array($position)) {
                $position = implode(',', array_filter($position, fn($v) => $v !== ''));
            }
            $month = $item['month'] ?? '';
            if (is_array($month)) {
                $month = implode(',', array_filter($month, fn($v) => $v !== ''));
            }
            $day = $item['day'] ?? '';
            if (is_array($day)) {
                $day = implode(',', array_filter($day, fn($v) => $v !== ''));
            }
            $hour = trim((string)($item['hour'] ?? ''));
            $rangedescr = trim((string)($item['rangedescr'] ?? ''));

            $normalized[] = [
                'position' => trim((string)$position),
                'month' => trim((string)$month),
                'day' => trim((string)$day),
                'hour' => $hour,
                'rangedescr' => $rangedescr,
            ];
        }
        return $normalized;
    }

    public function setValue($value)
    {
        if (is_a($value, 'SimpleXMLElement')) {
            if (isset($value->timerange)) {
                $ranges = [];
                foreach ($value->timerange as $tr) {
                    $ranges[] = [
                        'position' => (string)($tr->position ?? ''),
                        'month' => (string)($tr->month ?? ''),
                        'day' => (string)($tr->day ?? ''),
                        'hour' => (string)($tr->hour ?? ''),
                        'rangedescr' => (string)($tr->rangedescr ?? ''),
                    ];
                }
                $this->internalValue = json_encode($this->normalizeRanges($ranges));
                return;
            }
            $value = (string)$value;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if (empty($trimmed)) {
                $this->internalValue = json_encode([]);
                return;
            }
            if (str_starts_with($trimmed, '[') || str_starts_with($trimmed, '{')) {
                $decoded = json_decode($trimmed, true);
                if (is_array($decoded)) {
                    $this->internalValue = json_encode($this->normalizeRanges($decoded));
                    return;
                }
            } else {
                $b64 = base64_decode($trimmed, true);
                if ($b64 !== false && (str_starts_with($b64, '[') || str_starts_with($b64, '{'))) {
                    $decoded = json_decode($b64, true);
                    if (is_array($decoded)) {
                        $this->internalValue = json_encode($this->normalizeRanges($decoded));
                        return;
                    }
                }
            }
            $this->internalValue = $value;
            return;
        }

        if (is_array($value)) {
            $this->internalValue = json_encode($this->normalizeRanges($value));
            return;
        }

        parent::setValue($value);
    }

    public function asArray(): array
    {
        if (empty($this->internalValue)) {
            return [];
        }
        $decoded = json_decode($this->internalValue, true);
        return is_array($decoded) ? $decoded : [];
    }

    public function getNodeData()
    {
        return $this->asArray();
    }

    public function getDescription()
    {
        $ranges = $this->asArray();
        if (empty($ranges)) {
            return '';
        }
        $dayNames = [
            1 => gettext('Mon'),
            2 => gettext('Tue'),
            3 => gettext('Wed'),
            4 => gettext('Thu'),
            5 => gettext('Fri'),
            6 => gettext('Sat'),
            7 => gettext('Sun')
        ];
        $monthNames = [
            1 => gettext('Jan'), 2 => gettext('Feb'), 3 => gettext('Mar'),
            4 => gettext('Apr'), 5 => gettext('May'), 6 => gettext('Jun'),
            7 => gettext('Jul'), 8 => gettext('Aug'), 9 => gettext('Sep'),
            10 => gettext('Oct'), 11 => gettext('Nov'), 12 => gettext('Dec')
        ];

        $lines = [];
        foreach ($ranges as $range) {
            $descParts = [];
            if (!empty($range['position'])) {
                $days = array_filter(explode(',', $range['position']));
                $labels = array_map(fn($d) => $dayNames[(int)$d] ?? $d, $days);
                $descParts[] = implode(', ', $labels);
            } elseif (!empty($range['month']) && !empty($range['day'])) {
                $months = explode(',', $range['month']);
                $days = explode(',', $range['day']);
                $dates = [];
                for ($i = 0; $i < min(count($months), count($days)); $i++) {
                    $m = (int)$months[$i];
                    $dates[] = ($monthNames[$m] ?? $m) . ' ' . $days[$i];
                }
                $descParts[] = implode(', ', $dates);
            }
            if (!empty($range['hour'])) {
                $descParts[] = $range['hour'];
            }
            if (!empty($range['rangedescr'])) {
                $descParts[] = '(' . $range['rangedescr'] . ')';
            }
            $lines[] = implode(' ', $descParts);
        }
        return implode('; ', $lines);
    }

    public function getValidators()
    {
        $validators = parent::getValidators();
        $validators[] = new CallbackValidator(
            [
                "callback" => function ($value) {
                    $ranges = $this->asArray();
                    $result = [];
                    if (empty($ranges)) {
                        $result[] = gettext('The schedule must have at least one time range configured.');
                        return $result;
                    }
                    foreach ($ranges as $idx => $range) {
                        $lineNum = $idx + 1;
                        $hasRepeating = !empty($range['position']);
                        $hasDates = !empty($range['month']) && !empty($range['day']);

                        if (!$hasRepeating && !$hasDates) {
                            $result[] = sprintf(
                                gettext('Range %d: specify either repeating days of the week or calendar dates.'),
                                $lineNum
                            );
                        }

                        if ($hasRepeating) {
                            $days = explode(',', $range['position']);
                            foreach ($days as $day) {
                                if (!in_array((int)$day, [1, 2, 3, 4, 5, 6, 7])) {
                                    $result[] = sprintf(
                                        gettext('Range %d: invalid day of week "%s".'),
                                        $lineNum,
                                        $day
                                    );
                                }
                            }
                        }

                        if ($hasDates) {
                            $months = explode(',', $range['month']);
                            $days = explode(',', $range['day']);
                            if (count($months) !== count($days)) {
                                $result[] = sprintf(
                                    gettext('Range %d: month and day counts do not match.'),
                                    $lineNum
                                );
                            }
                        }

                        $hour = $range['hour'] ?? '';
                        if (!preg_match('/^([0-9]{1,2}):([0-9]{2})-([0-9]{1,2}):([0-9]{2})$/', $hour, $matches)) {
                            $result[] = sprintf(
                                gettext('Range %d: invalid time format "%s". Expected HH:MM-HH:MM.'),
                                $lineNum,
                                $hour
                            );
                        } else {
                            $startMinutes = ((int)$matches[1] * 60) + (int)$matches[2];
                            $stopMinutes = ((int)$matches[3] * 60) + (int)$matches[4];
                            if ($startMinutes > $stopMinutes) {
                                $result[] = sprintf(
                                    gettext('Range %d: start time cannot be greater than stop time.'),
                                    $lineNum
                                );
                            }
                        }
                    }
                    return $result;
                }
            ]
        );
        return $validators;
    }
}
