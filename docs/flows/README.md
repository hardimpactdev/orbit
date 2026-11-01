# Flow charts: one chart per Orbit operation kind

Each Orbit operation kind has one sequence chart in `docs/flows/<kind>.mmd`. The chart states the intent of the kind: how the command flows between the CLI or MCP, the Gateway, Swamp, the target Node, and other Nodes. The docs build shows it with a step-detail panel under each `orbit <command>` heading whose Gateway route starts the kind (`#[StartsOperation]`). A chart whose kind no CLI command starts, such as a child or internal kind, appears on no page.

Read the kind's chart first. Any change to a command's flow starts by updating its chart to the ideal flow; the code follows the chart. A chart shows the **target design**, not old code where it differs. Name what differs in your report, not in the chart.

Edit charts here, never in a copy. A chart-only change arrives as a pull request that touches only `docs/flows/`. A chart Nick approved carries `%% status: reviewed`.

## Design rules

These rules come from the October and November 2026 decisions and from Nick's chart reviews. Every chart follows them.

**The Gateway never executes work.** It checks the request, records the intent as a task (ADR 0244; today an Operation), and takes a lock per target. It passes the full intent as Swamp workflow inputs and records state only from the terminal result (R2).

**Node work follows one shape (R4).** The Gateway starts one Swamp run on Node 209 and replies 202. Swamp reaches Nodes over `@swamp/ssh`, with official `@swamp/cfgmgmt/*` behaviors where they fit. A check step confirms the end state. There are no per-Node workers.

**Steps never call the Gateway**, except for certificate signing. GitHub tokens are minted at admission. Secrets go Gateway → vault → step as vault key names, never values (R3).

**Record-only changes start no run (R1).** A change that touches no Node is written by the Gateway and answered at once.

**Read-only commands use the Node agent.** A command that only reads a Node answers from the Node agent's latest reports: no task, no Swamp run, no SSH. Stale reports give "can't verify". The Node agent observes only; it acts on nothing.

**Failure keeps records and allows a rerun.** The record is marked failed, not deleted. Say what stays on the Node (no rollback unless needed) and that a rerun is safe.

**Steps of other commands aren't kinds.** A former child kind becomes a step in its parent's workflow. Chart it as part of the parent, and report it as "merge into <parent>".

**Leftovers are removed.** A kind for machinery that Orbit has removed gets no chart, for example per-Node workers, one-off migrations, or duplicated work. Report them as "remove", with the reason.

**Prefer the gentle action.** Reload over restart. Wait briefly for a busy lock within the time budget. Clean up harmless leftovers on a best-effort basis.

### Decided by Nick

Nick decided these points in chart reviews:

- `doctor` and `tool:adopt` read Node agent state.
- `instance:create` renders `.env` from the Project env template and starts development Processes.
- `firewall` takes `<port>` plus `--label`, and it has no allowlist.
- `firewall` refuses exact conflicts. Narrower rules go first, and operator rules sit above managed rules.
- `tool.manager_converge` is removed. Its callers use the shared Tool Manager setup step.

For Schedules:

- Schedules are native Swamp schedules, except production per-minute Laravel schedulers.
- `schedule:enable` and `schedule:disable` publish or remove the trigger. `schedule:run` fires once as a task.
- `schedule:destroy` removes the trigger, then the record.

Step labels become the progress names, so keep them stable and meaningful.

Still open for Nick: the DNS hold for Routes, `instance:smoke`, and live `node:role:relocate`. Chart these with a `Note` that says "open: Nick decides", and list them in your report.

The migration's `DECISIONS.md` (on mini: `~/migration-dashboard/flows/DECISIONS.md`) holds the orchestrator's decisions. Where it differs from this page, `DECISIONS.md` wins. The proving order is in `~/migration-dashboard/data/proof-order.json` on mini.

## Sources

Read these sources; don't guess.

- `docs/cli/*.mdx`, `docs/reference/*.md`, and the ADRs about the kind (start with 0238, 0243, 0244).
- The kind's declaration: `apps/gateway/app/Domain/*/direct-operations.php`.
- Its Gateway Action: `apps/gateway/app/Actions/**`.
- Its Swamp workflow: `swamp/workflows/workflow-*.yaml`.
- Recall (`search`, `decisions`): decisions about the kind and its family.

## Reference charts

Copy the style of these reviewed charts exactly:

- `firewall.allow`: a plain Node change. It is the best model.
- `tool.install`: optional steps (`opt`) and per Tool Manager blocks.
- `tool.remove`: a record-only branch versus a Node-work branch (nested `alt`).
- `doctor.inspect` and `tool.adopt`: read-only through the Node agent, with no Swamp lane.
- `metrics.exporter_enable`: a second target Node (purple lane).
- `instance.create`: a long create flow.

## Format

A small renderer parses this subset, so follow it exactly.

### Header and lanes

The header lines come in this order: `sequenceDiagram`, a `%%` comment naming the design source, `%% status: draft`, `%% command: <cli command>`, and `autonumber`. The lane options follow.

Each lane is one `participant` line. A `%% lane <ID> icon=<terminal|gateway|workflow|server> pill=<caller|intent|executes|target|observer> tone=<gray|blue|green|amber|purple>` comment styles it. The default lanes are:

- `CLI as CLI / MCP<br/>orbit · MCP tools`
- `GW as Gateway<br/>Laravel API`
- `SW as Swamp<br/>serve on Node 209`
- `N as Target Node<br/>reached over ssh`
- For a read-only command, the Node agent replaces Swamp and the target: title `Node agent`, subtitle `orbit-agent`, `icon=server pill=observer tone=amber`.

Add a lane only when it really acts. Use at most 5 lanes, with `tone=purple` for an extra Node. A kind with no CLI caller, such as an automatic or scheduled one, starts from the Gateway or Swamp lane.

### Lines

Use only these lines:

- `participant X as Title<br/>sub`
- `A->>B: text` (call), `A-->>B: text` (reply), `A--xB: text` (failure)
- `Note over X: text`
- `alt label` / `else label` / `end`, `loop label` / `end`, `opt label` / `end`

A message or note has one main line of at most 26 characters. It may add `<br/>` and one mono detail line of at most 34 characters: a command, behavior, workflow, or field. A lane title has at most 14 characters and a subtitle at most 18.

### Step explanations

Every step has `%%>` lines directly under it, which the step-detail panel shows. They are labelled blocks: a short line ending in `:` is a heading, then `- ` bullets. Write no prose paragraphs. Common headings are Command, Input, Requirements, Result, What it does, Why, How, Runs as, Inputs, Returns, When, Not included, On the Node, Retry, and Per Tool Manager. An outcome block starts with `Result:`.

### Content

Step 1 is the real CLI call from `docs/cli/*.mdx`. The command and its argument go on the main line, the flags on the detail line. Its explanation starts with a `Command:` block: one full example, then `syntax: …`. Never write a JSON-like `{node: 7}`.

Show the whole flow: the CLI request, the Gateway check, run start, the 202, every Swamp and Node step, the result, the main failure in one `alt`/`else`, and the `operation:show` polling loop when the kind is asynchronous. Never leave out common steps. Use 8–14 numbered steps.

Use the Orbit glossary in `docs/concepts.md`: access grant, Placement, Node agent, Instance, Route, Process, Schedule, Tool, Tool Manager, Database server. Write "your Node has an access grant to Node 12", never "you may use the Node". Use example ids (Node 12, task #5140), keep them consistent within a chart, and never show secrets.

## Checks

Run `bin/check-flows` from the repository root before you report. CI runs it in the Docs build. It checks the format above and two lists:

- `pending.txt` names operation kinds that have no chart yet. A pull request that adds a kind's chart removes its line. The list may only shrink.
- `migrated.txt` names kinds whose Swamp workflow follows its chart. The labels of the Swamp lane's calls and notes must equal the workflow's step names.

The workflow of a kind is the one that names the kind in its `kind` input. `bin/check-operation-kinds`, in Generated contracts, requires a chart or a `pending.txt` line for every kind in the Gateway registry.

## Report

Report at most 80 words per kind:

- the file;
- what changed against the existing chart, if any;
- the design choices you made by these rules;
- anything that needs Nick (open points only), or "merge into X" or "remove".
