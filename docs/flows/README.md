# Flow charts: one chart per Orbit operation kind

Each Orbit operation kind has one sequence chart in `docs/flows/<kind>.mmd`. The chart states the intent of the kind: how the command flows between the CLI or MCP, the Gateway, Swamp, the target Node, and other Nodes. The command's docs page shows it with a step-detail panel.

Read the kind's chart first. Any change to a command's flow starts by updating its chart to the ideal flow; the code follows the chart. A chart shows the **target design**, not old code where it differs. Name what differs in your report, not in the chart.

## Target architecture

These rules were decided in October and November 2026. Every chart follows them.

- The **Gateway** (Laravel API) is the trusted center. It never executes work.
- The Gateway validates the request and records the intent as a task (ADR 0244; today an Operation).
- The Gateway passes the full intent as Swamp workflow inputs. Secrets travel as Swamp vault key names, never as values.
- The Gateway projects the result.
- The Gateway has two execution primitives: minting GitHub tokens and signing certificates. The App key and the Orbit CA stay in the Gateway.
- A step that needs a primitive calls the Gateway for it. This is the only allowed call from a step to the Gateway.
Swamp and the Node agent split the rest:

- **Swamp** does all execution. Only Node 209 runs Swamp (serve plus workers). There are no per-Node workers.
- Swamp reaches every other Node with the official `@swamp/ssh` behavior from 209. Prefer official Swamp behaviors (`@swamp/ssh`, `@swamp/cfgmgmt/*`) over custom ones.
- Steps never call the Gateway back otherwise. The Gateway reads results through its shared run reader (terminal-event projection).
- **orbit-agent** only observes. It acts on nothing.
- **Schedules** use native Swamp schedules: no systemd timers or units on Nodes (ADR 0244).
- `firewall.inspect` is being removed and folded into `doctor`.

## Decisions that override everything above

Read the migration's `DECISIONS.md` (on mini: `~/migration-dashboard/flows/DECISIONS.md`) before drafting. Where `DECISIONS.md` and this page differ, `DECISIONS.md` wins. It holds the orchestrator's decisions (R1–R4 and numbered ones). Examples:

- GitHub tokens are minted at admission and passed through the vault. There is no mint callback.
- Certificate signing is the only step→Gateway call.
- Record-only changes start no Swamp run.
- The Gateway writes state only from the terminal result.
- Every kind uses the `process.stop` shape.

The proving order is in `~/migration-dashboard/data/proof-order.json` on mini (waves, topology per wave).

## Words

Use Orbit's glossary in `docs/concepts.md` and the domain pages it links. Examples: "access grant" (a Node's permission to act on another Node), "Placement", "Node agent", "Instance", "Route", "Process", "Schedule", "Database server". Don't invent synonyms such as "you may use the Node".

Step 1 shows the real CLI call with its flags, taken from `docs/cli/*.mdx`. The command and its name argument go on the main line, the flags on the detail line (for example `firewall:deny 25` / `--node=7`). Never a JSON-style `{node: 7}` blob. Its explanation starts with a `Command:` block holding one full example and the syntax.

## Sources

Read these sources; don't guess.

- ADRs: `docs/decisions/0238`, `0243`, `0244`, plus any ADR about the kind.
- The kind's declaration: `apps/gateway/app/Domain/*/direct-operations.php`.
- Its Gateway Action: `apps/gateway/app/Actions/**`.
- Its Swamp workflow: `swamp/workflows/workflow-*.yaml`.
- Docs under `docs/`.
- Recall (`search`, `decisions`, `get`): decisions about the kind and its family.
- The dashboard data on mini, `~/migration-dashboard/data/commands.json`: each kind's workflow, status, and notes.

Outside a checkout, read the repository with `gh api`, for example `timeout 60 gh api repos/hardimpactdev/orbit/contents/<path> --jq .content | base64 -d`.

## Format

A small renderer parses this subset, so follow it exactly. Use `schedule.enable.mmd`, `instance.create.mmd`, and `route.create.mmd` as models.

### File

The repository is the source. Edit charts here, never in a copy. A chart Nick approved carries `%% status: reviewed`.

### Header lines

The header lines come in this order:

1. `sequenceDiagram`
2. A `%%` comment naming the design source (an ADR or decision id).
3. `  %% status: draft` or `  %% status: reviewed`
4. Optionally `  %% command: <cli command>`, which links the chart to its docs page.
5. `  autonumber`

### Lanes

Each lane is one `participant` line. An optional `%% lane <ID> icon=<terminal|gateway|workflow|server> pill=<caller|intent|executes|target> tone=<gray|blue|green|amber|purple>` comment styles it. The default lanes are:

- `CLI as CLI / MCP<br/>orbit · MCP tools`
- `GW as Gateway<br/>Laravel API`
- `SW as Swamp<br/>serve on Node 209`
- `N as Target Node<br/>reached over ssh`

Add a lane only when it really acts. Use at most 5 lanes, with `tone=purple` for an extra Node. A lane title has at most 14 characters; a subtitle has at most 18. A kind with no CLI caller, such as an automatic or scheduled one, starts from the Gateway or Swamp lane and drops the CLI lane.

### Syntax

Use only these lines:

- `participant X as Title<br/>sub`
- `A->>B: text` (call)
- `A-->>B: text` (reply)
- `A--xB: text` (failure)
- `Note over X: text`
- `alt label` / `else label` / `end`, `loop label` / `end`, `opt label` / `end`

`%%>` lines below a step hold its detail. The step-detail panel shows them: a line ending in `:` is a heading, and `- ` lines are its bullets.

### Text

A message or note has one main line. It may add `<br/>` and one mono detail line: a command, behavior, workflow, or field. The main line has at most 26 characters and the detail line at most 34. Use plain words and the active voice.

### Structure

Every chart shows the whole flow of the command, start to end. Never leave out common steps (Nick, 2026-11-01).

1. The happy path comes first.
2. One `alt`/`else` shows the main failure.
3. The flow covers the CLI request, admission, run start, the reply, every Swamp and Node step, and the result.
4. An asynchronous kind ends with the CLI polling loop (`operation:show`).
5. Use 8–14 numbered steps. Merge trivial ones; fewer is better.
6. Use example ids only (operation #5130, instance 42). Never show secrets.

## Checks

Run `bin/check-flows` from the repository root before you report. CI runs it too, and it also checks two lists:

- `pending.txt` names operation kinds that have no chart yet. Every other kind needs one. The list may only shrink.
- `migrated.txt` names kinds whose Swamp workflow is native. Their chart step labels must equal the workflow's step names.

## Report

Report at most 120 words per kind:

1. The file path.
2. The sources you relied on.
3. Assumptions and open questions where the target is not decided.
4. Where today's code differs from the chart.
