{#
 # Copyright (c) 2015-2026 Franco Fichtner <franco@opnsense.org>
 # Copyright (c) 2015-2018 Deciso B.V.
 # All rights reserved.
 #
 # Redistribution and use in source and binary forms, with or without modification,
 # are permitted provided that the following conditions are met:
 #
 # 1.  Redistributions of source code must retain the above copyright notice,
 # this list of conditions and the following disclaimer.
 #
 # 2.  Redistributions in binary form must reproduce the above copyright notice,
 # this list of conditions and the following disclaimer in the documentation
 # and/or other materials provided with the distribution.
 #
 # THIS SOFTWARE IS PROVIDED "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES,
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

<style>
    /* "dropdown in responsive table" issue workaround: display dummy road so dropdowns fits the table */
    @media screen and (max-width: 767px) {
        .dropdown_helper {
            display: block !important;
        }
    }
    .dropdown_helper {
        display: none;
    }
</style>

<script>

    function generic_search(that, entries) {
        let search = $(that).val().toLowerCase();
        $('.' + entries).each(function () {
            let row_by_col = $(this).find('td').map(function () {
                return $(this).text();
            }).get();
            let name = row_by_col.join(',').toLowerCase();
            if (entries == 'plugin_entry' && !$(this).hasClass('filter_sup_inst') && $.plugins_keep_full == undefined) {
                $(this).hide();
            } else if (search.length != 0 && name.indexOf(search) == -1) {
                $(this).hide();
            } else {
                $(this).show();
            }
        });
    }

    function cancel_update() {
        $('#updatelist').hide();
        $('#update_status_container').show();
    }

    /**
     * retrieve update status from backend
     */
    function updateStatus() {
        $('#upgrade_maj').hide();
        $('#upgrade').hide();

        ajaxGet('/api/core/firmware/status', {}, function (data, status){
            if (data['status'] == "update") {
                let show_log = '';

                $.status_reboot = data['status_reboot'];

                // show upgrade list
                $('#upgrade').show();
                $('#updatestatus').html(data['status_msg']);
                $('#updatelist > tbody').empty();
                $('#updatetab > a').tab('show');
                $.each(data['all_packages'], function (index, row) {
                    $('#updatelist > tbody').append('<tr><td>'+row['name']+'</td>' +
                    '<td>'+row['old']+'</td><td>'+row['new']+'</td><td>' +
                    row['reason']+'</td><td>'+row['repository'] + '</td></tr>');

                    if (row['name'] == data['product_target'] && row['new'] != 'N/A') {
                        show_log = row['new'].replace(/[p_-].*/, '').replace(/\.0/, '');
                    }
                });
                $('#update_status_container').hide();
                $('#updatelist').show();

                // display the current changelog if one was found
                if (show_log != '') {
                    changelog(show_log);
                }

                packagesInfo(false);
            } else if (data['status'] == "upgrade") {
                $.status_reboot = data['status_reboot'];

                if (data['upgrade_major_message'] != '') {
                    /* we trust this data, it was signed by us and secured by csrf */
                    stdDialogInform(
                        '{{ lang._('Upgrade instructions') }}',
                        htmlDecode(data['upgrade_major_message']),
                        "{{ lang._('OK') }}",
                        function () { show_upgrade(data) },
                        'warning'
                    );
                } else {
                    show_upgrade(data);
                }

                packagesInfo(false);
            } else if (data['status'] == "error") {
                stdDialogInform('{{ lang._('Firmware status') }}', data['status_msg'], "{{ lang._('Close') }}", undefined, 'danger');
                packagesInfo(true);
            } else if (data['status'] == "none") {
                stdDialogInform('{{ lang._('Firmware status') }}', data['status_msg'], "{{ lang._('Close') }}", undefined, 'success');
                packagesInfo(true);
            } else {
                // in case new responses are added
                console.log('Unknown check response');
                console.log(data);
            }
        });
    }

    /**
     * perform backend action and install poller to update status
     */
    function backend(type, params) {
        $.upgrade_check = type == 'check';

        $('#update_status').html('');
        $('#updatelist').hide();
        $('#update_status_container').show();
        $('#updatetab > a').tab('show');
        $('#updatetab_progress').addClass("fa fa-spinner fa-pulse");

        ajaxCall('/api/core/firmware/' + type, params || {}, function (data) {
            if (data && data['status'] == 'blocked' && data['preflight']) {
                $('#updatetab_progress').removeClass("fa fa-spinner fa-pulse");
                show_preflight_dialog(type === 'upgrade', data['preflight']);
                return;
            }
            setTimeout(trackStatus, 500);
        });
    }

    /**
     * read package details from backend
     */
    function details(package)
    {
        ajaxCall('/api/core/firmware/details/' + package, {}, function (data, status) {
            var details = "{{ lang._('Sorry, plugin details are currently not available.') }}";
            if (data['details'] != undefined) {
                details = data['details'];
            }
            stdDialogInform("{{ lang._('Plugin details') }}", details, "{{ lang._('Close') }}");
        });
    }

    /**
     * read license from backend
     */
    function license(package)
    {
        ajaxCall('/api/core/firmware/license/' + package, {}, function (data, status) {
            var license = "{{ lang._('Sorry, the package does not have an associated license file.') }}";
            if (data['license'] != undefined) {
                license = data['license'];
            }
            stdDialogInform("{{ lang._('License details') }}", license, "{{ lang._('Close') }}");
        });
    }

    /**
     * read changelog from backend
     */
    function changelog(version)
    {
        ajaxCall('/api/core/firmware/changelog/' + version, {}, function (data, status) {
            if (data['html'] != undefined) {
                header = data['version'];
                if (data['date'] != '') {
                    header += ' (' + data['date'] + ')';
                }
                /* we trust this data, it was signed by us and secured by csrf */
                stdDialogInform(header, htmlDecode(data['html']), "{{ lang._('Close') }}", undefined, 'primary');
            }
        });
    }

    /**
     * perform package action that requires reboot confirmation
     */
    function action_may_reboot(pkg_act, pkg_name)
    {
        if (pkg_act == 'reinstall' && (pkg_name == 'kernel' || pkg_name == 'base')) {
            // reboot required, inform the user.
            BootstrapDialog.show({
                type:BootstrapDialog.TYPE_WARNING,
                title: "{{ lang._('Reboot required') }}",
                message: "{{ lang._('The firewall will reboot directly after this set reinstall.') }}",
                buttons: [{
                    label: "{{ lang._('OK') }}",
                    cssClass: 'btn-warning',
                    action: function(dialogRef){
                        dialogRef.close();
                        backend(pkg_act + "/" + pkg_name);
                    }
                },{
                    label: "{{ lang._('Cancel') }}",
                    action: function(dialogRef){
                        dialogRef.close();
                    }
                }]
            });
        } else {
            backend(pkg_act + "/" + pkg_name);
        }
    }

    function show_upgrade(data) {
        $('#upgrade_maj').show();
        $('#updatestatus').html(data['status_msg']);
        $('#updatelist > tbody').empty();
        $('#updatetab > a').tab('show');
        $.each(data['all_sets'], function (index, row) {
            $('#updatelist > tbody').append('<tr><td>'+row['name']+'</td>' +
            '<td>'+row['old']+'</td><td>'+row['new']+'</td><td>' +
            row['reason']+'</td><td>'+row['repository'] + '</td></tr>');
        });
        $('#update_status_container').hide();
        $('#updatelist').show();
        changelog(data['upgrade_major_version']);
    }

    /**
     * safely decode HTML entities from browser API JSON responses for .text() insertion
     */
    function decodeApiText(val) {
        return (val !== null && val !== undefined && val !== '') ? htmlDecode(String(val)) : '';
    }

    /**
     * build DOM content for a pre-flight validation report
     */
    function build_preflight_dom(report, reboot_msg, readonlyMode) {
        let $wrap = $('<div/>');
        let summary = (report && report.summary) ? report.summary : { blockers: 0, warnings: 0, info: 0 };
        let findings = (report && Array.isArray(report.findings)) ? report.findings : [];

        if (reboot_msg) {
            $wrap.append($('<p/>').append($('<strong/>').text(reboot_msg)));
        }

        let $summary = $('<div class="__mb"/>');
        $summary.append($('<span class="label label-danger"/>').text("{{ lang._('Blockers') }}: " + (summary.blockers || 0))).append(' ');
        $summary.append($('<span class="label label-warning"/>').text("{{ lang._('Warnings') }}: " + (summary.warnings || 0))).append(' ');
        $summary.append($('<span class="label label-info"/>').text("{{ lang._('Info') }}: " + (summary.info || 0)));
        $wrap.append($summary);

        if (findings.length > 0) {
            let $table = $('<table class="table table-condensed table-striped"/>');
            let $tbody = $('<tbody/>');
            findings.forEach(function (item) {
                let badgeClass = 'label-info';
                if (item.severity === 'BLOCKER') {
                    badgeClass = 'label-danger';
                } else if (item.severity === 'WARNING') {
                    badgeClass = 'label-warning';
                }
                let $tr = $('<tr/>');
                $tr.append($('<td style="width:90px;vertical-align:top;"/>').append(
                    $('<span class="label"/>').addClass(badgeClass).text(decodeApiText(item.severity || 'INFO'))
                ));
                let $detail = $('<td/>');
                $detail.append($('<strong/>').text(decodeApiText(item.title)));
                if (item.message) {
                    $detail.append($('<div/>').text(decodeApiText(item.message)));
                }
                if (item.remediation) {
                    $detail.append($('<div class="text-muted"><small/></div>').find('small').text(decodeApiText(item.remediation)).end());
                }
                $tr.append($detail);
                $tbody.append($tr);
            });
            $table.append($tbody);
            $wrap.append($('<div style="max-height:360px;overflow-y:auto;"/>').append($table));
        } else {
            $wrap.append($('<p class="text-success"/>').text("{{ lang._('All pre-flight validation checks passed.') }}"));
        }

        if (!readonlyMode && (summary.blockers || 0) > 0) {
            let $overrideBox = $('<div class="alert alert-danger" style="margin-top:10px;margin-bottom:0;"/>');
            let $label = $('<label style="font-weight:normal;cursor:pointer;margin-bottom:0;"/>');
            let $cb = $('<input type="checkbox" id="preflight_override_cb" style="margin-right:6px;"/>');
            $label.append($cb).append(document.createTextNode("{{ lang._('I understand the risks and want to override pre-flight blockers and proceed anyway.') }}"));
            $overrideBox.append($label);
            $wrap.append($overrideBox);
        }

        return $wrap;
    }

    /**
     * display pre-flight validation report before confirming update or upgrade
     */
    function show_preflight_dialog(major, report, readonlyMode) {
        let blockers = (report && report.summary) ? (report.summary.blockers || 0) : 0;
        let warnings = (report && report.summary) ? (report.summary.warnings || 0) : 0;
        let reboot_msg = '';

        if (!readonlyMode) {
            if (major === true) {
                reboot_msg = "{{ lang._('The firewall will download all firmware sets and reboot multiple times for this upgrade. All operating system files and packages will be reinstalled as a consequence. This may take several minutes to complete.') }}";
            } else if ($.status_reboot == "1") {
                reboot_msg = "{{ lang._('The firewall will reboot directly after this firmware update.') }}";
            }
        }

        let dlgType = BootstrapDialog.TYPE_INFO;
        if (blockers > 0) {
            dlgType = BootstrapDialog.TYPE_DANGER;
        } else if (warnings > 0 || reboot_msg) {
            dlgType = BootstrapDialog.TYPE_WARNING;
        }

        let buttons = [];
        if (!readonlyMode) {
            buttons.push({
                id: 'btn_preflight_proceed',
                label: blockers > 0 ? "{{ lang._('Override and Proceed') }}" : "{{ lang._('Proceed') }}",
                cssClass: blockers > 0 ? 'btn-danger' : (warnings > 0 || reboot_msg ? 'btn-warning' : 'btn-primary'),
                action: function (dialogRef) {
                    let doOverride = blockers > 0 && dialogRef.getModalBody().find('#preflight_override_cb').is(':checked');
                    if (blockers > 0 && !doOverride) {
                        return;
                    }
                    dialogRef.close();
                    backend(major === true ? 'upgrade' : 'update', doOverride ? { override: '1' } : {});
                }
            });
        }
        buttons.push({
            label: readonlyMode ? "{{ lang._('Close') }}" : "{{ lang._('Cancel') }}",
            action: function (dialogRef) {
                dialogRef.close();
            }
        });

        BootstrapDialog.show({
            type: dlgType,
            size: BootstrapDialog.SIZE_WIDE,
            title: "{{ lang._('Pre-Flight Validation Report') }}",
            message: build_preflight_dom(report, reboot_msg, readonlyMode),
            buttons: buttons,
            onshown: function (dialogRef) {
                if (!readonlyMode && blockers > 0) {
                    let $btn = dialogRef.getButton('btn_preflight_proceed');
                    $btn.disable();
                    dialogRef.getModalBody().find('#preflight_override_cb').on('change', function () {
                        if ($(this).is(':checked')) {
                            $btn.enable();
                        } else {
                            $btn.disable();
                        }
                    });
                }
            }
        });
    }

    /**
     *  run pre-flight validation, show report and reboot notice before confirming
     */
    function upgrade_ui(major)
    {
        let mode = major === true ? 'upgrade' : 'update';
        ajaxGet('/api/core/firmware/preflight/' + mode, {}, function (report, status) {
            if (status !== 'success' || !report) {
                report = {
                    status: 'error',
                    can_proceed: false,
                    summary: { blockers: 1, warnings: 0, info: 0 },
                    findings: [{
                        severity: 'BLOCKER',
                        title: "{{ lang._('Pre-flight check failed') }}",
                        message: "{{ lang._('Unable to retrieve pre-flight validation report from backend.') }}"
                    }]
                };
            }
            show_preflight_dialog(major, report, false);
        });
    }

    /**
     * load and render ZFS Boot Environment Auto-Guard status
     */
    function loadBeGuardStatus() {
        ajaxGet('/api/core/firmware/beguard_status', {}, function (data, status) {
            if (status !== 'success' || !data) {
                return;
            }
            if (!data.zfs_supported) {
                $('#beguard_status_text').text("{{ lang._('Not available (non-ZFS root filesystem)') }}");
            } else if (!data.configured_enabled) {
                $('#beguard_status_text').text("{{ lang._('Disabled') }}");
            } else {
                $('#beguard_status_text').text(
                    "{{ lang._('Enabled') }}" +
                    ' (' + "{{ lang._('BE') }}: " + (decodeApiText(data.current_be) || 'unknown') +
                    ', ' + "{{ lang._('timeout') }}: " + data.timeout + 's' +
                    ', ' + "{{ lang._('retention') }}: " + data.retention + ')'
                );
            }

            if (data.state === 'reverted') {
                $('#beguard_revert_reason').text(
                    decodeApiText(data.reason) || "{{ lang._('Previous upgrade failed boot verification and was automatically reverted.') }}"
                );
                let meta = [];
                if (data.target_be) {
                    meta.push("{{ lang._('Failed BE') }}: " + decodeApiText(data.target_be));
                }
                if (data.fallback_be) {
                    meta.push("{{ lang._('Reverted to') }}: " + decodeApiText(data.fallback_be));
                }
                if (data.reverted_at) {
                    meta.push("{{ lang._('Time') }}: " + new Date(data.reverted_at * 1000).toLocaleString());
                }
                $('#beguard_revert_meta').text(meta.join(' | '));
                $('#beguard_revert_alert').show();
            } else {
                $('#beguard_revert_alert').hide();
            }

            if (data.state === 'armed') {
                $('#beguard_armed_detail').text(
                    "{{ lang._('Boot verification is active for') }} " + decodeApiText(data.target_be) +
                    ' (' + "{{ lang._('fallback') }}: " + decodeApiText(data.fallback_be) + ')'
                );
                $('#beguard_armed_alert').show();
            } else {
                $('#beguard_armed_alert').hide();
            }
        });
    }

    function rebootWait() {
        $.ajax({
            url: '/',
            timeout: 2500
        }).fail(function () {
            setTimeout(rebootWait, 2500);
        }).done(function () {
            $(location).attr('href', '/');
        });
    }

    function trackStatus() {
        ajaxGet('/api/core/firmware/upgradestatus', { v: Date.now() }, function(data, status) {
            if (status != 'success') {
                // recover from temporary errors
                setTimeout(trackStatus, 1000);
                return;
            }
            if (data['log'] != undefined && data['log'] != '') {
                var autoscroll = $('#update_status')[0].scrollTop +
                    $('#update_status')[0].clientHeight ===
                    $('#update_status')[0].scrollHeight;
                $('#update_status').html(data['log']);
                if (autoscroll) {
                    $('#update_status').scrollTop($('#update_status')[0].scrollHeight);
                }
            }
            if (data['status'] == 'done') {
                $('#updatetab_progress').removeClass("fa fa-spinner fa-pulse");
                if ($.upgrade_check === true) {
                    updateStatus();
                } else {
                    packagesInfo(true);
                    loadBeGuardStatus();
                }
            } else if (data['status'] == 'reboot') {
                BootstrapDialog.show({
                    type:BootstrapDialog.TYPE_INFO,
                    title: "{{ lang._('Performing reboot to finish this update') }}",
                    closable: false,
                    message: "{{ lang._('The system is being rebooted at the moment, please wait...') }}" +
                        ' <i class="fa fa-cog fa-spin"></i>',
                    onshow: function (dialogRef) {
                        setTimeout(rebootWait, 45000);
                    },
                });
            } else {
                // schedule next poll
                setTimeout(trackStatus, 500);
            }
        });
    }

    /**
     * show package info
     */
    function packagesInfo(reset) {
        $("#statustab_progress").addClass("fa fa-spinner fa-pulse");
        ajaxGet('/api/core/firmware/info', {}, function (data, status) {
            $('#packageslist > tbody').empty();
            $('#pluginlist > tbody').empty();
            var installed = {};

            $.each(data['product'], function(key, value) {
                if (key == 'product_check') {
                    if (value != null) {
                        $('#product_time_check').text(value['last_check']);
                    } else {
                        $('#product_time_check').text("{{ lang._('N/A') }}");
                    }
                } else {
                    $('#' + key).text(value);
                }
            });

            if (data['product']['product_license'] != undefined) {
                $.each(data['product']['product_license'], function(key, value) {
                    $('#product_license_' + key).text(value).closest('tr').show();
                });
                if (!Object.keys(data['product']['product_license']).length) {
                    $('[id^=product_license_]').closest('tr').hide();
                }
            }

            $("#statustab_progress").removeClass("fa fa-spinner fa-pulse");

            if (reset === true) {
                ajaxGet('/api/core/firmware/upgradestatus', { v: Date.now() }, function(data, status) {
                    if (data['log'] != undefined && data['log'] != '') {
                        $('#update_status').html(data['log']);
                    } else {
                        $('#update_status').html('{{ lang._('No previous action log found.') }}');
                    }
                    $('#update_status').scrollTop($('#update_status')[0].scrollHeight);
                });

                $('#updatelist').hide();
                $('#update_status_container').show();
            }

            var local_count = 0;
            var plugin_count = 0;
            var misconfigured_plugins = 0;
            var missing_plugins = 0;
            var changelog_count = 0;
            var changelog_max = 15;
            if ($.changelog_keep_full != undefined) {
                changelog_max = 9999;
            }

            $.each(data['package'], function(index, row) {
                if (row['installed'] == "1") {
                    local_count += 1;
                } else {
                    return 1;
                }
                $('#packageslist > tbody').append(
                    '<tr class="package_entry">' +
                    '<td>' + row['name'] + '</td>' +
                    '<td>' + row['version'] + '</td>' +
                    '<td>' + row['flatsize'] + '</td>' +
                    '<td>' + row['repository'] + '</td>' +
                    '<td>' + row['license'] + '</td>' +
                    '<td>' + row['comment'] + '</td>' +
                    '<td style="white-space:nowrap;vertical-align:middle;"><div class="input-group">' +
                    '<button class="btn btn-default btn-xs act_license" data-package="' + row['name'] + '" ' +
                    '  data-toggle="tooltip" title="{{ lang._('License') }}">' +
                    '<i class="fa fa-balance-scale fa-fw"></i></button> ' +
                    '<button class="btn btn-default btn-xs act_reinstall" data-package="' + row['name'] + '" ' +
                    '  data-toggle="tooltip" title="{{ lang._('Reinstall') }}">' +
                    '<i class="fa fa-recycle fa-fw"></i></button> ' + (row['locked'] === '1' ?
                        '<button data-toggle="tooltip" title="{{ lang._('Unlock') }}" class="btn btn-default btn-xs act_unlock" data-package="' + row['name'] + '">' +
                        '<i class="fa fa-lock fa-fw">' +
                        '</i></button>' :
                        '<button class="btn btn-default btn-xs act_lock" data-package="' + row['name'] + '" ' +
                        '  data-toggle="tooltip" title="{{ lang._('Lock') }}" >' +
                        '<i class="fa fa-unlock fa-fw"></i></button>'
                    ) + '</div></td>' +
                    '</tr>'
                );
            });

            if (local_count == 0) {
                $('#packageslist > tbody').append(
                    '<tr><td colspan=7>{{ lang._('No packages were found on your system. Please call for help.') }}</td></tr>'
                );
            }

            $.each(data['plugin'], function(index, row) {
                if (row['provided'] == "1") {
                    plugin_count += 1;
                }
                let status_text = '';
                let bold_on = '';
                let bold_off = '';
                if (row['installed'] == "1" && row['configured'] == "0") {
                    status_text = ' ({{ lang._('misconfigured') }})';
                    bold_on = '<b>';
                    bold_off = '</b>';
                    misconfigured_plugins = 1;
                } else if (row['installed'] == "0" && row['configured'] == "1") {
                    status_text = ' ({{ lang._('missing') }})';
                    bold_on = '<span class="text-danger"><b>';
                    bold_off = '</b></span>';
                    missing_plugins = 1;
                } else if (row['installed'] == "1") {
                    if (row['provided'] == "0") {
                        status_text = ' ({{ lang._('orphaned') }})';
                    } else {
                        status_text = ' ({{ lang._('installed') }})';
                    }
                    bold_on = '<b>';
                    bold_off = '</b>';
                }
                let cls_filter_sup_inst = '';
                if (['1', '2'].includes(row['tier']) || row['installed'] == '1' || row['configured'] == "1") {
                    cls_filter_sup_inst = 'filter_sup_inst'; // supported [tier 1,2] or (should be) installed
                }
                $('#pluginlist > tbody').append(
                    '<tr class="plugin_entry '+cls_filter_sup_inst+'">' +
                    '<td>' + bold_on + row['name'] + status_text + bold_off + '</td>' +
                    '<td>' + bold_on + row['version'] + bold_off + '</td>' +
                    '<td>' + bold_on + row['flatsize'] + bold_off + '</td>' +
                    '<td>' + bold_on + row['tier'] + bold_off + '</td>' +
                    '<td>' + bold_on + row['repository'] + bold_off + '</td>' +
                    '<td>' + bold_on + row['comment'] + bold_off + '</td>' +
                    '<td style="white-space:nowrap;vertical-align:middle;"><div class="input-group">' +
                    '<button class="btn btn-default btn-xs act_details" data-package="' + row['name'] + '" ' +
                        ' data-toggle="tooltip" title="{{ lang._('Info') }}">' +
                        '<i class="fa fa-info-circle fa-fw"></i></button>' +
                        (row['installed'] == "1" ?
                        '<button class="btn btn-default btn-xs act_remove" data-package="' + row['name'] + '" '+
                        '  data-toggle="tooltip" title="{{ lang._('Remove') }}">' +
                        '<i class="fa fa-trash fa-fw">' +
                        '</i></button>' :
                        '<button class="btn btn-default btn-xs act_install" data-package="' + row['name'] + '" ' +
                        'data-repository="'+row['repository']+'" data-toggle="tooltip" title="{{ lang._('Install') }}">' +
                        '<i class="fa fa-plus fa-fw">' +
                        '</i></button>'
                    ) + '</div></td>' + '</tr>'
                );
            });

            if (plugin_count == 0) {
                $('#pluginlist > tbody').append(
                    '<tr><td colspan=7>{{ lang._('Check for updates to view available plugins.') }}</td></tr>'
                );
            } else if ($.plugins_keep_full == undefined) {
                $('#pluginlist > tbody').append(
                    '<tr class="plugins-full filter_sup_inst"><td colspan=7><a id="plugins-act" href="#">{{ lang._('Click to view the community plugins.') }}</a></td></tr>'
                );
                $("#plugins-act").click(function(event) {
                    event.preventDefault();
                    $(".plugins-hidden").attr('style', '');
                    $(".plugins-full").attr('style', 'display: none;');
                    $.plugins_keep_full = 1;
                    $("#plugin_search").keyup();
                });
            }

            if (data['product']['product_log']) {
                $('#audit_upgrade').parent().show();
            } else {
                $('#audit_upgrade').parent().hide();
            }
            $('#audit_actions').show();
            $("#plugin_search").keyup();
            $("#package_search").keyup();

            if (misconfigured_plugins || missing_plugins) {
                if (!missing_plugins) {
                    $("#plugin_get").parent().hide();
                } else {
                    $("#plugin_get").parent().show();
                }
                $('#plugin_actions').show();
            } else {
                $('#plugin_actions').hide();
            }

            $("#changeloglist > tbody").empty();
            $("#changeloglist > thead").html("<tr><th>{{ lang._('Version') }}</th>" +
            "<th>{{ lang._('Date') }}</th><th></th></tr>");

            const installed_version = data['product_version'].replace(/[p_-].*/, '').replace(/\.0/, '');

            $.each(data['changelog'], function(index, row) {
                changelog_count += 1;

                let status_text = '';
                let bold_on = '';
                let bold_off = '';

                if (installed_version == row['version']) {
                    status_text = ' ({{ lang._('installed') }})';
                    bold_on = '<b>';
                    bold_off = '</b>';
                }

                $('#changeloglist > tbody').append(
                    '<tr' + (changelog_count > changelog_max ? ' class="changelog-hidden" style="display: none;" ' : '' ) +
                    '><td>' + bold_on + row['version'] + status_text + bold_off + '</td><td>' + bold_on + row['date'] + bold_off + '</td>' +
                    '<td><button class="btn btn-default btn-xs act_changelog" data-version="' + row['version'] + '" ' +
                    'data-toggle="tooltip" title="{{ lang._('View') }}">' +
                    '<i class="fa fa-book fa-fw"></i></button></td></tr>'
                );
            });

            if (!data['changelog'].length) {
                $('#changeloglist > tbody').append(
                    '<tr><td colspan=3>{{ lang._('Check for updates to view changelog history.') }}</td></tr>'
                );
            }

            if (changelog_count > changelog_max) {
                $('#changeloglist > tbody').append(
                    '<tr class="changelog-full"><td colspan=3><a id="changelog-act" href="#">{{ lang._('Click to view full changelog history.') }}</a></td></tr>'
                );
                $("#changelog-act").click(function(event) {
                    event.preventDefault();
                    $(".changelog-hidden").attr('style', '');
                    $(".changelog-full").attr('style', 'display: none;');
                    $.changelog_keep_full = 1;
                });
            }

            // link buttons to actions
            $(".act_reinstall").click(function(event) {
                event.preventDefault();
                action_may_reboot('reinstall', $(this).data('package'));
            });
            $(".act_unlock").click(function(event) {
                event.preventDefault();
                backend('unlock/' + $(this).data('package'));
            });
            $(".act_lock").click(function(event) {
                event.preventDefault();
                backend('lock/' + $(this).data('package'));
            });
            $(".act_remove").click(function(event) {
                event.preventDefault();
                let plugin_name = $(this).data('package');
                BootstrapDialog.show({
                    type:BootstrapDialog.TYPE_WARNING,
                    title: "{{ lang._('Confirm removal') }}",
                    message: "{{ lang._('Do you really want to remove this plugin?') }}" + " <strong>" + plugin_name + "</strong>",
                    buttons: [{
                        label: "{{ lang._('OK') }}",
                        cssClass: 'btn-warning',
                        action: function (dialogRef) {
                            dialogRef.close();
                            backend('remove/' + plugin_name);
                        }
                    },{
                        label: "{{ lang._('Cancel') }}",
                        action: function(dialogRef){
                            dialogRef.close();
                        }
                    }]
                });
            });
            $(".act_details").click(function(event) {
                event.preventDefault();
                details($(this).data('package'));
            });
            $(".act_install").click(function(event) {
                event.preventDefault();
                let package_name = $(this).data('package');
                /* XXX temporary placeholder to inform the user that he/she is installing from a different (external) source */
                if ($(this).data('repository') !== 'OPNsense') {
                    BootstrapDialog.show({
                        type:BootstrapDialog.TYPE_INFO,
                        title: "{{ lang._('Third party software') }}",
                        message: "{{ lang._('This software package is provided by an external vendor, for more information contact the author')}}",
                        buttons: [{
                            label: "{{ lang._('Install') }}",
                            action: function(dialogRef){
                                dialogRef.close();
                                backend('install/' + package_name);
                            }
                        }, {
                            label: "{{ lang._('Cancel') }}",
                            action: function(dialogRef){
                                dialogRef.close();
                            }
                        }]
                    });
                } else {
                    backend('install/' + package_name);
                }
            });
            $(".act_changelog").click(function(event) {
                event.preventDefault();
                changelog($(this).data('version'));
            });
            $(".act_license").click(function(event) {
                event.preventDefault();
                license($(this).data('package'));
            });
            // attach tooltip to generated buttons
            $('[data-toggle="tooltip"]').tooltip();
        });
    }

    $( document ).ready(function() {
        // link event handlers
        $('#checkupdate').click(function () { backend('check'); });
        $('#upgrade').click(function () { upgrade_ui(false); });
        $('#upgrade_maj').click(function () { upgrade_ui(true); });
        $('#upgrade_cancel').click(cancel_update);
        $("#plugin_see").click(function () { $('#plugintab > a').tab('show'); });
        $("#plugin_get").click(function () { backend('syncPlugins'); });
        $("#plugin_set").click(function () { backend('resyncPlugins'); });
        $('#audit_cleanup').click(function () { backend('cleanup'); });
        $('#audit_connection').click(function () { backend('connection'); });
        $('#audit_health').click(function () { backend('health'); });
        $('#audit_preflight').click(function (event) {
            event.preventDefault();
            ajaxGet('/api/core/firmware/preflight/update', {}, function (report, status) {
                if (status !== 'success' || !report) {
                    report = {
                        status: 'error',
                        can_proceed: false,
                        summary: { blockers: 1, warnings: 0, info: 0 },
                        findings: [{
                            severity: 'BLOCKER',
                            title: "{{ lang._('Pre-flight check failed') }}",
                            message: "{{ lang._('Unable to retrieve pre-flight validation report from backend.') }}"
                        }]
                    };
                }
                show_preflight_dialog(false, report, true);
            });
        });
        $('#audit_security').click(function () { backend('audit'); });
        $('#beguard_revert_dismiss').click(function () {
            ajaxCall('/api/core/firmware/beguard_dismiss', {}, function (data, status) {
                if (status === 'success' && data && data['status'] === 'ok') {
                    $('#beguard_revert_alert').hide();
                    if (typeof updateSystemStatus === 'function') {
                        updateSystemStatus();
                    }
                } else {
                    let errMsg = (data && (data['errorMessage'] || data['message']))
                        ? decodeApiText(data['errorMessage'] || data['message'])
                        : "{{ lang._('Unable to dismiss Boot Environment Auto-Guard notice.') }}";
                    stdDialogInform(
                        "{{ lang._('Dismiss failed') }}",
                        $('<div/>').text(errMsg),
                        "{{ lang._('Close') }}",
                        undefined,
                        'danger'
                    );
                }
            });
        });
        $('#audit_upgrade').click(function () {
            ajaxCall('/api/core/firmware/log/0', {}, function (data, status) {
                if (data['log'] != undefined) {
                    stdDialogConfirm("{{ lang._('Upgrade log') }}", data['log'],
                      "{{ lang._('Clear') }}", "{{ lang._('Close') }}", function () {
                        ajaxCall('/api/core/firmware/log/1');
                        $('#audit_upgrade').parent().hide();
                      }, 'primary');
                }
            });
        });

        // populate package information and BE guard status
        packagesInfo(true);
        loadBeGuardStatus();

        $("#plugin_search").keyup(function () { generic_search(this, 'plugin_entry'); });
        $("#package_search").keyup(function () { generic_search(this, 'package_entry'); });

        ajaxGet('/api/core/firmware/running', {}, function(data, status) {
            if (data['status'] == 'busy') {
                // if action is already running reattach now...
                backend('audit');
            } else if (window.location.hash == '#checkupdate') {
                // dashboard link: run check automatically after delay
                setTimeout(function () {
                    ajaxGet('/api/core/firmware/running', {}, function(data, status) {
                        if (data['status'] != 'busy') {
                            backend('check');
                        }
                    });
                }, 2000);
            }
        });

        // handle firmware config options
        function fillOptions() {
            ajaxGet('/api/core/firmware/get_options', {}, function(fwopts, status) {
                ajaxGet('/api/core/firmware/get', {}, function(config, status) {
                    var fwconf = config['firmware'];
                    var custom_selected = true;

                    $("#firmware_mirror").find('option').remove();
                    $("#firmware_type").find('option').remove();
                    $("#firmware_flavour").find('option').remove();
                    $("#firmware_reboot").prop('checked', fwconf['reboot'] == '1');
                    $("#firmware_aux").prop('checked', fwconf['aux'] == '1');
                    $("#firmware_be_guard").prop('checked', fwconf['be_guard'] != '0');
                    $("#firmware_be_guard_timeout").val(fwconf['be_guard_timeout'] || '300');
                    $("#firmware_be_guard_retention").val(fwconf['be_guard_retention'] || '3');
                    if (fwopts.zfs_supported === false) {
                        $("#firmware_be_guard_ufs_note").show();
                    } else {
                        $("#firmware_be_guard_ufs_note").hide();
                    }

                    $.each(fwopts.mirrors, function(key, value) {
                        var selected = false;
                        if (key == fwconf['mirror']) {
                            selected = true;
                            custom_selected = false;
                        }
                        $("#firmware_mirror").append($("<option/>")
                                .attr("value",key)
                                .text(value)
                                .prop('selected', selected)
                        );
                    });
                    $("#firmware_mirror :first-child").after($("<option/>")
                        .attr("value", fwconf['mirror'])
                        .text("(custom)")
                        .data("custom", 1)
                        .prop('selected', custom_selected)
                    );

                    $("#firmware_subscription").val(fwconf['subscription']);

                    $("#firmware_mirror").selectpicker('refresh');
                    $("#firmware_mirror").change();

                    custom_selected = true;
                    $.each(fwopts.flavours, function(key, value) {
                        var selected = false;
                        if (key == fwconf['flavour']) {
                            selected = true;
                            custom_selected = false;
                        }
                        $("#firmware_flavour").append($("<option/>")
                                .attr("value",key)
                                .text(value)
                                .prop('selected', selected)
                        );
                    });
                    $("#firmware_flavour :first-child").after($("<option/>")
                        .attr("value", fwconf['flavour'])
                        .text("(custom)")
                        .data("custom", 1)
                        .prop('selected', custom_selected)
                    );

                    $("#firmware_flavour").selectpicker('refresh');
                    $("#firmware_flavour").change();
                    if (fwconf['flavour'] !== '' || fwconf['reboot'] === '1' || fwconf['aux'] === '1') {
                        $("i.fa-toggle-off#show_advanced_firmware").click();
                    }

                    $.each(fwopts.families, function(key, value) {
                        var selected = false;
                        if (key == fwconf['type']) {
                            selected = true;
                        }
                        $("#firmware_type").append($("<option/>")
                                .attr("value",key)
                                .text(value)
                                .prop('selected', selected)
                        );
                    });
                    $("#firmware_type").selectpicker('refresh');
                    $("#firmware_type").change();
                });
            });
        }
        fillOptions();
        $("#reset_mirror").click(fillOptions);

        $("#firmware_mirror").change(function(){
            $("#firmware_mirror_value").val($(this).val());
            if ($(this).find(':selected').data("custom") == 1) {
                $("#firmware_mirror_custom").show();
            } else {
                $("#firmware_mirror_custom").hide();
            }
        });
        $("#firmware_flavour").change(function() {
            $("#firmware_flavour_value").val($(this).val());
            if ($(this).find(':selected').data("custom") == 1) {
                $("#firmware_flavour_custom").show();
            } else {
                $("#firmware_flavour_custom").hide();
            }
        });

        $("#change_mirror").click(function(){
            $("#settingstab_progress").addClass("fa fa-spinner fa-pulse");
            var confopt = {};
            confopt.mirror = $("#firmware_mirror_value").val();
            confopt.flavour = $("#firmware_flavour_value").val();
            confopt.type = $("#firmware_type").val();
            confopt.reboot = $("#firmware_reboot").is(":checked") ? '1' : '0';
            confopt.aux = $("#firmware_aux").is(":checked") ? '1' : '0';
            confopt.be_guard = $("#firmware_be_guard").is(":checked") ? '1' : '0';
            confopt.be_guard_timeout = $("#firmware_be_guard_timeout").val();
            confopt.be_guard_retention = $("#firmware_be_guard_retention").val();
            confopt.subscription = $("#firmware_subscription").val();
            ajaxCall('/api/core/firmware/set', { 'firmware': confopt }, function (data, status) {
                $("#settingstab_progress").removeClass("fa fa-spinner fa-pulse");
                if (data['status'] == 'ok') {
                    packagesInfo(true);
                    loadBeGuardStatus();
                } else {
                    let validation_msgs = '<ul>';
                    for (i = 0; i < data['status_msg'].length; i++) {
                        validation_msgs += '<li>' + $("<textarea/>").html(data['status_msg'][i]).text() + '</li>';
                    }
                    validation_msgs += '</ul>';

                    stdDialogInform('{{ lang._('Firmware status') }}', htmlDecode(validation_msgs), "{{ lang._('Close') }}", undefined, 'danger');
                }
            });
        });

        $("#update_status_copy").click(function () {
            $("#update_status").select();
            document.execCommand("copy");
            document.getSelection().removeAllRanges();
            setTimeout(function () { $("#update_status").blur(); }, 100);
        });

        // update history on tab state and implement navigation
        if(window.location.hash != "") {
            $('a[href="' + window.location.hash + '"]').click()
        }
        $('.nav-tabs a').on('shown.bs.tab', function (e) {
            history.pushState(null, null, e.target.hash);
        });
        $(window).on('hashchange', function(e) {
            $('a[href="' + window.location.hash + '"]').click()
        });
    });
</script>
<div class="container-fluid">
    <div class="row">
        <div class="col-md-12" id="content">
            <ul class="nav nav-tabs" data-tabs="tabs">
                <li id="statustab" class="active"><a data-toggle="tab" href="#status">{{ lang._('Status') }} <i id="statustab_progress"></i></a></li>
                <li id="settingstab"><a data-toggle="tab" href="#settings">{{ lang._('Settings') }} <i id="settingstab_progress"></i></a></li>
                <li id="changelogtab"><a data-toggle="tab" href="#changelog">{{ lang._('Changelog') }}</a></li>
                <li id="updatetab"><a data-toggle="tab" href="#updates">{{ lang._('Updates') }} <i id="updatetab_progress"></i></a></li>
                <li id="plugintab"><a data-toggle="tab" href="#plugins">{{ lang._('Plugins') }}</a></li>
                <li id="packagestab"><a data-toggle="tab" href="#packages">{{ lang._('Packages') }}</a></li>
            </ul>
            <div class="tab-content content-box">
                <div id="updates" class="tab-pane table-responsive">
                    <table class="table table-striped table-condensed" id="updatelist" style="display: none;">
                        <thead>
                            <tr>
                              <th style="width:20%">{{ lang._('Package name') }}</th>
                              <th style="width:20%">{{ lang._('Current version') }}</th>
                              <th style="width:20%">{{ lang._('New version') }}</th>
                              <th style="width:20%">{{ lang._('Required action') }}</th>
                              <th style="width:20%">{{ lang._('Repository') }}</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                        <tfoot>
                            <tr>
                                <td></td>
                                <td style="white-space:nowrap;vertical-align:middle;">
                                    <button class="btn btn-info" id="upgrade"><i class="fa fa-check"></i> {{ lang._('Update') }}</button>
                                    <button class='btn btn-warning' id="upgrade_maj"><i class="fa fa-check"></i> {{ lang._('Upgrade') }}</button>
                                    <button class="btn btn-default" id="upgrade_cancel"><i class="fa fa-times"></i> {{ lang._('Cancel') }}</button>
                                </td>
                                <td colspan="2" style="vertical-align:middle">
                                    <strong><div id="updatestatus"></div></strong>
                                </td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                    <div id="update_status_container" class="table-responsive">
                       <textarea name="output" id="update_status" class="form-control" rows="20" wrap="hard" readonly="readonly" style="max-width:100%; font-family: monospace;"></textarea>
                      <table class="table table-striped table-condensed">
                        <tbody>
                          <tr>
                            <td>
                              {{ lang._('Output shown here for diagnostic purposes. There is no general need for manual system intervention.') }}
                              <a id="update_status_copy">{{ lang._('Click here to copy to clipboard.') }}</a>
                            </td>
                          </tr>
                        </tbody>
                      </table>
                    </div>
                </div>
                <div id="status" class="tab-pane active table-responsive">
                    <div id="beguard_revert_alert" class="alert alert-danger" style="display:none; margin:10px;">
                        <button type="button" id="beguard_revert_dismiss" class="close" aria-label="{{ lang._('Dismiss') }}"><span aria-hidden="true">&times;</span></button>
                        <strong><i class="fa fa-exclamation-triangle"></i> {{ lang._('Boot Environment Auto-Guard Reverted Previous Upgrade') }}</strong>
                        <p id="beguard_revert_reason" style="margin:6px 0 4px 0;"></p>
                        <small id="beguard_revert_meta" class="text-muted"></small>
                    </div>
                    <div id="beguard_armed_alert" class="alert alert-info" style="display:none; margin:10px;">
                        <strong><i class="fa fa-shield"></i> {{ lang._('Boot Environment Verification Pending') }}:</strong>
                        <span id="beguard_armed_detail"></span>
                    </div>
                    <table class="table table-striped table-condensed">
                        <tbody>
                            <tr>
                                <td style="width: 150px;">{{ lang._('Type') }}</td>
                                <td id="product_id"></td>
                                <td></td>
                            </tr>
                            <tr>
                                <td style="width: 150px;">{{ lang._('Version') }}</td>
                                <td id="product_version"></td>
                                <td></td>
                            </tr>
                            <tr>
                                <td style="width: 150px;">{{ lang._('Architecture') }}</td>
                                <td id="product_arch"></td>
                                <td></td>
                            </tr>
                            <tr>
                                <td style="width: 150px;">{{ lang._('Commit') }}</td>
                                <td id="product_hash"></td>
                                <td></td>
                            </tr>
                            <tr>
                                <td style="width: 150px;">{{ lang._('Mirror') }}</td>
                                <td id="product_mirror"></td>
                                <td></td>
                            </tr>
                            <tr>
                                <td style="width: 150px;">{{ lang._('Repositories') }}</td>
                                <td id="product_repos"></td>
                                <td></td>
                            </tr>
                            <tr>
                                <td style="width: 150px;">{{ lang._('Updated on') }}</td>
                                <td id="product_time"></td>
                                <td></td>
                            </tr>
                            <tr>
                                <td style="width: 150px;">{{ lang._('Checked on') }}</td>
                                <td id="product_time_check"></td>
                                <td></td>
                            </tr>
                            <tr style='display:none'>
                                <td style="width: 150px;">{{ lang._('Licensed until') }}</td>
                                <td id="product_license_valid_to"></td>
                                <td></td>
                            </tr>
                            <tr>
                                <td style="width: 150px;">{{ lang._('Boot Guard') }}</td>
                                <td id="beguard_status_text"></td>
                                <td></td>
                            </tr>
                            <tr>
                                <td style="width: 150px;"></td>
                                <td style="min-width: 500px;">
                                    <button class="btn btn-primary" id="checkupdate"><i class="fa fa-refresh"></i> {{ lang._('Check for updates') }}</button>
                                    <div class="btn-group dropdown" id="audit_actions" style="display:none;">
                                        <button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown">
                                            <i class="fa fa-lock"></i> {{ lang._('Run an audit') }} <i class="caret"></i>
                                        </button>
                                        <ul class="dropdown-menu" role="menu">
                                            <li><a id="audit_cleanup" href="#">{{ lang._('Cleanup') }}</a></li>
                                            <li><a id="audit_connection" href="#">{{ lang._('Connectivity') }}</a></li>
                                            <li><a id="audit_health" href="#">{{ lang._('Health') }}</a></li>
                                            <li><a id="audit_preflight" href="#">{{ lang._('Pre-flight check') }}</a></li>
                                            <li><a id="audit_security" href="#">{{ lang._('Security') }}</a></li>
                                            <li><a id="audit_upgrade" href="#">{{ lang._('Upgrade') }}</a></li>
                                        </ul>
                                    </div>
                                    <div class="btn-group dropdown" id="plugin_actions" style="display:none;">
                                        <button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown">
                                            <i class="fa fa-exclamation-triangle"></i> {{ lang._('Resolve plugin conflicts') }} <i class="caret"></i>
                                        </button>
                                        <ul class="dropdown-menu" role="menu">
                                            <li><a id="plugin_see" href="#">{{ lang._('View and edit local conflicts') }}</a></li>
                                            <li><a id="plugin_get" href="#">{{ lang._('Run the automatic resolver') }}</a></li>
                                            <li><a id="plugin_set" href="#">{{ lang._('Reset all local conflicts') }}</a></li>
                                        </ul>
                                    </div>
                                </td>
                                <td></td>
                            </tr>
                            <tr class="dropdown_helper" style="padding-bottom: 120px;"></tr>
                        </tbody>
                    </table>
                </div>
                <div id="plugins" class="tab-pane table-responsive">
                    <table class="table table-striped table-condensed" id="pluginlist">
                        <thead>
                            <tr>
                                <th style="vertical-align:middle"><input type="text" class="input-sm" autocomplete="off" id="plugin_search" placeholder="{{ lang._('Name') }}"></th>
                                <th style="vertical-align:middle">{{ lang._('Version') }}</th>
                                <th style="vertical-align:middle">{{ lang._('Size') }}</th>
                                <th style="vertical-align:middle">{{ lang._('Tier') }}</th>
                                <th style="vertical-align:middle">{{ lang._('Repository') }}</th>
                                <th style="vertical-align:middle">{{ lang._('Comment') }}</th>
                                <th style="vertical-align:middle"></th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
                <div id="packages" class="tab-pane table-responsive">
                    <table class="table table-striped table-condensed" id="packageslist">
                        <thead>
                            <tr>
                                <th style="vertical-align:middle"><input type="text" class="input-sm" autocomplete="off" id="package_search" placeholder="{{ lang._('Name') }}"></th>
                                <th style="vertical-align:middle">{{ lang._('Version') }}</th>
                                <th style="vertical-align:middle">{{ lang._('Size') }}</th>
                                <th style="vertical-align:middle">{{ lang._('Repository') }}</th>
                                <th style="vertical-align:middle">{{ lang._('License') }}</th>
                                <th style="vertical-align:middle">{{ lang._('Comment') }}</th>
                                <th style="vertical-align:middle"></th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
                <div id="changelog" class="tab-pane table-responsive">
                    <table class="table table-striped table-condensed" id="changeloglist">
                        <thead></thead>
                        <tbody></tbody>
                    </table>
                </div>
                <div id="settings" class="tab-pane table-responsive">
                    <form id="frm_firmware_dummy">
                        <table class="table table-striped table-condensed">
                            <tbody>
                                <tr>
                                    <td style="text-align:left"><i class="fa fa-toggle-off text-danger" id="show_advanced_firmware"></i></a> <small>{{ lang._('advanced mode') }}</small></td>
                                    <td colspan="2" style="text-align:right">
                                        <small>{{ lang._('full help') }}</small> <a href="#"><i class="fa fa-toggle-off text-danger" id="show_all_help_firmware"></i></a>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="width: 150px;"><a id="help_for_mirror" href="#" class="showhelp"><i class="fa fa-info-circle"></i></a> {{ lang._('Mirror') }}</td>
                                    <td>
                                        <select class="selectpicker" id="firmware_mirror"  data-size="5" data-live-search="true">
                                        </select>
                                        <div style="display:none;" id="firmware_mirror_custom">
                                            <input type="text" id="firmware_mirror_value">
                                        </div>
                                        <div class="hidden" data-for="help_for_mirror">
                                            {{ lang._('Select an alternate firmware mirror.') }}
                                        </div>
                                    </td>
                                    <td></td>
                                </tr>
                                <tr data-advanced="true">
                                    <td><a id="help_for_flavour" href="#" class="showhelp"><i class="fa fa-info-circle"></i></a> {{ lang._('Flavour') }}</td>
                                    <td>
                                        <select class="selectpicker" id="firmware_flavour">
                                        </select>
                                        <div style="display:none;" id="firmware_flavour_custom">
                                            <input type="text" id="firmware_flavour_value">
                                        </div>
                                        <div class="hidden" data-for="help_for_flavour">
                                            {{ lang._('Select an alternate firmware flavour.') }}
                                        </div>
                                    </td>
                                    <td></td>
                                </tr>
                                <tr>
                                    <td><a id="help_for_type" href="#" class="showhelp"><i class="fa fa-info-circle"></i></a> {{ lang._('Type') }}</td>
                                    <td>
                                        <select class="selectpicker" id="firmware_type">
                                        </select>
                                        <div class="hidden" data-for="help_for_type">
                                            {{ lang._('Select the release type.') }}
                                        </div>
                                    </td>
                                    <td></td>
                                </tr>
                                <tr>
                                    <td style="width: 150px;"><a id="help_for_subscription" href="#" class="showhelp"><i class="fa fa-info-circle"></i></a> {{ lang._('Subscription') }}</td>
                                    <td>
                                        <input type="text" id="firmware_subscription">
                                        <div class="hidden" data-for="help_for_subscription">
                                            {{ lang._('Provide subscription key.') }}
                                        </div>
                                    </td>
                                    <td></td>
                                </tr>
                                <tr data-advanced="true">
                                    <td style="width: 150px;"><i class="fa fa-info-circle text-muted"></i> {{ lang._('Reboot') }}</td>
                                    <td>
                                        <input type="checkbox" id="firmware_reboot">
                                        {{ lang._('Always reboot after a successful update') }}
                                    </td>
                                    <td></td>
                                </tr>
                                <tr data-advanced="true">
                                    <td style="width: 150px;"><i class="fa fa-info-circle text-muted"></i> {{ lang._('Repository') }}</td>
                                    <td>
                                        <input type="checkbox" id="firmware_aux">
                                        {{ lang._('Activate the auxiliary packages repository') }}
                                    </td>
                                    <td></td>
                                </tr>
                                <tr>
                                    <td style="width: 150px;"><a id="help_for_be_guard" href="#" class="showhelp"><i class="fa fa-info-circle"></i></a> {{ lang._('Boot Guard') }}</td>
                                    <td>
                                        <input type="checkbox" id="firmware_be_guard">
                                        {{ lang._('Enable ZFS Boot Environment Auto-Guard before firmware upgrades') }}
                                        <div id="firmware_be_guard_ufs_note" class="text-warning" style="display:none; margin-top:4px;">
                                            <small><i class="fa fa-warning"></i> {{ lang._('This installation does not use ZFS on root. Boot Environment Auto-Guard will skip gracefully during upgrades.') }}</small>
                                        </div>
                                        <div class="hidden" data-for="help_for_be_guard">
                                            {{ lang._('Automatically creates a ZFS boot environment snapshot before upgrades, boots the upgraded environment once, and reverts automatically if health verification fails.') }}
                                        </div>
                                    </td>
                                    <td></td>
                                </tr>
                                <tr>
                                    <td style="width: 150px;"><a id="help_for_be_guard_timeout" href="#" class="showhelp"><i class="fa fa-info-circle"></i></a> {{ lang._('Guard Timeout') }}</td>
                                    <td>
                                        <input type="number" id="firmware_be_guard_timeout" min="30" max="3600" style="width: 120px;">
                                        <small class="text-muted">{{ lang._('seconds (30-3600, default: 300)') }}</small>
                                        <div class="hidden" data-for="help_for_be_guard_timeout">
                                            {{ lang._('Maximum time on first boot after an upgrade to pass kernel, web GUI, interface, default route, and pf ruleset checks before automatically reverting.') }}
                                        </div>
                                    </td>
                                    <td></td>
                                </tr>
                                <tr>
                                    <td style="width: 150px;"><a id="help_for_be_guard_retention" href="#" class="showhelp"><i class="fa fa-info-circle"></i></a> {{ lang._('Guard Retention') }}</td>
                                    <td>
                                        <input type="number" id="firmware_be_guard_retention" min="1" max="50" style="width: 120px;">
                                        <small class="text-muted">{{ lang._('pre-upgrade snapshots (1-50, default: 3)') }}</small>
                                        <div class="hidden" data-for="help_for_be_guard_retention">
                                            {{ lang._('Number of automatic pre-upgrade ZFS boot environments to retain. Older inactive pre-upgrade snapshots are pruned automatically.') }}
                                        </div>
                                    </td>
                                    <td></td>
                                </tr>
                                <tr>
                                    <td style="width: 150px;"><i class="fa fa-info-circle text-muted"></i> {{ lang._('Usage') }}</td>
                                    <td>
                                        {{ lang._('In order to apply these settings a firmware update must be performed after save, which can include a reboot of the system.') }}
                                    </td>
                                    <td></td>
                                </tr>
                                <tr>
                                    <td></td>
                                    <td>
                                        <button class="btn btn-primary" id="change_mirror" type="button"><i class="fa fa-floppy-o"></i> {{ lang._('Save') }}</button>
                                        <button class="btn btn-default" id="reset_mirror" type="button"><i class="fa fa-times"></i> {{ lang._('Cancel') }}</button>
                                    </td>
                                    <td></td>
                                </tr>
                            </tbody>
                        </table>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <div class="row">
        <div class="col-md-12">
            &nbsp;
        </div>
    </div>
</div>
