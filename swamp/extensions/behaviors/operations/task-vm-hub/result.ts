import { z } from "npm:zod@4.4.3";
const Args = z.object({
    node_id: z.number().int().positive(),
    source: z.ipv4(),
    range: z.string(),
    routers: z.array(z.ipv4()).min(1),
    stdout: z.string().max(1048576),
}).strict();
function address(raw: unknown): unknown {
    if (raw && typeof raw === "object" && "prefix" in raw) {
        const p = (raw as { prefix: { addr: string; len: number } }).prefix;
        return p.len === 32 ? p.addr : p.addr + "/" + p.len;
    }
    return raw;
}
export function hubResult(raw: unknown) {
    const args = Args.parse(raw);
    const nft = z.object({
        nftables: z.array(z.record(z.string(), z.unknown())),
    }).parse(JSON.parse(args.stdout));
    const matches = (source: string, port: number, router = false) =>
        nft.nftables.some((entry) => {
            const rule = entry.rule as Record<string, unknown> | undefined;
            if (
                !rule || rule.family !== "inet" ||
                rule.table !== "orbit_task_vms" || rule.chain !== "forward" ||
                !(router
                    ? typeof rule.comment === "string" &&
                        rule.comment.startsWith("orbit:task-vm-router-web-")
                    : rule.comment === "orbit:task-vm-control-ssh") ||
                !Array.isArray(rule.expr)
            ) return false;
            const fields: Record<string, unknown> = {};
            let accepts = false;
            for (const expression of rule.expr) {
                if (expression.accept === null) accepts = true;
                const match = expression.match;
                const payload = match?.left?.payload;
                if (payload && match.op === "==") {
                    fields[payload.protocol + "." + payload.field] = address(
                        match.right,
                    );
                }
            }
            return accepts && fields["ip.saddr"] === source &&
                fields["ip.daddr"] === args.range &&
                fields["tcp.dport"] === port;
        });
    if (!matches(args.source, 22)) {
        throw new Error("The live hub filter lacks the exact209 TCP22 rule");
    }
    if (
        args.routers.some((source) =>
            [80, 443].some((port) => !matches(source, port, true))
        )
    ) {
        throw new Error(
            "The live hub filter lacks an admitted Cluster Router TCP80/443 rule",
        );
    }
    return {
        ready: true as const,
        node_id: args.node_id,
        control_ssh_source: args.source,
        control_ssh_port: 22,
        router_sources: args.routers,
        router_web_ports: [80, 443],
        stdout: JSON.stringify({
            ready: true,
            node_id: args.node_id,
            control_ssh_source: args.source,
            control_ssh_port: 22,
            router_sources: args.routers,
            router_web_ports: [80, 443],
        }),
    };
}
export const behavior = {
    type: "@nckrtl/task-vm-hub-result",
    version: "2026.11.01.2",
    resources: {
        result: {
            schema: z.object({
                ready: z.literal(true),
                node_id: z.number(),
                control_ssh_source: z.string(),
                control_ssh_port: z.literal(22),
                router_sources: z.array(z.ipv4()),
                router_web_ports: z.tuple([z.literal(80), z.literal(443)]),
                stdout: z.string(),
            }),
            lifetime: "1d",
            garbageCollection: 10,
        },
    },
    methods: {
        decode: {
            description:
                "Verify the live task VM control rule and emit its public terminal receipt",
            arguments: Args,
            execute: async (
                args: z.infer<typeof Args>,
                ctx: {
                    writeResource(
                        spec: string,
                        name: string,
                        value: unknown,
                    ): Promise<unknown>;
                },
            ) => {
                const result = hubResult(args);
                await ctx.writeResource("result", "result", result);
                return result;
            },
        },
    },
};
