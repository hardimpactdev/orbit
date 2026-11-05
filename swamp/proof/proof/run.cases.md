# proof:run verification cases

`proof:run` picks the cases of the commands that a commit changes, runs them on a VM in a fresh test fleet, and reports a verdict for each. The proof of this command runs the built CLI against a nested topology with a stubbed task VM host that starts nested VMs, a set of test commits on branches and pull requests, and cases files whose cases pass, fail, end unrun, hang and leave residue. The commits are built by the setup; none is a real change. The test Gateway sets the VM wait and the run budget to seconds. See [verifying-commands](../../../.agents/skills/verifying-commands/SKILL.md). Every case below is checked against the Gateway records, the VM and the host. Unless a row says otherwise, "no run starts" means that no VM changes state, no VM is created, and no receipt exists.

## The command

| Case | Setup and action | Required observations |
| --- | --- | --- |
| Passing proof | A branch head commit changes one path that the selection list maps to a command whose cases file has one passing case. A VM is free. Run `proof:run COMMIT`. | Exit 0. The result lists the case as `passed` with its duration, the receipt link and the VM. While the run was in progress the VM held no Pi token, no model key, no GitHub token and no real-fleet key, Pi was not running, and the VM could not reach the real Gateway. The checkout in the VM was clean at `COMMIT`. After the run no VM, seed clone or nested VM exists. The receipt holds the case result, observations, Doctor findings and timings. |
| Failing proof | The same setup, but the case is broken so that its assertion fails. | Exit nonzero with `proof.cases_failed`. The case is `failed`. The receipt holds the failed assertion and the command output. The VM is gone. The same commit with the case fixed passes. This proves that the gate can fail. |
| Unrun case | A case ends `unrun` (setup precondition not met). | The case is `unrun`. The run fails with `proof.cases_failed`, exit nonzero. `unrun` never counts as a pass. |
| Several cases | A commit selects three cases, one of which fails. | All three run in order on one fleet. Two are `passed`, one `failed`. The result lists all three. The run fails. |
| Clean fleet | A selected case is marked as needing a clean fleet and runs after another case. | The fleet is reset before that case, and the case sees no state of the earlier case. |
| Residue | A passing case leaves a firewall rule behind. | The case is `failed` and names the residue. The run fails with `proof.cases_failed`. The VM is released. The next run starts without the rule. |
| Fleet not ready | A fault stops the nested Gateway from booting. | `proof.run_failed` names the failed step. Every selected case is `unrun` and no case started. Diagnostics are in the receipt. The VM and the fleet are gone. |
| Budget | A case hangs. | The run stops at the budget. The hanging case is `failed` and the cases after it are `unrun`. The run fails with `proof.cases_failed`. The VM is released. |
| Required input only | A branch head commit that changes one mapped path. Run with the commit and no option. | Only the cases that the diff selects run, and the exit status follows their verdicts. |
| Option `--all` | A docs-only commit, then a one-path commit, each with `--all`. | Every case runs both times, and the result lists every case. Without `--all`, the first commit is skipped. |
| Option `--json` | Run a passing and a failing proof with `--json`. | Stdout holds only the final JSON: commit, each case with its verdict and duration, the uncovered paths, the VM and `receipt_url`. Nothing is printed while the run follows. |
| Option `--idempotency-key` | Run twice with the same key while the first run is in progress, then after it ended. Run with another key. | The same key returns the original run and takes no second VM, in progress and after the end. Another key starts a new run. |
| Repeat | Run the same commit again after a passing run. | A new run on a new VM with the same verdicts. The first run and its receipt are unchanged. No skip reason appears. |
| Receipt retention | Read a receipt after 13 days and after 15 days. | It is readable at 13 days and gone at 15 days. The run record keeps the verdicts. |
| Missing input | In a terminal, run with no argument. Run noninteractively, and with `--json`, without the commit. | The terminal asks for the commit with a text prompt, asks again for an invalid value, and ends on Ctrl-C with no request. The other two are refused with `proof.commit_invalid`, with no request. |
| Invalid commit | Pass a 7 character SHA, an upper case SHA, a branch name, a tag name and a SHA with a trailing space. | `proof.commit_invalid` for each, with no request. A well-formed 64 character SHA is not refused as invalid. |
| Unknown commit | Pass a well-formed SHA that the repository does not have. | `proof.commit_not_found`. No run starts. |
| Callers | A caller without an access grant to the Gateway Node. The tasks extension off. | `node_access.required` for the first. `extension.disabled` for the second. No run starts. |
| Output forms | A wide and a narrow terminal, output piped to a file, and `--json`. | The progress tree shows the steps, then each case with its verdict, the VM by name and the receipt link. The piped output has no animation or escape codes. With `--json`, stdout holds only the JSON. |

## Which commits are accepted

| Case | Setup and action | Required observations |
| --- | --- | --- |
| Branch head | The head of a branch of the repository. | Accepted. |
| Merge queue commit | The head of a merge queue branch that combines two pull requests, each changing a different mapped path. | Accepted. The cases of both paths are selected, because the diff runs from the merge base with `main`. |
| Not a head | An older commit of a branch that has a newer head. | `proof.commit_untrusted`. No run starts. |
| Unapproved outside pull request | The head of a fork pull request, without the label and without an approval. | `proof.commit_untrusted`. The commit is never fetched: no bundle or checkout exists. No run starts. |
| Label without approval | The same pull request with the label `run-proofs` and no approving review. | `proof.commit_untrusted`. |
| Approval without label | The same pull request with a maintainer's approving review of the head and no label. | `proof.commit_untrusted`. |
| Approval by a non-maintainer | The label and an approving review of the head by a user without write access. | `proof.commit_untrusted`. |
| Approved outside pull request | The label and a maintainer's approving review of exactly the head. | Accepted, and exactly this commit runs. |
| Stale approval | A new push to the approved pull request, then a run for the new head. Then a run for the old head. | `proof.commit_untrusted` for both: the approval names the old commit, and the old commit is no longer the head. |
| Other sources | A tag, a branch of a fork that is not a pull request head, and the head of a closed pull request. | `proof.commit_untrusted` for each. No run starts. |

## Which cases are selected

| Case | Setup and action | Required observations |
| --- | --- | --- |
| Docs only | A commit that changes only a documentation page, a chart and a `*.cases.md` file. | Exit 0. `Skipped — nothing to prove (proof.nothing_to_prove)` in human and JSON output. No run starts. |
| Uncovered only | A commit that changes only a path that the selection list marks as having no case yet. | Exit 0 and skipped with `proof.nothing_to_prove`. The result lists the path as uncovered. No run starts. |
| Uncovered with cases | A commit that changes a mapped path and an uncovered path. | The mapped cases run. The result lists the uncovered path. The run does not fail because of it. |
| Command without a cases file | A mapped command whose `swamp/proof/<family>/<command>.cases.yaml` is not in the commit. | The command is listed as uncovered with the reason `no cases file for COMMAND`. The run does not fail because of it. |
| Unmapped path | A commit that changes a path that matches no line. | `proof.path_unmapped` names the path. No run starts. |
| Shared path | A path that the selection list maps to two commands. | The cases of both commands run. |
| Every case | A path that the selection list maps to `*`. | Every case runs. |
| Selection list changed | A commit that removes a line from the selection list and changes the path of that line. | Every case runs. The change cannot narrow its own selection. |
| Incomplete file list | A commit with more files than GitHub lists. | Every case runs. |
| Last match wins | A path that matches a broad line and a later narrow line. | The cases of the later line are selected. |
| Rename and delete | A commit that renames a mapped file out of its path, and one that deletes it. | The cases of the old path are selected. |

## VM and release

| Case | Setup and action | Required observations |
| --- | --- | --- |
| No free VM | The proof VM claim finds no free VM. Run a proof. | The run is accepted and waits with `capacity`. Nothing starts. After the wait the task fails with `proof.no_vm` and the exit is nonzero. |
| Concurrent runs | Start proofs on two hosts at once. | Each run has its own VM and fleet and shares no state. Each fleet is released at the end. |
| Network isolation | During a run, probe the real Gateway, another Node and another VM from inside the VM. | None is reachable. The Swamp host still reaches the VM. |
| Cancel while waiting | Press Ctrl-C during the VM wait. | Exit 130. The operation is cancelled. Nothing started, and no VM changed. |
| Cancel while running | Press Ctrl-C while the cases run, then send SIGTERM in a second run. | Exit 130 and exit 143. The operation is cancelled each time. Cases still to run are `unrun`. The fleet and the VM are released. A partial receipt exists. |
| Caller killed | Kill the CLI with SIGKILL while the cases run. | The run continues to its end or its budget, then releases the VM. |
| Release fails | Make the VM delete fail after the cases passed. | The verdicts stay `passed` and the exit status follows them. The failure is visible in the operation. The next proof passes. |
| Interrupted run | Kill the worker in the middle of the cases. | The run does not report success. After recovery the fleet and the VM are released, or the failure stays visible in the operation. No VM stays held without a run. |
| No secrets | During a run and afterwards, search the VM, the receipt, the operation record, the CLI output and the CI summary. | No Pi token, model key, GitHub token or real-fleet SSH or WireGuard key appears in any of them. The VM holds only the keys of its own test fleet. |

## The CI job

These rows are checked with test pull requests against a copy of the repository. They prove the job around the command; see [the VM job](/reference/feature-flow#the-vm-job).

| Case | Setup and action | Required observations |
| --- | --- | --- |
| Command change | A pull request from a branch of the repository that changes command code. | `Proofs` runs on a runner on a task VM host and passes the head SHA only. Its result equals the verdict of the same run started by hand. The job summary lists each case, the uncovered paths and the receipt link, and no command output. |
| Docs only | A docs-only pull request. | No VM job starts. `Proofs` passes without a run. |
| Uncovered paths | A pull request that changes only a path marked as having no case yet. | `Proofs` passes, and its summary lists the path as uncovered. |
| Merge queue | Two pull requests in one batch. | `Proofs` runs once on the batch commit. A failing case removes the batch from the queue. |
| Known failing case | A pull request with a failing case. | `Proofs` fails and the pull request cannot merge. |
| Unmapped path | A pull request that adds a file that matches no line. | The scope step fails and names the file. No VM job starts. |
| Job timeout | A run that waits for a VM and then runs to its budget. | The job ends before its 45 minute timeout, with the run's verdict. |
| Job cancelled | A new push cancels the job of the earlier commit. | The run of the earlier commit is cancelled and its VM is released. |
| Fork pull request | A pull request from a fork that changes command code, without approval. | No job runs on a task VM host runner. `Proofs` fails and the pull request cannot merge. |
| Approval and label | A maintainer approves the head and adds `run-proofs`. | The label run starts, passes the head SHA only, runs no code from the pull request on the runner, and reports `Proofs` for exactly that head. The failed `Proofs` job of the pull request runs again and passes. |
| New push after label | Push to the approved pull request. | The label is removed, `Proofs` fails again, and nothing runs until a maintainer approves the new head and adds the label again. |
