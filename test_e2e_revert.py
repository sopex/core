"""Opt-in demo-appliance test: disable one DNSBL, Apply, then verify protected-session recovery.

Example: python3 test_e2e_revert.py --run --resolver 192.0.2.1 --domain blocked.example --blocklist UUID
Requires an enabled, downloaded blocklist which blocks the chosen domain with 0.0.0.0.
Never run on production. Existing sessions are refused; recovery files are never deleted by this test.
"""
import argparse
import json
import re
import subprocess
import time


def run(*command):
    return subprocess.run(command, check=True, capture_output=True, text=True, timeout=240).stdout


def php(code, *arguments):
    bootstrap = "require_once 'config.inc'; require_once 'plugins.inc'; "
    return json.loads(run('php', '-r', bootstrap + code, '--', *arguments))


def state():
    return php('echo json_encode(\\OPNsense\\Core\\CommitSession::getInstance()->getState());')


def session(action):
    result = php('echo json_encode(\\OPNsense\\Core\\CommitSession::getInstance()->' + action + "('root', 'console'));")
    if result.get('status') != 'ok':
        raise RuntimeError(result)
    return result


def addresses(resolver, domain):
    run('/usr/local/sbin/unbound-control', '-c', '/var/unbound/unbound.conf', 'flush', domain)
    output = run('drill', '@' + resolver, domain, 'A')
    return re.findall(r'^\S+\s+\d+\s+IN\s+A\s+(\S+)', output, re.MULTILINE)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--run', action='store_true', help='Explicitly allow changing this demo appliance')
    parser.add_argument('--resolver', required=True)
    parser.add_argument('--domain', required=True)
    parser.add_argument('--blocklist', required=True)
    args = parser.parse_args()
    if not args.run:
        parser.error('--run is required to modify the demo configuration')
    if not re.fullmatch(r'[a-fA-F0-9-]{36}', args.blocklist):
        parser.error('blocklist must be a UUID')
    if state().get('active'):
        raise RuntimeError('An existing protected session is active; finish it first')
    before = addresses(args.resolver, args.domain)
    if '0.0.0.0' not in before:
        raise RuntimeError('The chosen domain must be blocked before this test')
    session('start')
    try:
        # Exercise Config::save, including its pre-write protection. Do not edit config.xml directly.
        saved = php(r'''
            $config = \OPNsense\Core\Config::getInstance()->lock();
            $nodes = $config->object()->xpath("/opnsense/OPNsense/unboundplus/dnsbl/blocklist[@uuid='" . $argv[1] . "']");
            if (count($nodes) !== 1 || (string)$nodes[0]->enabled !== '1') {
                throw new RuntimeException('Expected an enabled blocklist with this UUID');
            }
            $nodes[0]->enabled = '0';
            $config->save(['username' => 'root', 'description' => 'Protected session demo test']);
            echo json_encode(\OPNsense\Core\CommitSession::getInstance()->getState());
        ''', args.blocklist)
        if not saved.get('countdown_active'):
            raise RuntimeError('Configuration save did not arm recovery')
        applied = php(r'''
            $backend = new \OPNsense\Core\Backend();
            foreach (['template reload OPNsense/Unbound/*', 'unbound dnsbl'] as $action) {
                if (trim($backend->configdRun($action)) !== 'OK') {
                    throw new RuntimeException('Apply failed: ' . $action);
                }
            }
            echo 'true';
        ''')
        after = addresses(args.resolver, args.domain)
        if not applied or not after or '0.0.0.0' in after:
            raise RuntimeError('Disabling the selected list did not unblock the domain')
        session('revert')
        deadline = time.monotonic() + 240
        while state().get('active'):
            if time.monotonic() >= deadline:
                raise TimeoutError('Recovery still pending; inspect the banner and system log')
            time.sleep(1)
        restored = php(r'''
            $nodes = \OPNsense\Core\Config::getInstance()->object()->xpath(
                "/opnsense/OPNsense/unboundplus/dnsbl/blocklist[@uuid='" . $argv[1] . "']"
            );
            echo json_encode((string)$nodes[0]->enabled === '1');
        ''', args.blocklist)
        if not restored or '0.0.0.0' not in addresses(args.resolver, args.domain):
            raise RuntimeError('Configuration or live DNS policy was not restored')
        print('PASS: save armed protection; disable applied; rollback restored XML and live DNS policy')
    finally:
        if state().get('active'):
            session('revert')
            print('Recovery queued; snapshot and marker retained until recovery succeeds')


if __name__ == '__main__':
    main()
