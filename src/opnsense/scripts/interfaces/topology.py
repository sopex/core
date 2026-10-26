#!/usr/local/bin/python3

"""
    Copyright (c) 2026 Konstantinos Spartalis
    All rights reserved.

    Redistribution and use in source and binary forms, with or without
    modification, are permitted provided that the following conditions are met:

    1. Redistributions of source code must retain the above copyright notice,
     this list of conditions and the following disclaimer.

    2. Redistributions in binary form must reproduce the above copyright
     notice, this list of conditions and the following disclaimer in the
     documentation and/or other materials provided with the distribution.

    THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
    INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
    AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
    AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
    OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
    SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
    INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
    CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
    ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
    POSSIBILITY OF SUCH DAMAGE.

    --------------------------------------------------------------------------------------
    Aggregate L2/L3 topology and per-client state from native OPNsense sources.
"""

import ipaddress
import json
import os
import re
import sqlite3
import subprocess
import sys
import time
import xml.etree.ElementTree as ET

HOSTWATCH_DB = '/var/db/hostwatch/hosts.db'
NETFLOW_DB = '/var/netflow/src_addr_000300.sqlite'
KEA_LEASES4 = '/var/db/kea/kea-leases4.csv'
DNSMASQ_LEASES = '/var/db/dnsmasq.leases'
CONFIG_XML = '/conf/config.xml'
LLDPCTL_BIN = '/usr/local/sbin/lldpctl'
PFCTL_BIN = '/sbin/pfctl'
IFCONFIG_BIN = '/sbin/ifconfig'
QUARANTINE_TABLE = '__client_quarantine'

_MAC_RE = re.compile(r'^[0-9a-f]{2}(:[0-9a-f]{2}){5}$')


def normalize_mac(raw_mac):
    """Normalize a MAC address to lowercase colon-separated 6-octet format."""
    if not raw_mac or not isinstance(raw_mac, str):
        return ''
    cleaned = raw_mac.strip().lower().replace('-', ':')
    parts = cleaned.split(':')
    if len(parts) == 6:
        try:
            normalized = ':'.join(f'{int(p, 16):02x}' for p in parts)
            if _MAC_RE.match(normalized):
                return normalized
        except ValueError:
            return ''
    return cleaned if _MAC_RE.match(cleaned) else ''


def _lookup_oui_vendor(mac):
    """Look up hardware vendor from OPNsense OUI database if available."""
    try:
        from lib import OUI
        return OUI().get_vendor(mac, '')
    except Exception:
        return ''


def load_config_metadata(config_path=None):
    """Map interface devices (igb0, vlan01) to friendly names (LAN, IoT) and user MAC labels."""
    cfg_file = config_path or CONFIG_XML
    interfaces = {}
    client_meta = {}
    dhcp_subnets = {'kea': [], 'dnsmasq': []}
    if not os.path.exists(cfg_file):
        return interfaces, client_meta, dhcp_subnets

    try:
        tree = ET.parse(cfg_file)
        root = tree.getroot()
    except Exception:
        return interfaces, client_meta, dhcp_subnets

    # Inspect Kea DHCPv4 and Dnsmasq config to identify active DHCP service per interface/subnet
    kea_enabled = (
        root.findtext('./OPNsense/Kea/dhcp4/general/enabled', '0') == '1'
        or root.findtext('./OPNsense/Kea/dhcpv4/general/enabled', '0') == '1'
    )
    kea_if_raw = (
        root.findtext('./OPNsense/Kea/dhcp4/general/interfaces', '')
        or root.findtext('./OPNsense/Kea/dhcpv4/general/interfaces', '')
        or ''
    )
    kea_interfaces = set(x.strip() for x in kea_if_raw.split(',') if x.strip())
    kea_subnets = []
    for spath in ('./OPNsense/Kea/dhcp4/subnets/subnet4', './OPNsense/Kea/dhcpv4/subnets/subnet4'):
        for snode in root.findall(spath):
            cidr = (snode.findtext('subnet') or '').strip()
            suuid = snode.attrib.get('uuid', '')
            sif = (snode.findtext('interface') or '').strip()
            if cidr:
                dhcp_subnets['kea'].append({
                    'uuid': suuid,
                    'subnet': cidr,
                    'interface': sif
                })
                try:
                    kea_subnets.append((ipaddress.ip_network(cidr, strict=False), suuid, sif))
                except ValueError:
                    pass

    dnsmasq_enabled = (
        root.findtext('./dnsmasq/enable', '0') == '1'
        or root.findtext('./OPNsense/Dnsmasq/enable', '0') == '1'
    )
    dnsmasq_ifaces = set()
    dnsmasq_any_iface = False
    for rpath in ('./dnsmasq/dhcp_ranges', './OPNsense/Dnsmasq/dhcp_ranges'):
        for rnode in root.findall(rpath):
            rif = (rnode.findtext('interface') or '').strip()
            start_addr = (rnode.findtext('start_addr') or '').strip()
            end_addr = (rnode.findtext('end_addr') or '').strip()
            dhcp_subnets['dnsmasq'].append({
                'interface': rif,
                'start_addr': start_addr,
                'end_addr': end_addr
            })
            if rif:
                for part in rif.split(','):
                    if part.strip():
                        dnsmasq_ifaces.add(part.strip())
            else:
                dnsmasq_any_iface = True

    for iface in root.findall('./interfaces/*'):
        if iface.findtext('enable') == '0':
            continue
        dev = (iface.findtext('if') or '').strip()
        descr = iface.findtext('descr') or iface.tag.upper()
        if dev:
            ipaddr = (iface.findtext('ipaddr') or '').strip()
            subnet = (iface.findtext('subnet') or '').strip()
            matched_subnet_uuid = ''
            for net_obj, suuid, sif in kea_subnets:
                if sif and sif == iface.tag:
                    matched_subnet_uuid = suuid
                    break
            if not matched_subnet_uuid and ipaddr:
                try:
                    if_ip = ipaddress.ip_address(ipaddr)
                    for net_obj, suuid, _sif in kea_subnets:
                        if if_ip in net_obj:
                            matched_subnet_uuid = suuid
                            break
                except ValueError:
                    pass

            dhcp_service = ''
            if (kea_enabled or matched_subnet_uuid) and (iface.tag in kea_interfaces or matched_subnet_uuid):
                dhcp_service = 'kea'
            elif (dnsmasq_enabled or dnsmasq_ifaces) and (iface.tag in dnsmasq_ifaces or dnsmasq_any_iface):
                dhcp_service = 'dnsmasq'

            interfaces[dev] = {
                'id': iface.tag,
                'identifier': iface.tag,
                'device': dev,
                'name': descr,
                'descr': descr,
                'ipaddr': ipaddr,
                'ipv4': [ipaddr] if ipaddr and ipaddr != 'dhcp' else [],
                'ipv6': [],
                'subnet': subnet,
                'dhcp_service': dhcp_service,
                'kea_subnet_uuid': matched_subnet_uuid
            }

    # Custom user labels/policies from OPNsense->Interfaces->neighbors (both <neighbor> and <client>)
    for xpath in ('./OPNsense/Interfaces/neighbors/neighbor', './OPNsense/Interfaces/neighbors/client'):
        for node in root.findall(xpath):
            raw_mac = node.findtext('etheraddr') or node.findtext('ether_address') or ''
            mac = normalize_mac(raw_mac)
            if mac:
                existing = client_meta.get(mac, {})
                descr = node.findtext('descr', '') or existing.get('descr', '')
                quarantined = (node.findtext('quarantined', '0') == '1') or existing.get('quarantined', False)
                client_meta[mac] = {
                    'uuid': node.attrib.get('uuid', '') or existing.get('uuid', ''),
                    'descr': descr,
                    'quarantined': quarantined
                }

    return interfaces, client_meta, dhcp_subnets


def load_policy_and_static_metadata(config_path=None):
    """Load configured MAC/host firewall aliases and static DHCP reservations from config.xml."""
    cfg_file = config_path or CONFIG_XML
    available_aliases = []
    mac_to_aliases = {}
    ip_to_aliases = {}
    static_macs = {}

    if not os.path.exists(cfg_file):
        return available_aliases, mac_to_aliases, ip_to_aliases, static_macs

    try:
        tree = ET.parse(cfg_file)
        root = tree.getroot()
    except Exception:
        return available_aliases, mac_to_aliases, ip_to_aliases, static_macs

    for alias in root.findall('./OPNsense/Firewall/Alias/aliases/alias'):
        if alias.findtext('enabled', '1') == '0':
            continue
        atype = (alias.findtext('type') or '').strip()
        aname = (alias.findtext('name') or '').strip()
        if not aname or atype not in ('mac', 'host'):
            continue
        adescr = (alias.findtext('description') or '').strip()
        auuid = alias.attrib.get('uuid', '')
        available_aliases.append({
            'uuid': auuid,
            'name': aname,
            'type': atype,
            'description': adescr
        })
        content = alias.findtext('content') or ''
        for token in re.split(r'[\s,]+', content.strip()):
            if not token:
                continue
            if atype == 'mac':
                norm = normalize_mac(token)
                if norm:
                    mac_to_aliases.setdefault(norm, [])
                    if aname not in mac_to_aliases[norm]:
                        mac_to_aliases[norm].append(aname)
            elif atype == 'host':
                ip_to_aliases.setdefault(token.lower(), [])
                if aname not in ip_to_aliases[token.lower()]:
                    ip_to_aliases[token.lower()].append(aname)

    # Static reservations in Kea DHCPv4
    for rpath in (
        './OPNsense/Kea/dhcp4/reservations/reservation',
        './OPNsense/Kea/dhcpv4/reservations/reservation'
    ):
        for res in root.findall(rpath):
            mac = normalize_mac(res.findtext('hw_address') or '')
            if mac:
                static_macs[mac] = {
                    'uuid': res.attrib.get('uuid', ''),
                    'service': 'kea',
                    'ip': (res.findtext('ip_address') or '').strip(),
                    'hostname': (res.findtext('hostname') or '').strip(),
                    'descr': (res.findtext('description') or '').strip()
                }

    # Static host overrides with MAC in Dnsmasq
    for hpath in ('./dnsmasq/hosts', './OPNsense/Dnsmasq/hosts'):
        for host in root.findall(hpath):
            hwaddr_text = host.findtext('hwaddr') or ''
            for raw_mac in hwaddr_text.split(','):
                mac = normalize_mac(raw_mac)
                if mac and mac not in static_macs:
                    static_macs[mac] = {
                        'uuid': host.attrib.get('uuid', ''),
                        'service': 'dnsmasq',
                        'ip': (host.findtext('ip') or '').split(',')[0].strip(),
                        'hostname': (host.findtext('host') or '').strip(),
                        'descr': (host.findtext('descr') or '').strip()
                    }

    return available_aliases, mac_to_aliases, ip_to_aliases, static_macs


def load_dhcp_hostnames(dnsmasq_leases=None, kea_leases=None):
    """Priority chain: Dnsmasq leases -> Kea CSV leases."""
    dnsmasq_file = dnsmasq_leases or DNSMASQ_LEASES
    kea_file = kea_leases or KEA_LEASES4
    hostnames = {}
    if os.path.exists(dnsmasq_file):
        try:
            with open(dnsmasq_file, 'r', errors='ignore') as f:
                for line in f:
                    parts = line.strip().split()
                    if len(parts) >= 4 and parts[3] != '*':
                        mac = normalize_mac(parts[1])
                        if mac:
                            hostnames[mac] = parts[3]
        except Exception:
            pass

    if os.path.exists(kea_file):
        try:
            with open(kea_file, 'r', errors='ignore') as f:
                for line in f:
                    parts = line.strip().split(',')
                    # Kea CSV: address,hwaddr,client_id,valid_lifetime,expire,subnet_id,fqdn_fwd,fqdn_rev,hostname,state
                    if len(parts) >= 9 and parts[1] and parts[8]:
                        if parts[0] == 'address' and parts[1] == 'hwaddr':
                            continue
                        mac = normalize_mac(parts[1])
                        if mac:
                            hostnames[mac] = parts[8].rstrip('.')
        except Exception:
            pass
    return hostnames


def load_netflow_rates(window_sec=300, netflow_db=None, now=None):
    """Fetch recent bytes per IP from Insight SQLite DB if NetFlow is enabled."""
    nf_file = netflow_db or NETFLOW_DB
    rates = {}
    if not os.path.exists(nf_file) or window_sec <= 0:
        return rates
    ref_now = int(now if now is not None else time.time())
    cutoff = ref_now - window_sec
    try:
        conn = sqlite3.connect(f'file:{nf_file}?mode=ro', uri=True)
        cur = conn.cursor()
        cur.execute("SELECT name FROM sqlite_master WHERE type IN ('table', 'view')")
        tables = {row[0] for row in cur.fetchall()}

        target_table = None
        if 'src_addr_000300' in tables:
            target_table = 'src_addr_000300'
        elif 'timeserie' in tables:
            target_table = 'timeserie'

        if target_table:
            cur.execute(f"PRAGMA table_info({target_table})")
            cols = {row[1] for row in cur.fetchall()}
            addr_col = 'src_addr' if 'src_addr' in cols else ('addr' if 'addr' in cols else None)
            byte_col = 'octets' if 'octets' in cols else ('bytes' if 'bytes' in cols else None)
            time_col = 'last_seen' if 'last_seen' in cols else ('end_time' if 'end_time' in cols else None)

            if addr_col and byte_col and time_col:
                cur.execute(
                    f"SELECT {addr_col}, SUM({byte_col}) FROM {target_table} "
                    f"WHERE (CASE WHEN typeof({time_col}) IN ('integer', 'real') "
                    f"THEN {time_col} >= ? "
                    f"ELSE CAST(strftime('%s', {time_col}) AS INTEGER) >= ? END) "
                    f"GROUP BY {addr_col}",
                    (cutoff, cutoff)
                )
                for addr, total_bytes in cur.fetchall():
                    if addr and total_bytes:
                        rates[addr] = round((float(total_bytes) * 8) / window_sec)
        conn.close()
    except Exception:
        pass
    return rates


def load_pf_quarantine_ips():
    """Return set of IP addresses currently present in pf <__client_quarantine> table."""
    ips = set()
    if not os.path.exists(PFCTL_BIN):
        return ips
    try:
        sp = subprocess.run(
            [PFCTL_BIN, '-t', QUARANTINE_TABLE, '-T', 'show'],
            capture_output=True,
            text=True,
            timeout=3
        )
        if sp.returncode == 0 and sp.stdout:
            for line in sp.stdout.splitlines():
                candidate = line.strip()
                if candidate:
                    ips.add(candidate.lower())
    except Exception:
        pass
    return ips


def _is_lldp_cap_enabled(cap):
    """Return True if an LLDP capability dict has enabled set to a truthy status (not 'off'/'false'/'0')."""
    if not isinstance(cap, dict):
        return False
    val = cap.get('enabled', True)
    if isinstance(val, str):
        return val.strip().lower() not in ('off', 'false', '0', 'no', '')
    return bool(val)


def load_l2_enrichment(interfaces, lldp_json=None, bridge_outputs=None):
    """Discover intermediate L2 switches/APs via LLDP and bridge forwarding tables."""
    switches = []
    switch_by_if = {}
    bridge_mac_port = {}
    port_to_bridge = {}

    for ifname in interfaces:
        if not ifname.startswith('bridge'):
            continue
        bridge_text = None
        if isinstance(bridge_outputs, dict) and ifname in bridge_outputs:
            bridge_text = bridge_outputs[ifname]
        elif bridge_outputs is None and os.path.exists(IFCONFIG_BIN):
            try:
                sp = subprocess.run(
                    [IFCONFIG_BIN, ifname, 'addr'],
                    capture_output=True,
                    text=True,
                    timeout=3
                )
                if sp.returncode == 0 and sp.stdout:
                    bridge_text = sp.stdout
            except Exception:
                bridge_text = None
        if bridge_text:
            for line in bridge_text.splitlines():
                parts = line.strip().split()
                if len(parts) >= 2:
                    mac = normalize_mac(parts[0])
                    if mac:
                        # FreeBSD ifconfig bridgeX addr format: "<mac> Vlan1 <port> <age> flags=..." or "<mac> <port> ..."
                        port_dev = parts[2] if (len(parts) >= 3 and parts[1].lower().startswith('vlan')) else parts[1]
                        bridge_mac_port[mac] = port_dev
                        if port_dev and port_dev not in interfaces:
                            port_to_bridge[port_dev] = ifname

    raw_lldp_text = lldp_json
    if raw_lldp_text is None and os.path.exists(LLDPCTL_BIN):
        try:
            sp = subprocess.run(
                [LLDPCTL_BIN, '-f', 'json'],
                capture_output=True,
                text=True,
                timeout=3
            )
            if sp.returncode == 0 and sp.stdout.strip():
                raw_lldp_text = sp.stdout
        except Exception:
            raw_lldp_text = None

    if raw_lldp_text:
        try:
            lldp_data = json.loads(raw_lldp_text) if isinstance(raw_lldp_text, str) else raw_lldp_text
            raw_ifaces = lldp_data.get('lldp', {}).get('interface', [])
            if isinstance(raw_ifaces, dict):
                raw_ifaces = [raw_ifaces]
            for entry in raw_ifaces:
                if not isinstance(entry, dict):
                    continue
                for raw_ifname, details in entry.items():
                    if raw_ifname in interfaces:
                        ifname = raw_ifname
                        inferred_bridge_port = ''
                    elif raw_ifname in port_to_bridge:
                        ifname = port_to_bridge[raw_ifname]
                        inferred_bridge_port = raw_ifname
                    else:
                        continue
                    detail_list = [details] if isinstance(details, dict) else (
                        [d for d in details if isinstance(d, dict)] if isinstance(details, list) else []
                    )
                    for detail_item in detail_list:
                        chassis = detail_item.get('chassis', {})
                        port = detail_item.get('port', {})
                        sw_name = ''
                        sw_info = {}
                        if isinstance(chassis, dict):
                            if 'id' in chassis and isinstance(chassis['id'], dict):
                                sw_info = chassis
                            else:
                                for k, v in chassis.items():
                                    sw_name = k
                                    if isinstance(v, dict):
                                        sw_info = v
                                    break
                        sw_mac = normalize_mac(
                            sw_info.get('id', {}).get('value', '')
                            if isinstance(sw_info.get('id'), dict) else ''
                        )
                        caps = sw_info.get('capability', [])
                        if isinstance(caps, dict):
                            caps = [caps]
                        cap_types = [
                            c.get('type', '') for c in caps
                            if _is_lldp_cap_enabled(c)
                        ]
                        node_type = 'ap' if 'Wlan' in cap_types else 'switch'
                        port_id = ''
                        if isinstance(port, dict) and isinstance(port.get('id'), dict):
                            port_id = port['id'].get('value', '')
                        sw_bridge_port = inferred_bridge_port or bridge_mac_port.get(sw_mac, '')
                        sw_node = {
                            'interface': ifname,
                            'device': ifname,
                            'name': sw_name or sw_info.get('name', '') or sw_mac or f'Switch ({ifname})',
                            'mac': sw_mac,
                            'descr': sw_info.get('descr', ''),
                            'port': port_id or sw_bridge_port,
                            'bridge_port': sw_bridge_port,
                            'type': node_type,
                            'capabilities': cap_types
                        }
                        switches.append(sw_node)
                        if ifname not in switch_by_if:
                            switch_by_if[ifname] = sw_node
        except Exception:
            pass

    return switches, switch_by_if, bridge_mac_port


def _collect_host_rows(hostwatch_db=None, now=None):
    """Yield (ifname, mac, ip, last_ts, vendor) from hostwatch DB or fallback ARP/NDP."""
    hw_file = hostwatch_db or HOSTWATCH_DB
    ref_now = int(now if now is not None else time.time())
    if os.path.exists(hw_file):
        try:
            conn = sqlite3.connect(f'file:{hw_file}?mode=ro', uri=True)
            cur = conn.cursor()
            cur.execute("SELECT name FROM sqlite_master WHERE type IN ('table', 'view')")
            objects = {row[0] for row in cur.fetchall()}
            ts_expr = (
                "CASE WHEN typeof(last_seen) IN ('integer', 'real') "
                "THEN CAST(last_seen AS INTEGER) "
                "ELSE CAST(strftime('%s', last_seen) AS INTEGER) END"
            )
            if 'v_hosts' in objects:
                cur.execute(
                    f"SELECT interface_name, ether_address, ip_address, "
                    f"{ts_expr}, organization_name FROM v_hosts ORDER BY last_seen DESC"
                )
                rows = cur.fetchall()
            else:
                cur.execute(
                    f"SELECT interface_name, ether_address, ip_address, "
                    f"{ts_expr}, '' FROM hosts ORDER BY last_seen DESC"
                )
                rows = cur.fetchall()
            conn.close()
            for ifname, mac, ip, last_seen, vendor in rows:
                yield ifname, mac, ip, int(last_seen if last_seen is not None else 0), (vendor or '')
            return
        except Exception:
            pass

    # Fallback to system ARP and NDP tables when hostwatch.db is unavailable
    if hostwatch_db is None and os.path.exists('/usr/sbin/arp'):
        try:
            sp = subprocess.run(
                ['/usr/sbin/arp', '-an', '--libxo', 'json'],
                capture_output=True,
                text=True,
                timeout=5
            )
            if sp.returncode == 0 and sp.stdout:
                arp_data = json.loads(sp.stdout)
                arp_cache = arp_data.get('arp', {}).get('arp-cache', [])
                for row in arp_cache:
                    mac = row.get('mac-address')
                    ifname = row.get('interface')
                    ip = row.get('ip-address')
                    if mac and ifname and ip:
                        yield ifname, mac, ip, ref_now, _lookup_oui_vendor(mac)
        except Exception:
            pass

    if hostwatch_db is None and os.path.exists('/usr/sbin/ndp'):
        try:
            sp = subprocess.run(
                ['/usr/sbin/ndp', '-an'],
                capture_output=True,
                text=True,
                timeout=5
            )
            if sp.returncode == 0 and sp.stdout:
                for line in sp.stdout.splitlines()[1:]:
                    parts = line.split()
                    if len(parts) > 3 and parts[1] != '(incomplete)':
                        ip = parts[0].split('%', 1)[0]
                        mac = parts[1]
                        ifname = parts[2]
                        yield ifname, mac, ip, ref_now, _lookup_oui_vendor(mac)
        except Exception:
            pass


def build_topology(
    config_path=None,
    hostwatch_db=None,
    netflow_db=None,
    dnsmasq_leases=None,
    kea_leases=None,
    lldp_json=None,
    bridge_outputs=None,
    now=None
):
    ref_now = int(now if now is not None else time.time())
    interfaces, client_meta, dhcp_subnets = load_config_metadata(config_path=config_path)
    hostnames = load_dhcp_hostnames(dnsmasq_leases=dnsmasq_leases, kea_leases=kea_leases)
    netflow_bps = load_netflow_rates(netflow_db=netflow_db, now=ref_now)
    available_aliases, mac_to_aliases, ip_to_aliases, static_macs = load_policy_and_static_metadata(
        config_path=config_path
    )
    pf_quarantine_ips = load_pf_quarantine_ips() if config_path is None else set()
    switches, switch_by_if, bridge_mac_port = (
        load_l2_enrichment(interfaces, lldp_json=lldp_json, bridge_outputs=bridge_outputs)
        if (config_path is None or lldp_json is not None or bridge_outputs is not None)
        else ([], {}, {})
    )

    switches_by_if = {}
    switch_macs_by_if = {}
    for sw in switches:
        sw_if = sw.get('interface', '')
        sw_mac = sw.get('mac', '')
        if sw_if:
            switches_by_if.setdefault(sw_if, []).append(sw)
            if sw_mac:
                switch_macs_by_if.setdefault(sw_if, set()).add(sw_mac)

    parsed_kea_Subnets = []
    for ksub in dhcp_subnets.get('kea', []):
        try:
            parsed_kea_Subnets.append((
                ipaddress.ip_network(ksub['subnet'], strict=False),
                ksub.get('uuid', ''),
                ksub.get('interface', '')
            ))
        except Exception:
            pass

    clients_by_mac = {}

    for ifname, raw_mac, ip, last_ts, row_vendor in _collect_host_rows(hostwatch_db=hostwatch_db, now=ref_now):
        if ifname not in interfaces:
            continue  # Ignore WAN ISP neighbors unless explicitly configured in interfaces
        mac = normalize_mac(raw_mac)
        if not mac or not ip:
            continue
        key = f"{ifname}:{mac}"
        if key not in clients_by_mac:
            meta = client_meta.get(mac, {})
            static_info = static_macs.get(mac, {})
            dhcp_host = static_info.get('hostname', '') or hostnames.get(mac, '')
            custom_descr = meta.get('descr', '') or static_info.get('descr', '')
            resolved_hostname = custom_descr or dhcp_host
            display_name = resolved_hostname or ip
            vendor = row_vendor or _lookup_oui_vendor(mac)
            client_bridge_port = bridge_mac_port.get(mac, '')
            sw_info = None
            if client_bridge_port and ifname in switches_by_if:
                for candidate_sw in switches_by_if[ifname]:
                    if candidate_sw.get('bridge_port') == client_bridge_port or (
                        candidate_sw.get('port') == client_bridge_port
                    ):
                        sw_info = candidate_sw
                        break
            if sw_info is None:
                sw_info = switch_by_if.get(ifname)
            # Do not nest a switch/AP under itself or another switch on the same interface
            sw_mac_for_client = ''
            sw_name_for_client = ''
            if sw_info and mac not in switch_macs_by_if.get(ifname, set()) and sw_info.get('mac') != mac:
                sw_mac_for_client = sw_info.get('mac', '')
                sw_name_for_client = sw_info.get('name', '')
            if_id = interfaces[ifname].get('id', ifname)
            if_descr = interfaces[ifname].get('name', ifname)
            clients_by_mac[key] = {
                'mac': mac,
                'interface': if_id,
                'device': ifname,
                'interface_id': if_id,
                'interface_name': if_descr,
                'interface_descr': if_descr,
                'label': display_name,
                'hostname': resolved_hostname,
                'descr': custom_descr,
                'dhcp_hostname': dhcp_host,
                'vendor': vendor,
                'quarantined': bool(meta.get('quarantined', False)),
                'online': (ref_now - last_ts) < 900,
                'last_seen': last_ts,
                'addresses': [],
                'ipv4': [],
                'ipv6': [],
                'primary_ipv4': '',
                'primary_ipv6': '',
                'ipv4_addresses': [],
                'ipv6_addresses': [],
                'bps': 0,
                'static_lease': bool(static_info),
                'is_static': bool(static_info),
                'dhcp_service': static_info.get('service') or interfaces[ifname].get('dhcp_service', ''),
                'kea_subnet_uuid': interfaces[ifname].get('kea_subnet_uuid', ''),
                'aliases': list(mac_to_aliases.get(mac, [])),
                'switch_mac': sw_mac_for_client,
                'switch_name': sw_name_for_client,
                'bridge_port': client_bridge_port
            }
        else:
            if last_ts > clients_by_mac[key]['last_seen']:
                clients_by_mac[key]['last_seen'] = last_ts
                clients_by_mac[key]['online'] = (ref_now - last_ts) < 900

        entry = clients_by_mac[key]
        if ip not in entry['addresses']:
            entry['addresses'].append(ip)
        if ':' in ip:
            if ip not in entry['ipv6']:
                entry['ipv6'].append(ip)
                entry['ipv6_addresses'].append(ip)
                if not entry['primary_ipv6']:
                    entry['primary_ipv6'] = ip
                entry['bps'] += netflow_bps.get(ip, 0)
        else:
            if ip not in entry['ipv4']:
                entry['ipv4'].append(ip)
                entry['ipv4_addresses'].append(ip)
                if not entry['primary_ipv4']:
                    entry['primary_ipv4'] = ip
                entry['bps'] += netflow_bps.get(ip, 0)
                if parsed_kea_Subnets:
                    try:
                        ip_obj = ipaddress.ip_address(ip)
                        for net_obj, suuid, _sif in parsed_kea_Subnets:
                            if ip_obj in net_obj and suuid:
                                entry['kea_subnet_uuid'] = suuid
                                if not entry['dhcp_service']:
                                    entry['dhcp_service'] = 'kea'
                                break
                    except ValueError:
                        pass

        if ip.lower() in pf_quarantine_ips:
            entry['quarantined'] = True
        for alias_name in ip_to_aliases.get(ip.lower(), []):
            if alias_name not in entry['aliases']:
                entry['aliases'].append(alias_name)

    return {
        'status': 'ok',
        'gateway': 'OPNsense',
        'interfaces': list(interfaces.values()),
        'switches': switches,
        'clients': list(clients_by_mac.values()),
        'dhcp_subnets': dhcp_subnets,
        'available_aliases': available_aliases,
        'mac_aliases': available_aliases
    }


def resolve_mac_ips(mac, extra_ips=None, hostwatch_db=None, arp_cache_path=None):
    """Collect all valid IPv4/IPv6 addresses known for a MAC address."""
    norm_mac = normalize_mac(mac)
    validated_ips = set()

    def _add_ip(candidate):
        if not candidate:
            return
        addr_str = candidate.strip().split('%', 1)[0]
        try:
            ip_obj = ipaddress.ip_address(addr_str)
            validated_ips.add(str(ip_obj))
        except ValueError:
            pass

    if extra_ips:
        if isinstance(extra_ips, str):
            for token in re.split(r'[\s,]+', extra_ips):
                _add_ip(token)
        elif isinstance(extra_ips, (list, tuple, set)):
            for token in extra_ips:
                _add_ip(str(token))

    if norm_mac:
        for _ifname, row_mac, ip, _last_ts, _vendor in _collect_host_rows(hostwatch_db=hostwatch_db):
            if normalize_mac(row_mac) == norm_mac:
                _add_ip(ip)
        cache_file = arp_cache_path or ('/tmp/alias_filter_arp.cache' if hostwatch_db is None else None)
        if cache_file and os.path.exists(cache_file):
            try:
                with open(cache_file, 'r', errors='ignore') as f:
                    arp_cache = json.load(f)
                if isinstance(arp_cache, dict):
                    for cached_key, cached_meta in arp_cache.items():
                        if normalize_mac(cached_key) == norm_mac:
                            if isinstance(cached_meta, dict) and isinstance(cached_meta.get('items'), list):
                                for item_ip in cached_meta['items']:
                                    _add_ip(str(item_ip))
                            elif isinstance(cached_meta, list):
                                for item_ip in cached_meta:
                                    _add_ip(str(item_ip))
                        elif isinstance(cached_meta, dict) and normalize_mac(cached_meta.get('mac', '')) == norm_mac:
                            _add_ip(cached_key)
            except Exception:
                pass

    return sorted(validated_ips)


def enforce_quarantine(mac, action='block', extra_ips=None, config_path=None, hostwatch_db=None):
    """Immediately add/remove a client's IPs in <__client_quarantine> and kill active pf states."""
    norm_mac = normalize_mac(mac)
    if not norm_mac:
        return {'status': 'error', 'message': 'Invalid MAC address', 'addresses': []}

    act_norm = str(action or 'block').strip().lower()
    is_block = act_norm in ('block', 'add', '1', 'true', 'quarantine', 'enable')
    ips = resolve_mac_ips(norm_mac, extra_ips, hostwatch_db=hostwatch_db)

    if ips:
        try:
            if is_block:
                subprocess.run(
                    [PFCTL_BIN, '-t', QUARANTINE_TABLE, '-T', 'add'] + ips,
                    capture_output=True,
                    text=True,
                    timeout=5
                )
                for ip in ips:
                    subprocess.run(
                        [PFCTL_BIN, '-k', ip],
                        capture_output=True,
                        text=True,
                        timeout=3
                    )
                    subprocess.run(
                        [PFCTL_BIN, '-K', ip],
                        capture_output=True,
                        text=True,
                        timeout=3
                    )
            else:
                subprocess.run(
                    [PFCTL_BIN, '-t', QUARANTINE_TABLE, '-T', 'delete'] + ips,
                    capture_output=True,
                    text=True,
                    timeout=5
                )
            md5_cache = f'/var/db/aliastables/{QUARANTINE_TABLE}.md5.txt'
            if os.path.exists(md5_cache):
                try:
                    os.unlink(md5_cache)
                except OSError:
                    pass
        except Exception as exc:
            return {
                'status': 'error',
                'mac': norm_mac,
                'quarantined': is_block,
                'addresses': ips,
                'message': str(exc)
            }

    return {
        'status': 'ok',
        'mac': norm_mac,
        'quarantined': is_block,
        'addresses': ips
    }


def main(argv=None):
    args = list(sys.argv[1:] if argv is None else argv)
    if args and args[0] in ('quarantine', '--quarantine'):
        mac = args[1] if len(args) > 1 else ''
        action = args[2] if len(args) > 2 else 'block'
        extra_ips = args[3] if len(args) > 3 else ''
        print(json.dumps(enforce_quarantine(mac, action, extra_ips)))
    else:
        print(json.dumps(build_topology()))


if __name__ == '__main__':
    main()
