# Plain VM worker retirement

TaskVM enrollment no longer issues a guest worker token, writes worker configuration or installs/starts a guest worker. The TaskVM-only worker helpers are deleted. TaskVM declarations and private bindings no longer admit worker credentials; Tasks inherits that declaration change. Old saved `issue-worker`, `configure-worker` and `worker-ready` stages refuse `operation.kind_unavailable` before private reads or effects. They do not become successful no-ops. Existing source/bootstrap/runtime parents still need their complete209 composition.

The expanded-topology exception is separate: the sandbox's Gateway VM may own its own serve and one worker using Nick's existing collective token. The generic installer, topology seed and E2E topology harness remain intact. The removed outer TaskVM worker path had no topology predicate and did not implement that sandbox setup. No topology runtime readiness is claimed.
