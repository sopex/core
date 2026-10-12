<?php

/*
 * Copyright (C) 2015 Deciso B.V.
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

use OPNsense\Core\AppConfig;
use OPNsense\Core\Syslog;

/**
 * Class Backend
 * @package OPNsense\Core
 */
class Backend
{
    /**
     * @var string location of configd socket
     */
    private $configdSocket = '/var/run/configd.socket';

    /**
     * @var string apply actions journal, only exists while configuration protection is enabled
     */
    private $protectJournal = '/conf/config_protect.journal';

    /**
     * init Backend component
     */
    public function __construct()
    {
    }

    /**
     * Record apply type actions requested from the GUI/API while configuration protection is enabled,
     * a revert replays them so the running state follows the restored configuration
     * (e.g. Unbound blocklists are only rebuilt by "unbound dnsbl", not by a service reload).
     * @param string $event event string
     */
    private function journalProtected($event)
    {
        if (PHP_SAPI == 'cli' || !file_exists($this->protectJournal)) {
            return;
        }
        $event = static::protectJournalEvent($event);
        if ($event === null) {
            return;
        }
        $journal = @file($this->protectJournal, FILE_IGNORE_NEW_LINES);
        if (is_array($journal) && !in_array($event, $journal)) {
            if (@file_put_contents($this->protectJournal, $event . "\n", FILE_APPEND | LOCK_EX) === false) {
                $this->getLogger()->error("config protection: unable to record '{$event}'");
            }
        }
    }

    /**
     * Determine the action to replay when reverting protected configuration changes, also used to
     * validate the journal before it is replayed as root.
     * @param string $event event string
     * @return string|null event to replay, null when not an apply type action
     */
    public static function protectJournalEvent($event)
    {
        if (strpbrk($event, "\r\n") !== false) {
            return null;
        }
        $words = explode(' ', trim($event));
        $module = trim($words[0], "'");
        /* actions which change runtime state in place, a revert needs the full rebuild */
        $mapped = ['unbound dnsblquick' => 'unbound dnsbl'];
        if (count($words) >= 2 && isset($mapped["{$module} {$words[1]}"])) {
            return $mapped["{$module} {$words[1]}"];
        }
        if (
            count($words) < 2 || in_array($module, ['configd', 'firmware', 'service', 'system']) ||
            empty(array_intersect(array_slice($words, 1, 2), ['configure', 'dnsbl', 'reconfigure', 'reload', 'restart']))
        ) {
            return null;
        }
        return trim($event);
    }

    /**
     * get system logger
     * @param string $ident syslog identifier
     * @return Syslog log handler
     */
    protected function getLogger($ident = 'configd.py')
    {
        return new Syslog($ident, null, LOG_LOCAL2);
    }

    /**
     * send event to backend and return resource (or null on failure)
     * @param string $event event string
     * @param bool $detach detach process
     * @param int $timeout timeout in seconds
     * @param int $connect_timeout connect timeout in seconds
     * @param int $poll_timeout poll timeout after connect
     * @return resource|null
     * @throws \Exception
     */
    public function configdStream($event, $detach = false, $connect_timeout = 10, $poll_timeout = 2)
    {
        $this->journalProtected($event);

        // wait until socket exist for a maximum of $connect_timeout
        $simulate_mode = false;
        if (!file_exists($this->configdSocket) && (!empty((string)(new AppConfig())->globals->simulate_mode))) {
            $timeout_wait = -1;
            $simulate_mode = true;
        } else {
            $timeout_wait = $connect_timeout;
        }
        $errorMessage = "";
        while (
            !file_exists($this->configdSocket) ||
            ($stream = @stream_socket_client('unix://' . $this->configdSocket, $errorNumber, $errorMessage, $poll_timeout)) === false
        ) {
            sleep(1);
            $timeout_wait -= 1;
            if ($timeout_wait <= 0) {
                if (file_exists($this->configdSocket) && !$simulate_mode) {
                    $this->getLogger()->error("Failed to connect to configd socket: $errorMessage while executing " . $event);
                    return null;
                } elseif (!$simulate_mode) {
                    $this->getLogger()->error("failed waiting for configd (doesn't seem to be running)");
                }
                return null;
            }
        }


        stream_set_timeout($stream, $poll_timeout);
        // send command
        if ($detach) {
            fwrite($stream, '&' . $event);
        } else {
            fwrite($stream, $event);
        }

        return $stream;
    }

    /**
     * send event to backend using command parameter list (which will be quoted for proper handling)
     * @param string $event event string
     * @param array $params list of parameters to send with command
     * @param int $poll_timeout poll timeout after connect
     * @param bool $detach detach process
     * @param int $timeout timeout in seconds
     * @param int $connect_timeout connect timeout in seconds
     * @return resource|null
     * @throws \Exception
     */
    public function configdpStream($event, $params = [], $poll_timeout = 2, $detach = false, $timeout = 120, $connect_timeout = 10)
    {
        if (!is_array($params)) {
            $params = [$params];
        }

        foreach ($params as $param) {
            $event .= ' ' . escapeshellarg($param ?? '');
        }

        return $this->configdStream($event, $detach, $connect_timeout, $poll_timeout);
    }


    /**
     * send event to backend
     * @param string $event event string
     * @param bool $detach detach process
     * @param int $timeout timeout in seconds
     * @param int $connect_timeout connect timeout in seconds
     * @return string
     * @throws \Exception
     */
    public function configdRun($event, $detach = false, $timeout = 120, $connect_timeout = 10)
    {
        $endOfStream = chr(0) . chr(0) . chr(0);
        $errorOfStream = 'Execute error';
        $resp = '';

        $stream = $this->configdStream($event, $detach, $connect_timeout);

        // read response data
        $starttime = time();
        while (is_resource($stream)) {
            $resp = $resp . stream_get_contents($stream);

            if (strpos($resp, $endOfStream) !== false) {
                // end of stream detected, exit
                break;
            }

            // handle timeouts
            if ((time() - $starttime) > $timeout) {
                $this->getLogger()->error("Timeout (" . $timeout . ") executing : " . $event);
                return '';
            } elseif (feof($stream)) {
                $this->getLogger()->error("Configd disconnected while executing : " . $event);
                return '';
            }
        }

        if (
            strlen($resp) >= strlen($errorOfStream) &&
            substr($resp, 0, strlen($errorOfStream)) == $errorOfStream
        ) {
            return '';
        }

        return str_replace($endOfStream, '', $resp);
    }

    /**
     * send event to backend using command parameter list (which will be quoted for proper handling)
     * @param string $event event string
     * @param array $params list of parameters to send with command
     * @param bool $detach detach process
     * @param int $timeout timeout in seconds
     * @param int $connect_timeout connect timeout in seconds
     * @return string
     * @throws \Exception
     */
    public function configdpRun($event, $params = [], $detach = false, $timeout = 120, $connect_timeout = 10)
    {
        if (!is_array($params)) {
            $params = [$params];
        }

        foreach ($params as $param) {
            $event .= ' ' . escapeshellarg($param ?? '');
        }

        return $this->configdRun($event, $detach, $timeout, $connect_timeout);
    }

    /**
     * check configd socket for last restart, return 0 socket not present.
     * @return int last restart timestamp
     */
    public function getLastRestart()
    {
        if (file_exists($this->configdSocket)) {
            return filemtime($this->configdSocket);
        } else {
            return 0;
        }
    }
}
