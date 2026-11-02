import { z } from "npm:zod@4.4.3";
import { type NodeCommand, run } from "../_lib/node-commands.ts";
import { CliInstallInput } from "./cli_install.ts";
import { CliConvergePlan } from "./cli_converge_local.ts";
import { ReleaseProgram } from "./release_program.ts";

export const Code = z.string().regex(/^[a-z][a-z0-9_]*\.[a-z0-9_.-]{1,90}$/);
export const NodeRelease = CliConvergePlan.extend({
    node_id: z.number().int().positive(),
    row_id: z.number().int().positive().optional(),
    agent: z.object({
        url: CliInstallInput.shape.release.unwrap().shape.url,
        sha256: z.string().regex(/^[a-f0-9]{64}$/),
        version: z.string().min(1).max(128),
    }).strict().nullable(),
    daytime: z.boolean(),
    // Public SSH descriptor the native CLI child uses; the private key stays on Node 209.
    ssh: z.object({
        control_node_id: z.literal(209),
        identity_file: z.string().startsWith("/"),
        target: z.object({
            host: z.string().min(1),
            user: z.string().min(1),
            port: z.number().int().positive(),
            host_keys: z.array(z.string().min(1)).min(1),
        }).passthrough(),
    }).passthrough().nullable(),
}).strict();
const Observer = z.union([
    z.object({ error_code: Code }).strict(),
    z.object({ changed: z.boolean(), version: z.string() }).strict(),
]);

/** Distribute the admitted frozen observer binary, without asking the agent to act. */
export async function publishObserver(
    node: z.infer<typeof NodeRelease>,
    command: NodeCommand = run,
    signal?: AbortSignal,
) {
    signal?.throwIfAborted();
    if (!node.agent) throw new Error("Rollout has no frozen observer pin");
    const reply = await command(
        ["/usr/bin/python3", "-I", "-c", ReleaseProgram],
        new TextEncoder().encode(JSON.stringify({ agent: node.agent })),
        "root",
        { deadlineMs: 420000, maxOutputBytes: 4096 },
    );
    if (!reply.success) {
        throw new Error("Observer publication has no bounded outcome");
    }
    const result = Observer.parse(
        JSON.parse(new TextDecoder().decode(reply.stdout)),
    );
    if ("version" in result && result.version !== node.agent.version) {
        throw new Error("Observer publication differs from the admitted pin");
    }
    return result;
}
