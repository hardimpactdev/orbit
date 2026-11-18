Nested app-dev converge: stock all phases 141 s (Operation 198) vs one-phase network 43 s (Operation 200), roles-only 80 s (Operation 201), no change 20 s (Operation 199).

# Per-phase code digest for node.converge: proof on nested-app-dev

Candidate: task-5896 (resolver commits b4c099d0f, 02940c22e and the final commit). Each run is a default-scope converge of nested Node 3
(app-dev) through the nested Gateway's API (`converge.sh`), with the Gateway's checkout on the candidate. The times are the wall seconds from
admission to the end, and each phase's `elapsed_seconds` from the Operation result.

| Step | Change | Operation | Wall s | Phases that ran (s) | Skipped (`unchanged`) |
| --- | --- | --- | --- | --- | --- |
| 1 | first converge on the candidate (digests moved) | 198 | 141 | agent 11, software 22, roles 63, tools 2, network 19 | none |
| 2 | no change | 199 | 20 | none | all five |
| 3 | comment in `tasks/node-converge-hub-prune.yml` (network only) | 200 | 43 | network 17 | agent, software, roles, tools |
| 4 | comment in `tasks/node-role-runtime.yml` (roles only) | 201 | 80 | roles 57 | agent, software, tools, network |
| 5 | comment in `ansible.cfg` (shared) | 202 | 132 | agent 15, software 18, roles 60, tools 2, network 16 | none |
| 6 | probes reverted (ansible.cfg moved back: all run) | 204 | 122 | agent 11, software 20, roles 53, tools 1, network 12 | none |

`input` and `check` ran every time (check 8 to 12 s). Operation 203 was a converge while the probes were still in place (a failed revert);
it skipped every phase, which is correct for unchanged code. The roles phase reports one change on every run that runs it; that also
happens on main and is not from this change. After step 6 the nested Doctor is healthy (`doctor.txt`). The nested fleet stays running.

Raw outputs: `1-first.txt` ... `6-reverted.txt`. The resolved sets for the shipped bundle are in `phase-sets.txt`.

## Decisions

- Sets are computed at digest time from the staged bundle (`phase_code.ts`); no checked-in manifest.
- No phase of the shipped bundle falls back to the whole bundle. A test fails if one does, or if a reached file includes a task file outside its set.
- A bundle file that no phase names is in no digest: no skippable phase can load it. The fixture test proves this for an unused file.
- Tool helper and Tool files: tools and roles phases (as asked). `ansible.cfg`, `build_vars.py` and both converge playbooks: every phase.
- Handlers of a play are read by every phase (a notified handler runs whatever the tags select).
- Role Operations (`roleDigests`, `rolePhaseCode`) still hash the whole bundle: their phases are run by other playbooks, and mapping them is a separate step.

## Gates

- `deno test -A tests/phase_code_test.ts tests/node_converge_test.ts tests/fleet_converge_inputs_test.ts`: 85 passed.
- `tests/node_converge_ansible_test.ts` (needs ansible-playbook, so it ran in nested-gateway): 32 passed, 2 failed; the same 2 fail on origin/main there.
- `deno check` of the extension and tests: clean. `bin/swamp-shape-gate`: passed (credential steps skipped). `composer docs-lint`: passed.
