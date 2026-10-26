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

<script src="{{ cache_safe('/ui/js/d3.min.js') }}"></script>
<script src="{{ cache_safe('/ui/js/opnsense_topology.js') }}"></script>

<style>
    .topology-wrapper {
        position: relative;
        overflow: hidden;
        min-height: 620px;
    }
    .topology-toolbar {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        padding: 12px 15px;
        border-bottom: 1px solid rgba(128, 128, 128, 0.2);
    }
    .topology-toolbar-group {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }
    .topology-canvas-container {
        width: 100%;
        min-height: 560px;
        overflow: auto;
        cursor: grab;
    }
    .topology-canvas-container:active {
        cursor: grabbing;
    }
    .opnsense-topology-svg .topology-link {
        fill: none;
        stroke: #9aa5b1;
        stroke-width: 1.6px;
        stroke-opacity: 0.65;
    }
    .opnsense-topology-svg .topology-link.link-active-traffic {
        stroke: #2e86de;
        stroke-width: 2.4px;
        stroke-opacity: 0.9;
    }
    .opnsense-topology-svg .topology-link.link-quarantined {
        stroke: #d9534f;
        stroke-dasharray: 4, 4;
        stroke-width: 2px;
    }
    .opnsense-topology-svg .topology-node {
        cursor: pointer;
    }
    .opnsense-topology-svg .topology-node-circle {
        stroke-width: 2px;
        transition: r 0.15s ease, stroke-width 0.15s ease;
    }
    .opnsense-topology-svg .node-type-gateway .topology-node-circle {
        fill: #d9534f;
        stroke: #a94442;
    }
    .opnsense-topology-svg .node-type-interface .topology-node-circle {
        fill: #337ab7;
        stroke: #204d74;
    }
    .opnsense-topology-svg .node-type-switch .topology-node-circle,
    .opnsense-topology-svg .node-type-ap .topology-node-circle {
        fill: #8e44ad;
        stroke: #5b2c6f;
    }
    .opnsense-topology-svg .node-online .topology-node-circle {
        fill: #5cb85c;
        stroke: #3d8b3d;
    }
    .opnsense-topology-svg .node-offline .topology-node-circle {
        fill: #95a5a6;
        stroke: #7f8c8d;
    }
    .opnsense-topology-svg .node-quarantined .topology-node-circle {
        fill: #d9534f;
        stroke: #843534;
    }
    .opnsense-topology-svg .node-offline-cluster .topology-node-circle {
        fill: #f0ad4e;
        stroke: #eea236;
    }
    .opnsense-topology-svg .node-selected .topology-node-circle {
        stroke: #f39c12;
        stroke-width: 4px;
    }
    .opnsense-topology-svg .topology-node-label {
        font-size: 12px;
        font-weight: 600;
        fill: currentColor;
    }
    .opnsense-topology-svg .topology-node-sublabel {
        font-size: 10px;
        fill: #777;
    }
    .topology-inspector-drawer {
        position: absolute;
        top: 0;
        right: -400px;
        width: 380px;
        max-width: 95%;
        height: 100%;
        background: #fff;
        color: #333;
        border-left: 1px solid rgba(128, 128, 128, 0.25);
        box-shadow: -3px 0 12px rgba(0, 0, 0, 0.12);
        padding: 16px;
        overflow-y: auto;
        transition: right 0.22s ease-in-out;
        z-index: 25;
    }
    .topology-inspector-drawer.open {
        right: 0;
    }
    .topology-inspector-section {
        margin-bottom: 14px;
        padding-bottom: 12px;
        border-bottom: 1px solid rgba(128, 128, 128, 0.15);
    }
</style>

<script>
    $( document ).ready(function() {
        // Add the origin column since it is not part of dialogNeighbor.xml
        const $originColumn = $('<th data-column-id="origin"  data-type="string">{{ lang._('Origin') }}</th>');
        $('#{{formGridNeighbor['table_id']}} thead tr th[data-column-id="uuid"]').after($originColumn);

        $("#{{formGridNeighbor['table_id']}}").UIBootgrid(
            {   search:'/api/interfaces/neighbor_settings/search_item',
                get:'/api/interfaces/neighbor_settings/get_item/',
                set:'/api/interfaces/neighbor_settings/set_item/',
                add:'/api/interfaces/neighbor_settings/add_item/',
                del:'/api/interfaces/neighbor_settings/del_item/',
                options:{
                    formatters: {
                        commands: function (column, row) {
                            if (row.origin == 'manual') {
                                // exclude buttons for internal (dynamic) neighbors
                                return '<button type="button" class="btn btn-xs btn-default command-edit bootgrid-tooltip" data-row-id="' + row.uuid + '"><span class="fa fa-fw fa-pencil"></span></button> ' +
                                    '<button type="button" class="btn btn-xs btn-default command-copy bootgrid-tooltip" data-row-id="' + row.uuid + '"><span class="fa fa-fw fa-clone"></span></button>' +
                                    '<button type="button" class="btn btn-xs btn-default command-delete bootgrid-tooltip" data-row-id="' + row.uuid + '"><span class="fa fa-fw fa-trash-o"></span></button>';
                            }
                        }
                    }
                }
            }
        );
        $("#reconfigureAct").SimpleActionButton();

        var topologyWidget = null;
        function ensureTopologyLoaded() {
            if (!topologyWidget && window.OPNsenseTopology && window.OPNsenseTopology.TopologyWidget) {
                topologyWidget = new window.OPNsenseTopology.TopologyWidget({
                    canvasSelector: '#topology-canvas',
                    drawerSelector: '#topology-inspector-drawer',
                    translations: {
                        online: "{{ lang._('Online') }}",
                        offline: "{{ lang._('Offline') }}",
                        quarantined: "{{ lang._('Quarantined') }}",
                        unknownVendor: "{{ lang._('Unknown Vendor') }}",
                        noAddresses: "{{ lang._('No IP addresses observed') }}",
                        staticLeaseActive: "{{ lang._('Static Reservation') }}",
                        dynamicLease: "{{ lang._('Dynamic / Discovered') }}",
                        noMacAliases: "{{ lang._('No MAC/Host Aliases Configured') }}",
                        quarantineDevice: "{{ lang._('Pause Internet Access (Quarantine)') }}",
                        unquarantineDevice: "{{ lang._('Resume Internet Access') }}",
                        savedLabel: "{{ lang._('Device label saved.') }}",
                        errorSaving: "{{ lang._('Failed to save label.') }}",
                        quarantinedMsg: "{{ lang._('Device quarantined and active states killed.') }}",
                        unquarantinedMsg: "{{ lang._('Device removed from quarantine.') }}",
                        errorQuarantine: "{{ lang._('Failed to update quarantine.') }}",
                        noIpv4ForLease: "{{ lang._('No IPv4 address or DHCP subnet available for static lease.') }}",
                        staticLeaseSaved: "{{ lang._('Static DHCP reservation created via ') }}",
                        staticLeaseFailed: "{{ lang._('Could not create static reservation.') }}",
                        selectAliasPrompt: "{{ lang._('Please select a firewall alias first.') }}",
                        aliasAdded: "{{ lang._('Added to alias: ') }}",
                        aliasRemoved: "{{ lang._('Removed from alias: ') }}",
                        aliasError: "{{ lang._('Failed to update alias.') }}"
                    }
                });
                topologyWidget.init();
            } else if (topologyWidget) {
                topologyWidget.refresh();
            }
        }

        $('a[data-toggle="tab"]').on('shown.bs.tab', function (e) {
            if ($(e.target).attr('href') === '#topology') {
                window.location.hash = 'topology';
                ensureTopologyLoaded();
            } else {
                window.location.hash = 'neighbors';
            }
        });

        if (window.location.hash === '#topology') {
            $('#maintabs a[href="#topology"]').tab('show');
            ensureTopologyLoaded();
        }
    });
</script>

<ul class="nav nav-tabs" data-tabs="tabs" id="maintabs">
    <li class="active"><a data-toggle="tab" href="#neighbors" id="neighbors_tab">{{ lang._('Static Assignments') }}</a></li>
    <li><a data-toggle="tab" href="#topology" id="topology_tab"><i class="fa fa-sitemap fa-fw"></i> {{ lang._('Topology Map') }}</a></li>
</ul>

<div class="tab-content content-box">
    <div id="neighbors" class="tab-pane fade in active">
        {{ partial('layout_partials/base_bootgrid_table', formGridNeighbor)}}
    </div>

    <div id="topology" class="tab-pane fade topology-wrapper">
        <div class="topology-toolbar">
            <div class="topology-toolbar-group">
                <div class="btn-group" role="group">
                    <button type="button" id="topology-mode-horizontal" class="btn btn-default btn-sm active">
                        <i class="fa fa-align-left fa-fw"></i> {{ lang._('Horizontal Tree') }}
                    </button>
                    <button type="button" id="topology-mode-radial" class="btn btn-default btn-sm">
                        <i class="fa fa-dot-circle-o fa-fw"></i> {{ lang._('Radial Map') }}
                    </button>
                </div>
                <label class="checkbox-inline" style="margin-left: 8px;">
                    <input type="checkbox" id="topology-toggle-offline" /> {{ lang._('Expand all offline devices') }}
                </label>
                <span class="label label-success"><span id="topology-count-online">0</span> {{ lang._('Online') }}</span>
                <span class="label label-default"><span id="topology-count-offline">0</span> {{ lang._('Offline') }}</span>
                <span class="label label-danger"><span id="topology-count-quarantined">0</span> {{ lang._('Quarantined') }}</span>
            </div>

            <div class="topology-toolbar-group">
                <input type="text" id="topology-search-input" class="form-control input-sm" style="width: 220px;"
                       placeholder="{{ lang._('Search IP, MAC, host, vendor...') }}" />
                <button type="button" id="topology-refresh-btn" class="btn btn-primary btn-sm">
                    <i class="fa fa-refresh fa-fw"></i> {{ lang._('Refresh') }}
                </button>
                <span id="topology-loading-indicator" style="display: none;">
                    <i class="fa fa-spinner fa-spin"></i>
                </span>
            </div>
        </div>

        <div id="topology-canvas" class="topology-canvas-container"></div>

        <aside id="topology-inspector-drawer" class="topology-inspector-drawer">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                <h4 id="inspector-client-title" style="margin: 0; word-break: break-all;">{{ lang._('Client Details') }}</h4>
                <button type="button" id="topology-drawer-close" class="btn btn-xs btn-default">
                    <i class="fa fa-times"></i>
                </button>
            </div>

            <div id="inspector-feedback-msg" class="alert" style="display: none; padding: 8px 12px; margin-bottom: 12px;"></div>

            <div class="topology-inspector-section">
                <p style="margin-bottom: 6px;">
                    <span id="inspector-client-status" class="label label-default">{{ lang._('Offline') }}</span>
                    <span class="label label-primary" id="inspector-client-interface"></span>
                </p>
                <div><strong>{{ lang._('MAC Address') }}:</strong> <code id="inspector-client-mac"></code></div>
                <div><strong>{{ lang._('Vendor') }}:</strong> <span id="inspector-client-vendor"></span></div>
                <div><strong>{{ lang._('Live Bandwidth') }}:</strong> <span id="inspector-client-bandwidth">0 bps</span></div>
            </div>

            <div class="topology-inspector-section">
                <label>{{ lang._('Observed IPv4 / IPv6 Addresses') }}</label>
                <ul id="inspector-address-list" class="list-group" style="margin-bottom: 0;"></ul>
            </div>

            <div class="topology-inspector-section">
                <label for="inspector-label-input">{{ lang._('Device Friendly Name / Label') }}</label>
                <div class="input-group input-group-sm">
                    <input type="text" id="inspector-label-input" class="form-control" placeholder="{{ lang._('Enter device description...') }}" />
                    <span class="input-group-btn">
                        <button type="button" id="inspector-save-label-btn" class="btn btn-primary">
                            <i class="fa fa-check"></i> {{ lang._('Save') }}
                        </button>
                    </span>
                </div>
            </div>

            <div class="topology-inspector-section">
                <label>{{ lang._('DHCP Lease') }}: <small id="inspector-lease-status"></small></label>
                <div>
                    <button type="button" id="inspector-static-lease-btn" class="btn btn-default btn-sm btn-block">
                        <i class="fa fa-thumb-tack fa-fw"></i> {{ lang._('Convert to Static DHCP Reservation') }}
                    </button>
                </div>
            </div>

            <div class="topology-inspector-section">
                <label for="inspector-alias-select">{{ lang._('Firewall Policy Group (MAC / Host Alias)') }}</label>
                <div class="input-group input-group-sm">
                    <select id="inspector-alias-select" class="form-control"></select>
                    <span class="input-group-btn">
                        <button type="button" id="inspector-assign-alias-btn" class="btn btn-default">
                            <i class="fa fa-exchange"></i> {{ lang._('Toggle') }}
                        </button>
                    </span>
                </div>
            </div>

            <div>
                <button type="button" id="inspector-quarantine-btn" class="btn btn-danger btn-sm btn-block">
                    <i class="fa fa-ban fa-fw"></i> {{ lang._('Pause Internet Access (Quarantine)') }}
                </button>
            </div>
        </aside>
    </div>
</div>
{{ partial('layout_partials/base_apply_button', {'data_endpoint': '/api/interfaces/neighbor_settings/reconfigure', 'data_exclude_scope': 'topology_tab'}) }}
{{ partial('layout_partials/base_dialog',['fields':formDialogEdit,'id':formGridNeighbor['edit_dialog_id'],'label':lang._('Edit Neighbor')])}}

