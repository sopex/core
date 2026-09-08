<?php

/*
 * Copyright (C) 2026 Deciso B.V.
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

namespace OPNsense\Firewall\Migrations;

use OPNsense\Base\BaseModelMigration;
use OPNsense\Core\Config;
use OPNsense\Firewall\Filter;

class MFP1_0_10 extends BaseModelMigration
{
    public function run($model)
    {
        if ($model instanceof Filter) {
            $config = Config::getInstance()->object();
            $system = $config->system ?? null;
            $filter = $config->filter ?? null;
            $syslog = $config->syslog ?? null;

            if ($system !== null) {
                if (isset($system->disablefilter)) {
                    $model->settings->filter->disablefilter = !empty((string)$system->disablefilter) ? '1' : '0';
                }
                if (!empty((string)$system->optimization)) {
                    $model->settings->filter->optimization = (string)$system->optimization;
                }
                if (isset($system->{'state-policy'})) {
                    $model->settings->filter->{'state-policy'} = (string)$system->{'state-policy'};
                }
                if (isset($system->maximumstates) && (string)$system->maximumstates !== '') {
                    $model->settings->filter->maximumstates = (string)$system->maximumstates;
                }
                if (isset($system->maximumfrags) && (string)$system->maximumfrags !== '') {
                    $model->settings->filter->maximumfrags = (string)$system->maximumfrags;
                }
                if (isset($system->maximumtableentries) && (string)$system->maximumtableentries !== '') {
                    $model->settings->filter->maximumtableentries = (string)$system->maximumtableentries;
                }
                if (isset($system->adaptivestart) && (string)$system->adaptivestart !== '') {
                    $model->settings->filter->adaptivestart = (string)$system->adaptivestart;
                }
                if (isset($system->adaptiveend) && (string)$system->adaptiveend !== '') {
                    $model->settings->filter->adaptiveend = (string)$system->adaptiveend;
                }
                if (isset($system->aliasesresolveinterval) && (string)$system->aliasesresolveinterval !== '') {
                    $model->settings->filter->aliasesresolveinterval = (string)$system->aliasesresolveinterval;
                }
                if (isset($system->checkaliasesurlcert)) {
                    $model->settings->filter->checkaliasesurlcert = !empty((string)$system->checkaliasesurlcert) ? '1' : '0';
                }
                if (isset($system->disablereplyto)) {
                    $model->settings->filter->disablereplyto = !empty((string)$system->disablereplyto) ? '1' : '0';
                }
                if (isset($system->bogons->interval) && (string)$system->bogons->interval !== '') {
                    $model->settings->filter->bogonsinterval = (string)$system->bogons->interval;
                }
                if (isset($system->schedule_states)) {
                    $model->settings->filter->schedule_states = !empty((string)$system->schedule_states) ? '1' : '0';
                }
                if (isset($system->skip_rules_gw_down)) {
                    $model->settings->filter->skip_rules_gw_down = !empty((string)$system->skip_rules_gw_down) ? '1' : '0';
                }
                if (isset($system->lb_use_sticky)) {
                    $model->settings->filter->lb_use_sticky = !empty((string)$system->lb_use_sticky) ? '1' : '0';
                }
                if (isset($system->pf_share_forward)) {
                    $model->settings->filter->pf_share_forward = !empty((string)$system->pf_share_forward) ? '1' : '0';
                }
                if (isset($system->pf_disable_force_gw)) {
                    $model->settings->filter->pf_disable_force_gw = !empty((string)$system->pf_disable_force_gw) ? '1' : '0';
                }
                if (isset($system->srctrack) && (string)$system->srctrack !== '') {
                    $model->settings->filter->srctrack = (string)$system->srctrack;
                }
                if (isset($system->keepcounters)) {
                    $model->settings->filter->keepcounters = !empty((string)$system->keepcounters) ? '1' : '0';
                }
                if (!empty((string)$system->pfdebug)) {
                    $model->settings->filter->pfdebug = (string)$system->pfdebug;
                }
                if (isset($system->webgui->noantilockout)) {
                    $model->settings->filter->noantilockout = !empty((string)$system->webgui->noantilockout) ? '1' : '0';
                }
                if (isset($system->no_ipv6_rfc4890_req)) {
                    $model->settings->filter->no_ipv6_rfc4890_req = !empty((string)$system->no_ipv6_rfc4890_req) ? '1' : '0';
                }
                if (isset($system->no_port0_block)) {
                    $model->settings->filter->no_port0_block = !empty((string)$system->no_port0_block) ? '1' : '0';
                }
                if (isset($system->no_sshlockout)) {
                    $model->settings->filter->no_sshlockout = !empty((string)$system->no_sshlockout) ? '1' : '0';
                }
                if (isset($system->no_virusprot)) {
                    $model->settings->filter->no_virusprot = !empty((string)$system->no_virusprot) ? '1' : '0';
                }

                // Syncookies
                if (!empty((string)$system->syncookies)) {
                    $model->settings->filter->syncookies = (string)$system->syncookies;
                }
                if (isset($system->syncookies_adaptstart) && (string)$system->syncookies_adaptstart !== '') {
                    $model->settings->filter->syncookies_adaptstart = (string)$system->syncookies_adaptstart;
                }
                if (isset($system->syncookies_adaptend) && (string)$system->syncookies_adaptend !== '') {
                    $model->settings->filter->syncookies_adaptend = (string)$system->syncookies_adaptend;
                }

                // NAT reflection
                if (isset($system->disablenatreflection)) {
                    $dnr = (string)$system->disablenatreflection;
                    if ($dnr === 'purenat') {
                        $model->settings->nat->natreflection = 'purenat';
                    } elseif (!empty($dnr) && $dnr !== 'no') {
                        $model->settings->nat->natreflection = 'disable';
                    } else {
                        $model->settings->nat->natreflection = 'enable';
                    }
                }
                if (isset($system->enablebinatreflection)) {
                    $model->settings->nat->enablebinatreflection = !empty((string)$system->enablebinatreflection) ? '1' : '0';
                }
                if (isset($system->enablenatreflectionhelper)) {
                    $model->settings->nat->enablenatreflectionhelper = !empty((string)$system->enablenatreflectionhelper) ? '1' : '0';
                }
                if (isset($system->reflectiontimeout) && (string)$system->reflectiontimeout !== '') {
                    $model->settings->nat->reflectiontimeout = (string)$system->reflectiontimeout;
                }
            }

            if ($filter !== null) {
                if (isset($filter->bypassstaticroutes)) {
                    $model->settings->filter->bypassstaticroutes = !empty((string)$filter->bypassstaticroutes) ? '1' : '0';
                }
            }

            if ($syslog !== null) {
                if (isset($syslog->nologdefaultblock)) {
                    $model->settings->logging->logdefaultblock = empty((string)$syslog->nologdefaultblock) ? '1' : '0';
                }
                if (isset($syslog->nologdefaultpass)) {
                    $model->settings->logging->logdefaultpass = empty((string)$syslog->nologdefaultpass) ? '1' : '0';
                }
                if (isset($syslog->logoutboundnat)) {
                    $model->settings->logging->logoutboundnat = !empty((string)$syslog->logoutboundnat) ? '1' : '0';
                }
                if (isset($syslog->nologbogons)) {
                    $model->settings->logging->logbogons = empty((string)$syslog->nologbogons) ? '1' : '0';
                }
                if (isset($syslog->nologprivatenets)) {
                    $model->settings->logging->logprivatenets = empty((string)$syslog->nologprivatenets) ? '1' : '0';
                }
            }
        }

        parent::run($model);
    }
}
