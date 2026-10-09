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

namespace OPNsense\System\Status;

use OPNsense\Core\CommitSession;
use OPNsense\System\AbstractStatus;
use OPNsense\System\SystemStatusCode;

/**
 * Class CommitSessionStatus
 * Reports system status notices when an automatic configuration rollback has occurred.
 * @package OPNsense\System\Status
 */
class CommitSessionStatus extends AbstractStatus
{
    public function __construct()
    {
        $this->internalPriority = 5;
        $this->internalTitle = gettext('Configuration Rollback');
        $this->internalLocation = '/ui/core/backup/history';
        $this->internalIsBanner = false;
    }

    public function collectStatus()
    {
        $cs = CommitSession::getInstance();
        if ($cs->hasRevertNotice()) {
            $notice = $cs->getRevertNotice();
            $timeStr = !empty($notice['reverted_at_iso']) ? $notice['reverted_at_iso'] : date('c');
            $reason = !empty($notice['reason']) ? $notice['reason'] : 'countdown expired';

            $this->internalStatus = SystemStatusCode::NOTICE;
            $this->internalMessage = sprintf(
                gettext('An automatic configuration rollback was executed on %s (Reason: %s). Changes reverted to pre-session snapshot.'),
                $timeStr,
                $reason
            );
            $this->internalTimestamp = !empty($notice['reverted_at']) ? (int)$notice['reverted_at'] : time();
        }
    }

    public function dismissStatus()
    {
        CommitSession::getInstance()->dismissRevertNotice();
    }
}
