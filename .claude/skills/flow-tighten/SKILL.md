---
name: flow-tighten
description: Use when designing a new Orbit command or changing one, and when reviewing or drafting its flow chart (docs/flows/<kind>.mmd), to make the flow as short and efficient as it can be. Also use when asked to tighten, simplify or optimize a command's flow.
---

# Tighten a flow chart

Every Orbit command is designed as a flow chart first, and the code follows the chart. A chart can look complete and still do too much. Use this skill when you design a new command, and when you review an existing chart. It finds the steps a flow can lose or merge, and proposes the shorter ideal flow. The chart rules in [docs/flows/README.md](../../../docs/flows/README.md) still apply. This skill adds the efficiency rules.

## What a good flow looks like

1. **Every step earns its place.** For each step you can say why it must happen in this command, now, on this Node. If you can't, remove it.
2. **Work belongs to the command whose lifecycle it follows.** A one-time setup moves to the command that creates the thing. Example: Caddy access to an Instance's web root belongs to `instance:create`, not to every `route:create`.
3. **Don't redo what Orbit or Swamp already does.** Before keeping a step, check whether a default already covers it. Examples: private DNS answers a whole TLD, so a domain inside the TLD needs no record. Serve hot-reloads, so it needs no restart. The Gateway already reads the terminal result, so it needs no second run to record it.
4. **No detours or empty runs.** A record-only change starts no run. A step never calls the Gateway, except for certificate signing. The Gateway records state from the terminal result. A run whose only job is to report back is removed. Example: `instance.policy_event`.
5. **Read-only commands answer from the Gateway.** Lists and shows (Nodes, Routes, Instances on a Node, installed Tools) read the Gateway's recorded state. Assume it is current: Node agents report changes to the Gateway, and runs record their results. A read starts no run and contacts no Node.
6. **Same Node, back to back: one step.** Merge consecutive steps on one Node when nothing else (a reply, another Node, a different failure handling) sits between them. Example: install the certificate and publish the site.
7. **The common path is the shortest.** Conditional work sits in an `opt` whose label names the condition, so most runs skip it.
8. **Order for safety.** What makes a thing reachable goes last: DNS after the site serves, unpublish before removing.
9. **One check proves the end state.** Keep one check step at the end. Fold small read-backs into the step that writes.
10. **Gentle actions.** Reload, not restart. Wait briefly for a busy lock within the time budget. Cleanup of harmless leftovers is best effort.
11. **Behavior names by target.** A behavior is named `<step-family>-<node_id>`, or has a fixed name for a single target. Never name it per run or per task.
12. **Command boundary.** `<noun>:update` changes settings. `<noun>:<feature>:enable|disable` turns an ongoing behavior on or off.
13. **No stale holds.** Check each "open", "held" or "Nick decides" note against live state and current decisions before you keep it.

## The loop

1. For a new command, draft the chart by the README rules first. Then read the chart and the kind's live facts: its workflow in `swamp/workflows/`, its Gateway declaration, and recent runs (`operation-list --kind <kind>`). Use `gh api` only; never `git clone` or `git fetch`.
2. Walk the steps in order and test each one against rules 1–13. Note the step number, the rule and the change.
3. Write the proposal: each change with its step numbers and the reason in one line. Add the step count of the common path before and after, and what you keep on purpose and why.
4. Ask before applying to a reviewed chart. Open the chart in Nick's browser tab (`?flow=<kind>` on the migration dashboard) and explain by step number. Change one chart at a time.
5. Apply the agreed changes:
   - a step that moves to another command is added to that command's chart in the same PR;
   - run `bin/check-flows`;
   - reload the chart in the tab;
   - publish one PR.
6. A rule that came out of the review goes into this skill, so the next chart benefits.
