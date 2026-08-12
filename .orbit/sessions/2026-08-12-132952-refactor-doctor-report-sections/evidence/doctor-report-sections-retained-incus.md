# Doctor report sections retained Incus proof

- Candidate: `ca968c43b5c7a107f40131b7b8c39921d77657f9`
- Venue: `retained-incus`
- Environment: `dev-fixture`
- Topology: `dev-d58f78` (`operator_gateway_app-dev`)
- Incus host: `beast` over LAN address `192.168.6.20`
- Solo project: `33`
- Solo terminals: `retained-incus-doctor-proof` (`2288`) and
  `retained-incus-doctor-reproof` (`2289`)

## Candidate identity

The gateway runtime checkout and the candidate worktree had the same hashes:

```text
d5296b8c8207fd1d172d8d715e1d7e9ae5c5a49061cd13f8f716c9680fae4333  DoctorReportSections.php
26b1e46aafb4ce4c80df29dbf9f971252e991713e87acfa97cdea3d133984761  DoctorReportRunner.php
```

## Single-node report

The Solo terminal ran `orbit doctor --node=gateway --family=node --json` from
the retained operator checkout. Doctor detected the fixture's existing drift
and returned the expected final node report sections:

- scope fields stayed in order and included `families`, `node`, `role`,
  `roles`, `self`, `app`, `instance`, `workspace`, and `key`;
- roles were `gateway`, `router`, and `vpn`;
- the seven summary counts were present;
- all four disposition counts were present;
- typed issues stayed in observation order.

## Fleet report

The same Solo terminal ran `orbit doctor --all --family=node --json`. Doctor
returned the expected fleet report sections:

- fleet scope omitted `roles` and `instance`;
- targets stayed ordered as `app-dev-1`, then `gateway`;
- fleet summary contained the seven counts without dispositions;
- node summaries retained complete roles and their node disposition counts;
- fleet issues stayed grouped in target order.

## Stream report

The same Solo terminal ran
`orbit doctor --node=gateway --family=node --key=node.platform_record_mismatch --stream-json`.
It emitted queued, running, and done steps, followed by the final Doctor error
event with the exact node scope, seven summary counts, four disposition counts,
and the selected issue.

Result: passed. The expected node, fleet, and stream report contracts were
observed from the candidate source on retained Incus.

After the test-only review correction, the topology was synced from candidate
`ca968c43b5c7a107f40131b7b8c39921d77657f9`. The second Solo terminal reran the
selected stream command and observed the same queued/running/done sequence and
final Doctor report sections.
