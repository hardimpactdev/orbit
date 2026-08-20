# Round 11 Skill Reference TDD

## RED baseline

Solo process 2570 read only the installed Orbit skill at candidate base
`be7e3a1f62fb45c4f196750f4269d34ad5849cbc`.

- It could not construct an analytics `node:new` command because the role and
  `--postgres-process` were absent.
- It said `--template=websocket` provisions the role and could not give the
  live replacement command.
- It exposed inconsistent Redis/Valkey and hosted/workload wording.

## GREEN verification

Fresh Solo process 2571 read only the edited skill and its direct references.
It answered all five questions with high confidence:

- managed Valkey backs websocket scaling;
- operations Reverb does not require Valkey;
- the analytics example includes `--postgres-node`, `--postgres-process`, and
  `--clickhouse-node`;
- websocket node creation fails before effects and the live path is
  `node role:add --valkey-node`;
- the canonical category is “assignable workload roles.”
