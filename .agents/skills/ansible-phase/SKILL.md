---
name: ansible-phase
description: Routes Ansible phase implementation and review to Orbit's execution contract and playbook lessons. Use for changes under swamp/ansible.
---

# Write an Ansible phase

Read the command's chart first. The binding [Swamp shape guide](../../../swamp/docs/swamp-shape.md#ansible-phase-contract) owns machine execution, run files, secrets, callbacks and result transport. The [porting reference](../../../docs/swamp/porting.md) maps a port to its Gateway and workflow integration; [swamp-flow](../swamp-flow/SKILL.md) covers that work.

For the playbook:

1. Agree the intent file, phase tags, public result file and terminal selector with the workflow author. Keep existing Gateway-consumed job and step names unless their declarations change together.
2. Read [Ansible phase lessons](../../../docs/solutions/ansible-phase-lessons.md) for input builders, templates, local delegation and fixtures. Check the command's owning reference for domain policy rather than copying another playbook's exceptions.
3. List new assets in `swamp/manifest.yaml`. Run their template tests and `bin/swamp-shape-gate`, which owns pinned lint, module argument validation and command-output tests. To run one test file, register its cases or set its cost, follow [Swamp tests](../swamp-flow/SKILL.md#swamp-tests).
4. Follow [verifying-commands](../verifying-commands/SKILL.md) for the task copy's nested-fleet proof. Prove repair from stale state and an unchanged repeat; a directly repeated phase must report zero changes. Record fresh, unchanged and per-phase times with `ansible.posix.profile_tasks`.

Current hazards: fixture modules can accept arguments the real pinned module rejects; localhost fixtures can hide remote delegation and SSH failures; a generated vars file must survive the real Ansible loader. Exercise these paths, not only helper functions.

Update the owning reference or solution when a phase teaches a reusable lesson. Do not append an incident log to this skill.
