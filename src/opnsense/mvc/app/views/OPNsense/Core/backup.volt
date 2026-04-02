{#
 # Copyright (c) 2026 Konstantinos Spartalis (cspartalis@potatonetworks.com)
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
    $( document ).ready(function() {
        var data_get_map = {
            'frm_backupSettingsLocal': "/api/core/backup/getSettings",
            'frm_backupSettingsRemote': "/api/core/backup/getSettings"
        };
        mapDataToFormUI(data_get_map).done(function(){
            formatTokenizersUI();
            $('.selectpicker').selectpicker('refresh');
        });

        // link save button
        $("#btn_save_local").click(function(){
            saveFormToEndpoint("/api/core/backup/setSettings", 'frm_backupSettingsLocal', function(){
                // success
            }, true);
        });

        $("#btn_save_remote").click(function(){
            saveFormToEndpoint("/api/core/backup/setSettings", 'frm_backupSettingsRemote', function(){
                // success
            }, true);
        });

        $("#btn_download").click(function (e) {
            e.preventDefault();
            let params = {};
            if ($("#donotbackuprrd").is(":checked")) params.donotbackuprrd = 1;
            if ($("#encrypt").is(":checked")) {
                params.encrypt = 1;
                params.encrypt_password = $("#encrypt_password").val();
                params.encrypt_passconf = $("#encrypt_passconf").val();
                if (params.encrypt_password !== params.encrypt_passconf) {
                    BootstrapDialog.alert({ type: BootstrapDialog.TYPE_DANGER, title: "{{ lang._('Error') }}", message: "{{ lang._('The passwords do not match.') }}" });
                    return;
                }
                if (!params.encrypt_password) {
                    BootstrapDialog.alert({ type: BootstrapDialog.TYPE_DANGER, title: "{{ lang._('Error') }}", message: "{{ lang._('You must supply and confirm the password for encryption.') }}" });
                    return;
                }
            }

            $("#btn_download_progress").addClass("fa fa-spinner fa-pulse");

            $.ajax({
                type: "POST",
                url: "/api/core/backup/downloadThis",
                data: params,
                xhrFields: {
                    responseType: 'blob' // Force jQuery to treat response as binary
                },
                success: function(data, status, xhr) {
                    $("#btn_download_progress").removeClass("fa fa-spinner fa-pulse");

                    // Extract filename from Content-Disposition header
                    var filename = "config.xml";
                    var disposition = xhr.getResponseHeader('Content-Disposition');
                    if (disposition && disposition.indexOf('filename=') !== -1) {
                        var matches = /filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/.exec(disposition);
                        if (matches != null && matches[1]) {
                            filename = matches[1].replace(/['"]/g, '');
                        }
                    }

                    // Create a hidden link, attach the blob, and click it
                    var blob = new Blob([data], { type: xhr.getResponseHeader('Content-Type') });
                    var link = document.createElement('a');
                    link.href = window.URL.createObjectURL(blob);
                    link.download = filename;
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                },
                error: function() {
                    $("#btn_download_progress").removeClass("fa fa-spinner fa-pulse");
                    BootstrapDialog.alert({ type: BootstrapDialog.TYPE_DANGER, title: "{{ lang._('Error') }}", message: "{{ lang._('An error occurred during download.') }}" });
                }
            });
        });

        $("#encrypt").change(function(){
            if ($(this).is(':checked')) {
                $("#encrypt_opts").removeClass("hidden");
            } else {
                $("#encrypt_opts").addClass("hidden");
            }
        });

        $("#decrypt").change(function(){
            if ($(this).is(':checked')) {
                $("#decrypt_opts").removeClass("hidden");
            } else {
                $("#decrypt_opts").addClass("hidden");
            }
        });

        // restore area warn
        $('#restorearea').change(function () {
             $("#flush_history").prop('checked', false);
             if ($('#restorearea option:selected').text() == '') {
                 $.restorearea_warned = 0;
                 $("#flush_history").prop('checked', true);
             } else if ($.restorearea_warned != 1) {
                 $.restorearea_warned = 1;
                 BootstrapDialog.confirm({
                     title: '{{ lang._('Warning!') }}',
                     message: '{{ lang._('Selecting specific restore areas during a configuration import may cause loss of configuration integrity due to external references not being restored. It is recommended to keep this set to the default unless you know what you are doing.') }}',
                     type: BootstrapDialog.TYPE_WARNING,
                     btnOKClass: 'btn-warning',
                     btnOKLabel: '{{ lang._('I know what I am doing') }}',
                     btnCancelLabel: '{{ lang._('Use the default') }}',
                     callback: function(result) {
                         if (!result) {
                             $('#restorearea option:selected').prop('selected', false);
                             $('#restorearea').selectpicker('refresh');
                             $.restorearea_warned = 0;
                         }
                     }
                 });
             }
         });
         $.restorearea_warned = 0;

         // Setup providers
         $(".btn_setup_provider").click(function(e){
             e.preventDefault();
             let providerId = $(this).data('provider');
             let formId = "frm_provider_" + providerId;

             let formData = new FormData($("#"+formId)[0]);

             $("#"+formId+"_progress").addClass("fa fa-spinner fa-pulse");

             $.ajax({
                 type: "POST",
                 url: "/api/core/backup/setupProvider/" + providerId,
                 data: formData,
                 processData: false,
                 contentType: false,
                 success: function(data) {
                     $("#"+formId+"_progress").removeClass("fa fa-spinner fa-pulse");
                     if (data.status == "success") {
                         BootstrapDialog.alert({
                             type: BootstrapDialog.TYPE_INFO,
                             title: "{{ lang._('Backup Settings') }}",
                             message: data.message ? data.message : "{{ lang._('Settings saved successfully.') }}"
                         });
                     } else {
                         BootstrapDialog.alert({
                             type: BootstrapDialog.TYPE_DANGER,
                             title: "{{ lang._('Backup Settings failed') }}",
                             message: data.message || "{{ lang._('An error occurred') }}"
                         });
                     }
                 },
                 error: function() {
                     $("#"+formId+"_progress").removeClass("fa fa-spinner fa-pulse");
                 }
             });
         });

         // form submit for Restore configuration
         $("#frm_restore").submit(function(e){
               e.preventDefault();
               if (!$("#conffile").val()) {
                   BootstrapDialog.alert("{{ lang._('Please select a file to restore') }}");
                   return;
               }

               let formData = new FormData(this);
               $("#btn_restore_progress").addClass("fa fa-spinner fa-pulse");

               $.ajax({
                   type: "POST",
                   url: "/api/core/backup/restore",
                   data: formData,
                   processData: false,
                   contentType: false,
                   success: function(data) {
                       $("#btn_restore_progress").removeClass("fa fa-spinner fa-pulse");
                       if (data.status == "success") {
                           if (data.message) {
                                BootstrapDialog.show({
                                    type: BootstrapDialog.TYPE_INFO,
                                    title: "{{ lang._('Restore') }}",
                                    message: data.message,
                                    buttons: [{
                                        label: '{{ lang._('Close') }}',
                                        action: function(dialogRef){
                                            dialogRef.close();
                                            if (data.reboot) {
                                                window.location.reload();
                                            }
                                        }
                                    }]
                                });
                           }
                           if (data.reboot) {
                               setTimeout(function(){ window.location.reload(); }, 60000);
                           }
                       } else {
                           BootstrapDialog.alert({
                               type: BootstrapDialog.TYPE_DANGER,
                               title: "{{ lang._('Restore failed') }}",
                               message: data.message || "{{ lang._('An error occurred') }}"
                           });
                       }
                   },
                   error: function() {
                       $("#btn_restore_progress").removeClass("fa fa-spinner fa-pulse");
                   }
               });
         });
    });

    function show_value(key) {
        $('#show-' + key + '-btn').html('');
        $('#show-' + key + '-val').show();
        $("[name='" + key + "']").focus();
    }
</script>

<ul class="nav nav-tabs" data-tabs="tabs" id="maintabs">
    <li class="active"><a data-toggle="tab" href="#localbackup">{{ lang._('Local Backup') }}</a></li>
    <li><a data-toggle="tab" href="#remotebackup">{{ lang._('Remote Backup') }}</a></li>
    <li><a data-toggle="tab" href="#restore">{{ lang._('Restore') }}</a></li>
</ul>
<div class="tab-content content-box col-xs-12 __mb">
    <div id="localbackup" class="tab-pane fade in active">
        {{ partial("layout_partials/base_form",['fields':backupLocalForm,'id':'frm_backupSettingsLocal', 'apply_btn_id':'btn_save_local', 'apply_btn_title': lang._('Save')])}}
        <hr/>
        <div class="table-responsive">
            <table class="table table-striped table-condensed">
                <tbody>
                    <tr>
                        <td style="width: 22%"><strong>{{ lang._('Download') }}</strong></td>
                        <td style="width: 78%"></td>
                    </tr>
                    <tr>
                        <td></td>
                        <td>
                            <input name="donotbackuprrd" type="checkbox" id="donotbackuprrd" checked="checked" />
                            {{ lang._('Do not backup RRD data.') }}<br/>
                            <input name="encrypt" type="checkbox" id="encrypt" />
                            {{ lang._('Encrypt this configuration file.') }}<br/>
                            <div class="hidden table-responsive __mt" id="encrypt_opts">
                              <table class="table table-condensed">
                                <tr>
                                  <td>{{ lang._('Password') }}</td>
                                  <td><input id="encrypt_password" type="password" autocomplete="new-password"/></td>
                                </tr>
                                <tr>
                                  <td>{{ lang._('Confirmation') }}</td>
                                  <td><input id="encrypt_passconf" type="password" autocomplete="new-password"/></td>
                                </tr>
                              </table>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td></td>
                        <td>
                            <button class="btn btn-primary" id="btn_download">{{ lang._('Download configuration') }} <i id="btn_download_progress"></i></button>
                            <div class="text-muted __mt">{{ lang._('Click this button to download the system configuration in XML format.') }}</div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div id="restore" class="tab-pane fade in">
        <form id="frm_restore" enctype="multipart/form-data">
        <div class="table-responsive">
            <table class="table table-striped table-condensed">
                <tbody>
                    <tr>
                        <td style="width: 22%"><strong>{{ lang._('Restore') }}</strong></td>
                        <td style="width: 78%"></td>
                    </tr>
                    <tr>
                        <td>{{ lang._('Restore areas:') }}</td>
                        <td>
                            <select name="restorearea[]" id="restorearea" class="selectpicker" multiple="multiple" size="5" title="{{ lang._('All (recommended)') }}" data-live-search="true" data-size="10">
                            {% for areaId, areaDescription in areas %}
                                <option value="{{ areaId }}">{{ areaDescription }}</option>
                            {% endfor %}
                            </select>
                            <br/><input name="conffile" type="file" id="conffile" /><br/>
                            <input name="rebootafterrestore" type="checkbox" value="1" id="rebootafterrestore" checked="checked" />
                            {{ lang._('Reboot after a successful restore.') }}<br/>
                            <input name="keepconsole" type="checkbox" value="1" id="keepconsole" checked="checked" />
                            {{ lang._('Exclude console settings from import.') }}<br/>
                            <input name="flush_history" type="checkbox" value="1" id="flush_history" checked="checked" />
                            {{ lang._('Flush (full) local configuration history.') }}<br/>

                            <input name="decrypt" type="checkbox" value="1" id="decrypt" />
                            {{ lang._('Configuration file is encrypted.') }}
                            <div class="hidden table-responsive __mt" id="decrypt_opts">
                              <table class="table table-condensed">
                                <tr>
                                  <td>{{ lang._('Password') }}</td>
                                  <td><input name="decrypt_password" type="password" autocomplete="new-password"/></td>
                                </tr>
                              </table>
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td></td>
                        <td>
                            <button type="submit" class="btn btn-primary" id="btn_restore">{{ lang._('Restore configuration') }} <i id="btn_restore_progress"></i></button>
                            <div class="text-muted __mt">{{ lang._('Open a configuration XML file and click the button below to restore the configuration.') }}</div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        </form>
    </div>

    <div id="remotebackup" class="tab-pane fade in">
        {{ partial("layout_partials/base_form",['fields':backupRemoteForm,'id':'frm_backupSettingsRemote', 'apply_btn_id':'btn_save_remote', 'apply_btn_title': lang._('Save')])}}

{% if providers|length > 0 %}
        <hr/>
{% for providerId, provider in providers %}
        <form id="frm_provider_{{providerId}}" enctype="multipart/form-data">
        <div class="table-responsive {% if not loop.first %}__mt{% endif %}">
            <table class="table table-striped table-condensed opnsense_standard_table_form">
                <tbody>
                    <tr>
                        <td style="width: 22%"><strong>{{ provider['handle'].getName() }}</strong></td>
                        <td style="width: 78%"></td>
                    </tr>
                    {% for field in provider['handle'].getConfigurationFields() %}
                    {% set fieldId = providerId ~ "_" ~ field['name'] %}
                    <tr>
                        <td>
                            {% if field['help'] is defined and field['help'] is not empty %}
                            <a id="help_for_{{fieldId}}" href="#" class="showhelp"><i class="fa fa-info-circle"></i></a>
                            {% else %}
                            <i class="fa fa-info-circle text-muted"></i>
                            {% endif %}
                            {{ field['label'] }}
                        </td>
                        <td>
                            {% if field['type'] == 'checkbox' %}
                                <input name="{{field['name']}}" type="checkbox" {% if field['value'] %}checked="checked"{% endif %}>
                            {% elseif field['type'] == 'text' %}
                                <input name="{{field['name']}}" value="{{field['value']}}" type="text">
                            {% elseif field['type'] == 'file' %}
                                <input name="{{field['name']}}" type="file">
                            {% elseif field['type'] == 'password' %}
                                <input name="{{field['name']}}" type="password" autocomplete="new-password" value="{{field['value']}}" />
                            {% elseif field['type'] == 'textarea' %}
                                <textarea name="{{field['name']}}" rows="10">{{field['value']}}</textarea>
                            {% elseif field['type'] == 'passwordarea' %}
                                <div id="show-{{fieldId}}-btn">
                                  <button onclick="event.preventDefault();show_value('{{ fieldId }}');" class="btn btn-default">{{ lang._('Click to edit') }}</button>
                                </div>
                                <div id="show-{{fieldId}}-val" style="display:none">
                                  <textarea id="{{fieldId}}" name="{{field['name']}}" rows="10" style="min-width: 348px;">{{field['value']}}</textarea>
                                </div>
                            {% endif %}
                            <div class="hidden" data-for="help_for_{{fieldId}}">
                                {{ field['help'] }}
                            </div>
                        </td>
                    </tr>
                    {% endfor %}
                    <tr>
                        <td></td>
                        <td>
                            <button type="button" data-provider="{{providerId}}" class="btn btn-primary btn_setup_provider">
                              {{ lang._('Setup/Test %s') | format(provider['handle'].getName()) }} <i id="frm_provider_{{providerId}}_progress"></i>
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        </form>
    {% if not loop.last %}<hr/>{% endif %}
{% endfor %}
{% endif %}
    </div>
</div>
