---
name: flow-tighten
description: Use when designing a new Orbit command or changing one, and when reviewing or drafting its flow chart under docs/cli/, to make the flow as short and efficient as it can be. Also use when asked to tighten, simplify or optimize a command's flow.
---

# Tighten a flow chart

Every Orbit command is designed as a flow chart first, and the code follows the chart. A chart can look complete and still do too much. Use this skill when you design a new command, and when you review an existing chart. It finds the steps a flow can lose or merge, and proposes the shorter ideal flow. The chart rules in [docs/cli/README.md](../../../docs/cli/README.md) still apply. This skill adds the efficiency rules. For the command's CLI input and output, use [`designing-cli-commands`](../designing-cli-commands/SKILL.md).

## What a good flow looks like

1. **Every step earns its place.** For each step you can say why it must happen in this command, now, on this Node. If you can't, remove it.
2. **Work belongs to the command whose lifecycle it follows.** A one-time setup moves to the command that creates the thing. Example: Caddy access to an Instance's web root belongs to `instance:create`, not to every `route:create`.
3. **Don't redo what Orbit or Swamp already does.** Before keeping a step, check whether a default already covers it. Examples: private DNS answers a whole TLD, so a domain inside the TLD needs no record: every DNS step sits in an `opt domain outside the TLD`, or its explanation says why the name needs a record. Serve hot-reloads, so it needs no restart. The Gateway already reads the terminal result, so it needs no second run to record it.
4. **No detours or empty runs.** A record-only change starts no run. A step never calls the Gateway, except for certificate signing. The Gateway records state from the terminal result. A run whose only job is to report back is removed. Example: `instance.policy_event`.
5. **Read-only commands answer from the Gateway.** Lists and shows (Nodes, Routes, Instances on a Node, installed Tools) read the Gateway's recorded state. Assume it is current: Node agents report changes to the Gateway, and runs record their results. A read starts no run and contacts no Node. A write command checks its preconditions against that same recorded state at admission. A run reads a Node only when the fact can change between admission and the step, and the step depends on it. Example: `instance:rename` records the branch the Node agent reported, with no run.
6. **Merge parts of one action.** Consecutive steps on one Node that form one action become one step, for example writing a unit and reloading systemd. Keep separate actions apart when each is a useful progress name or has its own failure, for example "Clone, check out branch" and "Write .env".
7. **The common path is the shortest.** Conditional work sits in an `opt` whose label names the condition, so most runs skip it.
8. **Order for safety.** What makes a thing reachable goes last: DNS after the site serves, unpublish before removing.
9. **One check proves the end state.** Keep one check at the end: a step collects the state, and Swamp's native `assert` step checks it. Fold small read-backs into the step that writes.
10. **Gentle actions.** Reload, not restart. Wait briefly for a busy lock within the time budget. Cleanup of harmless leftovers is best effort.
11. **Behavior names by target.** A behavior is named `<step-family>-<node_id>`, or has a fixed name for a single target. Never name it per run or per task.
12. **Command boundary.** `<noun>:update` changes settings. `<noun>:<feature>:enable|disable` turns an ongoing behavior on or off.
13. **No stale holds.** Check each "open", "held" or "Nick decides" note against live state and current decisions before you keep it.
14. **No report or assert step.** A step fails the run with its own exit code. A run has no script step that reports the result or fails on an error at the end. Swamp's native `assert` step (rule 9) is not such a step. The Gateway maps the failed step and its exit code to `error_code`. A value the Gateway needs comes from the step that produced it.
15. **No input-check step.** The workflow's input schema validates the intent. No step re-reads or re-checks the inputs. A value that steps need, such as a clone URL, is computed by the Gateway and passed in.
16. **No read before a safe write.** Skip a read before a write that is safe to repeat or refuses by itself, such as `docker rm -f` or a push without force. A read that only picks the targets folds into the write step. The one end check stays.
17. **Create and remove mirror each other.** A merge or an `opt` on a create flow applies to its remove and update flows. Example: `route:create` installs the cert and publishes the site in one step, so `route:destroy` withdraws the site and removes the cert in one step.
18. **Parallel by default.** A workflow is a graph, not a script. Steps that don't depend on each other run at the same time. Work on several Nodes is one `forEach` step, not one step per Node. A chart shows such work as one step.
19. **Failure paths are declared.** Cleanup and alerts are steps with `dependsOn` and the condition `failed` or `completed`. Steps don't catch errors in their scripts.
20. **Reruns skip finished work.** A step whose work may already be done carries a `guard`, so a resumed or repeated run skips it.
21. **A chart shows only its own command.** It shows only the branches its own command can take. When commands share a workflow, the other commands' branches belong in their own charts. Example: `process:stop` shows no start-only steps.
22. **Node work: native Swamp or an Ansible phase.** Decide this for each part of the Node work.
    - **Ansible phase:** idempotent Node state with lists (packages, files, folders, users, units, containers, firewall rules) should be an Ansible phase, especially across several Nodes. As a guide, choose it when the work would otherwise be about five or more native steps. The phase is one Swamp step that runs one tagged part of a playbook ([ansible-phase](../ansible-phase/SKILL.md)). The chart shows it as one step, labelled with the phase; its tasks belong in the playbook. Its last phase, `check`, replaces rule 9's native `assert` step.
    - **Native:** Gateway calls such as certificate signing, a single command as an `@swamp/ssh exec` argument list, work that must not run twice (migrations, a release switch), and flows with a budget under about 10 s. Starting `ansible-playbook` alone costs about 2–3 s.
    - Native Node work uses official `@swamp/cfgmgmt` behaviors: `systemd`, `file`, `docker_container`, `docker_image`, `certificate`, `firewall`, `apt`, `git`, `user` and more. A behavior's `check` also serves as the end check; require its reported status to be `compliant`, because a successful check can report drift (#2545).
    - A script file is allowed only for a gap that neither covers, with the reason in the extension's README. No step copies scripts to a Node.

23. **Required parameters make the main path.** The main path is the command with only its required arguments. Each optional parameter appears only in its own `opt --flag` block, with the steps it adds. The main call line shows no optional flags, and no branch reads `alt no --flag … else --flag`. The workflow matches: a step for an optional input is conditional on that input. Example: `instance:register --project=7` checks and records; `opt --setup` and `opt --domain` hold the Node work.
24. **Caller-agnostic.** A chart shows the generic caller (CLI, MCP, API). A client that calls the command, such as Conn, is not drawn in; its steps belong in its own flow.

25. **Minimal charts.** A chart holds the flow steps and the true exceptions only. Show meaningful alternate outcomes, including a successful skip and its reason code, in the visible chart; do not hide them only in the step panel. A step explanation says what the label does not: why, when, what happens on failure. Never restate the label. What a command covers (for example the parts a converge applies) and what a flag means (`--force`) go in the docs page description and option table, not in the chart. A flag that adds no steps, only a different check or a different output (`--json`), gets no `opt` block. Behavior that is the same for every command (prompts for missing input, keyed replay of `--idempotency-key`) is documented once in the CLI standard and the operations reference; the chart gets no `Prompts:` block or replay branch for it, unless a current decision asks otherwise. If an explanation has more than a few bullets, cut it or move it to the docs page.

26. **Installation-independent charts.** Name roles and use placeholders for command inputs and returned IDs. Do not hard-code Node or task IDs, deployment hostnames or an execution host. Keep lifecycle states and implementation details in the step panel when they do not name the action. Concrete usage examples belong on the command's documentation page.

27. **Share flows when only inputs differ.** Reuse one workflow across actions when they follow the same steps and inputs express their differences. Do not duplicate the graph or add wrappers just to give each action its own workflow name. If action-specific conditions create substantial branching or obscure the order, failures or end checks, split the flows. Extract cohesive common phases into native child workflows with explicit input and output contracts. Keep each command's chart specific to that command (rule 21).

28. **Define the state after failure.** Decide during chart review what the command cleans up, restores or retains; do not default every command to rollback or retention.
    - Remove temporary files, credentials and other disposable resources owned by the run after success or failure. Declare cleanup through native dependencies (rule 19).
    - Restore a changed setting only when its prior value is known and restoration will not overwrite a later change. Remove only resources the run owns; never restore a whole Node snapshot to undo one command.
    - Keep a failed target when it is useful for diagnosis or recovery, such as an Instance and its checkout. Record its failed state and retained resources, and define the retry and removal path. Retention must be deliberate, not an untracked leftover.
    - Convergence repairs declared desired state within its ownership scope. It may remove resources explicitly marked for removal; do not assume it discovers and deletes every leftover. A failed target retained for inspection is not an orphan.
    - Order steps that remove access (revoking keys, closing ports) after the checks that need that access, so a failed run stays reachable and its rerun works.
    - Account for partial changes within a failing step, cleanup failure, and interruption. Cleanup must wait until the work it would remove has stopped. Preserve the original failure and expose incomplete cleanup; a worker crash does not prove cleanup ran.
    Show meaningful recovery branches in the chart and document only the consequences the command's user needs. Prove them with [verifying-commands](../verifying-commands/SKILL.md).

29. **A guard refuses by what it protects, not by input shape.** Before you add a refusal, list the ways a caller can reach the same harm with other inputs: a narrower or wider source, a range, another protocol, a CIDR that covers management addresses. If a narrower input can cause the same harm, refuse every input. Example: `firewall:deny` refuses any deny on a protected port, because a `--from` range can cover the Gateway. State what the inverse command does on the same input, for example an allow on a protected port skips as already applied.

## Swamp gotchas

Swamp's own skill (`swamp`, installed by `swamp repo upgrade`) explains how to build a workflow. These points change the design:

- A step that changes a Node over SSH declares `writes: true`. Without it, Swamp can run the step again when its worker drops.
- A step that waits for a behavior another step holds fails after 60 seconds (`SWAMP_LOCK_TIMEOUT_MS`). Keep steps on one behavior short, or give long work its own behavior name.
- Official SSH puts its connection name in an OpenSSH control-socket path. Keep that name short, preferably a short step family plus Node id: a valid 58-character behavior name can exceed Linux’s socket-path limit after SSH adds its suffix. Prove the actual transport; local SSH shims do not exercise this limit.
- Workflow inputs are recorded in the run. Pass vault key names, never secrets.
- Nest a workflow for a cohesive reusable phase with an explicit input and output contract, or for work with its own schedule. Do not nest merely to order steps (rule 27).
- Create a workflow with `swamp workflow create`, never by writing its id by hand.

## The loop

1. For a new command, draft the chart by the README rules first. Then read the chart and the kind's live facts: its workflow in `swamp/workflows/`, its Gateway declaration, and recent runs (`operation-list --kind <kind>`). Use `gh api` only; never `git clone` or `git fetch`.
2. Walk the steps in order and test each one against rules 1–29. Note the step number, the rule and the change.
3. Write the proposal: each change with its step numbers and the reason in one line. Add the step count of the common path before and after, and what you keep on purpose and why.
4. Apply what the rules settle. Ask Nick only about a choice that no rule or Recall decision settles: one plain question, context first, and a rendered chart (a local docs preview, see the README's "Edit, render and preview") explained by step number. Change one chart at a time.
5. Then:
   - a step that moves to another command is added to that command's chart in the same PR;
   - run `bin/check-flows`;
   - check it in the local docs preview;
   - publish one PR.
6. A rule that came out of the review goes into this skill, so the next chart benefits.
