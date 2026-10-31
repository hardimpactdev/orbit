import { Globals } from "../_lib/direct.ts";
import { DeploymentExecution } from "./deployment_execution.ts";
import { rollbackDirect } from "./rollback_direct.ts";

/** Retained rollback entry points only; instance.deploy is a native command DAG. */
export function deployDirect(
    ports: { rollback?: Parameters<typeof rollbackDirect>[0] } = {},
) {
    const rollback = rollbackDirect(ports.rollback);
    return {
        type: "@nckrtl/instance-deploy-direct",
        version: "2026.10.31.6",
        globalArguments: Globals,
        resources: {
            rollbackExecution: rollback.resources.rollbackExecution,
            activeExecution: {
                schema: DeploymentExecution,
                lifetime: "infinite",
                garbageCollection: 1,
            },
        },
        methods: {
            rollback: rollback.methods.run,
            rollbackFailure: rollback.methods.failure,
        },
    };
}
export const behavior = deployDirect();
