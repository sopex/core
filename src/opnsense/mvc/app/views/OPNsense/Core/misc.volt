{#
 # Copyright (c) 2026 Deciso B.V.
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
        const data_get_map = {'frm_misc_settings': '/api/core/misc/get'};

        mapDataToFormUI(data_get_map).done(function(data) {
            formatTokenizersUI();
            $('.selectpicker').selectpicker('refresh');
            $('#misc\\.powerd_enable').change();
            $('#misc\\.use_mfs_var').change();
            $('#misc\\.use_mfs_tmp').change();
        });

        // Toggle powerd sub-options
        $('#misc\\.powerd_enable').change(function() {
            const isChecked = $(this).is(':checked');
            $('#row_misc\\.powerd_ac_mode').toggle(isChecked);
            $('#row_misc\\.powerd_battery_mode').toggle(isChecked);
            $('#row_misc\\.powerd_normal_mode').toggle(isChecked);
        });

        // Toggle RAM disk sub-options
        $('#misc\\.use_mfs_var').change(function() {
            $('#row_misc\\.max_mfs_var').toggle($(this).is(':checked'));
        });

        $('#misc\\.use_mfs_tmp').change(function() {
            $('#row_misc\\.max_mfs_tmp').toggle($(this).is(':checked'));
        });

        // Save & apply action
        $('#reconfigureAct').SimpleActionButton({
            onPreAction: function() {
                const dfObj = new $.Deferred();
                saveFormToEndpoint(
                    '/api/core/misc/set',
                    'frm_misc_settings',
                    function() { dfObj.resolve(); },
                    true,
                    function() { dfObj.reject(); }
                );
                return dfObj.promise();
            }
        });
    });
</script>

<ul class="nav nav-tabs" data-tabs="tabs" id="maintabs">
</ul>
<div class="tab-content content-box">
    <div id="settings" class="tab-pane fade in active">
        {{ partial("layout_partials/base_form", ['fields': miscForm, 'id': 'frm_misc_settings']) }}
    </div>
</div>

{{ partial('layout_partials/base_apply_button', {'data_endpoint': '/api/core/misc/reconfigure'}) }}
