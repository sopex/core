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

namespace OPNsense\AppId\Api;

use OPNsense\Base\ApiMutableServiceControllerBase;
use OPNsense\Core\Backend;

class ServiceController extends ApiMutableServiceControllerBase
{
    protected static $internalServiceClass = '\OPNsense\AppId\AppId';
    protected static $internalServiceTemplate = 'OPNsense/AppId';
    protected static $internalServiceEnabled = 'general.enabled';
    protected static $internalServiceName = 'appid';

    /**
     * Reconfigure the daemon and reload the firewall afterwards, rules matching applications are only
     * diverted when application control is enabled (and running when failing open).
     */
    public function reconfigureAction()
    {
        $result = parent::reconfigureAction();
        if ($this->request->isPost()) {
            /* custom applications may have changed, invalidate the option list caches */
            @unlink('/tmp/appid_applications.json');
            @unlink('/tmp/appid_categories.json');
            (new Backend())->configdRun('filter reload skip_alias');
        }
        return $result;
    }

    /**
     * fetch and install a signed database update
     */
    public function updateAction()
    {
        if ($this->request->isPost()) {
            $response = json_decode((new Backend())->configdRun('appid update', false, 300), true);
            return is_array($response) ? $response : ['status' => 'error', 'message' => gettext('Update failed.')];
        }
        return ['status' => 'failed'];
    }
}
