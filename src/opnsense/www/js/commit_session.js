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
    }

    init() {
        this.checkStatus();
        this.checkNotice();
        // Poll status every 8 seconds
        this.pollInterval = setInterval(() => {
            this.syncStatus();
        }, 8000);
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
        ajaxGet('/api/core/commit_session/status', {}, (data, status) => {
            if (status === 'success' && data && data.active) {
                this.sessionData = data;
                this.active = true;
                this.localRemainingSeconds = data.remaining_seconds || 0;
                this.failCount = 0;
                this.connectionLost = false;
                this.renderBanner();
                this.startTimer();
            } else {
                this.active = false;
                this.removeBanner();
                this.stopTimer();
            }
        });
    }

    syncStatus() {
        if (!this.active) {
            // Check once in a while if a session was started elsewhere
            ajaxGet('/api/core/commit_session/status', {}, (data, status) => {
                if (status === 'success' && data && data.active) {
                    this.sessionData = data;
                    this.active = true;
                    this.localRemainingSeconds = data.remaining_seconds || 0;
                    this.renderBanner();
                    this.startTimer();
                }
            });
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
                    this.renderBanner();
                } else {
                    this.active = false;
                    this.removeBanner();
                    this.stopTimer();
                }
            },
            error: () => {
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
            if (this.sessionData && this.sessionData.countdown_active) {
                if (this.localRemainingSeconds > 0) {
                    this.localRemainingSeconds--;
                    $('#cs-timer-val').text(this.formatTime(this.localRemainingSeconds));
                } else {
                    $('#cs-timer-val').text('0:00');
                    if (!this.revertingNoticeShown) {
                        this.revertingNoticeShown = true;
                        let guiUrl = (this.sessionData && this.sessionData.gui_url) ? this.sessionData.gui_url : window.location.origin;
                        $('#cs-banner-msg').html(
                            '<strong>Automatic rollback triggered.</strong> Reverting to snapshot... After the revert, reconnect at <a href="' +
                            guiUrl + '" class="alert-link" style="text-decoration:underline;">' + guiUrl + '</a>.'
                        );
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
        if (this.connectionLost) {
            this.renderConnectionLostBanner();
            return;
        }

        let $container = this.getContainer();
        let user = (this.sessionData && this.sessionData.username) ? this.sessionData.username : 'admin';
        let countdownActive = this.sessionData && this.sessionData.countdown_active;
        let extCount = (this.sessionData && this.sessionData.extensions_count) || 0;
        let maxExt = (this.sessionData && this.sessionData.max_extensions) || 6;
        let canExtend = countdownActive && (extCount < maxExt);

        let statusText = '';
        if (countdownActive) {
            statusText = 'Automatic rollback in <strong><span id="cs-timer-val">' + this.formatTime(this.localRemainingSeconds) + '</span></strong>.';
        } else {
            statusText = '<em>No changes yet.</em>';
        }

        let html = `
            <div id="commit-session-banner" class="alert alert-warning" style="margin: 10px 15px; padding: 12px 18px; border-left: 6px solid #f0ad4e; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                    <div id="cs-banner-msg" style="font-size:14px;">
                        <i class="fa fa-shield fa-lg" style="margin-right:6px; color:#c07d0a;"></i>
                        <strong>Protected change session</strong> by <strong>${user}</strong>. ${statusText}
                    </div>
                    <div class="btn-group btn-group-sm" role="group">
                        <button type="button" id="cs-btn-confirm" class="btn btn-success" title="Keep all changes and end session">
                            <i class="fa fa-check"></i> Confirm
                        </button>
                        <button type="button" id="cs-btn-extend" class="btn btn-default" ${canExtend ? '' : 'disabled="disabled"'} title="Add 5 minutes to countdown (${extCount}/${maxExt} used)">
                            <i class="fa fa-clock-o"></i> Extend +5 min
                        </button>
                        <button type="button" id="cs-btn-revert" class="btn btn-danger" title="Discard changes and restore snapshot now">
                            <i class="fa fa-undo"></i> Revert now
                        </button>
                        <button type="button" id="cs-btn-diff" class="btn btn-default" title="View configuration differences">
                            <i class="fa fa-file-text-o"></i> View diff
                        </button>
                    </div>
                </div>
            </div>
        `;

        $container.html(html);
        this.bindBannerEvents();
    }

    renderConnectionLostBanner() {
        let $container = this.getContainer();
        let revertTime = '';
        if (this.sessionData && this.sessionData.expire_epoch) {
            revertTime = this.formatEpochTime(this.sessionData.expire_epoch);
        } else if (this.localRemainingSeconds > 0) {
            let exp = Math.round(Date.now() / 1000) + this.localRemainingSeconds;
            revertTime = this.formatEpochTime(exp);
        } else {
            revertTime = 'shortly';
        }

        let guiUrl = (this.sessionData && this.sessionData.gui_url) ? this.sessionData.gui_url : window.location.origin;

        let html = `
            <div id="commit-session-banner" class="alert alert-danger" style="margin: 10px 15px; padding: 15px 20px; border-left: 6px solid #d9534f; box-shadow: 0 4px 8px rgba(0,0,0,0.15);">
                <div id="cs-banner-msg" style="font-size:15px; line-height:1.5;">
                    <i class="fa fa-exclamation-triangle fa-lg" style="margin-right:8px;"></i>
                    <strong>Connection lost.</strong> The firewall will revert at <strong>${revertTime}</strong> (<span id="cs-timer-val">${this.formatTime(this.localRemainingSeconds)}</span> remaining) if not confirmed.
                    <br/>
                    After the revert, reconnect at <a href="${guiUrl}" class="alert-link" style="text-decoration:underline; font-weight:bold;">${guiUrl}</a>.
                </div>
            </div>
        `;

        $container.html(html);
    }

    removeBanner() {
        $('#commit-session-banner').remove();
    }

    bindBannerEvents() {
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
                            let targetUrl = (data && data.gui_url) ? data.gui_url : guiUrl;
                            $('#cs-banner-msg').html(
                                '<strong>Reverting configuration now.</strong> Reconnecting to <a href="' +
                                targetUrl + '" class="alert-link">' + targetUrl + '</a>...'
                            );
                            setTimeout(() => {
                                window.location.href = targetUrl;
                            }, 5000);
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
        ajaxGet('/api/core/commit_session/notice', {}, (data, status) => {
            if (status === 'success' && data && data.has_notice && data.notice) {
                this.renderNoticeDialog(data.notice);
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
