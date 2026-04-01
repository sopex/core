<?php

/*
 * Copyright (C) 2024 Deciso B.V.
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

namespace OPNsense\Auth\Api;

require_once("auth.inc");
require_once("interfaces.inc");

use OPNsense\Base\ApiControllerBase;
use OPNsense\Auth\AuthenticationFactory;
use OPNsense\Core\ACL;
use OPNsense\Core\Config;

class TesterController extends ApiControllerBase
{
    public function getSettingsAction()
    {
        $result = ['tester' => ['authmode' => []]];
        foreach (auth_get_authserver_list() as $auth_server_id => $auth_server) {
            $result['tester']['authmode'][$auth_server_id] = [
                'value' => htmlspecialchars($auth_server['name']),
                'selected' => 0
            ];
        }
        return $result;
    }

    public function testAction()
    {
        $result = ['status' => 'failed'];
        if ($this->request->isPost()) {
            $postInfo = $this->request->getPost('tester', []);
            $authmode = !empty($postInfo['authmode']) ? $postInfo['authmode'] : '';
            $username = !empty($postInfo['username']) ? $postInfo['username'] : '';
            $password = !empty($postInfo['password']) ? $postInfo['password'] : '';

            $authcfg = auth_get_authserver($authmode);
            if (!$authcfg) {
                $result['errors'] = ['authmode' => gettext("Invalid authentication server")];
                return $result;
            }
            if (empty($username) || empty($password)) {
                $result['errors'] = ['credentials' => gettext("A username and password must be specified.")];
                return $result;
            }

            $authName = $authcfg['name'];
            if ($authcfg['type'] == 'local') {
                $authName = 'Local Database';
            }

            $authFactory = new AuthenticationFactory();
            $authenticator = $authFactory->get($authName);

            if ($authenticator->authenticate($username, $password)) {
                $result['status'] = 'ok';
                $result['message'] = gettext("User") . ": " . $username . " " . gettext("authenticated successfully.");
                
                Config::getInstance()->forceReload();
                
                $result['groups'] = getUserGroups($authenticator->getUserName($username));
                $result['privileges'] = (new ACL())->userUrlMasks($username);
                
                $attributes = [];
                foreach ($authenticator->getLastAuthProperties() as $attr_name => $attr_value) {
                    if (is_array($attr_value)) {
                        $attr_value = implode(",", $attr_value);
                    }
                    $attributes[$attr_name] = htmlspecialchars($attr_value);
                }
                $result['attributes'] = $attributes;
            } else {
                $errors = [];
                foreach ($authenticator->getLastAuthErrors() as $err_name => $err_value) {
                    if (is_array($err_value)) {
                        $err_value = implode(",", $err_value);
                    }
                    $errors[$err_name] = $err_value;
                }
                // Fallback if no specific errors were returned
                if (empty($errors)) {
                    $errors['authentication'] = gettext("Authentication failed.");
                }
                $result['errors'] = $errors;
            }
        }
        return $result;
    }
}
