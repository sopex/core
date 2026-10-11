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

/** MVC/legacy adapter; all session transitions live in CommitSessionStore. */
class CommitSession extends Singleton
{
    public const SNAPSHOT_FILE = '/conf/commit_rollback/snapshot.xml';
    public const NOTICE_FILE = '/conf/commit_rollback_notice.json';
    public const DEFAULT_COUNTDOWN = 10;
    public const DEFAULT_EXTENSION = 5;
    public const DEFAULT_MAX_EXTENSIONS = 6;
    public const DEFAULT_MAX_SESSION = 120;
    private CommitSessionStore $store;

    protected function init()
    {
        $this->store = new CommitSessionStore((new AppConfig())->application->configDir);
    }

    public function isActive(): bool
    {
        return $this->store->active();
    }

    public function isReverting(): bool
    {
        return ($this->store->state()['status'] ?? '') === 'reverting';
    }

    public function getState(): array
    {
        return $this->store->state();
    }

    private function ensureWorker(): void
    {
        if (!$this->store->workerAlive()) {
            // daemon(8) owns the supervisor pidfile lock; concurrent starts cannot create two supervisors.
            exec('/usr/sbin/daemon -f -r -P /var/run/commit_watchdog_sup.pid ' .
                '/usr/local/bin/php /usr/local/opnsense/scripts/system/commit_watchdog.php', $output, $status);
            for ($attempt = 0; $attempt < 30 && !$this->store->workerAlive(); $attempt++) {
                usleep(100000);
            }
            if (!$this->store->workerAlive()) {
                throw new \RuntimeException(gettext('Failed to start the protected session worker.'));
            }
        }
    }

    private function command(string $action, string $username, string $source, callable $operation): array
    {
        try {
            $response = $operation();
            openlog('audit', LOG_ODELAY, LOG_AUTH);
            syslog(LOG_NOTICE, sprintf('commit-session: %s by %s via %s', $action, $username, $source));
            $message = match ($action) {
                'start' => gettext('Protected session started.'),
                'extend' => gettext('Countdown extended.'),
                'confirm' => gettext('Protected session ended.'),
                'revert' => gettext('Configuration rollback queued.')
            };
            return ['status' => 'ok', 'message' => $message] + $response;
        } catch (\Throwable $error) {
            return ['status' => 'failed', 'message' => $error->getMessage()];
        }
    }

    public function start(string $username, string $source = 'gui'): array
    {
        return $this->command('start', $username, $source, function () use ($username, $source) {
            $this->ensureWorker();
            return $this->store->start($username, $source, $this->getSettings(), function ($snapshot) {
                return $this->getGuiUrl(simplexml_load_string($snapshot));
            });
        });
    }

    public function extend(string $username, string $source = 'gui'): array
    {
        return $this->command('extend', $username, $source, fn() => $this->store->change('extend'));
    }

    public function confirm(string $username, string $source = 'gui'): array
    {
        return $this->command('confirm', $username, $source, fn() => $this->store->change('confirm'));
    }

    public function revert(string $username, string $source = 'gui', string $reason = 'manual'): array
    {
        return $this->command('revert', $username, $source, function () {
            $this->ensureWorker();
            return $this->store->change('revert');
        });
    }

    public function beginSave(string $proposed, ?array $revision = null)
    {
        if ($this->isActive()) {
            $this->ensureWorker();
        }
        return $this->store->beginSave($proposed, $this->isUserAction($revision));
    }

    public function finishSave($lease, ?string $backup): void
    {
        $this->store->finishSave($lease, $backup);
    }

    public function apply(string $operation, callable $apply)
    {
        if ($this->isActive()) {
            $this->ensureWorker();
        }
        return $this->store->apply($operation, $apply);
    }

    public function getSettings(): array
    {
        $cnf = Config::getInstance()->object();
        $countdown = self::DEFAULT_COUNTDOWN;
        $extension = self::DEFAULT_EXTENSION;
        $maxExtensions = self::DEFAULT_MAX_EXTENSIONS;
        $maxSession = self::DEFAULT_MAX_SESSION;

        if (isset($cnf->system->commit_rollback)) {
            $cr = $cnf->system->commit_rollback;
            if (!empty($cr->countdown)) {
                $countdown = max(1, min(30, (int)$cr->countdown));
            }
            if (!empty($cr->extension)) {
                $extension = max(1, min(30, (int)$cr->extension));
            }
            if (isset($cr->max_extensions) && (string)$cr->max_extensions !== '') {
                $maxExtensions = max(0, min(60, (int)$cr->max_extensions));
            }
            if (!empty($cr->max_session)) {
                $maxSession = max(15, min(480, (int)$cr->max_session));
            }
        }

        // A session limit shorter than one countdown would cut the first save short
        $maxSession = max($maxSession, $countdown);

        return [
            'countdown' => $countdown,
            'extension' => $extension,
            'max_extensions' => $maxExtensions,
            'max_session' => $maxSession,
        ];
    }

    public function getGuiUrl(?\SimpleXMLElement $xml = null): string
    {
        if ($xml === null) {
            $xml = Config::getInstance()->object();
        }

        $protocol = 'https';
        if (isset($xml->system->webgui->protocol) && !empty((string)$xml->system->webgui->protocol)) {
            $protocol = (string)$xml->system->webgui->protocol;
        }

        $port = '';
        if (isset($xml->system->webgui->port) && !empty((string)$xml->system->webgui->port)) {
            $portVal = (string)$xml->system->webgui->port;
            if ($portVal !== ($protocol === 'https' ? '443' : '80')) {
                $port = ':' . $portVal;
            }
        }

        // 1. Capture host user actually used to connect
        $host = '';
        if (!empty($_SERVER['HTTP_HOST'])) {
            $hostStr = trim($_SERVER['HTTP_HOST']);
            if (preg_match('/^\[([a-fA-F0-9:]+)\](?::\d+)?$/', $hostStr, $matches)) {
                $host = $matches[1];
            } elseif (preg_match('/^([^:]+)(?::\d+)?$/', $hostStr, $matches)) {
                $host = $matches[1];
            } else {
                $host = $hostStr;
            }
        } elseif (!empty($_SERVER['SERVER_ADDR'])) {
            $host = trim($_SERVER['SERVER_ADDR']);
        }

        // 2. Fallback for console / CLI: use LAN IP only if valid IP (not "dhcp"), otherwise 127.0.0.1
        if (empty($host)) {
            if (isset($xml->interfaces->lan->ipaddr)) {
                $lanIp = (string)$xml->interfaces->lan->ipaddr;
                if (filter_var($lanIp, FILTER_VALIDATE_IP)) {
                    $host = $lanIp;
                }
            }
        }
        if (empty($host)) {
            $host = '127.0.0.1';
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $host = '[' . $host . ']';
        }

        return sprintf('%s://%s%s', $protocol, $host, $port);
    }

    public function isUserAction(?array $revision = null, string $source = 'console'): bool
    {
        // Web GUI session or API key
        if (!empty($_SESSION['Username']) || !empty($_SERVER['PHP_AUTH_USER'])) {
            return true;
        }
        // Interactive console or SSH menu
        if (php_sapi_name() === 'cli' && function_exists('posix_isatty') && defined('STDIN') && @posix_isatty(STDIN)) {
            return true;
        }
        // A named account; parenthesised names such as "(root)" are process identities from whoami,
        // i.e. backend scripts and cron jobs
        if (isset($revision['username'])) {
            $u = trim(explode('@', (string)$revision['username'])[0]);
            return $u !== '' && $u[0] !== '(' && $u !== 'system';
        }
        return false;
    }

    public function getDiff(): array
    {
        $items = [];
        if ($this->isReverting()) {
            return $items;
        }

        $app = new AppConfig();
        $currentConfig = $app->application->configDir . '/config.xml';

        if (!file_exists(self::SNAPSHOT_FILE) || !file_exists($currentConfig)) {
            return $items;
        }

        $diff = Shell::shell_safe('/usr/bin/diff -u %s %s', [self::SNAPSHOT_FILE, $currentConfig], true);
        if (!empty($diff)) {
            foreach ($diff as $line) {
                $items[] = htmlspecialchars($line, ENT_QUOTES | ENT_HTML401);
            }
        }

        return $items;
    }

    public function hasRevertNotice(): bool
    {
        return file_exists(self::NOTICE_FILE);
    }

    public function getRevertNotice(): ?array
    {
        if (!$this->hasRevertNotice()) {
            return null;
        }
        $data = @json_decode(file_get_contents(self::NOTICE_FILE), true);
        return is_array($data) ? $data : null;
    }

    public function dismissRevertNotice(): bool
    {
        if (file_exists(self::NOTICE_FILE)) {
            return @unlink(self::NOTICE_FILE);
        }
        return false;
    }
}
