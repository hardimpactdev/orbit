# Doctor family probe runner retained Incus proof

- Candidate: `f8168fab4384b0ace8c4509da42acb693dc42570`
- Venue: `retained-incus`
- Environment: `dev-fixture`
- Topology: `dev-1493d7` (`operator_gateway_app-dev`)
- Incus host: `beast`, resolved to LAN address `192.168.6.20`
- Solo project: `31`
- Solo terminal: `retained-incus-doctor-proof` (`2286`)

## Candidate identity

`bin/orbit-secret-scan` returned `SECRET_SCAN: PASS` before acquisition. The
worktree was clean on candidate `f8168fab4384b0ace8c4509da42acb693dc42570`.

The gateway runtime checkout matched the local candidate for every changed
production file:

```text
c0e43c87d2aa76cdc232e8a85d932c8f638a51494423ef6ae0808c18a7e638a1  DoctorFamilyProbeRunner.php
93089b1731633fd055df35dcde8114d7ec041a7b61db893d83041bcacc3f478f  DoctorIssueFactory.php
c95be8e72762f7a911dc1ab758b5c0b336b48b5dede8445f97f2fa8ead198310  DoctorReportRunner.php
```

## Command

From the operator VM, through Beast's LAN address:

```text
ssh -o HostName=192.168.6.20 beast "incus exec orbit-e2e-dev-1493d7-operator -- sudo -u orbit bash -lc 'cd /home/orbit/orbit-run && /home/orbit/orbit-run/apps/cli/orbit doctor --all --stream-json'"
```

## Observed result

- Doctor selected the two eligible fleet targets in stable order:
  `app-dev-1`, then `gateway`.
- Both targets emitted running progress and one terminal `done` event.
- Doctor reached the final fleet report instead of aborting in one family.
- The final report included all eligible family sets for each node.
- The final report contained 15 fixture drift issues and no failed action.
- The command returned exit `1` with `drift_detected`, which is the expected
  result for this deliberately drifting fixture.

The first command attempt returned `127` before Orbit started because its SSH
shell quoting split the remote `cd` from the executable path. The corrected
command above ran the candidate and produced the result recorded here.

## Result

Passed. The real CLI-to-gateway path completed Doctor progress for both nodes
and returned the final fleet report from the exact candidate.

The topology was released after proof. Orbit reported `Instances reaped: 3`.
