---
name: flow-tighten
description: Use when designing a new Orbit command or changing one, and when reviewing or drafting its flow chart (docs/flows/<kind>.mmd), to make the flow as short and efficient as it can be. Also use when asked to tighten, simplify or optimize a command's flow.
---

# Tighten a flow chart

Every Orbit command is designed as a flow chart first, and the code follows the chart. A chart can look complete and still do too much. Use this skill when you design a new command, and when you review an existing chart. It finds the steps a flow can lose or merge, and proposes the shorter ideal flow. The chart rules in [docs/flows/README.md](../../../docs/flows/README.md) still apply. This skill adds the efficiency rules. For the command's CLI input and output, use [`designing-cli-commands`](../designing-cli-commands/SKILL.md).

## What a good flow looks like

1. **Every step earns its place.** For each step you can say why it must happen in this command, now, on this Node. If you can't, remove it.
2. **Work belongs to the command whose lifecycle it follows.** A one-time setup moves to the command that creates the thing. Example: Caddy access to an Instance's web root belongs to `instance:create`, not to every `route:create`.
3. **Don't redo what Orbit or Swamp already does.** Before keeping a step, check whether a default already covers it. Examples: private DNS answers a whole TLD, so a domain inside the TLD needs no record: every DNS step sits in an `opt domain outside the TLD`, or its explanation says why the name needs a record. Serve hot-reloads, so it needs no restart. The Gateway already reads the terminal result, so it needs no second run to record it.
4. **No detours or empty runs.** A record-only change starts no run. A step never calls the Gateway, except for certificate signing. The Gateway records state from the terminal result. A run whose only job is to report back is removed. Example: `instance.policy_event`.
5. **Read-only commands answer from the Gateway.** Lists and shows (Nodes, Routes, Instances on a Node, installed Tools) read the Gateway's recorded state. Assume it is current: Node agents report changes to the Gateway, and runs record their results. A read starts no run and contacts no Node.
6. **Same Node, back to back: one step.** Merge consecutive steps on one Node when nothing else (a reply, another Node, a different failure handling) sits between them. Example: install the certificate and publish the site.
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
22. **Swamp-native Node work, no shipped scripts.** Node work uses official `@swamp/cfgmgmt` behaviors: `systemd`, `file`, `docker_container`, `docker_image`, `certificate`, `firewall`, `apt`, `git`, `user` and more. A behavior's `check` also serves as the end check. A single command runs directly as an `@swamp/ssh exec` argument list. A script file is allowed only for a gap no official behavior covers, with the reason in the extension's README. No step copies scripts to a Node.

## Swamp gotchas

Swamp's own skill (`swamp`, installed by `swamp repo upgrade`) explains how to build a workflow. These points change the design:

- A step that changes a Node over SSH declares `writes: true`. Without it, Swamp can run the step again when its worker drops.
- A step that waits for a behavior another step holds fails after 60 seconds (`SWAMP_LOCK_TIMEOUT_MS`). Keep steps on one behavior short, or give long work its own behavior name.
- Workflow inputs are recorded in the run. Pass vault key names, never secrets.
- Nest a workflow only for a typed boundary or its own schedule, never just to order steps.
- Create a workflow with `swamp workflow create`, never by writing its id by hand.

## The loop

1. For a new command, draft the chart by the README rules first. Then read the chart and the kind's live facts: its workflow in `swamp/workflows/`, its Gateway declaration, and recent runs (`operation-list --kind <kind>`). Use `gh api` only; never `git clone` or `git fetch`.
2. Walk the steps in order and test each one against rules 1–22. Note the step number, the rule and the change.
3. Write the proposal: each change with its step numbers and the reason in one line. Add the step count of the common path before and after, and what you keep on purpose and why.
4. Ask before applying to a reviewed chart. Open the chart in Nick's browser tab (`?flow=<kind>` on the migration dashboard) and explain by step number. Change one chart at a time.
5. Apply the agreed changes:
   - a step that moves to another command is added to that command's chart in the same PR;
   - run `bin/check-flows`;
   - reload the chart in the tab;
   - publish one PR.
6. A rule that came out of the review goes into this skill, so the next chart benefits.
