# AI-router source retirement and collector boundary

The native parent composes `removeAiRouterSource(transport)({retired}, signal)`
from `operations/ai-router/source.ts`, or a placed
`@nckrtl/ai-router-source.remove` step with `writes: true`. `retired: false`
selects `/var/lib/orbit/ai-router`; `true` selects `/var/lib/orbit/proxycli`. No
caller can supply a path. Missing source is unchanged. Symlinked or
non-directory targets and ancestors refuse removal. Unrelated directories and
targets of descendant symlinks remain untouched.

The helper runs fixed system code through the canonical Node runner. It never
calls an agent action, Gateway callback, child Operation or nested workflow. The
owning lifecycle must compose it after its runtime has stopped using that
source. This lower does not authorize teardown or prove collector publication.
The agent and its published pin remain frozen.

The current `ai-router-collector` daemon is **not a pure observer**. In addition
to collection and read routes, its authenticated PATCH/PUT account routes call
`Collector::toggle`, which PATCHes upstream `/auth-files/status` and updates the
cached account state. Therefore the daemon as written does not qualify for the
observer-only exception in PLAN. Its mutation path must move to a native step;
only a separated read/collection daemon may retain that exception. This source
port changes no frozen agent code and makes no claim that the collector has
already been separated or safely replaced on a live Node.
