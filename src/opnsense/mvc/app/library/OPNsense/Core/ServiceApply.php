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
    private \Closure $guard;

    public function __construct(?Backend $backend = null, ?\Closure $guard = null)
    {
        $this->backend = $backend ?? new Backend();
        $this->guard = $guard ?? fn($operation, $apply) => CommitSession::getInstance()->apply($operation, $apply);
    }

    private function run(string $action): string
    {
        if (trim($this->backend->configdRun($action)) !== 'OK') {
            throw new \RuntimeException(sprintf(gettext('Apply action failed: %s'), $action));
        }
        return 'OK';
    }

    public function firewall(bool $refreshAliases = false): string
    {
        return ($this->guard)('filter', fn() => $this->run($refreshAliases ? 'filter reload' : 'filter reload skip_alias'));
    }

    public function routing(): string
    {
        // The routing action also reconfigures gateway monitoring and firewall rules.
        return ($this->guard)('routing', fn() => $this->run('interface routes configure'));
    }

    public function wireguard(): void
    {
        ($this->guard)('wireguard', function () {
            $this->run('interface invoke registration');
            // Cache invalidation returns JSON, not an action status.
            $this->backend->configdRun('!interface list assign-opts', false, 20);
            $this->run('template reload OPNsense/Wireguard');
            $this->run('wireguard configure');
        });
    }

    public function unbound(bool $enabled): void
    {
        ($this->guard)('unbound.general', function () use ($enabled) {
            // The existing "dns reload" action returns stdout. Use its status-returning variant.
            $this->run('dns reload_status');
            $this->configure('unbound', $enabled);
            // ISC DHCP is optional; an absent legacy action is not an Apply failure.
            if (is_file('/usr/local/opnsense/service/conf/actions.d/actions_dhcpd.conf')) {
                $this->run('dhcpd restart');
            }
        });
    }

    public function unboundDnsbl(bool $enabled, bool $restored = false): string
    {
        return ($this->guard)('unbound.dnsbl', function () use ($enabled, $restored) {
            $this->run('template reload OPNsense/Unbound/*');
            if (!$restored) {
                $this->run('unbound dnsbl_update');
            }
            // Recovery already restored dnsbl.json. Do not overwrite it or depend on Internet access.
            return $enabled ? $this->run('unbound dnsbl_reload') : 'OK';
        });
    }

    /** Shared implementation of ApiMutableServiceControllerBase's Apply operation. */
    public function reconfigure(
        string $service,
        bool $enabled,
        ?string $template = null,
        bool $restart = true,
        ?callable $status = null,
        ?callable $registration = null,
        bool $reloadFilter = false
    ): void {
        ($this->guard)($service . '.reconfigure', function () use (
            $service, $enabled, $template, $restart, $status, $registration, $reloadFilter
        ) {
            $this->configure($service, $enabled, $template, $restart, $status, $registration, $reloadFilter);
        });
    }

    private function configure(
        string $service,
        bool $enabled,
        ?string $template = null,
        bool $restart = true,
        ?callable $status = null,
        ?callable $registration = null,
        bool $reloadFilter = false
    ): void {
        $name = escapeshellarg($service);
        if ($restart || !$enabled) {
            try {
                $this->run($name . ' stop');
            } catch (\RuntimeException $error) {
                // rc(8) can fail "stop" when already stopped. Recovery must be repeatable.
                $state = $this->backend->configdRun($name . ' status');
                if (!str_contains($state, 'not running') || str_contains($state, 'is running')) {
                    throw $error;
                }
            }
        }
        if ($registration) {
            $registration();
        }
        if ($reloadFilter) {
            $this->run('filter reload skip_alias');
        }
        if ($template) {
            $this->run('template reload ' . escapeshellarg($template));
        }
        if ($enabled) {
            $running = !$restart && ($status ? $status() === 'running' :
                str_contains($this->backend->configdRun($name . ' status'), 'is running'));
            $this->run($name . ($running ? ' reload' : ' start'));
        }
    }
}
