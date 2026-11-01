import { z } from "npm:zod@4.4.3";
const Args = z.object({
    node_id: z.number().int().positive(),
    source: z.ipv4(),
    range: z.string(),
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
    const matches = nft.nftables.some((entry) => {
        const rule = entry.rule as Record<string, unknown> | undefined;
        if (
            !rule || rule.family !== "inet" ||
            rule.table !== "orbit_task_vms" || rule.chain !== "forward" ||
            rule.comment !== "orbit:task-vm-control-ssh" ||
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
        return accepts && fields["ip.saddr"] === args.source &&
            fields["ip.daddr"] === args.range && fields["tcp.dport"] === 22;
    });
    if (!matches) {
        throw new Error("The live hub filter lacks the exact209 TCP22 rule");
    }
    return {
        ready: true as const,
        node_id: args.node_id,
        control_ssh_source: args.source,
        control_ssh_port: 22,
        stdout: JSON.stringify({
            ready: true,
            node_id: args.node_id,
            control_ssh_source: args.source,
            control_ssh_port: 22,
        }),
    };
}
export const behavior = {
    type: "@nckrtl/task-vm-hub-result",
    version: "2026.11.01.1",
    resources: {
        result: {
            schema: z.object({
                ready: z.literal(true),
                node_id: z.number(),
                control_ssh_source: z.string(),
                control_ssh_port: z.literal(22),
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
