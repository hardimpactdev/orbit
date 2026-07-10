# Retained Topology Proof

## Topology

- Id: `dev-a9d572`
- Kind/provider/host: `operator_gateway` / `incus` / `beast`
- Inspected role and instance: `operator` / `orbit-e2e-dev-a9d572-operator`
- Supporting instance: `gateway` / `orbit-e2e-dev-a9d572-gateway`
- Source-mounted execution checkout: `/home/orbit/orbit-run`
- Solo validation terminal: process `960`, `Core injected output retained topology dev-a9d572`
- Acquisition command: `composer e2e:incus -- --start --topology=operator_gateway --checkout-roles=operator --json`

The topology acquisition returned success. Its operator launcher resolves from `/home/orbit/.local/bin/orbit` to `/home/orbit/orbit-run/bin/orbit`.

## Checkout Proof

The runtime overlay is intentionally not a Git checkout. Exact source identity was therefore proved with matching local and operator-VM SHA-256 values:

- `packages/core/src/Progress/LiveRepaintOutput.php`: `920c3d0d5c783bce426ac06ef6bbcae96a7d8912d702669a20d1d755b49c3d4f`
- `packages/core/tests/Progress/LiveRepaintOutputTest.php`: `232aa3322bf98ed730a28362aee050d3aa3666876586302038889ae96463a78a`

## Changed-Branch Proof

The exact regression ran under a real TTY in the retained operator VM:

```text
cd /home/orbit/orbit-run/packages/core
vendor/bin/pest --compact --do-not-cache-result tests/Progress/LiveRepaintOutputTest.php --filter="respects injected non-stream BufferedOutput"
```

Result: exit `0`, 1 test passed with 2 assertions. PTY artifacts:

- `focused-regression-pty/summary.txt`
- `focused-regression-pty/chunks.jsonl`
- `focused-regression-pty/transcript.txt`

Pest emitted a non-fatal result-cache permission warning from the read-only hydrated vendor tree after the passing result; the captured child exit remained `0`.

## Genuine-TTY Compatibility Proof

The retained operator VM executed `StreamedStepTree` with a genuine `ConsoleOutput`, started one step, allowed the process ticker to repaint for 1.2 seconds, completed the step, and finished the tree. The exact one-line PHP command is retained in `streamed-tree-pty/summary.txt`.

Result: exit `0` in 1.596 seconds, seven captured chunks, no timeout, no idle timeout, and visible cursor/repaint sequences across the `○` / `◉` frames before the final `Probed  TTY path retained` row. PTY artifacts:

- `streamed-tree-pty/summary.txt`
- `streamed-tree-pty/chunks.jsonl`
- `streamed-tree-pty/transcript.txt`

## Operator-Visible Supplemental Proof

From Solo terminal 960, the source launcher ran both read-only doctor scopes:

```text
./apps/cli/orbit doctor --self --no-interaction
./apps/cli/orbit doctor --all --no-interaction
```

Both commands reached the retained gateway and rendered decorated doctor result panels. Their exit `1` is the expected command contract when drift is reported: the self scope found three issues and the all scope found six gateway issues. No `--fix`, `--restore`, or `--adopt` action ran. Captures are retained under `doctor-pty/` and `doctor-all-pty/`; these are supplemental operator-surface evidence, not the passing assertion.

## Claude Consultation And Adjudication

Question sent to Claude process 943: identify the smallest sound topology, role, commands, evidence, and release policy for a shared-core injected-output capability change that the finalization gate classifies as topology-relevant.

Claude advised an `operator_gateway` Incus topology, a preserved Solo terminal in the operator VM, a read-only `doctor` command for the real-console path, and the focused core regression in the same VM TTY for the formerly broken path. Claude also advised that the topology may be released after proof while retaining the Solo terminal.

Final adjudication: adopt the topology, role, focused regression, and preservation advice. The doctor runs were retained as supplemental operator evidence because this prepared topology reported expected drift and did not emit a useful multi-frame cadence. The explicit `StreamedStepTree` ConsoleOutput probe supplies the stronger deterministic genuine-TTY compatibility proof without changing product state or widening the repository diff.

## Release And Cleanup

After final analyzer process 961 returned `VERDICT: yes`, the retained topology was released with:

```text
composer e2e:incus -- --stop --id=dev-a9d572 --json
```

The command exited `0` and reported both `orbit-e2e-dev-a9d572-operator` and `orbit-e2e-dev-a9d572-gateway` reaped. A direct read-only Beast check, `incus list "orbit-e2e-dev-a9d572*" --format csv -c n`, exited `0` with no matching instances. Solo terminal 960 remained preserved after cleanup as the validation anchor requested in the Claude consultation.

## Result

`Retained topology proof: passed`

The exact changed branch and the unchanged genuine-TTY path both passed on retained topology `dev-a9d572/operator_gateway`, with operator instance, commands, Solo terminal, checkout hashes, and PTY artifacts retained.
