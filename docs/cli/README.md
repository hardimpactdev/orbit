# CLI pages and flow charts

Each CLI family has a folder, `docs/cli/<family>/`. Its `index.mdx` gives the overview and lists the commands. Each command has its own page, `<command>.mdx`, such as `route/create.mdx` for `orbit route:create`. A page holds, in order: the title and description; the usage (syntax, arguments, options, examples, behavior); `## How it works` with the flow chart; and `## Errors`.

Each Orbit operation kind has one sequence chart. The chart states the intent of the kind: how the command flows between the CLI or MCP, the Gateway, Swamp, the target Node, and other Nodes. The docs build draws it with a step-detail panel where its page places it.

Read the kind's chart first. Any change to a command's flow starts by updating its chart to the ideal flow; the code follows the chart. A chart shows the **target design**, not old code where it differs. Name what differs in your report, not in the chart.

Command verification assets live in [swamp/proof](https://github.com/hardimpactdev/orbit/tree/main/swamp/proof). Review them with the command page and chart. Once a command has an executable verification entrypoint, link to it from its page and chart.

Edit charts here, never in a copy. A command's chart arrives in the one PR of that command change, with its page, cases, code and runnable proof cases; the chart, page and cases are the first commit of the draft PR. A pull request that touches only charts, their placements, and `flows-pending.txt` is for a chart that belongs to no command change, such as a stage chart. A chart whose spec Nick approved carries `%% status: reviewed`.

Only that approval sets it. A spec PR that changes a reviewed chart sets it to `draft` and keeps the other header lines; the approval in the draft sets `reviewed` again.

## Where a chart lives

A chart sits beside its command page, with the same name: `route/create.mmd` beside `route/create.mdx`. Its `%% kind: <kind>` line names the kind; the checks find a kind's chart by that line. The page places it under `## How it works` with `<div className="flow-at" data-chart="route/create"></div>`. A second kind of one command, or a kind that no command starts, gets a chart named after the kind's action, such as `database/write.mmd` or `instance/auto-deploy.mmd`. To add a chart, write it, place it, and remove its kind from `flows-pending.txt`.

To find a kind's chart, run `grep -rl '%% kind: route.create' docs/cli`.

The [command development pipeline](/reference/command-development-pipeline) places its six stage charts, `docs/reference/pipeline/<n>-<stage>.mmd`, with `<div className="flow-at" data-chart="reference/pipeline/<n>-<stage>"></div>`. They follow the same rules and `bin/check-flows` checks them. Charts of internal kinds with no page, such as `tasks/vm-*.mmd`, are linked from the pages that use them.

## Edit, render and preview

**Edit.** A chart is the `.mmd` file next to its page: `docs/cli/<family>/<command>.mdx` and `<command>.mmd`. Change the chart first, then the code. The page places it under `## How it works` with `<div className="flow-at" data-chart="<family>/<command>"></div>`.

**Render.** No image is built. `bin/docs-flows` bundles the placed charts into `docs/flows-data.js`. `docs/flow.js` draws each chart in the browser from its Mermaid text, as SVG with a step panel: click a step or use the arrow keys.

**Preview locally.**

1. Run `composer docs-build` in `apps/docs`. It writes `docs/docs.json`; `bin/docs-flows` writes `docs/flows-data.js`.
2. Run `npx mint dev` in `docs/`, then open `/cli/<family>/<command>`.

**Check.** Run `bin/check-flows`; CI runs it too.

**Publish.** After a green CI run on `main`, `.github/workflows/docs-site.yml` rebuilds the `docs-site` branch. Mintlify deploys that branch to https://orbit.nckrtl.com/docs within about a minute.

## Design rules

These rules come from the October and November 2026 decisions and from Nick's chart reviews. Every chart follows them.

**The Gateway never executes work.** It checks the request, records the intent as a task (ADR 0244; today an Operation), and takes a lock per target. It passes the full intent as Swamp workflow inputs and records state only from the terminal result (R2).

**Node work follows one shape (R4).** The Gateway starts one Swamp run and replies 202. A Swamp worker reaches Nodes over SSH and runs one Ansible phase per logical phase: the playbook reads, changes and checks the machine in one run (rule 22 of the `flow-tighten` skill). A native `@swamp/ssh` or `@swamp/cfgmgmt` step stays only where measured numbers show it beats the phase. There are no per-Node workers.

**Steps call the Gateway only to sign a certificate or to mint a GitHub token** for the step that is about to use it. Admission mints no token for a later step. Secrets go Gateway → vault → step as vault key names, never values (R3).

**Record-only changes start no run (R1).** A change that touches no Node is written by the Gateway and answered at once.

**Read-only commands answer from the Gateway.** Lists and shows (Nodes, Routes, Instances on a Node, installed Tools) read the Gateway's recorded state. Treat it as current: Node agents report changes to the Gateway, and runs record their results. A read starts no task and no Swamp run, and contacts no Node.

**Failure keeps records and allows a rerun.** The record is marked failed, not deleted. Say what stays on the Node (no rollback unless needed) and that a rerun is safe.

**Steps of other commands aren't kinds.** A former child kind becomes a step in its parent's workflow. Chart it as part of the parent, and report it as "merge into <parent>".

**Leftovers are removed.** A kind for machinery that Orbit has removed gets no chart, for example per-Node workers, one-off migrations, or duplicated work. Report them as "remove", with the reason.

**The main path uses only the required parameters.** Draw the command first as it runs with its required arguments alone; that is the happy path. Each optional parameter gets its own `opt --flag` block, labelled with the flag, holding only the steps that flag adds. The main call line shows no optional flags. Never use an `alt no --flag … else --flag` branch: it makes an opt-in path look like the default. Example: `instance:register --project=7` checks and records; `opt --setup` runs the setup steps, `opt --domain` creates the Route. The Swamp workflow follows the same shape: a step that exists only for an optional input is conditional on it.

**A chart is minimal.** It shows the real flow steps and the true exceptions, nothing else. A step explanation says only what the label and detail line do not: why it must happen, when it runs, what happens on failure.

Do not restate the label, and do not list what the command covers, which options mean or what a flag changes: those belong in the description and the option table of the command's docs page. A flag that adds no steps and only changes a check, such as `--force`, gets no `opt` block and no note in the chart. The same holds for a flag that only shapes the output (`--json`).

Behavior that is the same for every command (the `--json` output, prompts for missing input, keyed replay of `--idempotency-key`) is documented once, in the CLI standard and the operations reference. A chart gets no `opt` block, `Prompts:` block or replay branch for it, unless a current decision asks otherwise.

**A chart is caller-agnostic.** The caller is the CLI, MCP or API. A specific client that calls the command, such as Conn, is not drawn in its chart; that client's own steps belong in its own flow.

**A chart applies to any Orbit installation.** Use role names and placeholders such as `PORT`, `NODE` and `TASK`. Do not use concrete Node or task IDs, deployment hostnames or fixed execution hosts. Keep lifecycle states and implementation details in the step panel when they do not name the action. Concrete usage examples belong on the command's documentation page.

**Prefer the gentle action.** Reload over restart. Wait briefly for a busy lock within the time budget. Clean up harmless leftovers on a best-effort basis.

### Decided by Nick

Nick decided these points in chart reviews:

- `doctor` and `tool:adopt` answer from Gateway state that Node agent reports keep current.
- `instance:create` renders `.env` from the Project env template and starts development Processes.
- `firewall` takes `<port>` plus `--label`, and it has no allowlist.
- `firewall` refuses exact conflicts. Narrower rules go first, and operator rules sit above managed rules.
- `tool.manager_converge` is removed. Its callers use the shared Tool Manager setup step.

For Schedules:

- Schedules are native Swamp schedules, except production per-minute Laravel schedulers.
- `schedule:enable` and `schedule:disable` publish or remove the trigger. `schedule:run` fires once as a task.
- `schedule:destroy` removes the trigger, then the record.

Step labels become the progress names, so keep them stable and meaningful.

Still open for Nick: live `node:role:relocate`. Production smoke is removed for now; `instance.smoke.mmd` holds its future design. There is no DNS hold: Swamp reaches every Node, the VPN Node included. Chart these with a `Note` that says "open: Nick decides", and list them in your report.

The migration's `DECISIONS.md` (on mini: `~/migration-dashboard/flows/DECISIONS.md`) holds the orchestrator's decisions. Where it differs from this page, `DECISIONS.md` wins. The proving order is in `~/migration-dashboard/data/proof-order.json` on mini.

## Sources

Read these sources; don't guess.

- `docs/cli/<family>/*.mdx`, `docs/reference/*.md`, and the ADRs about the kind (start with 0238, 0243, 0244).
- The kind's declaration: `apps/gateway/app/Domain/*/direct-operations.php`.
- Its Gateway Action: `apps/gateway/app/Actions/**`.
- Its Swamp workflow: `swamp/workflows/workflow-*.yaml`.
- Recall (`search`, `decisions`): decisions about the kind and its family.

## Reference charts

Copy the style of these reviewed charts exactly. Read them on `main`: a chart that an open spec PR has set back to draft stays the model until that PR merges. A draft sibling or a reference text has no approval; it is input, not a model, and a defect in it is not a precedent.

- `firewall.allow`: a plain Node change. It is the best model.
- `tool.install`: optional steps (`opt`) and per Tool Manager blocks.
- `tool.remove`: a record-only branch versus a Node-work branch (nested `alt`).
- `doctor.inspect` and `tool.adopt`: read-only from Gateway state, with no Swamp lane; the Node agent lane shows how that state stays current.
- `metrics.exporter_enable`: a second target Node (purple lane).
- `instance.create`: a long create flow.

## Format

A small renderer parses this subset, so follow it exactly.

### Header and lanes

The header lines come in this order: `sequenceDiagram`, a `%%` comment naming the design source, `%% status: draft`, `%% command: <cli command>`, `%% kind: <kind>`, and `autonumber`. The lane options follow.

Each lane is one `participant` line. A `%% lane <ID> icon=<terminal|gateway|workflow|server> pill=<caller|intent|executes|target|observer> tone=<gray|blue|green|amber|purple>` comment styles it. The default lanes are:

- `CLI as CLI / MCP<br/>orbit · MCP tools`
- `GW as Gateway<br/>Laravel API`
- `SW as Swamp<br/>workflow execution`
- `N as Target Node<br/>reached over ssh`
- For a read-only command that depends on Node facts, a Node agent lane may show how the Gateway's state stays current: title `Node agent`, subtitle `orbit-agent`, `icon=server pill=observer tone=amber`.

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

Step 1 is the real CLI call from the command page, with placeholders for installation-specific values. The command and its argument go on the main line, the flags on the detail line. Its explanation starts with a `Command:` block: the full command with placeholders, then `syntax: …`. Never write a JSON-like `{node: 7}`.

Show the whole flow: the CLI request, the Gateway check, run start, the 202, every Swamp and Node step, the result, the main failure in one `alt`/`else`, and the `operation:show` polling loop when the kind is asynchronous. Never leave out common steps. Use 8–14 numbered steps.

Use the Orbit glossary in `docs/concepts.md`: access grant, Placement, Node agent, Instance, Route, Process, Schedule, Tool, Tool Manager, Database server. Write "the calling Node has an access grant to the target Node", never "you may use the Node". Use consistent role names and placeholders throughout the chart, and never show secrets.

## Checks

Run `bin/check-flows` from the repository root before you report. CI runs it in the Docs build. It checks the format above, the `%% kind:` lines, the chart placements, and two lists:

- `flows-pending.txt` names operation kinds that have no chart yet. A pull request that adds a kind's chart removes its line. The list may only shrink.
- `flows-migrated.txt` names kinds whose Swamp workflow follows its chart. The labels of the Swamp lane's calls and notes must equal the workflow's step names.

The workflow of a kind is the one that names the kind in its `kind` input. `bin/check-operation-kinds`, in Generated contracts, requires a chart or a `flows-pending.txt` line for every kind in the Gateway registry.

## Report

Report at most 80 words per kind:

- the file;
- what changed against the existing chart, if any;
- the design choices you made by these rules;
- anything that needs Nick (open points only), or "merge into X" or "remove".

## Behavior names

Name a step's behavior after its target, never its run. Swamp reuses a definition when a name repeats, so steps against one target run one at a time and different targets still run in parallel. Each name that a run makes stays behind as an auto-definition, and serve scans them all on each lookup by id ([Lab #4280](https://swamp-club.com/lab/4280)).

| Rule | Requirement |
| --- | --- |
| Target | `<step-family>-<node_id>` or the step's SSH address, for example `route-ssh-publish-<node_id>` |
| One target | A fixed name, for example `gateway-swamp-load` |
| Task VM | SSH steps: `<step-family>-<node_id>`, because the next VM reuses the WireGuard address. Local and data steps: `<step-family>-<address>` |
| Family | Never one name such as `ssh-<node_id>` across step families |
| Type | One behavior type per name; a fixed name that types would share carries its type, as in `result-network-command` |
| Characters | `^[a-z0-9][a-z0-9_-]*$` |
| Dots | A dynamic part that can hold a dot goes inside `(<name>).split(".").join("-")` |
| Per run | `run.id` or `operation_id` only when the workflow deletes the behavior at the end |

In every workflow, write each fixed `behaviorName` as a constant expression, for example `behaviorName: '${{ "ansible-input" }}'`. Swamp walks the child tree before the first step and looks up literal names; a missing definition can make each lookup scan all auto-definitions. The constant expression skips that start-time lookup and resolves to the same name before the step runs.

One behavior has one lock, and differing global arguments rewrite its definition. Steps that share a name take turns. A step that waits for the lock fails after 60 seconds (`SWAMP_LOCK_TIMEOUT_MS`). Swamp refuses a second type under one name, and it refuses a name with other characters when the step runs. With the dot rule, `10.44.0.2` becomes `10-44-0-2`. The run id, the index and integer ids need no wrapping.

The Swamp shape job runs `swamp/bin/lint-behavior-names.ts`. It rejects a workflow that has more per-run names than at the comparison base, any name that Swamp would refuse, and a name used with two behavior types.

## Designing a command

To design a new command or tighten a chart, use the `flow-tighten` skill in `.agents/skills/flow-tighten/SKILL.md`. It holds the rules for a short, efficient flow.
