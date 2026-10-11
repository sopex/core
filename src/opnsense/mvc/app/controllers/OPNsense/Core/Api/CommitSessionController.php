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
use OPNsense\Core\CommitSession;

/**
 * Class CommitSessionController
 * API endpoints for managing protected change sessions (commit-confirmed rollback)
 * @package OPNsense\Core\Api
 */
class CommitSessionController extends ApiControllerBase
{
    /**
     * Get current status of the protected change session
     * @return array
     */
    public function statusAction()
    {
        return CommitSession::getInstance()->getState();
    }

    private function mutate(string $operation): array
    {
        $this->throwReadOnly();
        if (!$this->request->isPost()) {
            return ['status' => 'failed', 'message' => gettext('POST request required.')];
        }
        $source = $this->request->getHeader('X-Client-Type');
        $source = in_array($source, ['gui', 'api', 'console'], true) ? $source : 'api';
        return CommitSession::getInstance()->$operation($this->getUserName(), $source);
    }

    public function startAction()
    {
        return $this->mutate('start');
    }

    public function confirmAction()
    {
        return $this->mutate('confirm');
    }

    public function extendAction()
    {
        return $this->mutate('extend');
    }

    public function revertAction()
    {
        return $this->mutate('revert');
    }

    /**
     * Get unified diff between snapshot and current configuration
     * @return array
     */
    public function diffAction()
    {
        return ['items' => CommitSession::getInstance()->getDiff()];
    }

    /**
     * Get or dismiss revert notice
     * @return array
     */
    public function noticeAction()
    {
        if ($this->request->isPost()) {
            $this->throwReadOnly();
            return [
                'status' => CommitSession::getInstance()->dismissRevertNotice() ? 'ok' : 'failed'
            ];
        }

        return [
            'has_notice' => CommitSession::getInstance()->hasRevertNotice(),
            'notice' => CommitSession::getInstance()->getRevertNotice(),
        ];
    }
}
