# Schedule run

Use official @swamp/ssh 2026.10.23.1 exec/copy with its normal connection, identity-file and pinned-host defaults. There is no family transport or forwarded environment. Public script metadata uses native additionalFiles.

The execute step overrides only timeoutSec. The Schedule API already accepts up to 86,400 seconds, while official SSH defaults to 300 seconds. Keep the existing domain timeout and its ten-second outer termination margin: timeout sends TERM at the admitted limit and KILL five seconds later. Queueing, concurrency, retention and recovery use native defaults.

The original run callback and system executor are removed. Retained control tests use schedule.enable. Native Schedule run tests cover stdout isolation, pipefail, timeout, context refusal, digest refusal, login shell, and resume after a target directory becomes available. Local fixtures prove DAG and official-extension behavior; they do not prove fleet delivery or sudo policy.
