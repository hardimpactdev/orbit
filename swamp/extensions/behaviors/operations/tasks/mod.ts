import { Globals } from "../_lib/direct.ts";
import { publishMethod } from "./publish.ts";
import { checkMethod } from "./check.ts";

/** Retained check and publish consumers; turn uses its own native workflow. */
export const behavior = {
    globalArguments: Globals,
    type: "@nckrtl/task-workspace",
    version: "2026.10.31.7",
    methods: {
        check: checkMethod,
        publish: publishMethod,
    },
};
