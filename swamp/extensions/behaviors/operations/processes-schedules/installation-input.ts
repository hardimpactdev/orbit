import { z } from "npm:zod@4.4.3";

const Digest = z.string().regex(/^[a-f0-9]{64}$/);
const Runtime = z.enum(["systemd", "docker"]);
const Artifact = z.object({ runtime: Runtime, name: z.string() }).strict();
const Base = z.object({
    process_id: z.number().int().positive(),
    agent_sha256: Digest.optional(),
    name: z.string(),
    desired: z.enum(["running", "stopped"]),
    on_demand: z.boolean(),
    directory: z.string().startsWith("/").max(4096).optional(),
    retired: Artifact.nullable(),
});
export const ProcessInstallation = z.discriminatedUnion("runtime", [
    Base.extend({
        runtime: z.literal("systemd"),
        content_hash: Digest,
        activation_pending: z.boolean(),
        account_user: z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/).optional(),
    }).strict(),
    Base.extend({ runtime: z.literal("docker"), spec: Digest }).strict(),
]).superRefine((input, ctx) => {
    const valid = (runtime: string, name: string) =>
        new RegExp(
            `^orbit-process-${input.process_id}-[a-z0-9](?:[a-z0-9-]*[a-z0-9])?` +
                (runtime === "systemd" ? "\\.service$" : "$"),
        ).test(name);
    if (
        !valid(input.runtime, input.name) ||
        (input.retired !== null &&
            (input.retired.runtime === input.runtime ||
                !valid(input.retired.runtime, input.retired.name)))
    ) {
        ctx.addIssue({
            code: "custom",
            message: "Process artifact identity differs",
        });
    }
});
