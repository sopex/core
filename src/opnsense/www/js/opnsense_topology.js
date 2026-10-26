/*
 * Copyright (C) 2026 Konstantinos Spartalis
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * 1. Redistributions of source code must retain the above copyright notice,
 *    this list of conditions and the following disclaimer.
 *
 * 2. Redistributions in binary form must reproduce the above copyright
 *    notice, this list of conditions and the following disclaimer in the
 *    documentation and/or other materials provided with the distribution.
 *
 * THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
 * INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
 * AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 * AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
 * OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 */

(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.OPNsenseTopology = factory();
    }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    /**
     * Format bits-per-second into human-readable rate string.
     *
     * @param {number} bps
     * @returns {string}
     */
    function formatBandwidth(bps) {
        var val = Number(bps) || 0;
        if (val <= 0) {
            return '0 bps';
        }
        if (val >= 1e9) {
            return (val / 1e9).toFixed(2) + ' Gbps';
        }
        if (val >= 1e6) {
            return (val / 1e6).toFixed(2) + ' Mbps';
        }
        if (val >= 1e3) {
            return (val / 1e3).toFixed(1) + ' Kbps';
        }
        return Math.round(val) + ' bps';
    }

    /**
     * Extract the first IP string from an array or scalar string field.
     *
     * @param {Array|string} val
     * @returns {string}
     */
    function firstIp(val) {
        if (Array.isArray(val)) {
            for (var i = 0; i < val.length; i++) {
                if (typeof val[i] === 'string' && val[i].trim() !== '') {
                    return val[i].trim();
                }
            }
            return '';
        }
        return (typeof val === 'string') ? val.trim() : '';
    }

    /**
     * Normalize an IP field (array or scalar string) into an array of IP strings.
     *
     * @param {Array|string} val
     * @returns {Array<string>}
     */
    function ipList(val) {
        if (Array.isArray(val)) {
            var res = [];
            for (var i = 0; i < val.length; i++) {
                if (typeof val[i] === 'string' && val[i].trim() !== '') {
                    res.push(val[i].trim());
                }
            }
            return res;
        }
        return (typeof val === 'string' && val.trim() !== '') ? [val.trim()] : [];
    }

    /**
     * Parse dotted-quad IPv4 string to unsigned 32-bit integer, or null if invalid.
     *
     * @param {string} ip
     * @returns {number|null}
     */
    function parseIpv4ToUint32(ip) {
        if (!ip || typeof ip !== 'string') {
            return null;
        }
        var parts = ip.trim().split('.');
        if (parts.length !== 4) {
            return null;
        }
        var num = 0;
        for (var i = 0; i < 4; i++) {
            if (!/^\d+$/.test(parts[i])) {
                return null;
            }
            var octet = parseInt(parts[i], 10);
            if (octet < 0 || octet > 255) {
                return null;
            }
            num = ((num << 8) | octet) >>> 0;
        }
        return num;
    }

    /**
     * Check whether an IPv4 address belongs to an IPv4 CIDR subnet.
     *
     * @param {string} ip
     * @param {string} cidr
     * @returns {boolean}
     */
    function ipv4InCidr(ip, cidr) {
        if (!ip || !cidr || typeof cidr !== 'string') {
            return false;
        }
        var slashIdx = cidr.indexOf('/');
        if (slashIdx === -1) {
            return false;
        }
        var netIp = parseIpv4ToUint32(cidr.slice(0, slashIdx));
        var targetIp = parseIpv4ToUint32(ip);
        var prefix = parseInt(cidr.slice(slashIdx + 1), 10);
        if (netIp === null || targetIp === null || isNaN(prefix) || prefix < 0 || prefix > 32) {
            return false;
        }
        if (prefix === 0) {
            return true;
        }
        var mask = (0xffffffff << (32 - prefix)) >>> 0;
        return ((netIp & mask) >>> 0) === ((targetIp & mask) >>> 0);
    }

    /**
     * Sanitize a friendly label into an RFC-compliant DNS hostname (max 63 chars) for Kea/Dnsmasq.
     *
     * @param {string} rawName
     * @returns {string}
     */
    function sanitizeDnsHostname(rawName) {
        if (!rawName || typeof rawName !== 'string') {
            return 'client-host';
        }
        var cleaned = rawName
            .trim()
            .toLowerCase()
            .replace(/[^a-z0-9-]+/g, '-')
            .replace(/^-+|-+$/g, '')
            .slice(0, 63)
            .replace(/-+$/g, '');
        return cleaned || 'client-host';
    }

    /**
     * Build the DHCP static reservation request descriptor for Kea or Dnsmasq.
     *
     * @param {Object} client
     * @param {Object} topologyData
     * @returns {Object|null}
     */
    function buildStaticLeaseRequest(client, topologyData) {
        if (!client || !client.mac) {
            return null;
        }
        var ipv4 = firstIp(client.ipv4) || firstIp(client.primary_ipv4) || firstIp(client.ipv4_addresses);
        if (!ipv4 && Array.isArray(client.addresses)) {
            for (var i = 0; i < client.addresses.length; i++) {
                if (client.addresses[i].indexOf(':') === -1) {
                    ipv4 = client.addresses[i];
                    break;
                }
            }
        }
        if (!ipv4) {
            return null;
        }

        var rawLabel = client.descr || client.hostname || client.dhcp_hostname || client.label || '';
        var dnsHost = sanitizeDnsHostname(rawLabel);
        var descr = client.descr || client.label || dnsHost;
        var dhcpSubnets = (topologyData && topologyData.dhcp_subnets) ? topologyData.dhcp_subnets : {};
        var keaSubnets = Array.isArray(dhcpSubnets.kea) ? dhcpSubnets.kea : [];
        var dnsmasqRanges = Array.isArray(dhcpSubnets.dnsmasq) ? dhcpSubnets.dnsmasq : [];

        var matchedKeaSubnet = '';
        for (var c = 0; c < keaSubnets.length; c++) {
            if (keaSubnets[c].subnet && ipv4InCidr(ipv4, keaSubnets[c].subnet)) {
                matchedKeaSubnet = keaSubnets[c].uuid;
                break;
            }
        }
        if (!matchedKeaSubnet && client.kea_subnet_uuid) {
            matchedKeaSubnet = client.kea_subnet_uuid;
        }
        if (!matchedKeaSubnet) {
            for (var k = 0; k < keaSubnets.length; k++) {
                if (keaSubnets[k].interface && keaSubnets[k].interface === client.interface) {
                    matchedKeaSubnet = keaSubnets[k].uuid;
                    break;
                }
            }
        }
        if (!matchedKeaSubnet && keaSubnets.length > 0 && !keaSubnets[0].subnet) {
            matchedKeaSubnet = keaSubnets[0].uuid;
        }

        var useKea = client.dhcp_service === 'kea' || (matchedKeaSubnet !== '' && client.dhcp_service !== 'dnsmasq');
        if (useKea && matchedKeaSubnet) {
            var keaRecord = {
                subnet: matchedKeaSubnet,
                hw_address: client.mac,
                ip_address: ipv4,
                hostname: dnsHost,
                description: descr
            };
            return {
                service: 'kea',
                endpoint: '/api/kea/dhcpv4/addReservation',
                reconfigureEndpoint: '/api/kea/service/reconfigure',
                payload: {
                    reservation: keaRecord,
                    subnet: matchedKeaSubnet,
                    hw_address: client.mac,
                    ip_address: ipv4,
                    hostname: dnsHost,
                    description: descr
                }
            };
        }

        var hasDnsmasq = client.dhcp_service === 'dnsmasq' || dnsmasqRanges.length > 0 || !matchedKeaSubnet;
        if (hasDnsmasq) {
            var dnsmasqRecord = {
                hwaddr: client.mac,
                ip: ipv4,
                host: dnsHost,
                descr: descr
            };
            return {
                service: 'dnsmasq',
                endpoint: '/api/dnsmasq/settings/add_host',
                reconfigureEndpoint: '/api/dnsmasq/service/reconfigure',
                payload: {
                    host: dnsmasqRecord,
                    hwaddr: client.mac,
                    ip: ipv4,
                    descr: descr
                }
            };
        }

        return null;
    }

    /**
     * Build the hierarchical tree model for D3 Tidy Tree from flat topology data.
     * Collapses offline hosts per interface/switch into a "+<N> offline devices" badge node
     * unless expanded or globally shown.
     *
     * @param {Object} data
     * @param {Object} options
     * @returns {Object}
     */
    function buildTopologyHierarchy(data, options) {
        options = options || {};
        var showAllOffline = Boolean(options.showAllOffline);
        var expandedClusters = options.expandedClusters || {};
        var searchQuery = (options.searchQuery || '').trim().toLowerCase();

        var interfaces = Array.isArray(data && data.interfaces) ? data.interfaces : [];
        var switches = Array.isArray(data && data.switches) ? data.switches : [];
        var clients = Array.isArray(data && data.clients) ? data.clients : [];

        var rootNode = {
            id: 'root:opnsense',
            name: (data && data.hostname) ? data.hostname : 'OPNsense',
            type: 'gateway',
            children: []
        };

        var ifMap = {};
        for (var i = 0; i < interfaces.length; i++) {
            var intf = interfaces[i];
            var ifKey = intf.identifier || intf.device || ('if_' + i);
            var ifNode = {
                id: 'if:' + ifKey,
                identifier: ifKey,
                device: intf.device || ifKey,
                name: intf.descr || intf.identifier || intf.device || ifKey,
                type: 'interface',
                ipv4: ipList(intf.ipv4),
                ipv6: ipList(intf.ipv6),
                children: [],
                _switchMap: {},
                _onlineClients: [],
                _offlineClients: []
            };
            ifMap[ifKey] = ifNode;
            if (intf.device) {
                ifMap[intf.device] = ifNode;
            }
            rootNode.children.push(ifNode);
        }

        // Index intermediate L2 switches / APs discovered via LLDP or bridge tables
        for (var s = 0; s < switches.length; s++) {
            var sw = switches[s];
            var parentIf = ifMap[sw.interface] || ifMap[sw.device];
            if (parentIf && sw.mac) {
                var swMac = sw.mac.toLowerCase();
                var swType = (sw.type === 'ap') ? 'ap' : 'switch';
                var swNode = {
                    id: 'sw:' + parentIf.identifier + ':' + swMac,
                    name: sw.name || sw.descr || ((swType === 'ap' ? 'AP ' : 'Switch ') + swMac),
                    mac: swMac,
                    port: sw.port || '',
                    type: swType,
                    interface: parentIf.identifier,
                    children: [],
                    _onlineClients: [],
                    _offlineClients: []
                };
                parentIf._switchMap[swMac] = swNode;
            }
        }

        function matchesSearch(client) {
            if (!searchQuery) {
                return true;
            }
            var haystack = [
                client.label || '',
                client.hostname || '',
                client.descr || '',
                client.mac || '',
                client.vendor || '',
                ipList(client.ipv4).join(' '),
                ipList(client.ipv6).join(' '),
                (client.addresses || []).join(' ')
            ].join(' ').toLowerCase();
            return haystack.indexOf(searchQuery) !== -1;
        }

        for (var c = 0; c < clients.length; c++) {
            var cl = clients[c];
            if (!matchesSearch(cl)) {
                continue;
            }
            var targetIfKey = cl.interface || cl.device || 'lan';
            var targetIf = ifMap[targetIfKey];
            if (!targetIf) {
                targetIf = {
                    id: 'if:' + targetIfKey,
                    identifier: targetIfKey,
                    device: cl.device || targetIfKey,
                    name: cl.interface_descr || targetIfKey.toUpperCase(),
                    type: 'interface',
                    ipv4: [],
                    ipv6: [],
                    children: [],
                    _switchMap: {},
                    _onlineClients: [],
                    _offlineClients: []
                };
                ifMap[targetIfKey] = targetIf;
                rootNode.children.push(targetIf);
            }

            var primaryClientIp = firstIp(cl.ipv4) || firstIp(cl.ipv6) ||
                ((Array.isArray(cl.addresses) && cl.addresses.length > 0) ? cl.addresses[0] : '');
            var displayLabel = cl.descr || cl.label || cl.hostname || primaryClientIp || cl.mac;
            var clientTreeItem = {
                id: 'client:' + cl.mac,
                name: displayLabel,
                type: 'client',
                client: cl,
                online: Boolean(cl.online),
                quarantined: Boolean(cl.quarantined),
                bps: Number(cl.bps) || 0,
                is_static: Boolean(cl.is_static)
            };

            var container = targetIf;
            var clMacLower = (cl.mac || '').toLowerCase();
            if (
                cl.switch_mac &&
                cl.switch_mac.toLowerCase() !== clMacLower &&
                targetIf._switchMap[cl.switch_mac.toLowerCase()]
            ) {
                container = targetIf._switchMap[cl.switch_mac.toLowerCase()];
            }

            if (cl.online || cl.quarantined || searchQuery !== '') {
                container._onlineClients.push(clientTreeItem);
            } else {
                container._offlineClients.push(clientTreeItem);
            }
        }

        function finalizeContainer(containerNode, clusterKey) {
            var onlineList = containerNode._onlineClients || [];
            var offlineList = containerNode._offlineClients || [];

            onlineList.sort(function (a, b) {
                if (b.bps !== a.bps) {
                    return b.bps - a.bps;
                }
                return String(a.name).localeCompare(String(b.name));
            });
            offlineList.sort(function (a, b) {
                return String(a.name).localeCompare(String(b.name));
            });

            for (var i = 0; i < onlineList.length; i++) {
                containerNode.children.push(onlineList[i]);
            }

            if (offlineList.length > 0) {
                var isExpanded = showAllOffline || Boolean(expandedClusters[clusterKey]);
                if (isExpanded) {
                    for (var j = 0; j < offlineList.length; j++) {
                        containerNode.children.push(offlineList[j]);
                    }
                    if (!showAllOffline) {
                        containerNode.children.push({
                            id: 'cluster:' + clusterKey,
                            clusterKey: clusterKey,
                            name: 'Hide ' + offlineList.length + ' offline device' + (offlineList.length === 1 ? '' : 's'),
                            type: 'offline_cluster',
                            expanded: true,
                            count: offlineList.length,
                            offlineClients: offlineList
                        });
                    }
                } else {
                    containerNode.children.push({
                        id: 'cluster:' + clusterKey,
                        clusterKey: clusterKey,
                        name: '+' + offlineList.length + ' offline device' + (offlineList.length === 1 ? '' : 's'),
                        type: 'offline_cluster',
                        expanded: false,
                        count: offlineList.length,
                        offlineClients: offlineList
                    });
                }
            }

            delete containerNode._onlineClients;
            delete containerNode._offlineClients;
        }

        for (var idx = 0; idx < rootNode.children.length; idx++) {
            var ifItem = rootNode.children[idx];
            var swKeys = Object.keys(ifItem._switchMap || {});
            for (var sk = 0; sk < swKeys.length; sk++) {
                var swItem = ifItem._switchMap[swKeys[sk]];
                finalizeContainer(swItem, ifItem.identifier + ':sw:' + swItem.mac);
                if (swItem.children.length > 0 || !searchQuery) {
                    ifItem.children.push(swItem);
                }
            }
            delete ifItem._switchMap;
            finalizeContainer(ifItem, ifItem.identifier);
        }

        return rootNode;
    }

    /**
     * Count total visible leaf nodes in a hierarchy to dynamically size horizontal tree height.
     *
     * @param {Object} node
     * @returns {number}
     */
    function countVisibleLeaves(node) {
        if (!node.children || node.children.length === 0) {
            return 1;
        }
        var sum = 0;
        for (var i = 0; i < node.children.length; i++) {
            sum += countVisibleLeaves(node.children[i]);
        }
        return Math.max(1, sum);
    }

    /**
     * Interactive D3 v3 Tidy Tree controller for OPNsense Interfaces -> Neighbors.
     *
     * @param {Object} config
     */
    function TopologyWidget(config) {
        this.config = Object.assign({
            canvasSelector: '#topology-canvas',
            drawerSelector: '#topology-inspector-drawer',
            listEndpoint: '/api/interfaces/topology/list',
            quarantineEndpoint: '/api/interfaces/topology/quarantine',
            labelEndpoint: '/api/interfaces/topology/label',
            assignAliasEndpoint: '/api/interfaces/topology/assign_alias',
            mode: 'horizontal',
            showAllOffline: false,
            searchQuery: '',
            translations: {}
        }, config || {});

        this.rawData = {
            interfaces: [],
            switches: [],
            clients: [],
            dhcp_subnets: { kea: [], dnsmasq: [] },
            mac_aliases: []
        };
        this.expandedClusters = {};
        this.collapsedInterfaceIds = {};
        this.selectedClientMac = null;
        this.svg = null;
        this.visGroup = null;
        this.zoomBehavior = null;
    }

    TopologyWidget.prototype.t = function (key, fallback) {
        if (this.config.translations && this.config.translations[key]) {
            return this.config.translations[key];
        }
        return fallback || key;
    };

    TopologyWidget.prototype.init = function () {
        this.bindControls();
        this.refresh();
    };

    TopologyWidget.prototype.bindControls = function () {
        var self = this;
        if (typeof $ === 'undefined') {
            return;
        }

        $('#topology-mode-horizontal').off('click.topology').on('click.topology', function () {
            self.config.mode = 'horizontal';
            $('#topology-mode-horizontal').addClass('active');
            $('#topology-mode-radial').removeClass('active');
            self.render();
        });

        $('#topology-mode-radial').off('click.topology').on('click.topology', function () {
            self.config.mode = 'radial';
            $('#topology-mode-radial').addClass('active');
            $('#topology-mode-horizontal').removeClass('active');
            self.render();
        });

        $('#topology-toggle-offline').off('change.topology').on('change.topology', function () {
            self.config.showAllOffline = $(this).is(':checked');
            self.render();
        });

        $('#topology-search-input').off('input.topology').on('input.topology', function () {
            self.config.searchQuery = $(this).val() || '';
            self.render();
        });

        $('#topology-refresh-btn').off('click.topology').on('click.topology', function () {
            self.refresh(null, true);
        });

        $('#topology-drawer-close').off('click.topology').on('click.topology', function () {
            self.closeDrawer();
        });

        $('#inspector-save-label-btn').off('click.topology').on('click.topology', function () {
            self.saveClientLabel();
        });

        $('#inspector-label-input').off('keydown.topology').on('keydown.topology', function (e) {
            if (e && (e.which === 13 || e.key === 'Enter')) {
                e.preventDefault();
                self.saveClientLabel();
            }
        });

        $('#inspector-quarantine-btn').off('click.topology').on('click.topology', function () {
            self.toggleClientQuarantine();
        });

        $('#inspector-static-lease-btn').off('click.topology').on('click.topology', function () {
            self.assignStaticLease();
        });

        $('#inspector-assign-alias-btn').off('click.topology').on('click.topology', function () {
            self.assignPolicyAlias();
        });
    };

    TopologyWidget.prototype.refresh = function (callback, flushCache) {
        var self = this;
        if (typeof $.ajax !== 'function') {
            return;
        }
        $('#topology-loading-indicator').show();
        $.ajax({
            url: self.config.listEndpoint,
            method: 'GET',
            dataType: 'json',
            data: flushCache ? { refresh: '1' } : undefined
        }).done(function (response) {
            if (response && typeof response === 'object') {
                self.rawData = response;
                self.render();
                if (self.selectedClientMac) {
                    var updated = self.findClientByMac(self.selectedClientMac);
                    if (updated) {
                        self.populateDrawer(updated);
                    }
                }
            }
        }).always(function () {
            $('#topology-loading-indicator').hide();
            if (typeof callback === 'function') {
                callback();
            }
        });
    };

    TopologyWidget.prototype.findClientByMac = function (mac) {
        if (!mac || !this.rawData || !Array.isArray(this.rawData.clients)) {
            return null;
        }
        var target = mac.toLowerCase();
        for (var i = 0; i < this.rawData.clients.length; i++) {
            if ((this.rawData.clients[i].mac || '').toLowerCase() === target) {
                return this.rawData.clients[i];
            }
        }
        return null;
    };

    TopologyWidget.prototype.render = function () {
        if (typeof d3 === 'undefined') {
            return;
        }
        var container = d3.select(this.config.canvasSelector);
        if (container.empty()) {
            return;
        }

        var hierarchy = buildTopologyHierarchy(this.rawData, {
            showAllOffline: this.config.showAllOffline,
            expandedClusters: this.expandedClusters,
            searchQuery: this.config.searchQuery
        });

        // Respect manually collapsed interface/switch subtrees
        var self = this;
        (function applyManualCollapse(node) {
            if (!node.children) {
                return;
            }
            for (var i = 0; i < node.children.length; i++) {
                applyManualCollapse(node.children[i]);
            }
            if (node.id && self.collapsedInterfaceIds[node.id]) {
                node._children = node.children;
                node.children = null;
            }
        })(hierarchy);

        var containerNode = container.node();
        var width = (containerNode && containerNode.clientWidth) ? containerNode.clientWidth : 980;
        var leafCount = countVisibleLeaves(hierarchy);

        // Update summary counters in toolbar
        this.updateSummaryCounters();

        container.selectAll('svg').remove();

        if (this.config.mode === 'radial') {
            this.renderRadialTree(container, hierarchy, width, leafCount);
        } else {
            this.renderHorizontalTree(container, hierarchy, width, leafCount);
        }
    };

    TopologyWidget.prototype.updateSummaryCounters = function () {
        if (typeof $ === 'undefined') {
            return;
        }
        var clients = Array.isArray(this.rawData.clients) ? this.rawData.clients : [];
        var onlineCount = 0;
        var offlineCount = 0;
        var quarantinedCount = 0;
        for (var i = 0; i < clients.length; i++) {
            if (clients[i].quarantined) {
                quarantinedCount++;
            }
            if (clients[i].online) {
                onlineCount++;
            } else {
                offlineCount++;
            }
        }
        $('#topology-count-online').text(onlineCount);
        $('#topology-count-offline').text(offlineCount);
        $('#topology-count-quarantined').text(quarantinedCount);
    };

    TopologyWidget.prototype.renderHorizontalTree = function (container, hierarchy, width, leafCount) {
        var self = this;
        var height = Math.max(560, leafCount * 38 + 80);
        var margin = { top: 30, right: 240, bottom: 30, left: 120 };
        var innerWidth = Math.max(520, width - margin.left - margin.right);
        var innerHeight = height - margin.top - margin.bottom;
        var svgWidth = Math.max(width, innerWidth + margin.left + margin.right);

        var svg = container.append('svg')
            .attr('width', svgWidth)
            .attr('height', height)
            .attr('class', 'opnsense-topology-svg');

        var vis = svg.append('g')
            .attr('transform', 'translate(' + margin.left + ',' + margin.top + ')');

        if (d3.behavior && d3.behavior.zoom) {
            try {
                var zoom = d3.behavior.zoom()
                    .scaleExtent([0.4, 2.5])
                    .on('zoom', function () {
                        vis.attr(
                            'transform',
                            'translate(' + (d3.event.translate[0] + margin.left) + ',' +
                            (d3.event.translate[1] + margin.top) + ') scale(' + d3.event.scale + ')'
                        );
                    });
                svg.call(zoom);
            } catch (e) {
                // Headless environment without DOM event support
            }
        }

        var tree = d3.layout.tree().size([innerHeight, innerWidth]);
        var diagonal = d3.svg.diagonal().projection(function (d) {
            return [d.y, d.x];
        });

        var nodes = tree.nodes(hierarchy);
        var links = tree.links(nodes);

        nodes.forEach(function (d) {
            if (d.depth === 0) {
                d.y = 0;
            } else if (d.depth === 1) {
                d.y = Math.min(220, innerWidth * 0.28);
            } else if (d.type === 'switch' || d.type === 'ap') {
                d.y = Math.min(430, innerWidth * 0.54);
            } else {
                d.y = Math.min(680, innerWidth * 0.82);
            }
        });

        vis.selectAll('path.topology-link')
            .data(links)
            .enter()
            .append('path')
            .attr('class', function (d) {
                var cls = 'topology-link';
                if (d.target && d.target.quarantined) {
                    cls += ' link-quarantined';
                } else if (d.target && d.target.bps > 1000000) {
                    cls += ' link-active-traffic';
                }
                return cls;
            })
            .attr('d', diagonal);

        var nodeGroup = vis.selectAll('g.topology-node')
            .data(nodes)
            .enter()
            .append('g')
            .attr('class', function (d) {
                return self.getNodeCssClass(d);
            })
            .attr('transform', function (d) {
                return 'translate(' + d.y + ',' + d.x + ')';
            })
            .on('click', function (d) {
                if (d3.event && d3.event.defaultPrevented) {
                    return;
                }
                self.onNodeClick(d);
            });

        this.populateNodeGraphics(nodeGroup, false);
    };

    TopologyWidget.prototype.renderRadialTree = function (container, hierarchy, width, leafCount) {
        var self = this;
        var diameter = Math.max(680, Math.min(width, 960), leafCount * 18);
        var radius = diameter / 2;
        var radialWidth = Math.max(width, diameter);

        var svg = container.append('svg')
            .attr('width', radialWidth)
            .attr('height', diameter)
            .attr('class', 'opnsense-topology-svg');

        var vis = svg.append('g')
            .attr('transform', 'translate(' + (radialWidth / 2) + ',' + (diameter / 2) + ')');

        if (d3.behavior && d3.behavior.zoom) {
            try {
                var zoom = d3.behavior.zoom()
                    .scaleExtent([0.4, 2.5])
                    .on('zoom', function () {
                        vis.attr(
                            'transform',
                            'translate(' + (d3.event.translate[0] + radialWidth / 2) + ',' +
                            (d3.event.translate[1] + diameter / 2) + ') scale(' + d3.event.scale + ')'
                        );
                    });
                svg.call(zoom);
            } catch (e) {
                // Headless environment without DOM event support
            }
        }

        var tree = d3.layout.tree()
            .size([360, radius - 140])
            .separation(function (a, b) {
                return (a.parent === b.parent ? 1 : 2) / (a.depth || 1);
            });

        var diagonal = d3.svg.diagonal.radial()
            .projection(function (d) {
                return [d.y, (d.x || 0) / 180 * Math.PI];
            });

        var nodes = tree.nodes(hierarchy);
        var links = tree.links(nodes);

        vis.selectAll('path.topology-link')
            .data(links)
            .enter()
            .append('path')
            .attr('class', function (d) {
                var cls = 'topology-link';
                if (d.target && d.target.quarantined) {
                    cls += ' link-quarantined';
                } else if (d.target && d.target.bps > 1000000) {
                    cls += ' link-active-traffic';
                }
                return cls;
            })
            .attr('d', diagonal);

        var nodeGroup = vis.selectAll('g.topology-node')
            .data(nodes)
            .enter()
            .append('g')
            .attr('class', function (d) {
                return self.getNodeCssClass(d);
            })
            .attr('transform', function (d) {
                if (d.depth === 0) {
                    return 'translate(0,0)';
                }
                return 'rotate(' + (d.x - 90) + ')translate(' + d.y + ')';
            })
            .on('click', function (d) {
                if (d3.event && d3.event.defaultPrevented) {
                    return;
                }
                self.onNodeClick(d);
            });

        this.populateNodeGraphics(nodeGroup, true);
    };

    TopologyWidget.prototype.getNodeCssClass = function (d) {
        var classes = ['topology-node', 'node-type-' + (d.type || 'client')];
        if (d.type === 'client') {
            if (d.quarantined) {
                classes.push('node-quarantined');
            } else if (d.online) {
                classes.push('node-online');
            } else {
                classes.push('node-offline');
            }
            if (d.client && this.selectedClientMac && d.client.mac === this.selectedClientMac) {
                classes.push('node-selected');
            }
        } else if (d.type === 'offline_cluster') {
            classes.push('node-offline-cluster');
        }
        return classes.join(' ');
    };

    TopologyWidget.prototype.populateNodeGraphics = function (nodeGroup, isRadial) {
        nodeGroup.append('circle')
            .attr('r', function (d) {
                if (d.type === 'gateway') {
                    return 13;
                }
                if (d.type === 'interface') {
                    return 10;
                }
                if (d.type === 'switch' || d.type === 'ap') {
                    return 9;
                }
                if (d.type === 'offline_cluster') {
                    return 8;
                }
                return 7;
            })
            .attr('class', 'topology-node-circle');

        // Primary label
        nodeGroup.append('text')
            .attr('dy', '.31em')
            .attr('x', function (d) {
                if (isRadial && d.depth > 0) {
                    return d.x < 180 ? 14 : -14;
                }
                return (d.children || d._children) ? -14 : 14;
            })
            .attr('text-anchor', function (d) {
                if (isRadial && d.depth > 0) {
                    return d.x < 180 ? 'start' : 'end';
                }
                return (d.children || d._children) ? 'end' : 'start';
            })
            .attr('transform', function (d) {
                if (isRadial && d.depth > 0) {
                    return d.x < 180 ? null : 'rotate(180)';
                }
                return null;
            })
            .attr('class', 'topology-node-label')
            .text(function (d) {
                if (d.type === 'client' && d.client) {
                    var suffix = '';
                    if (d.quarantined) {
                        suffix = ' [BLOCKED]';
                    } else if (d.bps > 0) {
                        suffix = ' (' + formatBandwidth(d.bps) + ')';
                    }
                    return d.name + suffix;
                }
                return d.name;
            });

        // Secondary subtitle (IP, port/MAC, or VLAN device) for horizontal layout
        if (!isRadial) {
            nodeGroup.filter(function (d) {
                return d.type === 'client' || d.type === 'interface' || d.type === 'switch' || d.type === 'ap';
            }).append('text')
                .attr('dy', '1.45em')
                .attr('x', function (d) {
                    return (d.children || d._children) ? -14 : 14;
                })
                .attr('text-anchor', function (d) {
                    return (d.children || d._children) ? 'end' : 'start';
                })
                .attr('class', 'topology-node-sublabel')
                .text(function (d) {
                    if (d.type === 'interface') {
                        var ip4 = firstIp(d.ipv4) || d.device;
                        return ip4 || '';
                    }
                    if (d.type === 'switch' || d.type === 'ap') {
                        return d.port || d.mac || '';
                    }
                    if (d.type === 'client' && d.client) {
                        var allAddrs = (Array.isArray(d.client.addresses) && d.client.addresses.length > 0)
                            ? d.client.addresses
                            : ipList(d.client.ipv4).concat(ipList(d.client.ipv6));
                        var addrCount = allAddrs.length;
                        var primaryIp = firstIp(d.client.ipv4) || firstIp(d.client.ipv6) || allAddrs[0] || d.client.mac;
                        if (addrCount > 1) {
                            return primaryIp + ' (+' + (addrCount - 1) + ' IP)';
                        }
                        return primaryIp;
                    }
                    return '';
                });
        }
    };

    TopologyWidget.prototype.onNodeClick = function (d) {
        if (!d) {
            return;
        }
        if (d.type === 'offline_cluster' && d.clusterKey) {
            this.expandedClusters[d.clusterKey] = !this.expandedClusters[d.clusterKey];
            this.render();
            return;
        }
        if (d.type === 'interface' || d.type === 'switch' || d.type === 'ap') {
            this.collapsedInterfaceIds[d.id] = !this.collapsedInterfaceIds[d.id];
            this.render();
            return;
        }
        if (d.type === 'client' && d.client) {
            this.selectedClientMac = d.client.mac;
            this.openDrawer(d.client);
            this.render();
        }
    };

    TopologyWidget.prototype.openDrawer = function (client) {
        if (typeof $ === 'undefined' || !client) {
            return;
        }
        this.populateDrawer(client);
        $(this.config.drawerSelector).addClass('open');
    };

    TopologyWidget.prototype.closeDrawer = function () {
        this.selectedClientMac = null;
        if (typeof $ !== 'undefined') {
            $(this.config.drawerSelector).removeClass('open');
        }
        this.render();
    };

    TopologyWidget.prototype.populateDrawer = function (client) {
        if (typeof $ === 'undefined' || !client) {
            return;
        }
        var primaryIp = firstIp(client.ipv4) || firstIp(client.ipv6) ||
            ((Array.isArray(client.addresses) && client.addresses.length > 0) ? client.addresses[0] : '');
        var title = client.descr || client.label || client.hostname || primaryIp || client.mac;
        $('#inspector-client-title').text(title);
        $('#inspector-client-mac').text(client.mac || '');
        $('#inspector-client-vendor').text(client.vendor || this.t('unknownVendor', 'Unknown Vendor'));
        $('#inspector-client-interface').text(client.interface_descr || client.interface || client.device || '');
        $('#inspector-client-bandwidth').text(formatBandwidth(client.bps || 0));
        $('#inspector-label-input').val(client.descr || client.hostname || '');

        var statusBadge = $('#inspector-client-status');
        statusBadge.removeClass('label-success label-default label-danger');
        if (client.quarantined) {
            statusBadge.addClass('label-danger').text(this.t('quarantined', 'Quarantined'));
        } else if (client.online) {
            statusBadge.addClass('label-success').text(this.t('online', 'Online'));
        } else {
            statusBadge.addClass('label-default').text(this.t('offline', 'Offline'));
        }

        // Populate collapsed IPv4 / IPv6 addresses list
        var addrContainer = $('#inspector-address-list').empty();
        var addresses = (Array.isArray(client.addresses) && client.addresses.length > 0)
            ? client.addresses
            : ipList(client.ipv4).concat(ipList(client.ipv6));
        if (addresses.length === 0) {
            addrContainer.append($('<li class="list-group-item text-muted">').text(this.t('noAddresses', 'No IP addresses observed')));
        } else {
            for (var i = 0; i < addresses.length; i++) {
                var ip = addresses[i];
                var fam = ip.indexOf(':') !== -1 ? 'IPv6' : 'IPv4';
                var li = $('<li class="list-group-item topology-ip-item">');
                li.append($('<span class="label label-info" style="margin-right:8px;">').text(fam));
                li.append($('<code>').text(ip));
                addrContainer.append(li);
            }
        }

        // Static DHCP Lease status and button
        var leaseInfo = $('#inspector-lease-status');
        var staticBtn = $('#inspector-static-lease-btn');
        if (client.is_static) {
            leaseInfo.text(this.t('staticLeaseActive', 'Static Reservation') +
                (client.dhcp_service ? ' (' + client.dhcp_service.toUpperCase() + ')' : ''));
            staticBtn.prop('disabled', true).addClass('disabled');
        } else {
            var leaseReq = buildStaticLeaseRequest(client, this.rawData);
            leaseInfo.text(this.t('dynamicLease', 'Dynamic / Discovered'));
            if (leaseReq) {
                staticBtn.prop('disabled', false).removeClass('disabled')
                    .attr('data-service', leaseReq.service);
            } else {
                staticBtn.prop('disabled', true).addClass('disabled');
            }
        }

        // Firewall Policy Aliases selector
        var aliasSelect = $('#inspector-alias-select').empty();
        var aliases = Array.isArray(this.rawData.mac_aliases) ? this.rawData.mac_aliases : [];
        var clientAliases = Array.isArray(client.aliases) ? client.aliases : [];
        if (aliases.length === 0) {
            aliasSelect.append($('<option value="">').text(this.t('noMacAliases', 'No MAC/Host Aliases Configured')));
        } else {
            for (var a = 0; a < aliases.length; a++) {
                var al = aliases[a];
                var isMember = clientAliases.indexOf(al.name) !== -1;
                var optLabel = al.name + ' (' + al.type + ')' + (isMember ? ' ✓' : '');
                aliasSelect.append($('<option>').val(al.name).text(optLabel));
            }
        }

        // Quarantine toggle button state
        var qBtn = $('#inspector-quarantine-btn');
        qBtn.removeClass('btn-danger btn-success');
        if (client.quarantined) {
            qBtn.addClass('btn-success').html(
                '<i class="fa fa-play fa-fw"></i> ' + this.t('unquarantineDevice', 'Resume Internet Access')
            );
        } else {
            qBtn.addClass('btn-danger').html(
                '<i class="fa fa-ban fa-fw"></i> ' + this.t('quarantineDevice', 'Pause Internet Access (Quarantine)')
            );
        }
    };

    TopologyWidget.prototype.showFeedback = function (message, isError) {
        if (typeof $ === 'undefined') {
            return;
        }
        var box = $('#inspector-feedback-msg');
        box.removeClass('alert-success alert-danger')
            .addClass(isError ? 'alert-danger' : 'alert-success')
            .text(message)
            .show();
        setTimeout(function () {
            box.fadeOut();
        }, 3500);
    };

    TopologyWidget.prototype.saveClientLabel = function () {
        var self = this;
        var client = this.findClientByMac(this.selectedClientMac);
        if (!client || typeof $.ajax !== 'function') {
            return;
        }
        var newLabel = ($('#inspector-label-input').val() || '').trim();
        $.ajax({
            url: self.config.labelEndpoint,
            method: 'POST',
            dataType: 'json',
            data: {
                mac: client.mac,
                descr: newLabel
            }
        }).done(function (res) {
            if (res && (res.result === 'saved' || res.status === 'ok')) {
                self.showFeedback(self.t('savedLabel', 'Device label saved.'), false);
                self.refresh(null, true);
            } else {
                self.showFeedback((res && res.message) || self.t('errorSaving', 'Failed to save label.'), true);
            }
        }).fail(function () {
            self.showFeedback(self.t('errorSaving', 'Failed to save label.'), true);
        });
    };

    TopologyWidget.prototype.toggleClientQuarantine = function () {
        var self = this;
        var client = this.findClientByMac(this.selectedClientMac);
        if (!client || typeof $.ajax !== 'function') {
            return;
        }
        var targetQuarantine = !client.quarantined;
        var allAddrs = (Array.isArray(client.addresses) && client.addresses.length > 0)
            ? client.addresses
            : ipList(client.ipv4).concat(ipList(client.ipv6));
        $.ajax({
            url: self.config.quarantineEndpoint,
            method: 'POST',
            dataType: 'json',
            data: {
                mac: client.mac,
                quarantined: targetQuarantine ? '1' : '0',
                action: targetQuarantine ? 'block' : 'unblock',
                descr: client.descr || '',
                addresses: allAddrs.join(',')
            }
        }).done(function (res) {
            if (res && (res.result === 'saved' || res.status === 'ok')) {
                var msg = targetQuarantine
                    ? self.t('quarantinedMsg', 'Device quarantined and active states killed.')
                    : self.t('unquarantinedMsg', 'Device removed from quarantine.');
                self.showFeedback(msg, false);
                self.refresh(null, true);
            } else {
                self.showFeedback((res && res.message) || self.t('errorQuarantine', 'Failed to update quarantine.'), true);
            }
        }).fail(function () {
            self.showFeedback(self.t('errorQuarantine', 'Failed to update quarantine.'), true);
        });
    };

    TopologyWidget.prototype.assignStaticLease = function () {
        var self = this;
        var client = this.findClientByMac(this.selectedClientMac);
        if (!client || typeof $.ajax !== 'function') {
            return;
        }
        var req = buildStaticLeaseRequest(client, this.rawData);
        if (!req) {
            self.showFeedback(self.t('noIpv4ForLease', 'No IPv4 address or DHCP subnet available for static lease.'), true);
            return;
        }

        $.ajax({
            url: req.endpoint,
            method: 'POST',
            dataType: 'json',
            contentType: 'application/json',
            data: JSON.stringify(req.payload)
        }).done(function (res) {
            if (res && res.result === 'saved') {
                if (req.reconfigureEndpoint) {
                    $.post(req.reconfigureEndpoint);
                }
                self.showFeedback(
                    self.t('staticLeaseSaved', 'Static DHCP reservation created via ') + req.service.toUpperCase() + '.',
                    false
                );
                self.refresh(null, true);
            } else {
                self.showFeedback(self.t('staticLeaseFailed', 'Could not create static reservation.'), true);
            }
        }).fail(function () {
            self.showFeedback(self.t('staticLeaseFailed', 'Could not create static reservation.'), true);
        });
    };

    TopologyWidget.prototype.assignPolicyAlias = function () {
        var self = this;
        var client = this.findClientByMac(this.selectedClientMac);
        if (!client || typeof $.ajax !== 'function') {
            return;
        }
        var aliasName = ($('#inspector-alias-select').val() || '').trim();
        if (!aliasName) {
            self.showFeedback(self.t('selectAliasPrompt', 'Please select a firewall alias first.'), true);
            return;
        }
        var clientIp = firstIp(client.ipv4) || firstIp(client.ipv6) ||
            ((Array.isArray(client.addresses) && client.addresses.length > 0) ? client.addresses[0] : '');
        $.ajax({
            url: self.config.assignAliasEndpoint,
            method: 'POST',
            dataType: 'json',
            data: {
                mac: client.mac,
                alias: aliasName,
                ip: clientIp,
                action: 'toggle'
            }
        }).done(function (res) {
            if (res && (res.result === 'saved' || res.status === 'ok')) {
                var actionWord = res.assigned
                    ? self.t('aliasAdded', 'Added to alias: ')
                    : self.t('aliasRemoved', 'Removed from alias: ');
                self.showFeedback(actionWord + res.alias, false);
                self.refresh(null, true);
            } else {
                self.showFeedback((res && res.message) || self.t('aliasError', 'Failed to update alias.'), true);
            }
        }).fail(function () {
            self.showFeedback(self.t('aliasError', 'Failed to update alias.'), true);
        });
    };

    return {
        TopologyWidget: TopologyWidget,
        buildTopologyHierarchy: buildTopologyHierarchy,
        buildStaticLeaseRequest: buildStaticLeaseRequest,
        formatBandwidth: formatBandwidth,
        sanitizeDnsHostname: sanitizeDnsHostname,
        countVisibleLeaves: countVisibleLeaves,
        ipv4InCidr: ipv4InCidr,
        firstIp: firstIp,
        ipList: ipList
    };
}));
