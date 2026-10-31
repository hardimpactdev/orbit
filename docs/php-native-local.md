# Native PHP lower APIs

These modules run fixed local system programs from Swamp. They do not admit or
project Operations, start workflows, call the agent, or choose a Node. Parent
workflows own placement, immutable inputs and cancellation.

- `operations/php/build_local.ts`: `installPhpBuild(input, signal?, runner?)`.
  Pass the admitted digest-pinned recipe plan. Package-only preparation uses its
  existing `fpm: "none"` plan; the caller does not invent package policy.
- `profile_local.ts`: `configurePhpRuntime(input, signal?, runner?)` publishes
  the admitted FPM runtime module.
- `shared_local.ts`: `convergeSharedPools(input, signal?, runner?)` publishes
  owned shared pools.
- `runtime_local.ts`: `convergeProductionRuntime(input, signal?, runner?)`,
  `inspectProductionPool(identity, signal?, runner?)`,
  `removeProductionRuntime(identity, signal?, runner?)`, and
  `refreshProductionCache(input, signal?, runner?)` manage one admitted
  production runtime. Monitor mode changes the owned monitoring pool; inspection
  is read-only.

Configuration and private inputs travel only on stdin to fixed sudo/Python
programs. Results contain bounded state facts. Package effects preserve prior
packages and service state for rollback; invalid FPM masters defer activation.
Ownership markers, generated paths and fingerprints prevent adoption of foreign
runtime files. Existing `orbit-agent` code remains frozen.

Wrappers export their typed inputs, results and injectable runners for parent
tests. `runtime_local.ts` also exports `ProductionRuntimeInputSchema` and
`ProductionCacheInputSchema`. Parent admission schemas compose these canonical
schemas rather than copying their validation rules. Sandbox tests use real files
and sockets with fake package/service commands. No live rollout or parent-kind
cutover is claimed by this lower layer.
