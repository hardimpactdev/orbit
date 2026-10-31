import { z } from "npm:zod@4.4.3";
import {
    checker,
    Swamp,
    unitPath,
    WorkerCaFile,
    WorkerConfig,
    workerUnit,
} from "../../node-worker/config.ts";

export const Args = WorkerConfig.extend({ node_id: z.literal(209) });
export const Assets = z.object({
    unit: z.string(),
    checker: z.string(),
    preflight: z.string(),
    checker_path: z.string(),
    unit_path: z.string(),
    ca_path: z.string(),
    runtime_version: z.string(),
    artifact_url: z.string(),
    artifact_sha256: z.string(),
    artifact_bytes: z.number().int().positive(),
}).strict();
export async function assets(
    raw: unknown,
    read: (path: string) => Promise<string>,
) {
    const worker = Args.parse(raw);
    const minimum = Swamp.bytes + Swamp.headroom;
    const checkerSource = await read("scripts/worker_check.py");
    const preflightSource = await read("scripts/preflight.py");
    return Assets.parse({
        unit: workerUnit(worker),
        checker: checkerSource.replace(
            "MINIMUM_MEMORY_BYTES = 0",
            `MINIMUM_MEMORY_BYTES = ${minimum}`,
        ),
        preflight: preflightSource.replace(
            "MINIMUM_MEMORY_BYTES = 0",
            `MINIMUM_MEMORY_BYTES = ${minimum}`,
        )
            .replace(
                'WORKER_USER = ""',
                `WORKER_USER = ${JSON.stringify(worker.user)}`,
            ),
        checker_path: checker,
        unit_path: unitPath,
        ca_path: WorkerCaFile,
        runtime_version: Swamp.version,
        artifact_url:
            `https://github.com/swamp-club/swamp/releases/download/v${Swamp.version}/swamp-linux-x86_64`,
        artifact_sha256: Swamp.sha256,
        artifact_bytes: Swamp.bytes,
    });
}
export const behavior = {
    type: "@nckrtl/node-worker-assets",
    version: "2026.10.31.2",
    resources: {
        assets: { schema: Assets, lifetime: "infinite", garbageCollection: 10 },
    },
    methods: {
        read: {
            description:
                "Read public worker installation assets and canonical unit policy",
            arguments: Args,
            execute: async (
                args: z.infer<typeof Args>,
                ctx: {
                    extensionFile: (path: string) => Promise<string> | string;
                    writeResource: (
                        spec: string,
                        name: string,
                        value: unknown,
                    ) => Promise<unknown>;
                },
            ) => {
                const result = await assets(
                    args,
                    async (path) =>
                        await Deno.readTextFile(await ctx.extensionFile(path)),
                );
                await ctx.writeResource("assets", "assets", result);
                return result;
            },
        },
    },
};
