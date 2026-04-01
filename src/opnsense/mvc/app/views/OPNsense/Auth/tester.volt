{#
# Copyright (c) 2026 Konstantinos Spartalis (cspartalis@potatonetworks.com)
# All rights reserved.
#
# Redistribution and use in source and binary forms, with or without modification,
# are permitted provided that the following conditions are met:
#
# 1. Redistributions of source code must retain the above copyright notice,
# this list of conditions and the following disclaimer.
#
# 2. Redistributions in binary form must reproduce the above copyright notice,
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

<script>
    $(document).ready(function () {
        var data_get_map = { 'frm_testerSettings': "/api/auth/tester/getSettings" };
        mapDataToFormUI(data_get_map).done(function (data) {
            formatTokenizersUI();
            $('.selectpicker').selectpicker('refresh');
        });

        $("#btn_test").click(function () {
            if (!$("#frm_testerSettings_progress").hasClass("fa-spinner")) {
                $("#test_results").hide();
                $("#frm_testerSettings_progress").addClass("fa fa-spinner fa-pulse");

                saveFormToEndpoint(
                    "/api/auth/tester/test",
                    'frm_testerSettings',
                    function (data) {
                        $("#frm_testerSettings_progress").removeClass("fa fa-spinner fa-pulse");
                        $("#test_results > tbody").empty();

                        if (data.status === 'ok') {
                            $("#test_results > tbody").append(
                                $("<tr>").append($("<td colspan='2'>").html(data.message))
                            );

                            // Render groups
                            if (data.groups && data.groups.length > 0) {
                                $("#test_results > tbody").append(
                                    $("<tr class='info'>").append($("<td colspan='2'>").html("<b>{{ lang._('Groups') }}</b>: " + data.groups.join(" ")))
                                );
                            }

                            // Render privileges
                            if (data.privileges && data.privileges.length > 0) {
                                $("#test_results > tbody").append(
                                    $("<tr class='info'>").append($("<td>").html("<b>{{ lang._('Uri') }}</b>")).append($("<td>").html("<b>{{ lang._('Networks') }}</b>"))
                                );
                                data.privileges.forEach(function (item) {
                                    $("#test_results > tbody").append(
                                        $("<tr>").append($("<td>").html(item[0])).append($("<td>").html(item[1].join(', ')))
                                    );
                                });
                            }

                            // Render attributes
                            if (data.attributes && Object.keys(data.attributes).length > 0) {
                                $("#test_results > tbody").append(
                                    $("<tr class='info'>").append($("<td colspan='2'>").html("<b>{{ lang._('Attributes received from server') }}</b>"))
                                );
                                $.each(data.attributes, function (attr_name, attr_value) {
                                    $("#test_results > tbody").append(
                                        $("<tr>").append($("<td>").html(attr_name)).append($("<td>").html(attr_value))
                                    );
                                });
                            }
                        } else {
                            $("#test_results > tbody").append(
                                $("<tr>").append($("<td colspan='2'>").html("{{ lang._('Authentication failed.') }}"))
                            );
                            if (data.errors) {
                                $.each(data.errors, function (err_name, err_value) {
                                    $("#test_results > tbody").append(
                                        $("<tr>").append($("<td>").html(err_name)).append($("<td>").html(err_value))
                                    );
                                });
                            }
                        }
                        $("#test_results").show();
                    },
                    true,
                    function () {
                        $("#frm_testerSettings_progress").removeClass("fa fa-spinner fa-pulse");
                    }
                );
            }
        });
    });
</script>

<div class="tab-content content-box col-xs-12 __mb">
    <div id="tester">
        {{ partial("layout_partials/base_form",['fields':testerForm,'id':'frm_testerSettings',
        'apply_btn_id':'btn_test', 'apply_btn_title': lang._('Test')])}}
    </div>
</div>
<div class="tab-content content-box col-xs-12 __mb">
    <table class="table table-condensed" id="test_results" style="display:none;">
        <thead>
            <tr>
                <th colspan="2">{{ lang._('Response')}}</th>
            </tr>
        </thead>
        <tbody>
        </tbody>
    </table>
</div>