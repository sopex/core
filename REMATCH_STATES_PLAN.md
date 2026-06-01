# Rematch Sessions — state re-evaluation on filter reload

Implements opnsense/core#10347 (Palo Alto–style "Rematch Sessions").

When enabled, after a filter reload all existing states created by **automation (MVC) filter rules**
are re-evaluated against the ruleset that was just loaded. States that would no longer be passed
are terminated, so traffic immediately follows the updated ruleset instead of persisting on stale
states until they expire. Modify, disable, delete and **reorder** are all covered, because the
evaluation runs against the actual, freshly-loaded ruleset rather than diffing rules.

## Decisions (as built)
- **Default off.** New global setting, opt-in.
- **Mode A — model evaluator.** A userspace matcher re-evaluates each state against the ruleset (pf has no native rematch).
- **MVC/automation rules only** are killed. Legacy `filter/rule` is intentionally not handled.
- **Considers the whole ruleset** when deciding pass/block, so a state still permitted by some other rule is never dropped.
- **Reorder included** — naturally, since we evaluate the loaded ruleset in order.
- **Global only**, in System → Settings → Firewall. No per-rule field.
- **Asynchronous** — evaluation/killing runs in a detached configd action, off the reload path.

## How it works
```
filter reload → filter_configure_sync()                         [src/etc/inc/filter.inc]
  ├─ build $fw, write /tmp/rules.debug, pfctl -f
  └─ if system/rematch_states:
       ├─ export normalized ruleset → /tmp/rematch_ruleset.json   (FilterRule::toCriteria())
       └─ configd_run('filter rematch states', detach=true)
            → scripts/filter/rematch_states.py
                ├─ load /tmp/rematch_ruleset.json
                ├─ query_states() (lib/states.py)
                ├─ for each state whose creating rule is an automation rule:
                │     verdict = rematch.evaluate(rules, state, resolver)   (lib/rematch.py)
                │     verdict == 'block' → mark for kill
                └─ pfctl -k id -k <id>  (chunked), log count
```

The evaluator is conservative: any token it cannot resolve (a port alias, or an alias table it
cannot read) yields `uncertain` for that state, which is treated as **keep**. Only states the
ruleset would *definitely* block are killed.

## Files changed
- `src/www/system_advanced_firewall.php` — global "Rematch states" checkbox (read/save/form), stored at `system/rematch_states`.
- `src/opnsense/mvc/app/library/OPNsense/Firewall/FilterRule.php` — new `toCriteria()` exporting normalized match criteria (reuses existing `parseFilterRules()` normalization).
- `src/etc/inc/filter.inc` — after a successful reload, export the ruleset JSON and trigger the detached configd action when the setting is on.
- `src/opnsense/scripts/filter/lib/rematch.py` — pure, unit-tested matcher (tri-state match + quick/last-match resolution).
- `src/opnsense/scripts/filter/rematch_states.py` — glue: enumerate states, resolve aliases/interfaces from the running system, evaluate, kill.
- `src/opnsense/service/conf/actions.d/actions_filter.conf` — `[rematch.states]` configd action.
- `src/opnsense/scripts/filter/tests/rematch_tests.py` (+ `__init__.py`) — 16 evaluator unit tests (all passing).

## Verification done here
- `python3 -m unittest rematch_tests` → 16/16 pass: the IOT allow-removed→reject case, address narrowing, quick first-match, non-quick last-match, alias/interface-network/negation/port-range matching, AF & protocol mismatch, and the conservative "unresolved → uncertain → keep" behaviour.
- `py_compile` clean on both Python modules.
- PHP files reviewed manually (no PHP available in this environment) — patterns mirror the existing `schedule_states` handling and `FilterRule` parsing.

## Still to verify on a live OPNsense VM (cannot be done here — pf required)
1. End-to-end: enable setting, establish an automation-allowed flow, remove/disable the allow rule above a catch-all reject, Apply → state terminated; new packets rejected.
2. Narrowing (`192.168.1.100/32` → `/24`) → flow keeps working.
3. Reorder reject above allow → flow terminated.
4. Scale: 50k+ states — measure runtime of the async pass.
5. HA/CARP: confirm it runs on the secondary after its own reload.
6. Confirm the new script is packaged with the executable bit (git mode 100755) and picked up by configd.

## Known limitations / fidelity notes
- **Mode A re-implements a subset of pf matching.** Conservatively biased to avoid false-positive kills; unresolved port aliases and unreadable alias tables make a state `uncertain` (kept). state-policy/sloppy/no-state nuances and mid-stream TCP adoption are not modelled.
- **NAT/rdr** is not considered; matching uses the post-translation tuple reported by `query_states`. Flows depending on rdr interactions may evaluate imperfectly.
- Only automation-owned states are ever killed; states from automatic/internal/legacy rules are left untouched (but are considered when deciding whether a flow is still permitted).
- Default off; turning it on is a behaviour change administrators opt into.
