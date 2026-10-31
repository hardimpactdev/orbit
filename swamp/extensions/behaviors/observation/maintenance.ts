import { z } from "npm:zod@4.4.3";
import { gatewayHttp, type Http } from "../automatic-deploy/gateway.ts";
import {
    DirectArgs,
    directOperation,
    GatewayOperation,
    Globals,
} from "../operations/_lib/direct.ts";
import { ValkeyClient } from "../operations/database/valkey_client.ts";
import { snapshot } from "./maintenance_snapshot.ts";
import {
    MaintenanceInput as Input,
    MaintenanceResult as Result,
    selectMaintenance,
} from "./maintenance_selection.ts";

const Args = DirectArgs.extend({
    kind: z.literal("observation.maintain"),
    node_id: z.number().int().positive(),
});
type Context = { globalArgs: z.infer<typeof Globals>; signal?: AbortSignal };
export function maintenance(
    makeHttp: (ctx: Context) => Http = (ctx) =>
        gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
    connect: (
        host: string,
        port: number,
    ) => Promise<Pick<ValkeyClient, "command" | "close">> =
        ValkeyClient.connect,
) {
    return {
        type: "@nckrtl/observation-maintenance",
        version: "2026.10.31.1",
        globalArguments: Globals,
        methods: {
            apply: {
                description:
                    "Select bounded Gateway state changes and snapshot Horizon directly in Redis",
                arguments: Args,
                execute: async (args: z.infer<typeof Args>, ctx: Context) => {
                    const gateway = new GatewayOperation(makeHttp(ctx), args);
                    return await directOperation(
                        args,
                        gateway,
                        async (raw) => {
                            const input = Input.parse(raw),
                                result = selectMaintenance(input);
                            if (
                                input.routine === "maintenance" &&
                                input.redis !== null
                            ) {
                                try {
                                    await gateway.withPrivateMaterial(
                                        async (material) => {
                                            const credentials = z.object({
                                                username: z.string().nullable(),
                                                password: z.string().nullable(),
                                            }).parse(material.queue_redis);
                                            const redis = input.redis!;
                                            const client = await connect(
                                                redis.host,
                                                redis.port,
                                            );
                                            try {
                                                if (credentials.password) {
                                                    await client.command(
                                                        "AUTH",
                                                        ...(credentials.username
                                                            ? [
                                                                credentials
                                                                    .username,
                                                            ]
                                                            : []),
                                                        credentials.password,
                                                    );
                                                }
                                                await client.command(
                                                    "SELECT",
                                                    String(redis.database),
                                                );
                                                const counts = await snapshot({
                                                    command: (args) =>
                                                        client.command(...args),
                                                }, redis);
                                                result.snapshot_jobs =
                                                    counts.jobs;
                                                result.snapshot_queues =
                                                    counts.queues;
                                            } finally {
                                                client.close();
                                            }
                                        },
                                    );
                                } catch {
                                    result.error_code =
                                        "observation.metrics_unavailable";
                                }
                            }
                            return result;
                        },
                        (value) => Result.parse(value),
                        ctx.signal,
                    );
                },
            },
        },
    };
}
export const behavior = maintenance();
