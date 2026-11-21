{#
 # Copyright (c) 2023 Deciso B.V.
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

<style>
    .diff_record {
        white-space: pre-wrap;
        font-family: monospace;
        border: none !important;
        padding-top: 0 !important;
        padding-bottom: 0 !important;
        word-break: break-all;
    }
</style>
<script>
    'use strict';
    function get_backups(){
        let provider = $("#providers").val();
        $("#host_processing").show();
        ajaxGet('/api/core/backup/backups/' + provider, {}, function(data, status){
            let target1 = $("#backups1").empty();
            let target2 = $("#backups2").empty();
            if (data.items && Object.keys(data.items).length > 1) {
                Object.keys(data.items).forEach(function(key) {
                    let record = data.items[key];
                    let payload = $("<div>");
                    payload.append(record.time_iso, "&nbsp;", record.username, "<br/>");
                    payload.append($("<small/>").text(record.description));
                    target1.append($("<option/>").attr('value', record.id).attr('data-content', payload.html()));
                    target2.append($("<option/>").attr('value', record.id).attr('data-content', payload.html()));
                });
            }
            $(".backups").selectpicker('refresh');
            $("#backups1").change();
            $("#host_processing").hide();
            if ($("#backups1 option").length === 0) {
                $("#diff_tfoot").show();
            } else {
                $("#diff_tfoot").hide();
            }
        });
        if (provider === 'this' ) {
            $(".only_local").show();
        } else {
            $(".only_local").hide();
        }
    }
    $( document ).ready(function () {
        ajaxGet('/api/core/backup/providers', {}, function(data, status){
            let selected_host = "{{selected_host}}";
            if (data.items && Object.keys(data.items).length > 1) {
                let target = $("#providers").empty();
                Object.keys(data.items).forEach(function(key) {
                    let opt = $("<option/>").attr('value', key).text(data.items[key].description);
                    if (selected_host == key) {
                        opt.attr('selected', 'selected');
                    }
                    target.append(opt);
                });
                target.selectpicker('refresh');
            }
            $("#providers").change(get_backups);
            $("#providers").change();
            if ($("#providers > option").length > 1) {
                $("#providers_pane").show();
            }
        });

        $("#backups1").change(function(){
            let target = $("#backups2");
            target.val($("#backups1").val());
            if ($('#backups2 option').length > target[0].selectedIndex) {
                target[0].selectedIndex = target[0].selectedIndex + 1;
            }
            target.selectpicker('refresh');
            target.change();
        });

        $("#backups2").change(function(){
            let provider = $("#providers").val();
            let url = '/api/core/backup/diff/'+provider+'/'+$("#backups1").val()+'/'+$("#backups2").val();
            ajaxGet(url, {}, function(data, status){
                let target = $("#diff_records").empty();
                if (data.items && data.items.length > 1) {
                    for (let i=0 ; i < data.items.length ; ++i) {
                        let $tr = $("<tr/>");
                        let $td = $("<td class='diff_record'/>").append($("<div>").html(data.items[i]).text());
                        let color = '#000000';
                        switch (data.items[i][0]) {
                            case '+':
                                color = '#3bbb33';
                                $td.addClass('diff_record_plus');
                                break;
                            case '-':
                                color = '#c13928';
                                $td.addClass('diff_record_minus');
                                break;
                            case '@':
                                color = '#3bb9c3';
                                $td.addClass('diff_record_at');
                                break;
                            default:
                                $td.addClass('diff_record_default');
                                break;
                        }
                        $td.css('color', color);
                        target.append($tr.append($td));
                    }
                }
            });
        });

        $("#act_revert").click(function(event){
            event.preventDefault();
            let backupId = $("#backups1").val();
            if (!backupId) {
                return;
            }
            ajaxGet("/api/core/backup/preflight_backup/" + encodeURIComponent(backupId), {}, function(report, status) {
                if (status !== 'success' || !report || report.status === 'not_found') {
                    report = {
                        status: 'error',
                        can_proceed: false,
                        summary: { blockers: 1, warnings: 0, info: 0 },
                        findings: [{
                            severity: 'BLOCKER',
                            title: "{{ lang._('Pre-flight validation failed') }}",
                            message: "{{ lang._('Unable to retrieve pre-flight validation report for the selected backup.') }}"
                        }],
                        interface_remapping: { required: false, missing: [], available_hardware: [] }
                    };
                }
                let summary = (report && report.summary) ? report.summary : { blockers: 0, warnings: 0, info: 0 };
                let findings = (report && Array.isArray(report.findings)) ? report.findings : [];
                let blockers = summary.blockers || 0;
                let remapping = (report && report.interface_remapping) ? report.interface_remapping : { required: false, missing: [], available_hardware: [] };

                let $msg = $('<div/>');
                $msg.append($('<p/>').html("{{ lang._('Restore from Configuration Backup') }} <br/>" + $("#backups1 option:selected").data('content')));

                let $sum = $('<div class="__mb"/>');
                $sum.append($('<span class="label label-danger"/>').text("{{ lang._('Blockers') }}: " + blockers)).append(' ');
                $sum.append($('<span class="label label-warning"/>').text("{{ lang._('Warnings') }}: " + (summary.warnings || 0))).append(' ');
                $sum.append($('<span class="label label-info"/>').text("{{ lang._('Info') }}: " + (summary.info || 0)));
                $msg.append($sum);

                let decodeApiText = function (val) {
                    return (val !== null && val !== undefined && val !== '') ? htmlDecode(String(val)) : '';
                };

                if (findings.length > 0) {
                    let $tbl = $('<table class="table table-condensed table-striped"/>');
                    let $tb = $('<tbody/>');
                    findings.forEach(function (f) {
                        let cls = f.severity === 'BLOCKER' ? 'label-danger' : (f.severity === 'WARNING' ? 'label-warning' : 'label-info');
                        let $tr = $('<tr/>');
                        $tr.append($('<td style="width:90px;"/>').append($('<span class="label"/>').addClass(cls).text(decodeApiText(f.severity || 'INFO'))));
                        let $td = $('<td/>').append($('<strong/>').text(decodeApiText(f.title)));
                        if (f.message) {
                            $td.append($('<div/>').text(decodeApiText(f.message)));
                        }
                        if (f.remediation) {
                            $td.append($('<small class="text-muted"/>').text(decodeApiText(f.remediation)));
                        }
                        $tr.append($td);
                        $tb.append($tr);
                    });
                    $tbl.append($tb);
                    $msg.append($('<div style="max-height:240px;overflow-y:auto;"/>').append($tbl));
                }

                if (remapping.required && Array.isArray(remapping.missing) && remapping.missing.length > 0) {
                    let $rtbl = $('<table class="table table-condensed table-bordered"/>');
                    $rtbl.append('<thead><tr><th>{{ lang._("Missing NIC") }}</th><th>{{ lang._("Map to Local Hardware NIC") }}</th></tr></thead>');
                    let $rtb = $('<tbody/>');
                    remapping.missing.forEach(function (miss) {
                        let missDev = decodeApiText(miss.device);
                        let $sel = $('<select class="form-control backup_nic_remap"/>').attr('data-old', missDev);
                        $sel.append($('<option value=""/>').text("{{ lang._('-- Do not remap --') }}"));
                        (remapping.available_hardware || []).forEach(function (hw) {
                            let hwDev = decodeApiText(hw.device);
                            let hwMac = decodeApiText(hw.mac);
                            let hwStatus = decodeApiText(hw.status);
                            let lbl = hwDev + (hwMac ? ' (' + hwMac + ')' : '') + (hwStatus ? ' [' + hwStatus + ']' : '');
                            $sel.append($('<option/>').attr('value', hwDev).text(lbl));
                        });
                        $rtb.append($('<tr/>').append($('<td/>').append($('<code/>').text(missDev))).append($('<td/>').append($sel)));
                    });
                    $rtbl.append($rtb);
                    $msg.append($('<div/>').append($('<strong/>').text("{{ lang._('Remap Missing Hardware Interfaces') }}")).append($rtbl));
                }

                if (blockers > 0) {
                    let $ov = $('<div class="alert alert-danger" style="margin-top:8px;margin-bottom:0;"/>');
                    let $lbl = $('<label style="font-weight:normal;cursor:pointer;margin-bottom:0;"/>');
                    $lbl.append('<input type="checkbox" id="backup_preflight_override" style="margin-right:6px;"/>');
                    $lbl.append(document.createTextNode("{{ lang._('Override remaining pre-flight blockers and restore anyway.') }}"));
                    $ov.append($lbl);
                    $msg.append($ov);
                }

                BootstrapDialog.show({
                    type: blockers > 0 ? BootstrapDialog.TYPE_DANGER : BootstrapDialog.TYPE_WARNING,
                    size: BootstrapDialog.SIZE_WIDE,
                    title: "{{ lang._('Confirmation required') }}",
                    message: $msg,
                    buttons: [{
                        label: "{{ lang._('No') }}",
                        action: function(dialogRef) {
                            dialogRef.close();
                        }
                    }, {
                        id: 'btn_backup_revert_yes',
                        label: "{{ lang._('Yes') }}",
                        cssClass: blockers > 0 ? 'btn-danger' : 'btn-warning',
                        action: function(dialogRef) {
                            let $body = dialogRef.getModalBody();
                            let remapPayload = {};
                            $body.find('.backup_nic_remap').each(function () {
                                let v = $(this).val();
                                if (v) {
                                    remapPayload[$(this).data('old')] = v;
                                }
                            });
                            let doOverride = $body.find('#backup_preflight_override').is(':checked') ? '1' : '0';
                            ajaxCall("/api/core/backup/revert_backup/" + encodeURIComponent(backupId), {
                                remap: remapPayload,
                                override: doOverride
                            }, function(resp, status) {
                                if (resp && resp.status === 'blocked') {
                                    let detail = "{{ lang._('Pre-flight blockers remain. Remap all missing interfaces or check override.') }}";
                                    if (resp.preflight && Array.isArray(resp.preflight.findings)) {
                                        let titles = resp.preflight.findings
                                            .filter(function (f) { return f.severity === 'BLOCKER'; })
                                            .map(function (f) { return decodeApiText(f.title || f.message); })
                                            .filter(Boolean);
                                        if (titles.length > 0) {
                                            detail += ' (' + titles.join('; ') + ')';
                                        }
                                    }
                                    stdDialogInform(
                                        "{{ lang._('Restore blocked') }}",
                                        $('<div/>').text(detail),
                                        "{{ lang._('Close') }}",
                                        undefined,
                                        'danger'
                                    );
                                    return;
                                }
                                if (status !== 'success' || !resp || resp.status !== 'reverted') {
                                    let errMsg = (resp && (resp.message || resp.errorMessage))
                                        ? decodeApiText(resp.message || resp.errorMessage)
                                        : "{{ lang._('Failed to restore configuration backup.') }}";
                                    stdDialogInform(
                                        "{{ lang._('Restore failed') }}",
                                        $('<div/>').text(errMsg),
                                        "{{ lang._('Close') }}",
                                        undefined,
                                        'danger'
                                    );
                                    return;
                                }
                                dialogRef.close();
                                get_backups();
                            });
                        }
                    }]
                });
            });
        });
        $("#act_remove").click(function(event){
            event.preventDefault();
            if (!$("#backups1").val()) {
                return;
            }
            BootstrapDialog.show({
                type: BootstrapDialog.TYPE_WARNING,
                title: "{{ lang._('Confirmation required') }}",
                message: "{{ lang._('Remove Configuration Backup') }} <br/>" + $("#backups1 option:selected").data('content'),
                buttons: [{
                label: "{{ lang._('No') }}",
                action: function(dialogRef) {
                    dialogRef.close();
                }}, {
                    label: "{{ lang._('Yes') }}",
                    cssClass: 'btn-warning',
                    action: function(dialogRef) {
                        ajaxCall("/api/core/backup/delete_backup/" + $("#backups1").val(),{}, function(){
                            $(".backups").find('[value="'+$("#backups1").val()+'"]').remove();
                            $("#backups1").selectpicker('refresh');
                            $("#backups1").change();
                            dialogRef.close();
                        });
                    }
                }]
            });
        });
        $("#act_download").click(function(event){
            event.preventDefault();
            if (!$("#backups1").val()) {
                return;
            }
            let url = '/api/core/backup/download/' + $("#providers").val() + '/' + $("#backups1").val();
            let link = document.createElement("a");
            $(link).click(function(e) {
                e.preventDefault();
                window.location.href = url;
            });
            $(link).click();
        });
    });

</script>

<div class="tab-content content-box __mb" id="providers_pane" style="display: none;">
    <div class="row">
        <div class="col-xs-12">
            <table class="table table-condensed table-striped">
                <thead>
                    <tr>
                        <th>{{ lang._('Host')}}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>
                            <select id="providers" class="selectpicker" data-live-search="true">
                                <option value="this" selected>{{ lang._('This Firewall')}}</option>
                            </select>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="tab-content content-box __mb">
    <div class="row">
        <div class="col-xs-12">
            <table class="table table-condensed table-striped">
                <thead>
                    <tr>
                        <th>
                            {{ lang._('Backups (compare)')}}
                            <i id="host_processing" class="fa fa-fw fa-spinner fa-pulse" style="display: none;"></i>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr style="height: 70px;">
                        <td>
                            <select class="selectpicker backups" id="backups1"></select>
                            <select class="selectpicker backups" id="backups2"></select>
                            <div>
                                <a id="act_revert" class="only_local btn btn-default btn-xs" data-toggle="tooltip" title="{{ lang._('Revert to this configuration')}}">
                                    <i class="fa fa-sign-in fa-fw"></i>
                                  </a>
                                  <a id="act_remove"  class="only_local btn btn-default btn-xs" data-toggle="tooltip" title="{{ lang._('Remove this backup')}}">
                                    <i class="fa fa-trash fa-fw"></i>
                                  </a>
                                  <a id="act_download" class="btn btn-default btn-xs" data-toggle="tooltip" title="{{ lang._('Download this backup')}}">
                                    <i class="fa fa-download fa-fw"></i>
                                  </a>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="tab-content content-box">
    <div class="row">
        <div class="col-xs-12">
            <table class="table table-condensed">
                <thead>
                    <tr>
                        <th >{{ lang._('Changes between selected versions')}}</th>
                    </tr>
                </thead>
                <tbody id="diff_records">
                </tbody>
                <tfoot id="diff_tfoot" style="display: none;">
                    <tr>
                        <td>{{ lang._('No backups available')}}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>
