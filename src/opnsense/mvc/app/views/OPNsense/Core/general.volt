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

<style>
    #row_general\.picture,
    #row_general\.picture_filename {
        display: none !important;
    }
</style>

<script>
    $(document).ready(function() {
        // Suppress table rows for hidden picture fields
        $('#row_general\\.picture, #row_general\\.picture_filename').hide();

        const data_get_map = {'frm_general_settings': '/api/core/general/get'};

        mapDataToFormUI(data_get_map).done(function(data) {
            formatTokenizersUI();
            $('.selectpicker').selectpicker('refresh');

            // Set initial state of DNS allow override exclude interface visibility
            $('#general\\.dnsallowoverride').change();

            // Render existing picture preview if present
            if (data && data.frm_general_settings && data.frm_general_settings.general) {
                const gen = data.frm_general_settings.general;
                if (gen.picture && gen.picture.length > 0) {
                    $('#picture_preview').attr('src', '/api/core/general/picture?' + new Date().getTime());
                    $('#picture_container').show();
                    $('#picture_upload_ctrl').hide();
                } else {
                    $('#picture_container').hide();
                    $('#picture_upload_ctrl').show();
                }
            }
        });

        // Toggle exclude interfaces when dnsallowoverride checkbox toggles
        $('#general\\.dnsallowoverride').change(function() {
            if ($(this).is(':checked')) {
                $('#row_general\\.dnsallowoverride_exclude').show();
            } else {
                $('#row_general\\.dnsallowoverride_exclude').hide();
            }
        });

        // Handle picture file selection via FileReader
        $('#pictfile').change(function(evt) {
            if (evt.target.files && evt.target.files[0]) {
                const file = evt.target.files[0];
                if (file.size > 10 * 1024 * 1024) {
                    BootstrapDialog.show({
                        type: BootstrapDialog.TYPE_DANGER,
                        title: "{{ lang._('Error') }}",
                        message: "{{ lang._('The image file is too large. Please upload something smaller than 10MB.') }}",
                        buttons: [{
                            label: "{{ lang._('Close') }}",
                            action: function(dialogRef) { dialogRef.close(); }
                        }]
                    });
                    $(this).val('');
                    return;
                }

                const reader = new FileReader();
                reader.onload = function(readerEvt) {
                    let binary = '';
                    const bytes = new Uint8Array(readerEvt.target.result);
                    const chunkSize = 0x8000;
                    for (let i = 0; i < bytes.length; i += chunkSize) {
                        binary += String.fromCharCode(...bytes.subarray(i, i + chunkSize));
                    }
                    const base64Data = btoa(binary);
                    $('#general\\.picture').val(base64Data);
                    $('#general\\.picture_filename').val(file.name);
                    $('#picture_preview').attr('src', 'data:' + file.type + ';base64,' + base64Data);
                    $('#picture_container').show();
                    $('#picture_upload_ctrl').hide();
                };
                reader.readAsArrayBuffer(file);
            }
        });

        // Handle picture deletion
        $('#remove_picture').click(function(event) {
            event.preventDefault();
            $('#general\\.picture').val('');
            $('#general\\.picture_filename').val('');
            $('#pictfile').val('');
            $('#picture_container').hide();
            $('#picture_upload_ctrl').show();
        });

        // Inject picture controls into the picture row
        const pictRow = $('#row_general\\.picture');
        if (pictRow.length > 0) {
            const customPictHtml = `
                <tr id="row_custom_picture">
                    <td>
                        <div class="control-label">
                            <a id="help_for_picture" href="#" class="showhelp"><i class="fa fa-info-circle fa-fw"></i></a>
                            <b>{{ lang._('Picture') }}</b>
                        </div>
                    </td>
                    <td>
                        <div id="picture_container" style="display:none; padding: 5px; position: relative; max-width: 250px;">
                            <button type="button" id="remove_picture" class="btn btn-xs btn-danger" style="position: absolute; top: 8px; left: 8px; z-index: 10;" title="{{ lang._('Remove picture') }}">
                                <i class="fa fa-trash"></i>
                            </button>
                            <a id="picture_link" href="/api/core/general/picture" target="_blank">
                                <img id="picture_preview" style="border: 1px solid #ccc; max-width: 200px; max-height: 200px; border-radius: 4px;" src="" alt="Picture Preview" />
                            </a>
                        </div>
                        <div id="picture_upload_ctrl">
                            <input type="file" id="pictfile" accept="image/*" class="form-control" />
                        </div>
                        <div class="hidden" data-for="help_for_picture">
                            <small>{{ lang._('Upload a picture, to be displayed in the Picture widget on the dashboard.') }}</small>
                        </div>
                    </td>
                    <td></td>
                </tr>
            `;
            pictRow.after(customPictHtml);
        }

        // Action button hook for save and reconfigure
        $('#reconfigureAct').SimpleActionButton({
            onPreAction: function() {
                const dfObj = new $.Deferred();
                saveFormToEndpoint(
                    '/api/core/general/set',
                    'frm_general_settings',
                    function() { dfObj.resolve(); },
                    true,
                    function() { dfObj.reject(); }
                );
                return dfObj;
            }
        });
    });
</script>

<ul class="nav nav-tabs" data-tabs="tabs" id="maintabs">
</ul>
<div class="tab-content content-box">
    <div id="settings" class="tab-pane fade in active">
        {{ partial("layout_partials/base_form", ['fields': generalForm, 'id': 'frm_general_settings']) }}
    </div>
</div>

{{ partial('layout_partials/base_apply_button', {'data_endpoint': '/api/core/general/reconfigure'}) }}
