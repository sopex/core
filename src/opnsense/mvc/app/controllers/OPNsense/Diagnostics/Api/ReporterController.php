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

namespace OPNsense\Diagnostics\Api;

use OPNsense\Base\ApiControllerBase;
use OPNsense\Core\Config;

require_once 'guiconfig.inc';

/**
 * Class CrashReporterController
 * @package OPNsense\Diagnostics\Api
 */
class ReporterController extends ApiControllerBase
{
    private function has_crash_report()
    {
        $skip_files = ['.', '..', 'minfree', 'bounds', ''];
        $PHP_errors_log = '/var/lib/php/tmp/PHP_errors.log';
        $count = 0;

        if (file_exists($PHP_errors_log) && !is_link($PHP_errors_log)) {
            if (intval(shell_safe('/bin/cat %s | /usr/bin/wc -l | /usr/bin/awk \'{ print $1 }\'', $PHP_errors_log))) {
                $count++;
            }
        }

        $crashes = glob('/var/crash/*');
        if ($crashes) {
            foreach ($crashes as $crash) {
                if (!in_array(basename($crash), $skip_files)) {
                    $count++;
                }
            }
        }

        return $count > 0;
    }

    private function get_crash_report_header()
    {
        global $config;
        $plugins = implode(' ', shell_safe('/usr/local/sbin/pkg-static info -g "os-*"', [], true));
        // Use AppInfo if available, else product::getInstance()
        if (class_exists('\OPNsense\Core\AppInfo')) {
            $productName = \OPNsense\Core\AppInfo::name();
            $productVersion = \OPNsense\Core\AppInfo::version();
            $productArch = \OPNsense\Core\AppInfo::architecture();
            $productHash = \OPNsense\Core\AppInfo::hash();
        } else {
            $product = \product::getInstance();
            $productName = $product->name();
            $productVersion = $product->version();
            $productArch = $product->arch();
            $productHash = $product->hash();
        }

        $crash_report_header = sprintf(
            "%s %s\n%s %s %s\n%sTime %s\n%s\n%s\nPHP %s\n",
            php_uname('v'),
            $productArch,
            $productName,
            $productVersion,
            $productHash,
            empty($plugins) ? '' : "Plugins $plugins\n",
            date('r'),
            shell_safe('/usr/local/bin/openssl version | cut -f -2 -d \' \''),
            shell_safe('/usr/local/bin/python3 -V'),
            PHP_VERSION
        );

        if (isset($_SERVER['HTTP_USER_AGENT'])) {
            $crash_report_header = "User-Agent {$_SERVER['HTTP_USER_AGENT']}\n{$crash_report_header}";
        }

        return $crash_report_header;
    }

    private function upload_crash_report($files, $agent)
    {
        $post = array();
        $counter = 0;

        foreach ($files as $filename) {
            if (is_link($filename) || $filename == '/var/crash/minfree.gz' || $filename == '/var/crash/bounds.gz'
                || filesize($filename) > 5 * 1024 * 1024) {
                continue;
            }
            $post["file{$counter}"] = curl_file_create($filename, "application/x-gzip", basename($filename));
            $counter++;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://crash.opnsense.org/');
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_VERBOSE, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, $agent);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_SAFE_UPLOAD, true);
        $postfields = $post;
        /* XXX workaround for PHP Curl API change requiring an array */
        if (is_array($post) && class_exists('CURLFile')) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post); /* fallback */
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-type: multipart/form-data;' ));
        $response = curl_exec($ch);
        curl_close($ch);

        return !$response;
    }

    public function infoAction()
    {
        global $config;

        $has_crashed = $this->has_crash_report();
        $is_prod = empty($config['system']['deployment']) || $config['system']['deployment'] != 'development';
        $email = isset($config['system']['contact_email']) ? $config['system']['contact_email'] : '';

        $reports = [];
        if ($has_crashed) {
            $crash_files = glob("/var/crash/*");
            if (!$crash_files) {
                $crash_files = [];
            }
            $reports['System Information'] = trim($this->get_crash_report_header());

            if (file_exists('/var/lib/php/tmp/PHP_errors.log') && !is_link('/var/lib/php/tmp/PHP_errors.log')) {
                $php_errors_size = @filesize('/var/lib/php/tmp/PHP_errors.log');
                $max_php_errors_size = 1 * 1024 * 1024;
                if ($php_errors_size > $max_php_errors_size) {
                    $php_errors = @file_get_contents(
                        '/var/lib/php/tmp/PHP_errors.log',
                        false,
                        null,
                        ($php_errors_size - $max_php_errors_size),
                        $max_php_errors_size
                    );
                } else {
                    $php_errors = @file_get_contents('/var/lib/php/tmp/PHP_errors.log');
                }
                if (!empty($php_errors)) {
                    $reports['PHP Errors'] = trim($php_errors);
                }
            }
            $dmesg_boot = @file_get_contents('/var/run/dmesg.boot');
            if (!empty($dmesg_boot)) {
                $reports['dmesg.boot'] = trim($dmesg_boot);
            }
            foreach ($crash_files as $cf) {
                if (!is_link($cf) && $cf != '/var/crash/minfree' && $cf != '/var/crash/bounds' && $cf != '/var/crash/manual_issue_report') {
                    if (filesize($cf) > 450000) {
                        $reports[$cf] = gettext('File too big to process. It will not be submitted automatically.');
                    } else {
                        $reports[$cf] = trim(file_get_contents($cf));
                    }
                }
            }
        }

        return [
            'reporter' => [
                'has_crashed' => $has_crashed,
                'is_prod' => $is_prod,
                'email' => $email,
                'desc' => '',
                'reports' => $reports,
                'message' => ''
            ]
        ];
    }

    public function submitAction()
    {
        if ($this->request->isPost()) {
            global $config;

            $configObj = Config::getInstance();
            $email = $this->request->getPost('reporter') && isset($this->request->getPost('reporter')['email']) ? trim($this->request->getPost('reporter')['email']) : '';
            $desc = $this->request->getPost('reporter') && isset($this->request->getPost('reporter')['desc']) ? trim($this->request->getPost('reporter')['desc']) : '';

            $crash_report_header = $this->get_crash_report_header();

            if (!empty($email)) {
                $crash_report_header .= "Email {$email}\n";
                if (!isset($config['system']['contact_email']) ||
                    $config['system']['contact_email'] !== $email) {
                    $config['system']['contact_email'] = $email;
                    write_config('Updated crash reporter contact email.');
                }
            } elseif (isset($config['system']['contact_email'])) {
                unset($config['system']['contact_email']);
                write_config('Removed crash reporter contact email.');
            }

            if (!empty($desc)) {
                $crash_report_header .= "Description\n\n{$desc}";
            }

            if (!is_dir('/var/crash')) {
                mkdir('/var/crash', 0750, true);
            }

            // Always pack and send if we reach here
            $files_to_upload = glob('/var/crash/*');
            if ($files_to_upload) {
                foreach ($files_to_upload as $file_to_upload) {
                    if (filesize($file_to_upload) > 450000) {
                        @unlink($file_to_upload);
                    }
                }
            }
            file_put_contents('/var/crash/crashreport_header.txt', $crash_report_header);

            if (file_exists('/var/lib/php/tmp/PHP_errors.log')) {
                shell_safe('/usr/bin/tail -c 1048576 /var/lib/php/tmp/PHP_errors.log > /var/crash/PHP_errors.log');
                @unlink('/var/lib/php/tmp/PHP_errors.log');
            }
            @copy('/var/run/dmesg.boot', '/var/crash/dmesg.boot');
            shell_safe('/usr/bin/gzip /var/crash/*');

            $files_to_upload = glob('/var/crash/*');

            if (class_exists('\OPNsense\Core\AppInfo')) {
                $user_agent = \OPNsense\Core\AppInfo::name() . "/" . \OPNsense\Core\AppInfo::version();
            } else {
                $product = \product::getInstance();
                $user_agent = $product->name() . "/" . $product->version();
            }

            $this->upload_crash_report($files_to_upload, $user_agent);

            foreach ($files_to_upload as $file_to_upload) {
                @unlink($file_to_upload);
            }

            return ['status' => 'ok'];
        }
        return ['status' => 'failed'];
    }

    public function dismissAction()
    {
        if ($this->request->isPost()) {
            $files_to_upload = glob('/var/crash/*');
            if ($files_to_upload) {
                foreach ($files_to_upload as $file_to_upload) {
                    @unlink($file_to_upload);
                }
            }
            @unlink('/var/lib/php/tmp/PHP_errors.log');
            return ['status' => 'ok'];
        }
        return ['status' => 'failed'];
    }

    public function forceAction()
    {
        if ($this->request->isPost()) {
            if (!is_dir('/var/crash')) {
                mkdir('/var/crash', 0750, true);
            }
            touch('/var/crash/manual_issue_report');
            return ['status' => 'ok'];
        }
        return ['status' => 'failed'];
    }
}
