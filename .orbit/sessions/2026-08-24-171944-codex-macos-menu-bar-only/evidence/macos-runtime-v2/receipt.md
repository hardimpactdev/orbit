# macOS menu-bar runtime receipt

- Candidate: `8d7e13e2db0fe16ba8ace88a2f3762596a307683`
- Host: `nick.local`
- Runtime: `Darwin 27.0.0 arm64`, macOS `27.0` (`26A5416b`)
- Bundle: `apps/macos/target/debug/bundle/macos/Orbit Desktop.app`
- Binary SHA-256: `b340ea8649ebb3b88a9dd872f6e83d363ed22f614263b0a651655927c48cb501`

## Visible application contract

The exact runtime source bundle ran as PID `47172`. The final candidate differs
from that runtime source only by Mago formatting in a gateway PHP test; its
`apps/macos` tree is byte-identical. macOS Accessibility reported
`background only=true`, zero windows, and two menu bars. Its status menu
contained `Open Orbit`, `Refresh`, `Restart Orbit`, and `Quit Orbit`. Clicking
`Open Orbit` opened Google Chrome at `https://app.orbit/`.

## Failed Quit contract

The first Quit attempt met a genuine Docker discovery failure. The candidate
remained active. Its menu showed `Agent: Quit failed` and
`Quit failed: docker discovery failed`. Clicking `Refresh` and reopening the
menu preserved both messages. The crash and update watcher tests prove that
watchers pause during the attempt, resume after this failure, and exit only
after explicit exit.

The first fixture design used PATH shims, but the app correctly prepended its
safe system path. The attempt therefore stopped three real Orbit launchd jobs
before Docker failed. It did not stop any real Docker container or the existing
Agent. After proof, all three original jobs were bootstrapped from their
existing plists. `https://orbit.nmbp` and its Vite endpoint both returned HTTP
200, the seven original managed containers remained running, and the existing
Agent remained PID `24828`.

## Successful Quit contract

The corrected proof used a real temporary launchd job named
`dev.hardimpact.orbit.fixture` and a Docker-compatible API isolated at
`127.0.0.1:24751`. The exact candidate bundle ran as PID `48635` with only its
Docker client directed to that endpoint. Clicking `Quit Orbit`:

1. removed the temporary launchd job;
2. discovered the labeled fixture container;
3. stopped that fixture container;
4. rediscovered both providers as empty; and
5. exited the candidate.

The Docker API retained this command sequence:

```text
HEAD /_ping
GET /v1.44/containers/json?filters=...
HEAD /_ping
POST /v1.44/containers/orbit-fixtur/stop
HEAD /_ping
GET /v1.44/containers/json?filters=...
```

The Rust shutdown-order tests prove that a supervised Agent is stopped after
launchd and Docker work. This host had an existing non-candidate Agent listener,
so the isolated candidate reported that ownership conflict and did not stop the
unrelated listener.

The installed Orbit app was restored as PID `49799`. The final host topology
matched the pre-proof topology: three original Orbit launchd jobs, seven real
managed containers, and Agent PID `24828`.

Retained topology proof: passed - host topology kind=host-macos; host=nick.local; os=Darwin/macOS 27.0; command=exact candidate Accessibility inspection plus failed-Quit persistence and corrected isolated successful Quit; evidence=.orbit/evidence/macos-runtime-v2/receipt.md
