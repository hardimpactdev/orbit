import { turnBehavior } from "./turn.ts";
import { publishMethod } from "./publish.ts";
import { checkMethod } from "./check.ts";

/** One model and native write lock per VM, shared by every task workspace effect. */
export const behavior = {
    ...turnBehavior,
    type: "@nckrtl/task-workspace",
    version: "2026.10.31.5",
    methods: {
        ...turnBehavior.methods,
        check: checkMethod,
        publish: publishMethod,
    },
};
