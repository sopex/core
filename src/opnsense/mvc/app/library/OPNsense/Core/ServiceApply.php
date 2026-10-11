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

namespace OPNsense\Core;

/**
 * Apply operations shared by the GUI and protected-session recovery.
 * Callers must load the configuration they want to apply before invoking these operations.
 */
class ServiceApply
{
    private Backend $backend;

    public function __construct(?Backend $backend = null)
    {
        $this->backend = $backend ?? new Backend();
    }

    private function run(string $action): string
    {
        if (trim($this->backend->configdRun($action)) !== 'OK') {
            throw new \RuntimeException(sprintf('Apply action failed: %s', $action));
        }
        return 'OK';
    }

    public function firewall(bool $refreshAliases = false): string
    {
        return $this->run($refreshAliases ? 'filter reload' : 'filter reload skip_alias');
    }

    public function wireguard(): void
    {
        $this->run('interface invoke registration');
        // This action returns JSON rather than an OK status; force invalidation as in the GUI.
        $this->backend->configdRun('!interface list assign-opts', false, 20);
        $this->run('template reload OPNsense/Wireguard');
        $this->run('wireguard configure');
    }

    public function unbound(bool $enabled): void
    {
        $this->run('dns reload');
        $this->run('unbound stop');
        if ($enabled) {
            $this->run('unbound start');
        }
        $this->run('dhcpd restart');
    }
}
