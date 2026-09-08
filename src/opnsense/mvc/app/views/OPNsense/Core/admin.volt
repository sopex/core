{#
 # Copyright (C) 2026 Deciso B.V.
 # All rights reserved.
 #
 # Redistribution and use in source and binary forms, with or without modification,
 # are permitted provided that the following conditions are met:
 #
 # 1. Redistributions of source code must retain the above copyright notice,
 #    this list of conditions and the following disclaimer.
 #
 # 2. Redistributions in binary form must reproduce the above copyright notice,
 #    this list of conditions and the following disclaimer in the documentation
 #    and/or other materials provided with the distribution.
 #
 # THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
 # INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
 # AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 # AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
 # OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 # SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 # INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 # CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 # ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 # POSSIBILITY OF SUCH DAMAGE.
 #}

<script>
    $(document).ready(function() {
        let warningShown = false;

        // Map forms and populate initial data
        const data_get_map = {
            'frm_webgui': '/api/core/admin/get',
            'frm_ssh': '/api/core/admin/get',
            'frm_console': '/api/core/admin/get',
            'frm_auth': '/api/core/admin/get',
            'frm_deployment': '/api/core/admin/get'
        };

        mapDataToFormUI(data_get_map).done(function(data) {
            formatTokenizersUI();

            // Populate dynamic selectpicker options if returned by API
            if (data && data.frm_webgui) {
                const apiData = data.frm_webgui;

                // 1. SSL Certificates
                const $certSelect = $('#admin\\.webgui\\.ssl-certref');
                $certSelect.empty();
                const certs = apiData.certificates || {};
                const certKeys = Object.keys(certs);
                if (certKeys.length === 0) {
                    $('#admin\\.webgui\\.protocol option[value="https"]').prop('disabled', true);
                    $('#admin\\.webgui\\.protocol').val('http');
                    $('#no_certs_alert').show();
                } else {
                    $('#no_certs_alert').hide();
                    certKeys.forEach(function(refid) {
                        $certSelect.append(new Option(certs[refid], refid));
                    });
                    if (apiData.admin && apiData.admin.webgui && apiData.admin.webgui['ssl-certref']) {
                        $certSelect.val(apiData.admin.webgui['ssl-certref']);
                    }
                }

                // 2. SSL Ciphers
                const $sslCiphers = $('#admin\\.webgui\\.ssl-ciphers');
                $sslCiphers.empty();
                const ciphers = apiData.ciphers || {};
                Object.keys(ciphers).forEach(function(cKey) {
                    $sslCiphers.append(new Option(ciphers[cKey], cKey));
                });
                if (apiData.admin && apiData.admin.webgui && apiData.admin.webgui['ssl-ciphers']) {
                    const selectedCiphers = apiData.admin.webgui['ssl-ciphers'].split(',');
                    $sslCiphers.val(selectedCiphers);
                }

                // 3. WebGUI and SSH Interfaces
                const ifaces = apiData.interfaces || {};
                ['#admin\\.webgui\\.interfaces', '#admin\\.ssh\\.interfaces'].forEach(function(selector) {
                    const $ifSelect = $(selector);
                    $ifSelect.empty();
                    Object.keys(ifaces).forEach(function(ifKey) {
                        $ifSelect.append(new Option(ifaces[ifKey], ifKey));
                    });
                });
                if (apiData.admin && apiData.admin.webgui && apiData.admin.webgui.interfaces) {
                    $('#admin\\.webgui\\.interfaces').val(apiData.admin.webgui.interfaces.split(','));
                }
                if (apiData.admin && apiData.admin.ssh && apiData.admin.ssh.interfaces) {
                    $('#admin\\.ssh\\.interfaces').val(apiData.admin.ssh.interfaces.split(','));
                }

                // 4. Authentication Servers
                const $authSelect = $('#admin\\.webgui\\.authmode');
                $authSelect.empty();
                const authServers = apiData.authservers || {};
                Object.keys(authServers).forEach(function(aKey) {
                    $authSelect.append(new Option(authServers[aKey], aKey));
                });
                if (apiData.admin && apiData.admin.webgui && apiData.admin.webgui.authmode) {
                    $authSelect.val(apiData.admin.webgui.authmode.split(','));
                }

                // 5. OpenSSH Queries
                const sshOpts = apiData.sshoptions || {};
                const sshFieldMap = {
                    'kex': '#admin\\.ssh\\.kex',
                    'cipher': '#admin\\.ssh\\.ciphers',
                    'mac': '#admin\\.ssh\\.macs',
                    'key': '#admin\\.ssh\\.keys',
                    'key-sig': '#admin\\.ssh\\.keysig'
                };
                Object.keys(sshFieldMap).forEach(function(k) {
                    const $ctrl = $(sshFieldMap[k]);
                    $ctrl.empty();
                    (sshOpts[k] || []).forEach(function(opt) {
                        $ctrl.append(new Option(opt, opt));
                    });
                    const modelKey = (k === 'cipher') ? 'ciphers' : (k === 'key-sig' ? 'keysig' : k);
                    if (apiData.admin && apiData.admin.ssh && apiData.admin.ssh[modelKey]) {
                        $ctrl.val(apiData.admin.ssh[modelKey].split(','));
                    }
                });

                // 6. OpenSSH Rekey Limit
                const $rekey = $('#admin\\.ssh\\.rekeylimit');
                $rekey.empty();
                const rekeyLimits = apiData.ssh_rekeylimit_choices || apiData.rekeylimit_choices || {};
                Object.keys(rekeyLimits).forEach(function(rKey) {
                    $rekey.append(new Option(rekeyLimits[rKey], rKey));
                });
                if (apiData.admin && apiData.admin.ssh && apiData.admin.ssh.rekeylimit) {
                    $rekey.val(apiData.admin.ssh.rekeylimit);
                }

                // 7. Sudo Groups & User Token
                const $sudoGroup = $('#admin\\.console\\.sudo_allow_group');
                const $tokenGroup = $('#admin\\.console\\.user_allow_gen_token');
                $sudoGroup.empty();
                $tokenGroup.empty();
                $sudoGroup.append(new Option('wheel', ''));
                const groups = apiData.groups || {};
                Object.keys(groups).forEach(function(gKey) {
                    $sudoGroup.append(new Option('wheel, ' + groups[gKey], groups[gKey]));
                    $tokenGroup.append(new Option(groups[gKey], groups[gKey]));
                });
                if (apiData.admin && apiData.admin.console && apiData.admin.console.sudo_allow_group) {
                    $sudoGroup.val(apiData.admin.console.sudo_allow_group);
                }
                if (apiData.admin && apiData.admin.console && apiData.admin.console.user_allow_gen_token) {
                    $tokenGroup.val(apiData.admin.console.user_allow_gen_token.split(','));
                }

                // 8. Console Types
                const $priConsole = $('#admin\\.console\\.primaryconsole');
                const $secConsole = $('#admin\\.console\\.secondaryconsole');
                $priConsole.empty();
                $secConsole.empty();
                $secConsole.append(new Option("{{ lang._('None') }}", ''));
                const consoles = apiData.consoles || apiData.console_types || {};
                Object.keys(consoles).forEach(function(cKey) {
                    $priConsole.append(new Option(consoles[cKey], cKey));
                    $secConsole.append(new Option(consoles[cKey], cKey));
                });
                if (apiData.admin && apiData.admin.console && apiData.admin.console.primaryconsole) {
                    $priConsole.val(apiData.admin.console.primaryconsole);
                }
                if (apiData.admin && apiData.admin.console && apiData.admin.console.secondaryconsole) {
                    $secConsole.val(apiData.admin.console.secondaryconsole);
                }
            }

            $('.selectpicker').selectpicker('refresh');

            // Trigger protocol change to set initial port placeholder and SSL field visibility
            $('#admin\\.webgui\\.protocol').change();

            // Auto-expand SSH cryptographic overrides if any non-empty override exists
            let hasCryptoOverride = false;
            ['#admin\\.ssh\\.kex', '#admin\\.ssh\\.ciphers', '#admin\\.ssh\\.macs', '#admin\\.ssh\\.keys', '#admin\\.ssh\\.keysig', '#admin\\.ssh\\.rekeylimit'].forEach(function(fld) {
                const val = $(fld).val();
                if (val && val.length > 0 && val !== '') {
                    hasCryptoOverride = true;
                }
            });
            if (hasCryptoOverride) {
                $('#btn_toggle_advanced_ssh_crypto').click();
            }
        });

        // 1. Protocol Switcher
        $('#admin\\.webgui\\.protocol').change(function() {
            const proto = $(this).val();
            if (proto === 'https') {
                $('#admin\\.webgui\\.port').attr('placeholder', '443');
                $('#row_admin\\.webgui\\.ssl-certref, #row_admin\\.webgui\\.ssl-ciphers, #row_admin\\.webgui\\.ssl-hsts, #row_admin\\.webgui\\.disablehttpredirect').closest('tr').show();
            } else {
                $('#admin\\.webgui\\.port').attr('placeholder', '80');
                $('#row_admin\\.webgui\\.ssl-certref, #row_admin\\.webgui\\.ssl-ciphers, #row_admin\\.webgui\\.ssl-hsts, #row_admin\\.webgui\\.disablehttpredirect').closest('tr').hide();
            }
        });

        // 2. WebGUI Listen Interface Lockout Warning
        $('#admin\\.webgui\\.interfaces').change(function() {
            const selected = $(this).val();
            if (!selected || selected.length === 0) {
                warningShown = false;
            } else if (!warningShown) {
                warningShown = true;
                BootstrapDialog.confirm({
                    title: "{{ lang._('Warning!') }}",
                    message: "{{ lang._('Changing the listen interfaces of the web GUI may prevent you from accessing this page if you continue. It is recommended to keep this set to the default unless you know what you are doing.') }}",
                    type: BootstrapDialog.TYPE_WARNING,
                    btnOKClass: 'btn-warning',
                    btnOKLabel: "{{ lang._('I know what I am doing') }}",
                    btnCancelLabel: "{{ lang._('Use the default') }}",
                    callback: function(result) {
                        if (!result) {
                            $('#admin\\.webgui\\.interfaces option:selected').prop('selected', false);
                            $('#admin\\.webgui\\.interfaces').selectpicker('refresh');
                            warningShown = false;
                        }
                    }
                });
            }
        });

        // 3. OpenSSH Cryptographic Overrides Expand/Collapse
        const $sshCryptoRows = $('#row_admin\\.ssh\\.kex, #row_admin\\.ssh\\.ciphers, #row_admin\\.ssh\\.macs, #row_admin\\.ssh\\.keys, #row_admin\\.ssh\\.keysig, #row_admin\\.ssh\\.rekeylimit').closest('tr');
        $sshCryptoRows.hide();

        const toggleBtnHtml = `
            <tr id="row_toggle_ssh_crypto">
                <td>
                    <div class="control-label">
                        <i class="fa fa-info-circle text-muted"></i> <b>{{ lang._('Advanced') }}</b>
                    </div>
                </td>
                <td>
                    <button id="btn_toggle_advanced_ssh_crypto" type="button" class="btn btn-xs btn-default">
                        <i class="fa fa-chevron-down"></i> {{ lang._('Show cryptographic overrides') }}
                    </button>
                </td>
                <td></td>
            </tr>
        `;
        $('#row_admin\\.ssh\\.interfaces').closest('tr').after(toggleBtnHtml);

        $('#btn_toggle_advanced_ssh_crypto').click(function(e) {
            e.preventDefault();
            if ($sshCryptoRows.is(':visible')) {
                $sshCryptoRows.hide();
                $(this).html('<i class="fa fa-chevron-down"></i> {{ lang._('Show cryptographic overrides') }}');
            } else {
                $sshCryptoRows.show();
                $(this).html('<i class="fa fa-chevron-up"></i> {{ lang._('Hide cryptographic overrides') }}');
            }
            $(window).trigger('resize');
        });

        // 4. Save and Reconfigure Action Handler with WebGUI Restart Polling
        function handleReconfigureResponse(data) {
            if (data && data.restart_webgui && data.redirect_url) {
                const redirectUrl = data.redirect_url;
                BootstrapDialog.show({
                    type: BootstrapDialog.TYPE_INFO,
                    title: "{{ lang._('Applying Settings') }}",
                    closable: false,
                    message: "{{ lang._('The web GUI is reloading at the moment, please wait...') }} " +
                             '<i class="fa fa-cog fa-spin"></i><br/><br/>' +
                             "{{ lang._('If the page does not reload automatically, follow this link:') }} " +
                             '<a href="' + redirectUrl + '" target="_blank">' + redirectUrl + '</a>',
                    onshow: function() {
                        setTimeout(function() {
                            pollTarget(redirectUrl);
                        }, 20000);
                    }
                });

                function pollTarget(targetUrl) {
                    $.ajax({
                        url: targetUrl,
                        timeout: 1250
                    }).done(function() {
                        window.location.assign(targetUrl);
                    }).fail(function() {
                        setTimeout(function() {
                            pollCurrent(targetUrl);
                        }, 1250);
                    });
                }

                function pollCurrent(targetUrl) {
                    $.ajax({
                        url: '/ui/core/admin',
                        timeout: 1250
                    }).done(function() {
                        window.location.assign('/ui/core/admin');
                    }).fail(function() {
                        setTimeout(function() {
                            pollTarget(targetUrl);
                        }, 1250);
                    });
                }
            }
        }

        // Attach SimpleActionButton to each tab's save button
        $('[id^="save_"]').each(function() {
            const $btn = $(this);
            const formId = this.id.replace(/^save_/, 'frm_');

            $btn.attr({
                'data-label': "{{ lang._('Save') }}",
                'data-endpoint': '/api/core/admin/reconfigure'
            });

            $btn.SimpleActionButton({
                onPreAction: function() {
                    const dfObj = new $.Deferred();
                    saveFormToEndpoint(
                        '/api/core/admin/set',
                        formId,
                        function() { dfObj.resolve(); },
                        true,
                        function() { dfObj.reject(); }
                    );
                    return dfObj.promise();
                },
                onAction: function(data, status) {
                    handleReconfigureResponse(data);
                }
            });
        });
    });
</script>

<div id="no_certs_alert" class="alert alert-warning" style="display:none;" role="alert">
    {{ lang._('No Certificates have been defined. You must create or import a Certificate before SSL can be enabled.') }}
    <a href="/ui/trust/cert" class="alert-link">{{ lang._('Certificate Manager') }}</a>
</div>

<ul class="nav nav-tabs" role="tablist" id="maintabs">
    {{ partial('layout_partials/base_tabs_header', ['formData': adminForm]) }}
</ul>

<div class="content-box tab-content">
    {{ partial('layout_partials/base_tabs_content', ['formData': adminForm]) }}
</div>
