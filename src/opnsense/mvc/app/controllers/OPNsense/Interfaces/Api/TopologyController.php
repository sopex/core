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

namespace OPNsense\Interfaces\Api;

use OPNsense\Base\ApiMutableModelControllerBase;
use OPNsense\Core\Backend;
use OPNsense\Core\Config;
use OPNsense\Firewall\Alias;

class TopologyController extends ApiMutableModelControllerBase
{
    protected static $internalModelName = 'neighbor';
    protected static $internalModelClass = 'OPNsense\Interfaces\Neighbor';

    /**
     * Normalize and validate an Ethernet MAC address.
     *
     * @param string $mac
     * @return string|null
     */
    private function normalizeMac($mac)
    {
        $clean = str_replace('-', ':', strtolower(trim((string)$mac)));
        if (preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/', $clean)) {
            return $clean;
        }
        return null;
    }

    /**
     * Find or create a <client> override record by MAC address.
     *
     * @param string $mac
     * @param bool $create
     * @return mixed|null
     */
    private function findOrCreateClient($mac, $create = true)
    {
        $mdl = $this->getModel();
        foreach ($mdl->client->iterateItems() as $node) {
            if ($node->getInternalIsVirtual()) {
                continue;
            }
            if ($this->normalizeMac((string)$node->etheraddr) === $mac) {
                return $node;
            }
        }
        if (!$create) {
            return null;
        }
        $node = $mdl->client->Add();
        $node->etheraddr = $mac;
        $node->quarantined = '0';
        return $node;
    }

    /**
     * Retrieve correlated L2/L3 network topology and client list.
     *
     * @return array
     */
    public function listAction()
    {
        $flush = !empty($this->request->get('refresh')) || !empty($this->request->get('flush'));
        $cmd = ($flush ? '!' : '') . 'interface topology list';
        $raw = (new Backend())->configdRun($cmd);
        $decoded = json_decode((string)$raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        return [
            'status' => 'failed',
            'interfaces' => [],
            'switches' => [],
            'clients' => [],
            'dhcp_subnets' => ['kea' => [], 'dnsmasq' => []],
            'mac_aliases' => [],
        ];
    }

    /**
     * Alias for listAction().
     *
     * @return array
     */
    public function getAction()
    {
        return $this->listAction();
    }

    /**
     * Toggle or set persistent client quarantine state and immediately enforce via pfctl.
     *
     * @return array
     */
    public function quarantineAction()
    {
        $result = ['result' => 'failed'];
        if (!$this->request->isPost()) {
            return $result;
        }

        $mac = $this->normalizeMac($this->request->getPost('mac', 'string', ''));
        if ($mac === null) {
            $result['message'] = gettext('Invalid MAC address specified.');
            return $result;
        }

        $actionParam = strtolower(trim((string)$this->request->getPost('action', 'string', '')));
        $quarantinedParam = $this->request->getPost('quarantined', 'string', null);
        if ($actionParam === 'block' || $actionParam === 'quarantine') {
            $isQuarantined = true;
        } elseif ($actionParam === 'unblock' || $actionParam === 'unquarantine') {
            $isQuarantined = false;
        } else {
            $isQuarantined = !empty($quarantinedParam) && $quarantinedParam !== '0' && $quarantinedParam !== 'false';
        }

        $descr = $this->request->getPost('descr', 'striptags', null);
        $addresses = $this->request->getPost('addresses', null, '');
        if (is_array($addresses)) {
            $addresses = implode(',', $addresses);
        } else {
            $addresses = trim((string)$addresses);
        }

        Config::getInstance()->lock();
        $mdl = $this->getModel();

        // Update any configured static neighbor entries matching this MAC
        foreach ($mdl->neighbor->iterateItems() as $neighbor) {
            if ($neighbor->getInternalIsVirtual()) {
                continue;
            }
            if ($this->normalizeMac((string)$neighbor->etheraddr) === $mac) {
                $neighbor->quarantined = $isQuarantined ? '1' : '0';
                if ($descr !== null && $descr !== '') {
                    $neighbor->descr = $descr;
                }
            }
        }

        // Persist in <client> overrides so dynamically discovered hosts remain quarantined across reboots
        $clientNode = $this->findOrCreateClient($mac, true);
        $clientNode->quarantined = $isQuarantined ? '1' : '0';
        if ($descr !== null && $descr !== '') {
            $clientNode->descr = $descr;
        }

        $valMsgs = $mdl->performValidation();
        if (count($valMsgs) > 0) {
            $result['validations'] = [];
            foreach ($valMsgs as $msg) {
                $result['validations'][$msg->getField()] = $msg->getMessage();
            }
            return $result;
        }

        $this->save(false, true);

        $backend = new Backend();
        $backend->configdRun('template reload OPNsense/Filter');
        $enfRaw = $backend->configdpRun('interface topology quarantine', [
            $mac,
            $isQuarantined ? 'block' : 'unblock',
            $addresses,
        ]);
        $enfData = json_decode((string)$enfRaw, true);

        return [
            'result' => 'saved',
            'status' => 'ok',
            'mac' => $mac,
            'quarantined' => $isQuarantined,
            'enforcement' => is_array($enfData) ? $enfData : [],
        ];
    }

    /**
     * Persist a friendly description/label for a discovered client MAC.
     *
     * @return array
     */
    public function labelAction()
    {
        $result = ['result' => 'failed'];
        if (!$this->request->isPost()) {
            return $result;
        }

        $mac = $this->normalizeMac($this->request->getPost('mac', 'string', ''));
        if ($mac === null) {
            $result['message'] = gettext('Invalid MAC address specified.');
            return $result;
        }

        $descr = trim((string)$this->request->getPost('descr', 'striptags', ''));
        if ($descr === '') {
            $descr = trim((string)$this->request->getPost('label', 'striptags', ''));
        }

        Config::getInstance()->lock();
        $mdl = $this->getModel();

        foreach ($mdl->neighbor->iterateItems() as $neighbor) {
            if ($neighbor->getInternalIsVirtual()) {
                continue;
            }
            if ($this->normalizeMac((string)$neighbor->etheraddr) === $mac) {
                $neighbor->descr = $descr;
            }
        }

        $clientNode = $this->findOrCreateClient($mac, true);
        $clientNode->descr = $descr;

        $valMsgs = $mdl->performValidation();
        if (count($valMsgs) > 0) {
            $result['validations'] = [];
            foreach ($valMsgs as $msg) {
                $result['validations'][$msg->getField()] = $msg->getMessage();
            }
            return $result;
        }

        $this->save(false, true);

        return [
            'result' => 'saved',
            'status' => 'ok',
            'mac' => $mac,
            'descr' => $descr,
        ];
    }

    /**
     * Alias for labelAction().
     *
     * @return array
     */
    public function setClientAction()
    {
        return $this->labelAction();
    }

    /**
     * Add or remove a client MAC (or IP) to/from a Firewall Alias and refresh aliases.
     *
     * @return array
     */
    public function assignAliasAction()
    {
        $result = ['result' => 'failed'];
        if (!$this->request->isPost()) {
            return $result;
        }

        $mac = $this->normalizeMac($this->request->getPost('mac', 'string', ''));
        $aliasTarget = trim((string)$this->request->getPost('alias', 'string', ''));
        $action = strtolower(trim((string)$this->request->getPost('action', 'string', 'toggle')));
        $ip = trim((string)$this->request->getPost('ip', 'string', ''));

        if ($mac === null || $aliasTarget === '') {
            $result['message'] = gettext('MAC address and target alias are required.');
            return $result;
        }

        Config::getInstance()->lock();
        $aliasMdl = new Alias(true);
        $matchedAlias = null;
        $matchedUuid = null;

        foreach ($aliasMdl->aliases->alias->iterateItems() as $uuid => $aliasNode) {
            if ($aliasNode->getInternalIsVirtual()) {
                continue;
            }
            if ($uuid === $aliasTarget || (string)$aliasNode->name === $aliasTarget) {
                $matchedAlias = $aliasNode;
                $matchedUuid = $uuid;
                break;
            }
        }

        if ($matchedAlias === null) {
            $result['message'] = gettext('Specified firewall alias was not found.');
            return $result;
        }

        $aliasType = (string)$matchedAlias->type;
        if ($aliasType === 'mac') {
            $entryValue = $mac;
        } elseif ($aliasType === 'host' || $aliasType === 'network') {
            if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
                $result['message'] = gettext('A valid IP address is required for host or network aliases.');
                return $result;
            }
            $entryValue = $ip;
        } else {
            $result['message'] = gettext('Only MAC, host, or network aliases can be assigned from the topology inspector.');
            return $result;
        }

        $rawContent = (string)$matchedAlias->content;
        $rawTokens = preg_split('/[\s,]+/', trim($rawContent));
        $entries = [];
        $seenLower = [];
        $targetCompare = strtolower($entryValue);
        $wasPresent = false;

        foreach ($rawTokens as $token) {
            $trimmed = trim((string)$token);
            if ($trimmed === '') {
                continue;
            }
            if ($aliasType === 'mac') {
                $normToken = $this->normalizeMac($trimmed);
                if ($normToken !== null) {
                    $trimmed = $normToken;
                }
            }
            $lowerToken = strtolower($trimmed);
            if ($lowerToken === $targetCompare) {
                $wasPresent = true;
            }
            if (!isset($seenLower[$lowerToken])) {
                $seenLower[$lowerToken] = true;
                $entries[] = $trimmed;
            }
        }

        $assigned = false;
        if ($action === 'add' || ($action === 'toggle' && !$wasPresent)) {
            if (!$wasPresent) {
                $entries[] = $entryValue;
            }
            $assigned = true;
        } elseif ($action === 'remove' || ($action === 'toggle' && $wasPresent)) {
            $entries = array_values(array_filter(
                $entries,
                function ($item) use ($targetCompare) {
                    return strtolower($item) !== $targetCompare;
                }
            ));
            $assigned = false;
        } else {
            $assigned = $wasPresent;
        }

        $matchedAlias->content = implode("\n", $entries);
        $valMsgs = $aliasMdl->performValidation();
        if (count($valMsgs) > 0) {
            $result['validations'] = [];
            foreach ($valMsgs as $msg) {
                $result['validations'][$msg->getField()] = $msg->getMessage();
            }
            return $result;
        }

        $aliasMdl->serializeToConfig();
        Config::getInstance()->save();

        $backend = new Backend();
        $backend->configdRun('template reload OPNsense/Filter');
        $backend->configdRun('filter refresh_aliases');

        return [
            'result' => 'saved',
            'status' => 'ok',
            'uuid' => $matchedUuid,
            'alias' => (string)$matchedAlias->name,
            'entry' => $entryValue,
            'assigned' => $assigned,
        ];
    }
}
