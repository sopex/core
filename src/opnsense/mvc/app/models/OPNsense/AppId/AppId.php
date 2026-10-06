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

namespace OPNsense\AppId;

use OPNsense\Base\BaseModel;
use OPNsense\Firewall\Filter;

/**
 * Application control (appidd) settings.
 *
 * Firewall rules matching applications are rendered as pass rules diverting candidate flows to appidd,
 * each rule owns a divert port so the daemon knows which rule matched without evaluating layer 3/4 itself.
 */
class AppId extends BaseModel
{
    /* first divert port, Intrusion Detection uses 8000 */
    const DIVERT_PORT_BASE = 8100;
    /* maximum number of firewall rules matching applications (size of the divert port range) */
    const MAX_RULES = 256;
    const PIDFILE = '/var/run/appidd.pid';
    const POLICY_FILE = '/usr/local/etc/appidd/policy.json';

    /**
     * @param Filter $rule firewall rule node
     * @return bool true when the rule matches on applications or application categories
     */
    public static function isApplicationRule($rule)
    {
        return !$rule->application->isEmpty() || !$rule->application_category->isEmpty();
    }

    /**
     * Collect enabled firewall rules matching applications in a deterministic order (sequence, uuid)
     * and assign a divert port to each of them. The firewall ruleset and the appidd policy are both
     * derived from this list, which keeps them in sync on every reload.
     * @param Filter|null $filter firewall filter model, a new instance is used when not provided
     * @return array policy entries indexed by rule uuid
     */
    public function getRulePolicy($filter = null)
    {
        $result = [];
        if ($this->general->enabled->isEmpty()) {
            return $result;
        }

        $candidates = [];
        foreach (($filter ?? new Filter())->rules->rule->iterateItems() as $uuid => $rule) {
            if (!$rule->enabled->isEmpty() && static::isApplicationRule($rule)) {
                $candidates[] = ['sequence' => $rule->sequence->asInt(), 'uuid' => $uuid, 'rule' => $rule];
            }
        }
        usort($candidates, function ($a, $b) {
            return [$a['sequence'], $a['uuid']] <=> [$b['sequence'], $b['uuid']];
        });

        foreach (array_slice($candidates, 0, static::MAX_RULES) as $idx => $candidate) {
            $rule = $candidate['rule'];
            $result[$candidate['uuid']] = [
                'port' => static::DIVERT_PORT_BASE + $idx,
                'applications' => $rule->application->getValues(),
                'categories' => $rule->application_category->getValues(),
                'negate' => !$rule->application_not->isEmpty(),
                'action' => $rule->action->getValue(),
                'otherwise' => $rule->application_otherwise->getValue(),
                'log' => !$rule->log->isEmpty(),
                'description' => $rule->description->getValue(),
            ];
        }

        return $result;
    }

    /**
     * @return array divert port per rule uuid
     */
    public function getRulePorts($filter = null)
    {
        return array_map(function ($entry) {
            return $entry['port'];
        }, $this->getRulePolicy($filter));
    }
}
