# Task turn data

This extension registers `@nckrtl/task-turn-data`, the public data shaping in
`tasks/turn.ts`. It ships no script and runs no command.

`operation-task-check` calls its `checked` method in the step `Read the result`.
The method reads the check receipt and exports the commit and tree to push only
when the check passed.

The Pi turn workflow and the Pi loop workflows that ran it on task VMs are
removed ([old paths](../../../../../docs/reference/old-paths.md#the-pi-task-loop)).
