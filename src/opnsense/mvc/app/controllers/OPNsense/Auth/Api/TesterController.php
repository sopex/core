<?php

/*
 * Copyright (C) 2026 Konstantinos Spartalis (cspartalis@potatonetworks.com)
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

use OPNsense\Base\ApiControllerBase;
use OPNsense\Auth\AuthenticationFactory;
use OPNsense\Core\ACL;
use OPNsense\Core\Config;

/**
 * Class TesterController
 * @package OPNsense\Auth\Api
 */
class TesterController extends ApiControllerBase
{
    /**
     * Get settings for the tester form
     * @return array
     */
    public function getSettingsAction()
    {
        $result = ['tester' => ['authmode' => []]];
        $authFactory = new AuthenticationFactory();
        foreach ($authFactory->listServers() as $auth_server_name => $auth_server) {
            $result['tester']['authmode'][$auth_server_name] = [
                'value' => $auth_server['name'],
                'selected' => 0
            ];
        }
        return $result;
    }

    /**
     * Run authentication test
     * @return array
     */
    public function testAction()
    {
        $result = ['status' => 'failed'];
        if ($this->request->isPost()) {
            $postInfo = $this->request->getPost('tester') ?? [];
            $authmode = !empty($postInfo['authmode']) ? $postInfo['authmode'] : '';
            $username = !empty($postInfo['username']) ? $postInfo['username'] : '';
            $password = !empty($postInfo['password']) ? $postInfo['password'] : '';

            $authFactory = new AuthenticationFactory();
            $authServers = $authFactory->listServers();

            if (!isset($authServers[$authmode])) {
                $result['errors'] = ['authmode' => gettext("Invalid authentication server")];
                return $result;
            }
            if (empty($username) || empty($password)) {
                $result['errors'] = ['credentials' => gettext("A username and password must be specified.")];
                return $result;
            }

            $authcfg = $authServers[$authmode];
            $authName = $authcfg['name'];
            if ($authcfg['type'] == 'local') {
                $authName = 'Local Database';
            }

            /** @var \OPNsense\Auth\Base $authenticator */
            $authenticator = $authFactory->get($authName);

            if ($authenticator->authenticate($username, $password)) {
                $result['status'] = 'ok';
                $result['message'] = gettext("User") . ": " . $username . " " . gettext("authenticated successfully.");

                // Config may be updated during LDAP group sync, reload before proceeding
                Config::getInstance()->forceReload();

                $result['groups'] = $this->getUserGroups($authenticator->getUserName($username));
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
                // Fallback
                if (empty($errors)) {
                    $errors['authentication'] = gettext("Authentication failed.");
                }
                $result['errors'] = $errors;
            }
        }
        return $result;
    }

    /**
     * Helper to statically extract groups specific to a user from config since legacy
     * function local_user_get_groups() was left in auth.inc
     * @param string $username internal username
     * @return array
     */
    private function getUserGroups($username)
    {
        $member_groups = [];
        $userUID = null;
        $configObj = Config::getInstance()->object();

        if (isset($configObj->system->user)) {
            foreach ($configObj->system->user as $userNode) {
                if ((string) $userNode->name === $username) {
                    $userUID = (string) $userNode->uid;
                    break;
                }
            }
        }

        if ($userUID !== null && isset($configObj->system->group)) {
            foreach ($configObj->system->group as $groupNode) {
                if (!empty($groupNode->member)) {
                    $members = explode(',', (string) $groupNode->member);
                    if (in_array($userUID, $members)) {
                        $member_groups[] = (string) $groupNode->name;
                    }
                }
            }
        }

        return $member_groups;
    }
}
