import { z } from "npm:zod@4.4.3";
import { gatewayHttp, type Http } from "../../automatic-deploy/gateway.ts";
import { DirectArgs, GatewayOperation, Globals } from "../_lib/direct.ts";
import {
    DeploymentExecution,
    readDeploymentExecution,
    sameDeploymentExecution,
    saveDeploymentExecution,
} from "./deployment_execution.ts";
import {
    ProductionCheckpoint,
    runProductionDeploy,
} from "./deploy_production.ts";
import { rollbackDirect } from "./rollback_direct.ts";

const Args = DirectArgs.extend({
    kind: z.literal("instance.deploy"),
    node_id: z.number().int().positive(),
});
type Context = {
    globalArgs: z.infer<typeof Globals>;
    signal?: AbortSignal;
    readResource(name: string): Promise<Record<string, unknown> | null>;
    writeResource(
        spec: string,
        name: string,
        value: Record<string, unknown>,
    ): Promise<unknown>;
};
type ProductionPorts = NonNullable<Parameters<typeof runProductionDeploy>[2]>;
type Ports = {
    rollback?: Parameters<typeof rollbackDirect>[0];
    http?: (ctx: Context) => Http;
    source?: ProductionPorts["source"];
    inspectRunner?: ProductionPorts["inspectRunner"];
    run?: ProductionPorts["run"];
    now?: () => number;
    production?: Omit<
        ProductionPorts,
        "http" | "now" | "source" | "inspectRunner" | "run"
    >;
};

/** Retained production and rollback root. Development is the native SSH DAG. */
export function deployDirect(ports: Ports = {}) {
    const now = ports.now ?? Date.now;
    const rollback = rollbackDirect(ports.rollback);
    const production = {
        ...ports.production,
        http: ports.http,
        now,
        source: ports.source,
        inspectRunner: ports.inspectRunner,
        run: ports.run,
    };
    return {
        type: "@nckrtl/instance-deploy-direct",
        version: "2026.11.01.3",
        globalArguments: Globals,
        resources: {
            rollbackExecution: rollback.resources.rollbackExecution,
            activeExecution: {
                description: "Public production effect owner",
                schema: DeploymentExecution,
                lifetime: "infinite",
                garbageCollection: 1,
            },
            productionExecution: {
                description: "Public production progress",
                schema: ProductionCheckpoint,
                lifetime: "infinite",
                garbageCollection: 10,
            },
        },
        methods: {
            rollback: rollback.methods.run,
            rollbackFailure: rollback.methods.failure,
            failure: {
                description:
                    "Settle a failed production root only when its effect checkpoint is inactive",
                arguments: Args,
                execute: async (raw: z.infer<typeof Args>, ctx: Context) => {
                    const args = Args.parse(raw);
                    const http = (ports.http ??
                        ((ctx: Context) =>
                            gatewayHttp(
                                ctx.globalArgs.gatewayUrl,
                                ctx.globalArgs.caFile,
                            )))(ctx);
                    const gateway = new GatewayOperation(http, args);
                    const active = await readDeploymentExecution(ctx);
                    if (
                        active?.active && !sameDeploymentExecution(args, active)
                    ) return { status: "held" };
                    const stored = await ctx.readResource(
                        `production-${args.operation_id}-${args.run_id}`,
                    );
                    if (stored !== null) {
                        const checkpoint = ProductionCheckpoint.parse(stored);
                        if (
                            checkpoint.operation_id !== args.operation_id ||
                            checkpoint.run_id !== args.run_id
                        ) throw new Error("deployment.identity_changed");
                        if (
                            checkpoint.active ||
                            (checkpoint.stage >= 4 &&
                                !checkpoint.result.selection_confirmed)
                        ) return { status: "held" };
                        const at = new Date(now()).toISOString();
                        const outcome = checkpoint.outcome ??
                            {
                                status: "failed",
                                error_code: checkpoint.stage === 6
                                    ? "operation.native_failed"
                                    : "deployment.interrupted",
                            };
                        await gateway.project({
                            ...outcome,
                            started_at: checkpoint.started_at,
                            finished_at: at,
                            result: {
                                ...checkpoint.result,
                                failed_step: checkpoint.result.failed_step ??
                                    "native_execution",
                            },
                        });
                        await saveDeploymentExecution(ctx, args, false);
                        return { status: outcome.status };
                    }
                    if (active?.active) return { status: "held" };
                    const at = new Date(now()).toISOString();
                    await gateway.project({
                        status: "failed",
                        started_at: at,
                        finished_at: at,
                        error_code: "deployment.interrupted",
                        result: { failed_step: "native_execution" },
                    });
                    await saveDeploymentExecution(ctx, args, false);
                    return { status: "failed" };
                },
            },
            prepareProduction: {
                description:
                    "Prepare production before native Process children",
                arguments: Args,
                execute: (raw: z.infer<typeof Args>, ctx: Context) =>
                    runProductionDeploy(raw, ctx, production, "prepare"),
            },
            finishProduction: {
                description:
                    "Continue production after native Process children",
                arguments: Args,
                execute: (raw: z.infer<typeof Args>, ctx: Context) =>
                    runProductionDeploy(raw, ctx, production, "finish"),
            },
        },
    };
}
export const behavior = deployDirect();
