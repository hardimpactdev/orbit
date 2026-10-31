import { Globals } from "../_lib/direct.ts";
import { checkMethod } from "./check.ts";

/** Retained check consumer; other repository operations use native workflows. */
export const behavior = {
    globalArguments: Globals,
    type: "@nckrtl/task-workspace",
    version: "2026.10.31.8",
    methods: {
        check: checkMethod,
    },
};
