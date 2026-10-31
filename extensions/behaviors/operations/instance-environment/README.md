# Instance environment

`operation-instance-environment` is one directly admitted
`instance.sync_environment` root. Its exact Node worker applies the stored
environment and optional testing merge. Production then prepares the existing
config cache, refreshes FPM OPcache and restarts opted-in Processes through the
canonical PHP and Process lower actions. Its Gateway callback records the result
and owned key names. No child, executor or receipt endpoint is used.

`sync.ts::synchronizeEnvironment(plan, use, invoke?, signal?)` is also a lower
action for create and clone roots. The public plan contains placement, the
frozen account. The existing shared private callback supplies
`environment_material`: rendered content, stored/previously owned key names and
the optional testing plan. Values never become native arguments, resources or
outputs. A missing, late or repeated callback cannot report success or start
delayed effects.

The worker reads existing workload content locally for the unowned-key check. It
uses the workflow-owned `local.ts` filesystem program through bounded sudo. It
does not invoke the agent. Descriptor-relative access refuses symlinks, checks
file and directory ownership, writes a 0600 candidate, syncs it and atomically
replaces the destination. Git tracking checks run as the admitted account.
Development uses the configured Node user; production uses the frozen Instance
account. The root targets `orbit-node-N` with `labels.node` equal to string `N`.

A failed or uncertain testing write cannot turn an earlier main-file write into
a successful parent. A retry repeats the safe environment writes. A failed
config-cache build preserves the old cache and prevents OPcache refresh and
Process restarts. Applications without a config cache stay uncached. A missing
current release skips runtime refresh. The Gateway only captures intent and
projects the final result. No agent action is invoked.
