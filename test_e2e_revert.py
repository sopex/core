import json
import os
import re
import subprocess
import sys
import time

def run_cmd(cmd):
    p = subprocess.run(cmd, shell=True, capture_output=True, text=True)
    return p.returncode, p.stdout.strip(), p.stderr.strip()

def run_php(code):
    full_code = f"<?php\n{code}\n"
    p = subprocess.run(["php"], input=full_code, capture_output=True, text=True)
    return p.returncode, p.stdout.strip(), p.stderr.strip()

def drill_domain(domain="pornhub.com"):
    rc, out, err = run_cmd(f"drill -s 192.168.1.173 @192.168.1.173 {domain}")
    m = re.search(r"ANSWER SECTION:\s*\n[^\n]*\s+(\S+)$", out, re.MULTILINE)
    ip = m.group(1) if m else None
    return ip, out

def cleanup_and_reset():
    print("[Setup] Cleaning up previous state and resetting to known baseline...")
    # Confirm or abort active session if present
    cleanup_code = """
    require_once 'config.inc';
    require_once 'plugins.inc';
    $session = \\OPNsense\\Core\\CommitSession::getInstance();
    if ($session->isActive()) {
        $session->confirm('root', 'setup');
    }
    """
    run_php(cleanup_code)

    # Remove any leftover files
    for f in ["/conf/commit_rollback_pending.json", "/conf/commit_rollback_notice.json", "/conf/commit_rollback/snapshot.xml"]:
        if os.path.exists(f):
            try:
                os.unlink(f)
            except Exception:
                pass

    # Ensure config.xml has enabled=1 for oisd2
    config_file = "/conf/config.xml"
    with open(config_file, "r") as f:
        content = f.read()

    pattern_0 = r"(<blocklist uuid=\"[^\"]+\">\s*<enabled>)0(</enabled>\s*<type>oisd2</type>)"
    if re.search(pattern_0, content):
        content = re.sub(pattern_0, r"\g<1>1\g<2>", content)
        with open(config_file, "w") as f:
            f.write(content)
        print("[Setup] Restored config.xml oisd2 to enabled=1")
        run_cmd("configctl template reload 'OPNsense/Unbound/*' && configctl unbound dnsbl")

    run_cmd("/usr/local/sbin/unbound-control -c /var/unbound/unbound.conf flush pornhub.com")
    time.sleep(1)

print("--- Starting E2E Verification ---")

cleanup_and_reset()

# Step 0: Initial resolution check
ip, _ = drill_domain("pornhub.com")
print(f"[Step 0] Initial drill resolution for pornhub.com: {ip}")
assert ip == "0.0.0.0", f"Expected 0.0.0.0 initially, got {ip}"

# Step 1: Start Commit Session via PHP
print("[Step 1] Starting Commit Session via PHP...")
start_code = """
require_once 'config.inc';
require_once 'plugins.inc';
$session = \\OPNsense\\Core\\CommitSession::getInstance();
$res = $session->start('root', 'e2e_test');
echo json_encode($res);
"""
rc, out, err = run_php(start_code)
print(f"Start result: {out}")
start_res = json.loads(out)
assert start_res.get("status") == "ok", f"Session start failed: {out}"

# Verify session active and watchdog running
time.sleep(1)
status_code = """
require_once 'config.inc';
require_once 'plugins.inc';
$session = \\OPNsense\\Core\\CommitSession::getInstance();
echo json_encode($session->getState());
"""
rc, out, err = run_php(status_code)
state = json.loads(out)
print(f"Session state after start: {state}")
assert state.get("active") is True
assert state.get("status") == "pending"
assert state.get("reverting") is not True

# Step 2: Disable Unbound blocklist in config.xml
print("[Step 2] Disabling Unbound blocklist (oisd2) in config.xml...")
config_file = "/conf/config.xml"
with open(config_file, "r") as f:
    config_content = f.read()

assert "<type>oisd2</type>" in config_content
pattern = r"(<blocklist uuid=\"[^\"]+\">\s*<enabled>)1(</enabled>\s*<type>oisd2</type>)"
assert re.search(pattern, config_content), "Pattern for oisd2 blocklist not found in config.xml"
new_content = re.sub(pattern, r"\g<1>0\g<2>", config_content)
with open(config_file, "w") as f:
    f.write(new_content)

# Trigger save notification so session records revision
save_code = """
require_once 'config.inc';
require_once 'plugins.inc';
$session = \\OPNsense\\Core\\CommitSession::getInstance();
$rev = ['username' => 'root'];
$session->onConfigSave('/conf/backup/config-manual.xml', $rev);
"""
run_php(save_code)

# Apply change to Unbound runtime:
print("Applying disabled blocklist to Unbound runtime...")
rc, out, err = run_cmd("configctl template reload 'OPNsense/Unbound/*' && configctl unbound dnsbl")
print(f"Reload DNSBL: rc={rc}")
run_cmd("/usr/local/sbin/unbound-control -c /var/unbound/unbound.conf flush pornhub.com")
time.sleep(1)

# Verify DNS resolution is now ALLOWED (not 0.0.0.0)
ip, _ = drill_domain("pornhub.com")
print(f"DNS resolution with blocklist disabled: {ip}")
assert ip != "0.0.0.0", f"Expected domain to resolve to public IP, but got {ip}"

# Check diff in CommitSession
diff_code = """
require_once 'config.inc';
require_once 'plugins.inc';
$session = \\OPNsense\\Core\\CommitSession::getInstance();
echo json_encode($session->getDiff());
"""
rc, out, err = run_php(diff_code)
diff = json.loads(out)
print(f"Diff count lines: {len(diff)}")
assert len(diff) > 0, "Expected non-empty diff while change is active"

# Step 3: Trigger Revert
print("[Step 3] Triggering Revert via CommitSession->revert()...")
revert_code = """
require_once 'config.inc';
require_once 'plugins.inc';
$session = \\OPNsense\\Core\\CommitSession::getInstance();
$res = $session->revert('root', 'gui', 'manual');
echo json_encode($res);
"""
t0 = time.time()
rc, out, err = run_php(revert_code)
print(f"Revert response: {out}")
rev_res = json.loads(out)
assert rev_res.get("status") == "ok"

# Step 4: Verify REVERTING state during rollback
print("[Step 4] Checking state IMMEDIATELY after revert...")
# 1. Marker file
with open("/conf/commit_rollback_pending.json", "r") as f:
    marker = json.load(f)
print(f"Marker content during revert: status={marker.get('status')}, reverting={marker.get('reverting')}")
assert marker.get("status") == "reverting"
assert marker.get("reverting") is True

# 2. CommitSession::isReverting() and getState()
check_code = """
require_once 'config.inc';
require_once 'plugins.inc';
$session = \\OPNsense\\Core\\CommitSession::getInstance();
echo json_encode([
    'isReverting' => $session->isReverting(),
    'state' => $session->getState(),
    'diff' => $session->getDiff(),
]);
"""
rc, out, err = run_php(check_code)
mid_state = json.loads(out)
print(f"Mid-revert isReverting: {mid_state['isReverting']}")
print(f"Mid-revert state: {mid_state['state']}")
print(f"Mid-revert diff length: {len(mid_state['diff'])}")

assert mid_state["isReverting"] is True
assert mid_state["state"]["status"] == "reverting"
assert mid_state["state"]["reverting"] is True
assert mid_state["state"]["countdown_active"] is False
assert mid_state["state"]["remaining_seconds"] == 0
assert len(mid_state["diff"]) == 0, f"Diff must be empty during revert, got {mid_state['diff']}"

# Step 5: Wait for revert and rc.reload_all to complete
print("[Step 5] Waiting for revert completion...")
while True:
    time.sleep(2)
    elapsed = time.time() - t0
    rc, out, err = run_php(check_code)
    current = json.loads(out)
    if not current["state"].get("active", False):
        print(f"Revert completed in {elapsed:.1f}s!")
        break
    if elapsed > 180:
        raise TimeoutError("Revert timed out after 180s")
    print(f"Still reverting ({elapsed:.1f}s)... status={current['state'].get('status')}")

# Step 6: Post-revert checks
print("[Step 6] Running post-revert verification...")
# Marker file must be removed
assert not os.path.exists("/conf/commit_rollback_pending.json"), "Marker file still exists!"

# config.xml must have restored enabled=1
with open(config_file, "r") as f:
    restored_config = f.read()
assert re.search(r"(<blocklist uuid=\"[^\"]+\">\s*<enabled>)1(</enabled>\s*<type>oisd2</type>)", restored_config), "oisd2 was not restored to enabled=1 in config.xml!"
print("config.xml has oisd2 restored to <enabled>1</enabled>")

# Verify DNS resolution: MUST be blocked (0.0.0.0)!
ip, _ = drill_domain("pornhub.com")
print(f"DNS resolution after revert: {ip}")
assert ip == "0.0.0.0", f"Blocklist failed to reload! pornhub.com resolved to {ip} instead of 0.0.0.0"

# Verify notice exists
notice_code = """
require_once 'config.inc';
require_once 'plugins.inc';
$session = \\OPNsense\\Core\\CommitSession::getInstance();
echo json_encode([
    'hasNotice' => $session->hasRevertNotice(),
    'notice' => $session->getRevertNotice(),
]);
"""
rc, out, err = run_php(notice_code)
notice_info = json.loads(out)
print(f"Revert notice info: {notice_info}")
assert notice_info.get("hasNotice") is True

print("=== ALL E2E VERIFICATION CHECKS PASSED SUCCESSFULLY ===")
