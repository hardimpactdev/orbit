# Doctor inventory ordering retained Incus proof

- Candidate: `528f4bb8e9318b566b1e86b471d21d3ab2e2e1b7`
- Venue: `retained-incus`
- Environment: `dev-fixture`
- Topology: `dev-bc5b3d` (`operator_gateway_app-dev`)
- Beast connection: `ssh -tt -o HostName=192.168.6.20 beast`
- Operator checkout: `/home/orbit/orbit-run`
- Launcher: `/home/orbit/orbit-run/apps/cli/orbit`

The gateway fixture received two expected tools on `app-dev-1`. They were
inserted in this database row order:

```text
8|zzz-orbit-order-proof
9|aaa-orbit-order-proof
```

The agent-owned Solo terminal then ran this command twice from the operator
checkout:

```text
orbit doctor --node=app-dev-1 --family=tool --json
```

Both commands returned exit code `1`, as expected for detected drift. The proof
tool names appeared in this order in each Doctor result:

```text
first_order:
zzz-orbit-order-proof
zzz-orbit-order-proof
aaa-orbit-order-proof
aaa-orbit-order-proof
second_order:
zzz-orbit-order-proof
zzz-orbit-order-proof
aaa-orbit-order-proof
aaa-orbit-order-proof
same_order=0
```

Each tool produces two missing-tool findings. Both runs preserved the database
row ID order, including the intentionally reverse-alphabetical names. The
comparison exit code `0` confirms that both extracted issue sequences matched.
