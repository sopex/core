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

namespace OPNsense\Core\Api;

use OPNsense\Base\ApiControllerBase;
use OPNsense\Core\Backend;

/**
 * Configuration protection, reverts saved changes which are not confirmed in time.
 * State and timer are owned by the backend (config_protect.php), so a revert
 * still happens when the client lost access to the GUI.
 * @package OPNsense\Core\Api
 */
class ConfigProtectController extends ApiControllerBase
{
    /**
     * @param string $action configd protect action
     * @return array protection status
     */
    private function protectAction($action)
    {
        if ($action != 'status') {
            $this->throwReadOnly();
            if (!$this->request->isPost()) {
                return ['status' => 'failed'];
            }
        }
        $response = json_decode((new Backend())->configdRun("system protect {$action}"), true);
        return is_array($response) ? $response : ['status' => 'failed'];
    }

    /**
     * @return array protection status
     */
    public function statusAction()
    {
        return $this->protectAction('status');
    }

    /**
     * enable protection, the current configuration is accepted as baseline
     * @return array protection status
     */
    public function enableAction()
    {
        return $this->protectAction('enable');
    }

    /**
     * disable protection, pending changes are kept
     * @return array protection status
     */
    public function disableAction()
    {
        return $this->protectAction('disable');
    }

    /**
     * accept pending changes as new baseline
     * @return array protection status
     */
    public function confirmAction()
    {
        return $this->protectAction('confirm');
    }

    /**
     * postpone the revert of pending changes
     * @return array protection status
     */
    public function extendAction()
    {
        return $this->protectAction('extend');
    }

    /**
     * revert pending changes now instead of waiting for the timer
     * @return array protection status
     */
    public function revertAction()
    {
        return $this->protectAction('revert');
    }
}
