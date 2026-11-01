# Certificate removal account-home proof

The fixture uses the public `certificate.remove` envelope from `CertificateProjection` and `CertificateOperationsTest`, with only environment-specific Node, SSH and identity values bound by the shared runner. It creates public scope markers on the disposable target, then changes that user's NSS home spelling to the same directory with a trailing `/.`. The physical home and SSH authorized keys stay in place.

The expected outcome is a failed native `remove` action that emits `certificate.account_invalid`, with both admitted and unrelated scopes intact. The fixed `expect.outputs` selector requires that exact public refusal from `certificate/remove`; an earlier SSH, copy or Caddy failure must fail the proof. The parity script checks only the NSS spelling and retained public markers. It does not infer the refusal from preserved files.

Pinned e459 can omit outputs from failed steps. Until the shared F1 wrapper and proof runner can expose and check the selected public refusal, this fixture must fail closed and the behavior-changing PR stays draft. No successful receipt is claimed. Do not weaken the output match to an arbitrary failed root.

The kind budget is 60 seconds. Its unchanged workflow inherits the 600-second queue default; the fixture records that exact value. Source measurements are local fixtures, not a production p95. The five executable phase budgets total 50 seconds. Setup and VM lifecycle are outside the kind run measurement. `target-setup.sh` runs only inside the shared runner's disposable target, never on a fleet Node.
