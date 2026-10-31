import { Globals } from "../_lib/direct.ts";
import { analysisMethod } from "./analysis.ts";
import { publishMethod } from "./publish.ts";
import { checkMethod } from "./check.ts";
import { scriptBehavior } from "./script.ts";

/** One model and native write lock per VM, shared by every task workspace effect. */
export const behavior = {
    globalArguments: Globals,
    type: "@nckrtl/task-workspace",
    version: "2026.10.31.6",
    methods: {
        script: scriptBehavior.methods.execute,
        check: checkMethod,
        analyze: analysisMethod,
        publish: publishMethod,
    },
};
