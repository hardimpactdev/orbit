---
title: "Old paths"
description: "Code that still runs but that a stage of the command development pipeline replaces. Check this page before you build on it."
covers:
  - apps/gateway/app/Infrastructure/TaskVms/{RecipeImageBuilds.php,RecipeImageBuildSteps.php}
  - apps/gateway/app/Console/Commands/BuildTaskVmRecipeCommand.php
  - swamp/extensions/behaviors/operations/proof-reaper/**
---

# Old paths

Check this page before you build on something. Each row is code that still runs, was not removed, and is replaced by a stage of the [command development pipeline](/reference/command-development-pipeline). Keep it and do not extend it. When its stage lands, delete it with its tests and remove the row. Code that is replaced and has no live caller is deleted, not listed here.

| Item | Replaced by | Removed at |
| --- | --- | --- |
| Recipe image build: `RecipeImageBuilds`, `RecipeImageBuildSteps`, `BuildTaskVmRecipeCommand`, `task-vms:build-*`, and the seed refresh that an operator starts with `task-vms:refresh-seed` | The per-host refresh by converge ([stage 6](/reference/command-development-pipeline#6-per-host-task-image-and-seed-refresh)); a recipe build stays only for a new host or a new base OS | Stage 6 |
| `proof-reaper`, and the Project 62 branch of `TaskProvisionIntent` and `ProofReaper` | Nothing: only the removed `bin/swamp-proof` made Project 62 groups | When no Project 62 group is open |
| `bin/e2e-topology` and the per-issue topology of a subtask that declares `topology`, with its virtiofs share of the worktree | The nested fleet and `orbit-nested` ([stage 2](/reference/command-development-pipeline#2-development-environment)) | When no subtask declares a topology |
| `bin/proof-fleet` (`reset`, `ready`, `verify`, `release`) and the `proof-fleet-*` workflows | `orbit proof` and `proof:run` ([stage 3](/reference/command-development-pipeline#3-proof)) | Stage 3 |

`apps/agent/**` is frozen. Its image-build commands (`image_build`, `image_recipe`) belong to the recipe path and wait for the agent strip.
