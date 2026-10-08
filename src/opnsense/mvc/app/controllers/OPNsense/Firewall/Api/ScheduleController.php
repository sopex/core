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

namespace OPNsense\Firewall\Api;

use OPNsense\Base\ApiMutableModelControllerBase;
use OPNsense\Base\UserException;
use OPNsense\Core\Backend;
use OPNsense\Core\Config;
use OPNsense\Firewall\Schedule;

/**
 * Class ScheduleController
 * @package OPNsense\Firewall\Api
 */
class ScheduleController extends ApiMutableModelControllerBase
{
    protected static $internalModelName = 'schedule';
    protected static $internalModelClass = 'OPNsense\Firewall\Schedule';

    /**
     * Search schedules
     * @return array
     */
    public function searchItemAction()
    {
        $result = $this->searchBase('schedules.schedule', null, 'name');
        $model = $this->getModel();
        foreach ($result['rows'] as &$record) {
            $node = $model->getNodeByReference('schedules.schedule.' . $record['uuid']);
            $ranges = $node != null ? $node->timeranges->asArray() : [];
            $isActive = !empty((string)$record['enabled']) && Schedule::isTimeBasedRuleActive(['timerange' => $ranges]);
            $record['status'] = $isActive ? '1' : '0';
            $record['timeranges_text'] = $node != null ? $node->timeranges->getDescription() : '';
        }
        return $result;
    }

    /**
     * Get schedule details or new defaults
     * @param string|null $uuid
     * @return array
     */
    public function getItemAction($uuid = null)
    {
        return $this->getBase('schedule', 'schedules.schedule', $uuid);
    }

    /**
     * Add new schedule
     * @return array
     */
    public function addItemAction()
    {
        return $this->addBase('schedule', 'schedules.schedule');
    }

    /**
     * Update schedule and cascade renames if schedule is in use
     * @param string $uuid
     * @return array
     */
    public function setItemAction($uuid)
    {
        Config::getInstance()->lock();
        $node = $this->getModel()->getNodeByReference('schedules.schedule.' . $uuid);
        $oldName = $node !== null ? (string)$node->name : null;

        if ($oldName !== null && $this->request->isPost() && $this->request->hasPost('schedule')) {
            $postData = $this->request->getPost('schedule');
            $newName = $postData['name'] ?? null;
            if (!empty($newName) && $newName !== $oldName) {
                $this->getModel()->refactor($oldName, $newName);
            }
        }

        return $this->setBase('schedule', 'schedules.schedule', $uuid);
    }

    /**
     * Delete schedule by uuid, verifying it is not in use by any rules
     * @param string $uuid
     * @return array
     * @throws UserException
     */
    public function delItemAction($uuid)
    {
        Config::getInstance()->lock();
        $node = $this->getModel()->getNodeByReference('schedules.schedule.' . $uuid);
        $name = $node !== null ? (string)$node->name : null;

        if ($name !== null) {
            $uses = $this->getModel()->whereUsed($name);
            if (!empty($uses)) {
                $details = [];
                foreach ($uses as $ref => $ruleDescr) {
                    $details[] = sprintf('[%s] %s', $ref, $ruleDescr);
                }
                $message = sprintf(
                    gettext('Cannot delete schedule "%s". Currently in use by: %s'),
                    $name,
                    implode(', ', $details)
                );
                throw new UserException($message, gettext('Schedule in use'));
            }
        }

        return $this->delBase('schedules.schedule', $uuid);
    }

    /**
     * Toggle schedule enabled state
     * @param string $uuid
     * @param string|null $enabled
     * @return array
     */
    public function toggleItemAction($uuid, $enabled = null)
    {
        return $this->toggleBase('schedules.schedule', $uuid, $enabled);
    }

    /**
     * Reload filter rules for schedule changes
     * @return array
     */
    public function reconfigureAction()
    {
        $result = ['status' => 'failed'];
        if ($this->request->isPost()) {
            (new Backend())->configdRun('filter reload skip_alias');
            $result = ['status' => 'ok'];
        }
        return $result;
    }
}
