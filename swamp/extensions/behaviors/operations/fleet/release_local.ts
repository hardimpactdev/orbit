import { z } from "npm:zod@4.4.3";
import { CliInstallInput } from "./cli_install.ts";
import { CliConvergePlan } from "./cli_converge_local.ts";

export const Code = z.string().regex(/^[a-z][a-z0-9_]*\.[a-z0-9_.-]{1,90}$/);
export const NodeRelease = CliConvergePlan.extend({
    node_id: z.number().int().positive(),
    // Accepted, unused: rollouts admitted before the record tables were dropped carry it.
    row_id: z.number().int().positive().optional(),
    agent: z.object({
        url: CliInstallInput.shape.release.unwrap().shape.url,
        sha256: z.string().regex(/^[a-f0-9]{64}$/),
        version: z.string().min(1).max(128),
    }).strict().nullable(),
    daytime: z.boolean(),
    // Public SSH descriptor the native CLI child uses; the private key stays on Node 209.
    ssh: z.object({
        control_node_id: z.number().int().positive(),
        identity_file: z.string().startsWith("/"),
        target: z.object({
            host: z.string().min(1),
            user: z.string().min(1),
            port: z.number().int().positive(),
            host_keys: z.array(z.string().min(1)).min(1),
        }).passthrough(),
    }).passthrough().nullable(),
}).strict();
