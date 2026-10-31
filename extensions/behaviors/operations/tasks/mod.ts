import { turnBehavior } from "./turn.ts";
import { checkMethod } from "./check.ts";
import { scriptBehavior } from "./script.ts";

/** One model and native write lock per VM, shared by every task workspace effect. */
export const behavior = {
  ...turnBehavior,
  type: "@nckrtl/task-workspace",
  version: "2026.10.31.3",
  methods: {
    ...turnBehavior.methods,
    script: scriptBehavior.methods.execute,
    check: checkMethod,
  },
};
