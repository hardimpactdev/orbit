import { localCommand } from "../operations/_lib/local-worker.ts";
import { z } from "npm:zod@4.4.3";

const hour = 3600000;
export const WorkerCredentialDuration = "24h";
export const Token = z.object({
    name: z.string(),
    state: z.string(),
    createdAt: z.string(),
    expiresAt: z.string(),
    vaultName: z.string().optional(),
    secretKey: z.string().optional(),
}).passthrough();
type Token = z.infer<typeof Token>;
export function due(token: Token, now = Date.now()) {
    const created = Date.parse(token.createdAt),
        expires = Date.parse(token.expiresAt);
    return ["revoked", "expired"].includes(token.state) ||
        !Number.isFinite(created) || !Number.isFinite(expires) ||
        expires - created > 24 * hour || now >= created + 20 * hour ||
        expires - now <= 4 * hour;
}
export type Native = (args: string[]) => Promise<unknown>;
/** Native CLI owns token custody, single-machine binding and transitions. No issuer or Node keys. */
export const native: Native = async (args) => {
    const result = await localCommand("/home/orbit/.local/bin/swamp", {
        args: [
            ...args,
            "--repo-dir",
            "/home/orbit/apps/orbit-monorepo-staging/checkout/swamp",
            "--json",
        ],
        cwd: "/home/orbit/apps/orbit-monorepo-staging/checkout/swamp",
        env: {
            SWAMP_HOME: "/home/orbit/.swamp",
            SWAMP_CONFIG_DIR: "/home/orbit/.config/swamp",
            SWAMP_API_KEY_FILE: "/home/orbit/.config/swamp/api-key",
        },
        stdin: "null",
        stdout: "piped",
        stderr: "null",
    }).output();
    if (!result.success || result.stdout.length > 4 * 1024 * 1024) {
        throw new Error(
            "Native worker token command refused; inspect its audit",
        );
    }
    return JSON.parse(new TextDecoder().decode(result.stdout));
};
