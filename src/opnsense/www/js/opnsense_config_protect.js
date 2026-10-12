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

/**
 * Configuration protection navbar button, the timer itself is owned by the backend,
 * this only reflects its state and offers enable/confirm/extend/disable.
 * Translated texts are passed as data attributes on #config_protect.
 */
function initConfigProtect() {
    const $toggle = $('#config_protect');
    if ($toggle.length === 0) {
        return;
    }
    const text = $toggle.data();
    let status = null;
    let deadline = null;
    let lastReverted = undefined;
    let pollTimer = null;

    function formatRemaining() {
        const seconds = Math.max(0, Math.round((deadline - Date.now()) / 1000));
        return Math.floor(seconds / 60) + ':' + String(seconds % 60).padStart(2, '0');
    }

    function render() {
        const $icon = $toggle.find('i');
        const reverting = status.reverting || (status.pending && deadline <= Date.now());
        $icon.removeClass('text-muted text-success text-warning text-danger');
        $icon.toggleClass('fa-shield', !reverting).toggleClass('fa-spinner fa-pulse', reverting);
        if (!status.enabled) {
            $icon.addClass('text-muted');
            $('#config_protect_remaining').text('');
        } else if (reverting) {
            $icon.addClass('text-warning');
            $('#config_protect_remaining').text('');
        } else if (!status.pending) {
            $icon.addClass('text-success');
            $('#config_protect_remaining').text('');
        } else {
            $icon.addClass(deadline - Date.now() < 60000 ? 'text-danger' : 'text-warning');
            $('#config_protect_remaining, .config-protect-remaining').text(formatRemaining());
        }
    }

    function update(data) {
        clearTimeout(pollTimer);
        if (data.enabled === undefined) {
            // no access (button stays hidden) or backend failure, the GUI may be unreachable while reverting
            if (status !== null && status.enabled) {
                pollTimer = setTimeout(poll, 5000);
            }
            return;
        }
        if (lastReverted !== undefined && data.reverted && data.reverted !== lastReverted) {
            // revert finished, show the result shortly and load the page with the restored settings
            BootstrapDialog.show({
                type: BootstrapDialog.TYPE_WARNING,
                title: text.title,
                message: text.reverted,
                closable: false
            });
            setTimeout(function () {
                window.location.reload();
            }, 2000);
            return;
        }
        lastReverted = data.reverted ?? null;
        status = data;
        deadline = data.pending ? Date.now() + data.remaining * 1000 : null;
        $toggle.show();
        render();
        if (status.enabled) {
            // pick up timers started by changes and actions from other sessions
            const soon = status.reverting || (status.pending && status.remaining < 10);
            pollTimer = setTimeout(poll, soon ? 2000 : 10000);
        }
    }

    function poll() {
        ajaxGet('/api/core/config_protect/status', {}, update);
    }

    function action(name) {
        ajaxCall('/api/core/config_protect/' + name, {}, update);
    }

    function showDialog() {
        let message = $('<div/>');
        if (status.pending) {
            const parts = text.pending.split('%s');
            message.append($('<p/>').append(
                document.createTextNode(parts[0]),
                $('<b class="config-protect-remaining"/>'),
                document.createTextNode(parts.slice(1).join('%s'))
            ));
        } else if (status.enabled) {
            message.append($('<p/>').text(text.active));
        }
        message.append($('<p/>').text(text.help));

        let buttons = [];
        if (status.pending) {
            buttons.push({label: text.confirm, cssClass: 'btn-primary', action: function (dialog) {
                action('confirm');
                dialog.close();
            }});
            buttons.push({label: text.extend, action: function (dialog) {
                action('extend');
                dialog.close();
            }});
            buttons.push({label: text.revert, cssClass: 'btn-danger', action: function (dialog) {
                action('revert');
                dialog.close();
            }});
        } else if (status.enabled) {
            // pending changes are either confirmed or reverted, not left behind by disabling
            buttons.push({label: text.disable, action: function (dialog) {
                action('disable');
                dialog.close();
            }});
        } else {
            buttons.push({label: text.enable, cssClass: 'btn-primary', action: function (dialog) {
                action('enable');
                dialog.close();
            }});
        }
        buttons.push({label: text.close, action: function (dialog) {
            dialog.close();
        }});

        BootstrapDialog.show({
            type: status.pending ? BootstrapDialog.TYPE_WARNING : BootstrapDialog.TYPE_INFO,
            title: text.title,
            message: message,
            buttons: buttons,
            onshown: render
        });
    }

    $toggle.click(function () {
        if (status !== null) {
            showDialog();
        }
    });

    // countdown between status polls
    setInterval(function () {
        if (status !== null && status.pending) {
            render();
        }
    }, 1000);

    // saves made from this page start the timer, refresh shortly after
    $(document).ajaxComplete(function (event, xhr, settings) {
        if (status !== null && status.enabled && settings.type === 'POST' &&
                !settings.url.startsWith('/api/core/config_protect/')) {
            clearTimeout(pollTimer);
            pollTimer = setTimeout(poll, 2000);
        }
    });

    poll();
}
