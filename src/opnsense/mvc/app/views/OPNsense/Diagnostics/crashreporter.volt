<script>
    $( document ).ready(function() {
        var data_get_map = {'frm_crashReporter':"/api/diagnostics/crashreporter/info"};
        mapDataToFormUI(data_get_map).done(function(data){
            formatTokenizersUI();
            $('.selectpicker').selectpicker('refresh');
            
            if (data.reporter.has_crashed) {
                if (data.reporter.is_prod) {
                    $("#btn_submit").show();
                    $("#submit_instructions").show();
                    $("#btn_dismiss").show();
                    $("#crash_detected_msg").show();
                } else {
                    $("#development_msg").show();
                    $("#btn_dismiss").show();
                }
                
                var results = data.reporter.reports;
                var html = "";
                for (var key in results) {
                    html += "<p><strong>" + $('<div/>').text(key).html() + "</strong>:<br/>";
                    html += "<pre>" + $('<div/>').text(results[key]).html() + "</pre></p>";
                }
                $("#crash_reports_container").html(html).show();
            } else {
                $("#no_crash_msg").show();
                $("#btn_new_issue").show();
            }
            if (data.reporter.message !== "") {
                $("#system_message").text(data.reporter.message).show();
            }
            if (data.reporter.submitted_message !== undefined) {
                // To display post-action message if necessary, though reloading is standard
            }
        });

        $("#btn_submit").click(function () {
            if (!$("#btn_submit_progress").hasClass("fa-spinner")) {
                $("#btn_submit_progress").addClass("fa fa-spinner fa-pulse");
                saveFormToEndpoint(
                    "/api/diagnostics/crashreporter/submit",
                    'frm_crashReporter',
                    function(data) {
                        $("#btn_submit_progress").removeClass("fa fa-spinner fa-pulse");
                        location.reload();
                    },
                    true,
                    function(error) {
                        $("#btn_submit_progress").removeClass("fa fa-spinner fa-pulse");
                    }
                );
            }
        });

        $("#btn_dismiss").click(function () {
            ajaxCall("/api/diagnostics/crashreporter/dismiss", {}, function(data,status) {
                location.reload();
            });
        });

        $("#btn_new_issue").click(function () {
            ajaxCall("/api/diagnostics/crashreporter/force", {}, function(data,status) {
                location.reload();
            });
        });
    });
</script>

<div class="content-box col-xs-12 __mb">
    <div style="padding: 10px;">
        <p>
            <strong id="system_message" style="display:none;"></strong>
        </p>
        <p id="crash_detected_msg" style="display:none;">
            {{ lang._('An issue was detected.') }}
            <br/><br/>
            {{ lang._('Would you like to submit this crash report to the developers?') }}
        </p>
        <p id="development_msg" style="display:none;">
            {{ lang._('Development deployment is configured so crash reports cannot be sent.') }}
        </p>
        <p id="no_crash_msg" style="display:none;">
            {{ lang._('No issues were detected.') }}
        </p>
    </div>

    <div id="submit_instructions" style="display:none;">
        <hr/>
        <div style="padding: 10px;">
            <p>{{ lang._('You can help us further by adding your contact information and a problem description. Please note that providing your contact information greatly improves the chances of bugs being fixed.') }}</p>
        </div>
        <div id="crashReporterForm">
            {{ partial("layout_partials/base_form",['fields':crashReporterForm,'id':'frm_crashReporter'])}}
        </div>
        <div style="padding: 10px;">
            <hr/>
            <p>{{ lang._('Please double-check the following contents to ensure you are comfortable submitting the following information.') }}</p>
        </div>
    </div>

    <div id="crash_reports_container" style="display:none; padding: 10px;">
    </div>

    <div style="padding: 10px; padding-bottom: 20px;">
        <button type="button" class="btn btn-default pull-right" id="btn_dismiss" style="display:none;">{{ lang._('Dismiss this report') }}</button>
        <button type="button" class="btn btn-primary pull-right" id="btn_submit" style="display:none; margin-right: 8px;">
            <i id="btn_submit_progress" class=""></i>
            {{ lang._('Submit this report') }}
        </button>
        <button type="button" class="btn btn-primary pull-right" id="btn_new_issue" style="display:none;">{{ lang._('Report an issue') }}</button>
        <div style="clear:both"></div>
    </div>
</div>
