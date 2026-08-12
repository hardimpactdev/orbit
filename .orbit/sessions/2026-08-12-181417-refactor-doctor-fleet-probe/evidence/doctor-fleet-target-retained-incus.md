# Doctor fleet target retained Incus proof

- Candidate: `01f598f5b4ce33d74a63384776158c43b9f0b1d5`
- Venue: `retained-incus`
- Environment: `dev-fixture`
- Topology: `dev-263f9f` (`operator_gateway_app-dev`)
- Incus host: Beast over LAN at `192.168.6.20`
- SSH user: `nckrtl`
- Solo project: `49`
- Solo terminal: `Doctor fleet target retained Incus proof` (`2314`)
- Command: `orbit doctor --all --json` twice from the operator
- Expected: Both runs return the same ordered fleet report. The fixture can exit with known drift.
- Observed: Both runs exited `1` with `drift_detected`. Both outputs were 6,915 bytes and had the same SHA-256, `2e4ccfefaa8fcc45b0f79c43ca73a2036d736c83e82808b3cb46f08946917553`.
- Result: passed

The gateway checkout matched the candidate:

```text
4bb9199a057dfddb1e61ed5c2e9d6923feb1e44a90566e735821d3c7741a8f8d  DoctorFleetTargetProbe.php
7573fad05e2358a390f467803d031735a8cdc1480f4ecc3f5209ef3f091fc041  DoctorReportRunner.php
```

The non-zero exit is expected fixture drift. It is not a command, transport,
or report failure.
