import { z } from "npm:zod@4.4.3";
import { bootstrapProbeResult } from "../node-bootstrap/probe_result.ts";

export const AlgorithmMark = "@ORBIT_ENROLLMENT_HOST_ALGORITHM@";
export const KeyMark = "@ORBIT_ENROLLMENT_HOST_KEY@";
const Copy = z.object({
    vault: z.literal("orbit-control-inputs"),
    key: z.string(),
}).strict();
const Role = z.object({
    kind: z.enum(["node.role_add", "node.role_converge"]),
    intent: z.record(z.string(), z.unknown()),
    execution_secrets: z.record(z.string(), Copy),
}).strict();
const Args = z.object({
    platform: z.enum(["linux", "macos"]),
    expected_architecture: z.string().nullable(),
    observation: z.object({ stdout: z.string(), exitCode: z.literal(0) }),
    host_key: z.object({
        algorithm: z.string(),
        publicKey: z.string(),
        fingerprint: z.string(),
    }),
    roles: z.array(Role).max(12),
    dns: z.array(z.record(z.string(), z.unknown())).default([]),
    bootstrap_user: z.string(),
    bootstrap_account: z.object({ stdout: z.string(), exitCode: z.literal(0) })
        .nullable(),
    cli: z.record(z.string(), z.unknown()).nullable().default(null),
}).strict();
const Bound = z.object({
    cli: z.record(z.string(), z.unknown()).nullable(),
    stdout: z.string(),
    roles: z.array(Role),
    dns: z.array(z.record(z.string(), z.unknown())),
    host_keys: z.array(z.string()),
    bootstrap_authorized_keys: z.string(),
});

/** Substitute public observations only. No process, transport, vault read or Gateway client. */
function bind(value: unknown, algorithm: string, key: string): unknown {
    if (typeof value === "string") {
        return value.split(AlgorithmMark).join(algorithm).split(KeyMark).join(
            key,
        );
    }
    if (Array.isArray(value)) {
        return value.map((item) => bind(item, algorithm, key));
    }
    if (value !== null && typeof value === "object") {
        return Object.fromEntries(
            Object.entries(value).map((
                [name, item],
            ) => [name, bind(item, algorithm, key)]),
        );
    }
    return value;
}
export function bindEnrollment(raw: unknown) {
    const input = Args.parse(raw);
    const observation = bootstrapProbeResult({
        bootstrap: input,
        observation: input.observation,
        host_key: input.host_key,
    });
    const key = input.host_key.publicKey.split(/\s+/)[1];
    let authorizedKeys = "";
    if (input.platform === "linux") {
        const account = input.bootstrap_account?.stdout.trim().split(":") ?? [];
        if (
            account.length !== 7 || account[0] !== input.bootstrap_user ||
            !/^\/(?:[A-Za-z0-9._-]+\/?)+$/.test(account[5]) ||
            account[5].split("/").some((part) => part === "." || part === "..")
        ) {
            throw new Error("node.bootstrap_account_unavailable");
        }
        authorizedKeys = account[5].replace(/\/$/, "") +
            "/.ssh/authorized_keys";
    }
    return Bound.parse({
        cli: bind(input.cli, input.host_key.algorithm, key),
        stdout: observation.stdout,
        host_keys: [input.host_key.publicKey],
        bootstrap_authorized_keys: authorizedKeys,
        roles: bind(input.roles, input.host_key.algorithm, key),
        dns: bind(input.dns, input.host_key.algorithm, key),
    });
}
export const behavior = {
    type: "@nckrtl/node-enrollment-plan",
    version: "2026.11.02.1",
    resources: {
        plan: { schema: Bound, lifetime: "infinite", garbageCollection: 10 },
    },
    methods: {
        bind: {
            description:
                "Bind approved public host observations into frozen role resources",
            arguments: Args,
            execute: async (
                raw: z.infer<typeof Args>,
                ctx: {
                    writeResource(
                        s: string,
                        n: string,
                        value: unknown,
                    ): Promise<unknown>;
                },
            ) => {
                const result = bindEnrollment(raw);
                await ctx.writeResource("plan", "plan", result);
                return result;
            },
        },
    },
};
