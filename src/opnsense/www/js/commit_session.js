/**
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

'use strict';

class CommitSessionManager {
    constructor() {
        this.active = false;
        this.sessionData = null;
        this.timerInterval = null;
        this.pollInterval = null;
        this.localRemainingSeconds = 0;
        this.failCount = 0;
        this.connectionLost = false;
        this.revertingNoticeShown = false;
        this.isForbidden = false;
    }

    init() {
        if (this.initialized) {
            return;
        }
        this.initialized = true;

        // Intercept announcements from the system status framework (statusObj)
        // without adding to statusObj.observers, so statusObj.observers.length === 0
        // is preserved for native widgets (StatusIcon, StatusDialog, StatusBanner).
        if (typeof statusObj !== 'undefined' && typeof statusObj.notify === 'function') {
            if (!statusObj._commitSessionNotifyWrapped) {
                statusObj._commitSessionNotifyWrapped = true;
                const origNotify = statusObj.notify.bind(statusObj);
                statusObj.notify = (status) => {
                    origNotify(status);
                    this.handleStatusUpdate(status);
                };
            }
            if (statusObj.data) {
                this.handleStatusUpdate(statusObj.data);
            }
        }

        // GUI entrypoint to start/manage protected change session
        $(document).off('click', '#btn-start-commit-session').on('click', '#btn-start-commit-session', (e) => {
            e.preventDefault();
            this.promptStartSession();
        });
        this.updateHeaderButton();
    }

    handleStatusUpdate(status) {
        if (this.isForbidden) {
            return;
        }
        if (status && status.subsystems && status.subsystems.commitsession) {
            let cs = status.subsystems.commitsession;
            if (cs.location) {
                if (this.active) {
                    this.active = false;
                    this.removeBanner();
                    this.stopTimer();
                    this.stopPolling();
                    this.updateHeaderButton();
                }
                this.checkNotice();
            } else {
                if (!this.active) {
                    this.checkStatus();
                }
            }
        } else if (this.active) {
            // System status framework no longer reports commitsession
            this.active = false;
            this.removeBanner();
            this.stopTimer();
            this.stopPolling();
            this.updateHeaderButton();
        }
    }

    promptStartSession() {
        if (this.isForbidden) {
            return;
        }
        if (this.active) {
            let $banner = $('#commit-session-banner');
            if (!$banner.length) {
                this.renderBanner();
                $banner = $('#commit-session-banner');
            }
            if ($banner.length) {
                $('html, body').animate({
                    scrollTop: $banner.offset().top - 20
                }, 300);
            } else {
                BootstrapDialog.show({
                    title: 'Protected Change Session',
                    message: 'A protected change session is currently active.',
                    type: BootstrapDialog.TYPE_INFO
                });
            }
            return;
        }

        BootstrapDialog.confirm({
            title: 'Start Protected Change Session',
            message: 'A protected change session creates a snapshot of the current configuration.<br/><br/>' +
                     'Any changes saved during the session will arm an automatic rollback countdown. ' +
                     'If not confirmed before the timer expires, changes are automatically reverted to the snapshot.<br/><br/>' +
                     'Do you want to start a protected change session now?',
            type: BootstrapDialog.TYPE_PRIMARY,
            btnOKClass: 'btn-primary',
            btnOKLabel: 'Start Session',
            btnCancelLabel: 'Cancel',
            callback: (result) => {
                if (result) {
                    ajaxCall('/api/core/commit_session/start', {}, (data) => {
                        if (data && data.status === 'ok') {
                            this.active = true;
                            this.checkStatus();
                            this.updateHeaderButton();
                            if (typeof updateSystemStatus === 'function') {
                                updateSystemStatus();
                            }
                        } else {
                            if (data && data.status === 403) {
                                this.isForbidden = true;
                                this.updateHeaderButton();
                            }
                            BootstrapDialog.show({
                                title: 'Failed to Start Session',
                                message: (data && data.message) ? data.message : 'Unable to start protected change session.',
                                type: BootstrapDialog.TYPE_DANGER
                            });
                        }
                    });
                }
            }
        });
    }

    updateHeaderButton() {
        let $btn = $('#btn-start-commit-session');
        if ($btn.length) {
            if (this.isForbidden) {
                $btn.closest('li').hide();
                return;
            }
            if (this.sessionData && (this.sessionData.reverting || this.sessionData.status === 'reverting')) {
                $btn.removeClass('text-muted text-success').addClass('text-warning');
                $btn.attr('data-original-title', 'Configuration rollback in progress...').attr('title', 'Configuration rollback in progress...');
            } else if (this.active) {
                $btn.removeClass('text-muted').addClass('text-warning');
                $btn.attr('data-original-title', 'Protected change session is active').attr('title', 'Protected change session is active');
            } else {
                $btn.removeClass('text-warning text-success').addClass('text-muted');
                $btn.attr('data-original-title', 'Start protected change session').attr('title', 'Start protected change session');
            }
        }
    }

    startPolling() {
        this.stopPolling();
        // Poll status every 5 seconds ONLY WHILE A SESSION IS ACTIVE
        this.pollInterval = setInterval(() => {
            this.syncStatus();
        }, 5000);
    }

    stopPolling() {
        if (this.pollInterval) {
            clearInterval(this.pollInterval);
            this.pollInterval = null;
        }
    }

    formatTime(seconds) {
        let sec = Math.max(0, parseInt(seconds, 10));
        let m = Math.floor(sec / 60);
        let s = sec % 60;
        return m + ':' + (s < 10 ? '0' : '') + s;
    }

    formatEpochTime(epoch) {
        if (!epoch) return '';
        let d = new Date(epoch * 1000);
        return d.toLocaleTimeString();
    }

    getContainer() {
        let $c = $('#commit-session-banner-area');
        if ($c.length === 0) {
            $c = $('<div id="commit-session-banner-area"></div>');
            let $pageMain = $('.page-content-main > .container-fluid > .row');
            if ($pageMain.length > 0) {
                $pageMain.prepend($c);
            } else {
                $('header.page-content-head').after($c);
            }
        }
        return $c;
    }

    checkStatus() {
        if (this.isForbidden) {
            return;
        }
        $.ajax({
            url: '/api/core/commit_session/status',
            type: 'GET',
            dataType: 'json',
            timeout: 5000,
            success: (data) => {
                if (data && data.active) {
                    this.sessionData = data;
                    this.active = true;
                    this.localRemainingSeconds = data.remaining_seconds || 0;
                    this.failCount = 0;
                    this.connectionLost = false;
                    this.renderBanner();
                    if (data.status !== 'reverting' && !data.reverting) {
                        this.startTimer();
                    } else {
                        this.stopTimer();
                    }
                    if (!this.pollInterval) {
                        this.startPolling();
                    }
                } else {
                    this.active = false;
                    this.removeBanner();
                    this.stopTimer();
                    this.stopPolling();
                    this.updateHeaderButton();
                    this.checkNotice();
                }
            },
            error: (xhr) => {
                if (xhr && xhr.status === 403) {
                    this.isForbidden = true;
                }
                this.active = false;
                this.removeBanner();
                this.stopTimer();
                this.stopPolling();
            }
        });
    }

    syncStatus() {
        if (!this.active || this.isForbidden) {
            this.stopPolling();
            return;
        }

        $.ajax({
            url: '/api/core/commit_session/status',
            type: 'GET',
            dataType: 'json',
            timeout: 5000,
            success: (data) => {
                this.failCount = 0;
                if (this.connectionLost) {
                    this.connectionLost = false;
                }
                if (data && data.active) {
                    this.sessionData = data;
                    this.localRemainingSeconds = data.remaining_seconds || 0;
                    if (data.status === 'reverting' || data.reverting) {
                        this.stopTimer();
                    }
                    this.renderBanner();
                } else {
                    this.active = false;
                    this.removeBanner();
                    this.stopTimer();
                    this.stopPolling();
                    this.updateHeaderButton();
                    if (typeof updateSystemStatus === 'function') {
                        updateSystemStatus();
                    }
                    this.checkNotice();
                }
            },
            error: (xhr) => {
                if (xhr && xhr.status === 403) {
                    // Unauthorized; stop polling immediately to avoid repeated 403 errors
                    this.isForbidden = true;
                    this.stopPolling();
                    this.removeBanner();
                    this.stopTimer();
                    return;
                }
                this.failCount++;
                if (this.failCount >= 2 && !this.connectionLost) {
                    this.connectionLost = true;
                    this.renderConnectionLostBanner();
                }
            }
        });
    }

    startTimer() {
        this.stopTimer();
        this.timerInterval = setInterval(() => {
            if (this.sessionData && this.sessionData.countdown_active && this.sessionData.status !== 'reverting' && !this.sessionData.reverting) {
                if (this.localRemainingSeconds > 0) {
                    this.localRemainingSeconds--;
                    $('#cs-timer-val').text(this.formatTime(this.localRemainingSeconds));
                } else {
                    $('#cs-timer-val').text('0:00');
                    if (!this.revertingNoticeShown) {
                        this.revertingNoticeShown = true;
                        this.stopTimer();
                        if (this.sessionData) {
                            this.sessionData.status = 'reverting';
                            this.sessionData.reverting = true;
                            this.sessionData.revert_reason = 'countdown_expired';
                        }
                        this.renderBanner();
                    }
                }
            }
        }, 1000);
    }

    stopTimer() {
        if (this.timerInterval) {
            clearInterval(this.timerInterval);
            this.timerInterval = null;
        }
    }

    renderBanner() {
        let $container = this.getContainer();
        let data = this.sessionData || {};

        if (data.reverting || data.status === 'reverting') {
            let reasonStr = data.revert_reason || 'manual';
            let reasonLabel = reasonStr === 'countdown_expired' ? 'Countdown Expired' : 'Rollback in Progress';
            let attempts = parseInt(data.revert_attempts || 0, 10);
            let failing = attempts > 0 && !!data.last_revert_error && !data.revert_running;
            let bannerClass = failing ? 'alert-danger' : 'alert-warning';
            let iconHtml = failing
                ? '<span class="fa fa-exclamation-triangle fa-lg" style="margin-right:10px;"></span>'
                : '<span class="fa fa-spinner fa-spin fa-lg" style="margin-right:10px; color:#d9534f;"></span>';
            let msgHtml = 'Reverting changes to pre-session snapshot and reloading services... Please wait.';
            if (failing) {
                let retryStr = (data.next_retry_seconds !== null && data.next_retry_seconds !== undefined)
                    ? 'Next attempt in ' + this.formatTime(data.next_retry_seconds) + '.'
                    : 'Retrying shortly.';
                msgHtml = 'Rollback failed ' + attempts + ' time' + (attempts === 1 ? '' : 's') +
                    ' (' + $('<span>').text(data.last_revert_error).html() + '). ' + retryStr +
                    ' The firewall is still running the unconfirmed configuration.' +
                    ' Confirm the session to keep it and stop retrying.';
            }
            let html = `
                <div id="commit-session-banner" class="alert ${bannerClass}" style="margin: 10px 15px; padding: 12px 15px; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.2);">
                    <div class="row" style="display:flex; align-items:center; flex-wrap:wrap;">
                        <div class="col-xs-12">
                            ${iconHtml}
                            <strong style="text-transform:uppercase; letter-spacing:0.5px;">${failing ? 'Rollback Failing' : 'Reverting Configuration'} (${reasonLabel})</strong>
                            <span style="margin: 0 8px;">|</span>
                            <span id="cs-banner-msg">
                                ${msgHtml}
                            </span>
                        </div>
                    </div>
                </div>
            `;
            $container.html(html);
            this.updateHeaderButton();
            return;
        }

        let isCountdown = !!data.countdown_active;
        let alertClass = isCountdown ? 'alert-warning' : 'alert-info';
        let remainingStr = this.formatTime(this.localRemainingSeconds);
        let savesCount = data.saves_count || 0;
        let extensionsCount = data.extensions_count || 0;
        let maxExtensions = data.max_extensions || 6;
        let extensionsRemaining = Math.max(0, maxExtensions - extensionsCount);
        let userStr = data.username || 'admin';
        let sourceStr = data.source || 'gui';
        let sessionLimitHtml = '';
        let limitRem = data.session_limit_remaining_seconds;
        if (isCountdown && limitRem !== null && limitRem !== undefined && limitRem <= 900) {
            sessionLimitHtml = `<span style="color:#d9534f;">Session limit reached in <strong>${this.formatTime(limitRem)}</strong>; confirm before then.</span>`;
        }

        let html = `
            <div id="commit-session-banner" class="alert ${alertClass}" style="margin: 10px 15px; padding: 10px 15px; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.2);">
                <div class="row" style="display:flex; align-items:center; flex-wrap:wrap;">
                    <div class="col-md-7 col-sm-12" style="margin-bottom: 5px;">
                        <span id="cs-banner-icon" class="fa fa-shield fa-lg" style="margin-right:8px;"></span>
                        <strong style="text-transform:uppercase; letter-spacing:0.5px;">Protected Change Session</strong>
                        <span style="margin: 0 8px;">|</span>
                        <span id="cs-banner-msg">
        `;

        if (isCountdown) {
            html += `
                Rollback in <strong id="cs-timer-val" style="font-size:1.15em; color:#d9534f; background:#fff; padding:2px 6px; border-radius:3px; border:1px solid #d9534f;">${remainingStr}</strong>
                &nbsp;(${savesCount} saved change${savesCount === 1 ? '' : 's'})
            `;
        } else {
            html += `
                <span>Changes not yet saved. Countdown will start on first save.</span>
            `;
        }

        html += `
                        </span>
                        <div style="font-size: 0.85em; opacity: 0.85; margin-top: 2px;">
                            Session by <strong>${userStr}</strong> via <strong>${sourceStr}</strong>.
                            Extensions remaining: <strong>${extensionsRemaining} of ${maxExtensions}</strong>.
                            ${sessionLimitHtml}
                        </div>
                    </div>
                    <div class="col-md-5 col-sm-12 text-right" style="margin-bottom: 5px;">
                        <button id="cs-btn-confirm" class="btn btn-success btn-xs" style="margin-right:5px;" title="Keep changes permanently and end protected session">
                            <i class="fa fa-check"></i> Confirm Changes
                        </button>
                        <button id="cs-btn-extend" class="btn btn-default btn-xs" style="margin-right:5px;" ${(!isCountdown || extensionsRemaining <= 0) ? 'disabled' : ''} title="Add 5 minutes to countdown">
                            <i class="fa fa-clock-o"></i> Extend (+5m)
                        </button>
                        <button id="cs-btn-revert" class="btn btn-danger btn-xs" style="margin-right:5px;" title="Discard changes and restore pre-session snapshot immediately">
                            <i class="fa fa-undo"></i> Revert Now
                        </button>
                        <button id="cs-btn-diff" class="btn btn-info btn-xs" title="View configuration differences against pre-session snapshot">
                            <i class="fa fa-exchange"></i> Diff
                        </button>
                    </div>
                </div>
            </div>
        `;

        $container.html(html);
        this.updateHeaderButton();
        this.bindBannerActions();
    }

    renderConnectionLostBanner() {
        let $container = this.getContainer();
        let guiUrl = (this.sessionData && this.sessionData.gui_url) ? this.sessionData.gui_url : window.location.origin;

        let html = `
            <div id="commit-session-banner" class="alert alert-danger" style="margin: 10px 15px; padding: 12px 15px; border-radius: 4px; box-shadow: 0 2px 4px rgba(0,0,0,0.3);">
                <div class="row" style="display:flex; align-items:center;">
                    <div class="col-xs-12">
                        <i class="fa fa-exclamation-triangle fa-2x pull-left" style="margin-right:15px; color:#d9534f;"></i>
                        <div>
                            <strong>Connection Lost During Protected Change Session!</strong>
                            <div style="margin-top:4px;">
                                The firewall did not respond to status checks. The commit-watchdog is continuing its countdown locally and will
                                automatically revert configuration and reload services if changes are not confirmed before expiration.
                                <br/>
                                If you applied network or firewall changes that blocked your connection, wait for rollback or reconnect at:
                                <a href="${guiUrl}" class="alert-link" style="text-decoration:underline; font-weight:bold;">${guiUrl}</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `;

        $container.html(html);
    }

    removeBanner() {
        $('#commit-session-banner-area').empty();
        this.updateHeaderButton();
    }

    bindBannerActions() {
        $('#cs-btn-confirm').off('click').on('click', () => {
            BootstrapDialog.confirm({
                title: 'Confirm Configuration Changes',
                message: 'Are you sure you want to confirm all changes and end the protected change session?',
                type: BootstrapDialog.TYPE_SUCCESS,
                btnOKClass: 'btn-success',
                btnOKLabel: 'Confirm',
                callback: (result) => {
                    if (result) {
                        ajaxCall('/api/core/commit_session/confirm', {}, (data) => {
                            if (data && data.status === 'ok') {
                                this.active = false;
                                this.removeBanner();
                                this.stopTimer();
                                this.stopPolling();
                                this.updateHeaderButton();
                                if (typeof updateSystemStatus === 'function') {
                                    updateSystemStatus();
                                }
                                BootstrapDialog.show({
                                    title: 'Session Confirmed',
                                    message: 'The protected change session has been ended. Your changes are confirmed.',
                                    type: BootstrapDialog.TYPE_INFO
                                });
                            } else {
                                BootstrapDialog.alert({
                                    title: 'Error',
                                    message: data.message || 'Failed to confirm session.',
                                    type: BootstrapDialog.TYPE_DANGER
                                });
                            }
                        });
                    }
                }
            });
        });

        $('#cs-btn-extend').off('click').on('click', () => {
            ajaxCall('/api/core/commit_session/extend', {}, (data) => {
                if (data && data.status === 'ok') {
                    if (this.sessionData) {
                        this.sessionData.extensions_count = data.extensions_count;
                        this.localRemainingSeconds += (data.extension_seconds || 300);
                    }
                    this.renderBanner();
                } else {
                    BootstrapDialog.alert({
                        title: 'Extend Failed',
                        message: data.message || 'Unable to extend countdown.',
                        type: BootstrapDialog.TYPE_WARNING
                    });
                }
            });
        });

        $('#cs-btn-revert').off('click').on('click', () => {
            let guiUrl = (this.sessionData && this.sessionData.gui_url) ? this.sessionData.gui_url : window.location.origin;
            BootstrapDialog.confirm({
                title: 'Revert Configuration',
                message: 'Are you sure you want to revert all changes immediately? The configuration will restore the pre-session snapshot and reload services.',
                type: BootstrapDialog.TYPE_DANGER,
                btnOKClass: 'btn-danger',
                btnOKLabel: 'Revert Now',
                callback: (result) => {
                    if (result) {
                        ajaxCall('/api/core/commit_session/revert', {}, (data) => {
                            this.stopTimer();
                            let targetUrl = (data && data.gui_url) ? data.gui_url : guiUrl;
                            if (this.sessionData) {
                                this.sessionData.status = 'reverting';
                                this.sessionData.reverting = true;
                                this.sessionData.revert_reason = 'manual';
                                this.sessionData.gui_url = targetUrl;
                            }
                            this.renderBanner();
                            if (!this.pollInterval) {
                                this.startPolling();
                            }
                        });
                    }
                }
            });
        });

        $('#cs-btn-diff').off('click').on('click', () => {
            ajaxGet('/api/core/commit_session/diff', {}, (data) => {
                let diffHtml = '';
                if (data && data.items && data.items.length > 0) {
                    diffHtml = '<div style="max-height:450px; overflow-y:auto; background:#222; color:#eee; padding:10px; font-family:monospace; font-size:12px; border-radius:4px;">';
                    data.items.forEach((line) => {
                        let color = '#ccc';
                        if (line.startsWith('+') && !line.startsWith('+++')) {
                            color = '#5cb85c';
                        } else if (line.startsWith('-') && !line.startsWith('---')) {
                            color = '#d9534f';
                        } else if (line.startsWith('@@')) {
                            color = '#5bc0de';
                        }
                        diffHtml += '<div style="color:' + color + '; white-space:pre-wrap;">' + line + '</div>';
                    });
                    diffHtml += '</div>';
                } else {
                    diffHtml = '<div class="alert alert-info">No configuration differences detected between snapshot and current configuration.</div>';
                }

                BootstrapDialog.show({
                    title: 'Snapshot versus Current Configuration Diff',
                    message: diffHtml,
                    size: BootstrapDialog.SIZE_WIDE,
                    buttons: [{
                        label: 'Close',
                        action: function(dialog) {
                            dialog.close();
                        }
                    }]
                });
            });
        });
    }

    checkNotice() {
        if (this.isForbidden) {
            return;
        }
        $.ajax({
            url: '/api/core/commit_session/notice',
            type: 'GET',
            dataType: 'json',
            timeout: 5000,
            success: (data) => {
                if (data && data.has_notice && data.notice) {
                    this.renderNoticeDialog(data.notice);
                }
            },
            error: (xhr) => {
                if (xhr && xhr.status === 403) {
                    this.isForbidden = true;
                }
            }
        });
    }

    renderNoticeDialog(notice) {
        let msg = `
            <div class="alert alert-warning">
                <i class="fa fa-info-circle fa-lg"></i>
                <strong>Notice:</strong> An automatic configuration rollback was executed on <strong>${notice.reverted_at_iso || 'recently'}</strong>.
                <br/>
                Reason: <em>${notice.reason || 'countdown expired'}</em>. Reverted to previous snapshot.
            </div>
            <p>You can review the rollback in configuration history or <a href="/ui/core/backup/history" class="alert-link" style="text-decoration:underline; font-weight:bold;">view the reverted diff here</a>.</p>
        `;

        BootstrapDialog.show({
            title: 'Configuration Rollback Notice',
            message: msg,
            type: BootstrapDialog.TYPE_WARNING,
            closable: true,
            buttons: [
                {
                    label: 'View Reverted Diff',
                    cssClass: 'btn-primary',
                    action: function() {
                        window.location.href = '/ui/core/backup/history';
                    }
                },
                {
                    label: 'Dismiss',
                    cssClass: 'btn-default',
                    action: function(dialog) {
                        ajaxCall('/api/core/commit_session/notice', {}, function() {
                            dialog.close();
                            if (typeof updateSystemStatus === 'function') {
                                updateSystemStatus();
                            }
                        });
                    }
                }
            ]
        });
    }
}

$(document).ready(function() {
    window.commitSessionManager = new CommitSessionManager();
    window.commitSessionManager.init();
});
