# Session-index replay retained-topology proof

- Feature commit: `d6e0b4ca46857d97802285459909c4ebaaf5f9ec`
- Topology: `dev-c08fa2` (`operator`, Incus provider on `beast`)
- Role / instance: `operator` / `orbit-e2e-dev-c08fa2-operator`
- Source checkout: `/home/orbit/orbit-run`
- Solo terminal: process `999` (`session-index-topology-proof`)
- Local/source helper SHA-256: `e8c92177119ac1a4d4efbe33eefa211436013c1f0b4217f9f620c4a1465ae04a`

## Exact proof command

The Solo terminal piped a fixed script over SSH into the retained operator instance:

```text
printf '%s' '<base64-encoded fixed proof script>' | base64 -D | ssh beast incus exec orbit-e2e-dev-c08fa2-operator -- sudo -u orbit bash
```

The decoded script changed to `/home/orbit/orbit-run`, asserted the helper SHA-256, ran `php -l bin/orbit-session-index`, created an owned temporary archive corpus under `/tmp/session-index-topology.XXXXXX`, ran:

```text
php bin/orbit-session-index --sessions-dir="$sessions" --write
```

and asserted all of these runtime results:

- two records were indexed;
- a same-line stale verdict plus four-space stale grandchild did not preempt the two-space direct child;
- the direct-child record normalized to `fresh_analyzer_verdict=yes` with raw `yes - final`;
- exact `None currently.` was blocker-free;
- plural `No blockers currently.` remained blocker-positive.

## Terminal result

```text
topology=dev-c08fa2 role=operator instance=orbit-e2e-dev-c08fa2-operator checkout=/home/orbit/orbit-run
source_sha256=e8c92177119ac1a4d4efbe33eefa211436013c1f0b4217f9f620c4a1465ae04a  bin/orbit-session-index
No syntax errors detected in bin/orbit-session-index
Wrote /tmp/session-index-topology.5G5mJM/sessions/index.json.
{
    "record_count": 2,
    "direct_child": {
        "fresh_analyzer_verdict": "yes",
        "fresh_analyzer_verdict_raw": "yes - final",
        "blockers_present": false
    },
    "plural_blocker": {
        "blockers_present": true
    },
    "assertions_passed": true
}
topology_proof=passed
```

No `composer test:e2e*` command was invoked.
