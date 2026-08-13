# Doctor outcome reconciler retained Incus proof

- Candidate: `b75f2a063dc31d5633c2f7a1b7625a5a0c784286`
- Topology: `dev-dfbb7c` (`operator_gateway_app-dev`)
- Beast connection: `nckrtl@192.168.6.20`
- Solo terminal: project `69`, process `2350`
- Candidate sync: `composer e2e:incus -- --sync --id=dev-dfbb7c --json`

The operator source mount had the same hashes as the committed candidate:

```text
c642f798d71fdf76968a21283c91a22b41584667eb68b40195a35e243de3d74d  DoctorOutcomeReconciler.php
0f8da38c0b73a458f09e9619f6bd12cf98f32d59506d5ae8a7bffe6cee2aaa52  DoctorIssueResolutionId.php
f58a7c05aee48c8470f19f8955153aa27921d00f031d995e17585a1e5e6ee22a  DoctorReportRunner.php
```

The proof added one disposable orphan DNS record to the retained gateway. It
then ran this command from the source-mounted operator checkout:

```text
orbit doctor --node=gateway --family=node --key=node.dns_mapping_mismatch --restore --json
orbit doctor --node=gateway --family=node --key=node.dns_mapping_mismatch --json
```

The restore returned one completed `node.dns_mapping_mismatch` Doctor action.
Doctor converged in one pass with `fixed=1`, `failed=0`, and zero remaining
issues. The final fresh probe was healthy with zero issues. Both commands
exited 0. Doctor removed the disposable record during convergence.

`composer e2e:incus -- --stop --id=dev-dfbb7c --json` then released the
operator, gateway, and app-dev instances successfully.
