import { userInfo } from "node:os";
import { z } from "npm:zod@4.4.3";

const Rule = z.object({
    node_id: z.number().int().positive(),
    name: z.string(),
    action: z.enum(["allow", "deny"]),
    source: z.string(),
    port: z.string(),
    protocol: z.enum(["tcp", "udp"]),
});
const Tuple = z.object({
    port: z.number().int().min(1).max(65535),
    ensure: z.enum(["present", "absent"]),
});
const Intent = z.object({
    ssh: z.object({
        target: z.object({
            host: z.string(),
            port: z.literal(22),
            host_keys: z.array(z.string()).min(1),
        }),
    }),
    rule: Rule,
    tuples: z.array(Tuple).min(1),
    ensure: z.enum(["present", "absent"]),
});
const State = z.object({
    status: z.enum(["compliant", "applied"]),
    current: z.object({ backend: z.literal("ufw"), ruleExists: z.boolean() }),
});
export function mutationResult(raw: unknown) {
    const { ensure, state } = z.object({
        ensure: z.enum(["present", "absent"]),
        state: State,
    }).parse(raw);
    if (state.current.ruleExists !== (ensure === "present")) {
        throw new Error("firewall.verify_failed");
    }
    return { backend: ensure === "absent" ? "absent" : "active" };
}
export function requirePublishedPins(raw: unknown, snapshot: string) {
    const intent = Intent.parse(raw);
    const host = intent.ssh.target.host;
    const entries = snapshot.split(/\r?\n/).filter((line) =>
        !line.startsWith("#")
    ).map((line) => line.trim().split(/\s+/));
    const matched = entries.filter((parts) =>
        parts[0]?.split(",").includes(host)
    );
    const keys = matched.map((parts) => parts.slice(1, 3).join(" "));
    if (
        !intent.ssh.target.host_keys.every((key) => keys.includes(key)) ||
        keys.some((key) => !intent.ssh.target.host_keys.includes(key))
    ) {
        throw new Error("firewall.known_hosts_unpublished");
    }
    return { ready: true };
}
const Observe = z.object({ intent: Intent, stdout: z.string() });
export function observe(raw: unknown) {
    const { intent, stdout } = Observe.parse(raw);
    if (
        stdout.split(/\r?\n/).filter((line) => line.trim() === "Status: active")
                .length !== 1 || stdout.includes("Status: inactive")
    ) throw new Error("firewall.backend_inactive");
    const tag =
        `orbit:node:${intent.rule.node_id}:firewall:${intent.rule.name}`;
    return {
        legacy: intent.ensure === "absent" &&
            intent.tuples.some((tuple) => tuple.ensure === "absent") &&
            stdout.split(/\r?\n/).some((line) =>
                line.split(/\s+#\s*/)[1]?.trim() === tag
            ),
    };
}
const Result = Observe.extend({ outcomes: z.record(z.string(), z.unknown()) });
export function result(raw: unknown) {
    const args = Result.parse(raw);
    if (observe(args).legacy) throw new Error("firewall.remove_verify_failed");
    for (const [index, tuple] of args.intent.tuples.entries()) {
        const step = z.object({
            status: z.literal("succeeded"),
            outputs: State,
        }).parse(
            args.outcomes[
                `verify-${
                    args.intent.rule.source === "any" ? "any" : "source"
                }-${index}`
            ],
        );
        mutationResult({ ensure: tuple.ensure, state: step.outputs });
    }
    return { backend: args.intent.ensure === "absent" ? "absent" : "active" };
}
type Context = {
    writeResource(spec: string, name: string, data: unknown): Promise<unknown>;
};
const write = async (ctx: Context, spec: string, data: unknown) => ({
    dataHandles: [await ctx.writeResource(spec, spec, data)],
});
export const behavior = {
    type: "@nckrtl/firewall-mutation-result",
    version: "2026.11.01.1",
    resources: {
        preflight: {
            schema: z.object({ ready: z.literal(true) }),
        },
        observation: {
            schema: z.object({ legacy: z.boolean() }),
        },
        result: {
            schema: z.object({ stdout: z.string(), exitCode: z.literal(0) }),
        },
    },
    methods: {
        preflight: {
            description:
                "Require every admitted target pin in the worker's published known_hosts snapshot",
            arguments: z.object({ intent: Intent }),
            execute: async (
                args: { intent: z.infer<typeof Intent> },
                ctx: Context,
            ) => write(
                ctx,
                "preflight",
                requirePublishedPins(
                    args.intent,
                    await Deno.readTextFile(
                        `${userInfo().homedir}/.ssh/known_hosts`,
                    ),
                ),
            ),
        },
        observe: {
            description:
                "Refuse inactive UFW before the official model can select another backend",
            arguments: Observe,
            execute: async (args: z.infer<typeof Observe>, ctx: Context) =>
                write(ctx, "observation", observe(args)),
        },
        result: {
            description:
                "Verify all native official-model tuple contributions and legacy removal",
            arguments: Result,
            execute: async (args: z.infer<typeof Result>, ctx: Context) =>
                write(ctx, "result", {
                    stdout: JSON.stringify(result(args)),
                    exitCode: 0,
                }),
        },
    },
};
