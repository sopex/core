{#
 # Copyright (c) 2026 Konstantinos Spartalis
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
 # THIS SOFTWARE IS PROVIDED “AS IS” AND ANY EXPRESS OR IMPLIED WARRANTIES,
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
        let data_get_map = {'frm_settings':"/api/appid/settings/get"};
        mapDataToFormUI(data_get_map).done(function(){
            formatTokenizersUI();
            $('.selectpicker').selectpicker('refresh');
            updateServiceControlUI('appid');
        });

        $("#{{formGridCustom['table_id']}}").UIBootgrid({
            search:'/api/appid/settings/search_custom',
            get:'/api/appid/settings/get_custom/',
            set:'/api/appid/settings/set_custom/',
            add:'/api/appid/settings/add_custom/',
            del:'/api/appid/settings/del_custom/',
            toggle:'/api/appid/settings/toggle_custom/'
        });

        function updateDatabaseInfo() {
            ajaxGet('/api/appid/applications/info', {}, function(data) {
                $("#database_version").text(data.version ? data.version : "{{ lang._('none installed') }}");
                $("#database_applications").text(data.applications !== undefined ? data.applications : 0);
            });
        }

        $("#updateAct").SimpleActionButton({
            onAction: function(data) {
                if (data.status === 'error') {
                    BootstrapDialog.show({
                        type: BootstrapDialog.TYPE_DANGER,
                        title: "{{ lang._('Database update') }}",
                        message: $('<div/>').text(data.message).html(),
                        buttons: [{
                            label: "{{ lang._('Close') }}",
                            action: function(dialogRef) {
                                dialogRef.close();
                            }
                        }]
                    });
                }
                updateDatabaseInfo();
                $("#grid-applications").bootgrid('reload');
            }
        });

        $("#reconfigureAct").SimpleActionButton({
            onPreAction: function() {
                const dfObj = new $.Deferred();
                saveFormToEndpoint("/api/appid/settings/set", 'frm_settings', function(){
                    dfObj.resolve();
                });
                return dfObj;
            }
        });

        $('a[data-toggle="tab"]').on('shown.bs.tab', function (e) {
            switch (e.target.hash) {
                case '#applications':
                    updateDatabaseInfo();
                    if (!$("#grid-applications").hasClass('tabulator')) {
                        $("#grid-applications").UIBootgrid({
                            search:'/api/appid/applications/search'
                        });
                    } else {
                        $("#grid-applications").bootgrid('reload');
                    }
                    break;
            }
        });

        let selected_tab = window.location.hash != "" ? window.location.hash : "#settings";
        $('a[href="' +selected_tab + '"]').tab('show');
        $('.nav-tabs a').on('shown.bs.tab', function (e) {
            history.pushState(null, null, e.target.hash);
        });
        $(window).on('hashchange', function(e) {
            $('a[href="' + window.location.hash + '"]').click()
        });
    });
</script>

<ul class="nav nav-tabs" data-tabs="tabs" id="maintabs">
    <li><a data-toggle="tab" href="#settings" id="settings_tab">{{ lang._('Settings') }}</a></li>
    <li><a data-toggle="tab" href="#custom" id="custom_tab">{{ lang._('Custom Applications') }}</a></li>
    <li><a data-toggle="tab" href="#applications" id="applications_tab">{{ lang._('Applications') }}</a></li>
</ul>
<div class="tab-content content-box">
    <!-- Tab: General settings -->
    <div id="settings" class="tab-pane fade in">
        {{ partial("layout_partials/base_form",['fields':generalForm,'id':'frm_settings'])}}
    </div>
    <!-- Tab: Custom applications -->
    <div id="custom" class="tab-pane fade in">
        {{ partial('layout_partials/base_bootgrid_table', formGridCustom)}}
    </div>
    <!-- Tab: Application database -->
    <div id="applications" class="tab-pane fade in">
        <table class="table table-condensed">
            <tbody>
                <tr>
                    <td style="width:22%">{{ lang._('Database version') }}</td>
                    <td id="database_version"></td>
                </tr>
                <tr>
                    <td>{{ lang._('Applications') }}</td>
                    <td id="database_applications"></td>
                </tr>
                <tr>
                    <td></td>
                    <td>
                        <button class="btn btn-primary" id="updateAct"
                                data-endpoint="/api/appid/service/update"
                                data-label="{{ lang._('Update database') }}"
                                data-error-title="{{ lang._('Database update failed') }}"
                                type="button">
                        </button>
                    </td>
                </tr>
            </tbody>
        </table>
        <table id="grid-applications" class="table table-condensed table-hover table-striped table-responsive">
            <thead>
                <tr>
                    <th data-column-id="id" data-type="string" data-identifier="true" data-visible="false">{{ lang._('ID') }}</th>
                    <th data-column-id="name" data-type="string">{{ lang._('Application') }}</th>
                    <th data-column-id="category_name" data-type="string">{{ lang._('Category') }}</th>
                    <th data-column-id="risk" data-type="numeric">{{ lang._('Risk') }}</th>
                    <th data-column-id="custom" data-type="boolean" data-formatter="boolean">{{ lang._('Custom') }}</th>
                    <th data-column-id="description" data-type="string">{{ lang._('Description') }}</th>
                </tr>
            </thead>
            <tbody>
            </tbody>
            <tfoot>
            </tfoot>
        </table>
    </div>
</div>

{{ partial('layout_partials/base_apply_button', {'data_endpoint': '/api/appid/service/reconfigure', 'data_service_widget': 'appid', 'data_grid_reload': formGridCustom['table_id']}) }}
{{ partial('layout_partials/base_dialog',['fields':formDialogCustom,'id':formGridCustom['edit_dialog_id'],'label':lang._('Edit custom application')])}}
