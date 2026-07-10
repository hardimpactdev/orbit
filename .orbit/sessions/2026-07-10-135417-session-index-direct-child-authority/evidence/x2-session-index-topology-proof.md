# X2 session-index direct-child authority retained-topology proof

- Feature commit: `53d16f55e427eff41e2f1c153caf52f6abe46003`
- Topology: `dev-57dcbb` (`operator`, Incus provider on `beast`)
- Role / instance: `operator` / `orbit-e2e-dev-57dcbb-operator`
- Source checkout: `/home/orbit/orbit-run`
- Solo terminal: process `1004` (`x2-session-index-topology-proof`)
- Local/source helper SHA-256: `98e91617f70771a2bb33f00d187b4b429725b776d201e0a26d2b70a356786e41`

## Exact proof

The retained Solo terminal entered the operator instance before the proof and
ran a fixed Bash script from `/home/orbit/orbit-run`. The script:

1. asserted the source-mounted helper SHA-256;
2. ran `php -l bin/orbit-session-index`;
3. created a temporary three-archive corpus for an exact direct child, a
   four-space grandchild, and prose containing `VERDICT: yes`;
4. ran `php bin/orbit-session-index --sessions-dir="$sessions" --write`; and
5. asserted that only the exact direct child was authoritative.

## Result

```json
{
  "topology": "dev-57dcbb",
  "role": "operator",
  "instance": "orbit-e2e-dev-57dcbb-operator",
  "checkout": "/home/orbit/orbit-run",
  "source_sha256": "98e91617f70771a2bb33f00d187b4b429725b776d201e0a26d2b70a356786e41",
  "record_count": 3,
  "direct_child": "yes",
  "grandchild": "unknown",
  "prose": "unknown",
  "assertions_passed": true
}
```

Terminal result: `topology_proof=passed`.

No `composer test:e2e*` command was invoked.
