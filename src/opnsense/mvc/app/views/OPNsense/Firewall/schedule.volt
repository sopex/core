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
    $(document).ready(function() {
        let currentRanges = [];

        function renderRangesTable() {
            let $tbody = $('#scheduleRangesTable tbody');
            $tbody.empty();

            const dayLabels = {
                1: "{{ lang._('Mon') }}",
                2: "{{ lang._('Tue') }}",
                3: "{{ lang._('Wed') }}",
                4: "{{ lang._('Thu') }}",
                5: "{{ lang._('Fri') }}",
                6: "{{ lang._('Sat') }}",
                7: "{{ lang._('Sun') }}"
            };

            const monthLabels = {
                1: "{{ lang._('Jan') }}", 2: "{{ lang._('Feb') }}", 3: "{{ lang._('Mar') }}",
                4: "{{ lang._('Apr') }}", 5: "{{ lang._('May') }}", 6: "{{ lang._('Jun') }}",
                7: "{{ lang._('Jul') }}", 8: "{{ lang._('Aug') }}", 9: "{{ lang._('Sep') }}",
                10: "{{ lang._('Oct') }}", 11: "{{ lang._('Nov') }}", 12: "{{ lang._('Dec') }}"
            };

            if (currentRanges.length === 0) {
                $tbody.append('<tr><td colspan="4" class="text-center text-muted"><em>{{ lang._('No time ranges configured. Add at least one range below.') }}</em></td></tr>');
            } else {
                currentRanges.forEach(function(range, index) {
                    let dayDisplay = '';
                    if (range.position && range.position.length > 0) {
                        let days = range.position.split(',').map(function(d) {
                            return dayLabels[parseInt(d)] || d;
                        });
                        dayDisplay = days.join(', ');
                    } else if (range.month && range.day) {
                        let months = range.month.split(',');
                        let days = range.day.split(',');
                        let parts = [];
                        for (let i = 0; i < Math.min(months.length, days.length); i++) {
                            let m = parseInt(months[i]);
                            parts.push((monthLabels[m] || m) + ' ' + days[i]);
                        }
                        dayDisplay = parts.join(', ');
                    }

                    let row = $('<tr>');
                    row.append($('<td>').text(dayDisplay));
                    row.append($('<td>').text(range.hour || ''));
                    row.append($('<td>').text(range.rangedescr || ''));
                    let $btnDel = $('<button type="button" class="btn btn-xs btn-default"><span class="fa fa-trash"></span></button>');
                    $btnDel.on('click', function() {
                        currentRanges.splice(index, 1);
                        syncRangesToField();
                        renderRangesTable();
                    });
                    row.append($('<td class="text-right">').append($btnDel));
                    $tbody.append(row);
                });
            }
        }

        function syncRangesToField() {
            $('#schedule\\.timeranges').val(JSON.stringify(currentRanges));
        }

        function loadRangesFromField() {
            let raw = $('#schedule\\.timeranges').val();
            currentRanges = [];
            if (raw) {
                try {
                    let parsed = JSON.parse(raw);
                    if (Array.isArray(parsed)) {
                        currentRanges = parsed;
                    }
                } catch (e) {
                    currentRanges = [];
                }
            }
            renderRangesTable();
        }

        $("#{{formGridSchedule['table_id']}}").UIBootgrid({
            search: '/api/firewall/schedule/search_item',
            get: '/api/firewall/schedule/get_item/',
            set: '/api/firewall/schedule/set_item/',
            add: '/api/firewall/schedule/add_item/',
            del: '/api/firewall/schedule/del_item/',
            toggle: '/api/firewall/schedule/toggle_item/',
            options: {
                formatters: {
                    status: function(column, row) {
                        if (row.status === '1') {
                            return '<span class="fa fa-clock-o text-success" data-toggle="tooltip" title="{{ lang._('Active') }}"></span> <span class="text-success">{{ lang._('Active') }}</span>';
                        }
                        return '<span class="fa fa-clock-o text-muted" data-toggle="tooltip" title="{{ lang._('Inactive') }}"></span> <span class="text-muted">{{ lang._('Inactive') }}</span>';
                    },
                    commands: function(column, row) {
                        return '<button type="button" class="btn btn-xs btn-default command-edit bootgrid-tooltip" data-row-id="' + row.uuid + '"><span class="fa fa-fw fa-pencil"></span></button> ' +
                            '<button type="button" class="btn btn-xs btn-default command-copy bootgrid-tooltip" data-row-id="' + row.uuid + '"><span class="fa fa-fw fa-clone"></span></button> ' +
                            '<button type="button" class="btn btn-xs btn-default command-delete bootgrid-tooltip" data-row-id="' + row.uuid + '"><span class="fa fa-fw fa-trash-o"></span></button>';
                    }
                }
            }
        });

        $('#{{formGridSchedule['edit_dialog_id']}}').on('shown.bs.modal', function() {
            setTimeout(loadRangesFromField, 200);
        });

        // Day of week toggle buttons
        $('.btn-day-toggle').on('click', function(e) {
            e.preventDefault();
            $(this).toggleClass('active btn-primary btn-default');
        });

        // Range type switch
        $('input[name="rangeType"]').on('change', function() {
            if ($(this).val() === 'repeating') {
                $('#repeatingDaysGroup').show();
                $('#specificDatesGroup').hide();
            } else {
                $('#repeatingDaysGroup').hide();
                $('#specificDatesGroup').show();
            }
        });

        // Add Time Range button handler
        $('#btnAddTimeRange').on('click', function() {
            let rangeType = $('input[name="rangeType"]:checked').val();
            let startHour = $('#rangeStartHour').val();
            let startMin = $('#rangeStartMin').val();
            let stopHour = $('#rangeStopHour').val();
            let stopMin = $('#rangeStopMin').val();
            let descr = $('#rangeDescription').val();

            let startMinutes = (parseInt(startHour, 10) * 60) + parseInt(startMin, 10);
            let stopMinutes = (parseInt(stopHour, 10) * 60) + parseInt(stopMin, 10);

            if (startMinutes > stopMinutes) {
                BootstrapDialog.alert({
                    title: "{{ lang._('Validation Error') }}",
                    message: "{{ lang._('Start time cannot be greater than stop time.') }}",
                    type: BootstrapDialog.TYPE_WARNING
                });
                return;
            }

            let hourStr = startHour + ':' + startMin + '-' + stopHour + ':' + stopMin;
            let newRange = {
                hour: hourStr,
                rangedescr: descr
            };

            if (rangeType === 'repeating') {
                let selectedDays = [];
                $('.btn-day-toggle.active').each(function() {
                    selectedDays.push($(this).data('day'));
                });
                if (selectedDays.length === 0) {
                    BootstrapDialog.alert({
                        title: "{{ lang._('Validation Error') }}",
                        message: "{{ lang._('Please select at least one day of the week.') }}",
                        type: BootstrapDialog.TYPE_WARNING
                    });
                    return;
                }
                newRange.position = selectedDays.join(',');
                newRange.month = '';
                newRange.day = '';
            } else {
                let dateVal = $('#rangeSpecificDate').val();
                if (!dateVal) {
                    BootstrapDialog.alert({
                        title: "{{ lang._('Validation Error') }}",
                        message: "{{ lang._('Please choose a date.') }}",
                        type: BootstrapDialog.TYPE_WARNING
                    });
                    return;
                }
                let parts = dateVal.split('-');
                if (parts.length === 3) {
                    newRange.month = parseInt(parts[1], 10).toString();
                    newRange.day = parseInt(parts[2], 10).toString();
                    newRange.position = '';
                } else {
                    return;
                }
            }

            currentRanges.push(newRange);
            syncRangesToField();
            renderRangesTable();

            // Reset inputs
            $('.btn-day-toggle').removeClass('active btn-primary').addClass('btn-default');
            $('#rangeSpecificDate').val('');
            $('#rangeDescription').val('');
        });

        $('#scheduleRangesContainer').detach().appendTo('#frm_{{formGridSchedule['edit_dialog_id']}}');
        $("#reconfigureAct").SimpleActionButton();
    });
</script>

<div class="tab-content content-box">
    {{ partial('layout_partials/base_bootgrid_table', formGridSchedule) }}
</div>

{{ partial('layout_partials/base_apply_button', {'data_endpoint': '/api/firewall/schedule/reconfigure'}) }}

{{ partial("layout_partials/base_dialog", [
    'fields': formDialogEdit,
    'id': formGridSchedule['edit_dialog_id'],
    'label': lang._('Edit Schedule')
]) }}

<div id="scheduleRangesContainer">
    <hr/>
    <div class="form-group">
        <label class="control-label col-md-2"><strong>{{ lang._('Time Ranges') }}</strong></label>
        <div class="col-md-10">
            <table id="scheduleRangesTable" class="table table-condensed table-striped table-bordered">
                <thead>
                    <tr>
                        <th style="width: 40%;">{{ lang._('Day(s) / Date(s)') }}</th>
                        <th style="width: 25%;">{{ lang._('Time') }}</th>
                        <th style="width: 25%;">{{ lang._('Description') }}</th>
                        <th style="width: 10%;"></th>
                    </tr>
                </thead>
                <tbody>
                </tbody>
            </table>

            <div class="well well-sm" style="margin-top: 10px;">
                <h4>{{ lang._('Add Range') }}</h4>
                <div style="margin-bottom: 10px;">
                    <label class="radio-inline">
                        <input type="radio" name="rangeType" value="repeating" checked> {{ lang._('Weekly Repeating') }}
                    </label>
                    <label class="radio-inline">
                        <input type="radio" name="rangeType" value="specific"> {{ lang._('Specific Date') }}
                    </label>
                </div>

                <div id="repeatingDaysGroup" style="margin-bottom: 10px;">
                    <label style="display:block; margin-bottom: 5px;">{{ lang._('Days of the Week') }}</label>
                    <div class="btn-group" role="group">
                        <button type="button" class="btn btn-default btn-sm btn-day-toggle" data-day="1">{{ lang._('Mon') }}</button>
                        <button type="button" class="btn btn-default btn-sm btn-day-toggle" data-day="2">{{ lang._('Tue') }}</button>
                        <button type="button" class="btn btn-default btn-sm btn-day-toggle" data-day="3">{{ lang._('Wed') }}</button>
                        <button type="button" class="btn btn-default btn-sm btn-day-toggle" data-day="4">{{ lang._('Thu') }}</button>
                        <button type="button" class="btn btn-default btn-sm btn-day-toggle" data-day="5">{{ lang._('Fri') }}</button>
                        <button type="button" class="btn btn-default btn-sm btn-day-toggle" data-day="6">{{ lang._('Sat') }}</button>
                        <button type="button" class="btn btn-default btn-sm btn-day-toggle" data-day="7">{{ lang._('Sun') }}</button>
                    </div>
                </div>

                <div id="specificDatesGroup" style="display: none; margin-bottom: 10px;">
                    <label for="rangeSpecificDate" style="display:block; margin-bottom: 5px;">{{ lang._('Date') }}</label>
                    <input type="date" id="rangeSpecificDate" class="form-control input-sm" style="max-width: 200px;">
                </div>

                <div class="row" style="margin-bottom: 10px;">
                    <div class="col-xs-6">
                        <label style="display:block; margin-bottom: 5px;">{{ lang._('Start Time') }}</label>
                        <div class="form-inline">
                            <select id="rangeStartHour" class="form-control schedule-time-select">
                                <option value="00">00</option><option value="01">01</option><option value="02">02</option>
                                <option value="03">03</option><option value="04">04</option><option value="05">05</option>
                                <option value="06">06</option><option value="07">07</option><option value="08">08</option>
                                <option value="09" selected>09</option><option value="10">10</option><option value="11">11</option>
                                <option value="12">12</option><option value="13">13</option><option value="14">14</option>
                                <option value="15">15</option><option value="16">16</option><option value="17">17</option>
                                <option value="18">18</option><option value="19">19</option><option value="20">20</option>
                                <option value="21">21</option><option value="22">22</option><option value="23">23</option>
                            </select>
                            <span class="schedule-time-sep">:</span>
                            <select id="rangeStartMin" class="form-control schedule-time-select">
                                <option value="00" selected>00</option>
                                <option value="15">15</option>
                                <option value="30">30</option>
                                <option value="45">45</option>
                                <option value="59">59</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-xs-6">
                        <label style="display:block; margin-bottom: 5px;">{{ lang._('Stop Time') }}</label>
                        <div class="form-inline">
                            <select id="rangeStopHour" class="form-control schedule-time-select">
                                <option value="00">00</option><option value="01">01</option><option value="02">02</option>
                                <option value="03">03</option><option value="04">04</option><option value="05">05</option>
                                <option value="06">06</option><option value="07">07</option><option value="08">08</option>
                                <option value="09">09</option><option value="10">10</option><option value="11">11</option>
                                <option value="12">12</option><option value="13">13</option><option value="14">14</option>
                                <option value="15">15</option><option value="16">16</option><option value="17" selected>17</option>
                                <option value="18">18</option><option value="19">19</option><option value="20">20</option>
                                <option value="21">21</option><option value="22">22</option><option value="23">23</option>
                            </select>
                            <span class="schedule-time-sep">:</span>
                            <select id="rangeStopMin" class="form-control schedule-time-select">
                                <option value="00" selected>00</option>
                                <option value="15">15</option>
                                <option value="30">30</option>
                                <option value="45">45</option>
                                <option value="59">59</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div style="margin-bottom: 10px;">
                    <label for="rangeDescription" style="display:block; margin-bottom: 5px;">{{ lang._('Range Description') }}</label>
                    <input type="text" id="rangeDescription" class="form-control input-sm" placeholder="{{ lang._('e.g. Work hours') }}">
                </div>

                <button type="button" id="btnAddTimeRange" class="btn btn-default btn-sm">
                    <span class="fa fa-plus"></span> {{ lang._('Add Time Range') }}
                </button>
            </div>
        </div>
    </div>
</div>

<style>
    .schedule-time-select {
        width: 70px !important;
        display: inline-block !important;
        height: 34px !important;
        line-height: 24px !important;
        padding: 4px 20px 4px 10px !important;
        font-size: 13px !important;
        vertical-align: middle !important;
        background-position: right 6px center !important;
    }
    .schedule-time-sep {
        font-weight: bold;
        font-size: 16px;
        margin: 0 4px;
        vertical-align: middle;
    }
</style>
