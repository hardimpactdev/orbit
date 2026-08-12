# Doctor database-connection restore proof

- Candidate: `f6d9cd512382fdf4e45ec4e0def7cff54e157128`
- Venue: retained Incus topology `dev-9f7aa9` on Beast
- Beast transport: `nckrtl@192.168.6.20` (LAN)
- Runtime checkout: `/home/orbit/orbit-run`
- Runtime file SHA-256: `e9461258f7ee55e3f1f4eedb7bf15011dd458b256e67eb1295d7cb1320e4086d`
- Command: `orbit doctor --node=app-dev-1 --family=database_connection --restore --json`
- Expected: restore the registered instance database settings, preserve the target identity in each action, and converge.
- Observed: the initial verify reported `database_connection.env_missing` and `database_connection.env_mismatch`. Restore completed both actions with `target_type=instance`, `target_id=1`, and `env_prefix=DB`. It converged after one pass. The next verify was healthy with zero issues and zero actions. The node `.env` contained the expected PostgreSQL settings.
- Result: passed
- Cleanup: Solo terminal `2322` closed. Topology `dev-9f7aa9` released and all three instances reaped.
