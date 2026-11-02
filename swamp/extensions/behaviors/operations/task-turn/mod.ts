import { turnDataMethods, turnDataResources } from "../tasks/turn.ts";

/** Pure public data shaping; every operational action is a placed command step. */
export const behavior = {
    type: "@nckrtl/task-turn-data",
    version: "2026.11.02.2",
    resources: turnDataResources,
    methods: turnDataMethods,
};
