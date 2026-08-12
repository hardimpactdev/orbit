# Doctor Tool Family Retained Incus Proof

- Candidate: `577ff62df64e7e8c47adb394744c4c73a9764128`
- Venue: retained-incus
- Environment: dev-fixture
- Topology: `dev-0f28da`
- Beast connection: LAN SSH with `HostName=192.168.6.20`
- Operator instance: `orbit-e2e-dev-0f28da-operator`
- Command: `orbit doctor --node=app-dev-1 --family=tool --json`
- First exit: 0
- Second exit: 0
- Output comparison: exact byte match
- First SHA-256: `a86ee0ee2bc90011325b1f6e625fdd6b2f7b2b60052419b608d557f1f9a4c2a6`
- Second SHA-256: `a86ee0ee2bc90011325b1f6e625fdd6b2f7b2b60052419b608d557f1f9a4c2a6`
- Result: healthy response with zero issues and zero actions on both runs

The proof used the retained Incus topology command lane. No human-only
`composer test:e2e*` lane was run.
