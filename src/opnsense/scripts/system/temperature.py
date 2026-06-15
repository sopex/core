#!/usr/local/bin/python3

import subprocess
import re
import sys

try:
    import ujson as json
except ImportError:
    import json

def get_nvme_temps():
    temps = []
    try:
        # Get list of nvme controllers
        sp = subprocess.run(['/sbin/nvmecontrol', 'devlist'], capture_output=True, text=True, check=True, timeout=2)
        controllers = []
        for line in sp.stdout.split('\n'):
            m = re.match(r'^(nvme\d+):', line.strip())
            if m:
                controllers.append(m.group(1))

        for ctrl in controllers:
            try:
                sp_log = subprocess.run(['/sbin/nvmecontrol', 'logpage', '-p', '2', ctrl], capture_output=True, text=True, check=True, timeout=2)
                for line in sp_log.stdout.split('\n'):
                    m = re.match(r'^Temperature(?:\s+Sensor\s+(\d+))?:\s*(.+)$', line.strip(), re.IGNORECASE)
                    if m:
                        sensor_num = m.group(1)
                        data_str = m.group(2)
                        val = None

                        m_c = re.search(r'([\d\.\-]+)\s*(?:C|Celsius)', data_str, re.IGNORECASE)
                        if m_c:
                            val = float(m_c.group(1))
                        else:
                            m_k = re.search(r'([\d\.\-]+)\s*(?:K|Kelvin)', data_str, re.IGNORECASE)
                            if m_k:
                                val = float(m_k.group(1)) - 273.15
                            else:
                                m_f = re.search(r'([\d\.\-]+)\s*(?:F|Fahrenheit)', data_str, re.IGNORECASE)
                                if m_f:
                                    val = (float(m_f.group(1)) - 32.0) * 5.0 / 9.0

                        if val is not None:
                            device_name = f"{ctrl}"
                            if sensor_num:
                                device_name += f"s{sensor_num}"

                            temps.append({
                                'device': device_name,
                                'temperature': round(val, 1),
                                'type': 'nvme'
                            })
            except Exception:
                continue
    except Exception:
        pass
    return temps

def get_smart_temps():
    temps = []
    try:
        sp = subprocess.run(['/sbin/sysctl', '-n', 'kern.disks'], capture_output=True, text=True, check=True, timeout=2)
        disks = sp.stdout.strip().split()

        for disk in disks:
            if disk.startswith(('cd', 'nvme', 'nda', 'nvd')):
                continue

            try:
                sp_smart = subprocess.run(['/usr/local/sbin/smartctl', '-a', f'/dev/{disk}'], capture_output=True, text=True, timeout=2)

                for line in sp_smart.stdout.split('\n'):
                    line_stripped = line.strip()
                    parts = line_stripped.split()

                    if len(parts) >= 10 and parts[0] in ['190', '194'] and 'temperature' in parts[1].lower():
                        try:
                            # parts[9] is the raw value
                            val = float(parts[9])
                            temps.append({
                                'device': disk,
                                'temperature': round(val, 1),
                                'type': 'disk'
                            })
                            break
                        except ValueError:
                            pass

                    m = re.search(r'(?:Current Drive Temperature|Temperature):\s*([\d\.]+)', line_stripped, re.IGNORECASE)
                    if m:
                        try:
                            val = float(m.group(1))
                            temps.append({
                                'device': disk,
                                'temperature': round(val, 1),
                                'type': 'disk'
                            })
                            break
                        except ValueError:
                            pass
            except Exception:
                continue
    except Exception:
        pass
    return temps

def get_sfp_temps():
    temps = []
    try:
        sp = subprocess.run(['/sbin/ifconfig', '-l'], capture_output=True, text=True, check=True, timeout=2)
        interfaces = sp.stdout.strip().split()
        ignored_prefixes = ('lo', 'enc', 'pflog', 'pfsync', 'tun', 'tap', 'bridge', 'ovpn', 'wg', 'gif', 'gre', 'lagg')

        for iface in interfaces:
            if '.' in iface or iface.startswith(ignored_prefixes):
                continue

            try:
                sp_if = subprocess.run(['/sbin/ifconfig', '-v', iface], capture_output=True, text=True, timeout=2)
                for line in sp_if.stdout.split('\n'):
                    m = re.search(r'module temperature:\s*([\d\.\-]+)\s*C', line, re.IGNORECASE)
                    if m:
                        try:
                            val = float(m.group(1))
                            temps.append({
                                'device': iface,
                                'temperature': round(val, 1),
                                'type': 'sfp'
                            })
                        except ValueError:
                            continue
            except Exception:
                continue
    except Exception:
        pass
    return temps

if __name__ == '__main__':
    results = []
    results.extend(get_nvme_temps())
    results.extend(get_smart_temps())
    results.extend(get_sfp_temps())
    print(json.dumps(results))
