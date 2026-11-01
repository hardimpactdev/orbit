import { isIP } from "node:net";
import { z } from "npm:zod@4.4.3";

const Limit = 256 * 1024;
const Shape = z.object({
    comment: z.string(),
    action: z.enum(["allow", "deny"]),
    direction: z.enum(["in", "out", "fwd"]),
    source: z.string(),
    destination: z.string(),
    port: z.string(),
    protocol: z.enum(["any", "tcp", "udp"]),
    in_interface: z.string().nullable(),
    out_interface: z.string().nullable(),
    family: z.enum(["v4", "v6"]).nullable(),
}).strict();
const Expected = z.object({ name: z.string(), shape: Shape }).strict();
const Row = z.object({
    name: z.string(),
    comment: z.string(),
    action: z.enum(["allow", "deny"]),
    source: z.string(),
    destination: z.string(),
    port: z.string(),
    protocol: z.enum(["any", "tcp", "udp"]),
    interface: z.string().nullable(),
    family: z.literal("v4"),
    match: z.enum(["exact", "drift", "unmanaged", "missing"]),
}).strict();
export const Snapshot = z.object({
    backend: z.enum(["active", "inactive"]),
    observed_at: z.iso.datetime(),
    live: z.array(Shape.extend({ family: z.literal("v4") })).max(512),
    comparison: z.object({ live: z.array(Row).max(512), missing: z.array(Row) })
        .strict(),
}).strict();
const Args = z.object({ stdout: z.string(), expected: z.array(Expected) })
    .strict();
type Rule = z.infer<typeof Shape>;
function unavailable(): never {
    throw new Error("firewall.inspection_unavailable");
}
function endpoint(value: string): string {
    if (["any", "Anywhere", "0.0.0.0/0"].includes(value)) return "any";
    const [ip, prefix, extra] = value.split("/");
    if (isIP(ip) !== 4 || extra !== undefined) return unavailable();
    if (prefix === undefined || prefix === "32") return ip;
    if (!/^\d{1,2}$/.test(prefix) || Number(prefix) > 32) return unavailable();
    const bits = Number(prefix);
    if (bits === 0) return "any";
    const number = ip.split(".").reduce((n, v) => (n * 256) + Number(v), 0);
    const block = 2 ** (32 - bits);
    const network = Math.floor(number / block) * block;
    return [24, 16, 8, 0].map((shift) => Math.floor(network / 2 ** shift) % 256)
        .join(".") + "/" + bits;
}
function networkInterface(value: string): [string, string | null] {
    const m = /^(.*?) on ([a-zA-Z0-9_.:-]{1,64})$/.exec(value);
    return m ? [m[1].trim(), m[2]] : [value.trim(), null];
}
function parseRule(line: string): Rule | undefined {
    const m =
        /^(?:\[\s*\d+\]\s*)?(.+?)\s+(ALLOW|DENY)\s+(IN|OUT|FWD)\s+(.+?)(?:\s+#\s*(.*))?$/
            .exec(line);
    if (!m) return unavailable();
    if (m[1].includes("(v6)") || m[4].includes("(v6)")) return undefined;
    const [to, toInterface] = networkInterface(m[1]);
    const [from, fromInterface] = networkInterface(m[4]);
    const port = /^(?:(.+?)\s+)?(\d{1,5}(?::\d{1,5})?)(?:\/(tcp|udp))?$/.exec(
        to,
    );
    const numbers = port ? port[2].split(":").map(Number) : [];
    if (
        numbers.some((v) => v < 1 || v > 65535) ||
        (numbers.length === 2 && numbers[0] > numbers[1])
    ) return unavailable();
    const direction = m[3].toLowerCase() as Rule["direction"];
    return Shape.parse({
        comment: m[5]?.trim() ?? "",
        action: m[2].toLowerCase(),
        direction,
        source: endpoint(from),
        destination: endpoint(port ? port[1] ?? "any" : to),
        port: port ? numbers.join(":") : "any",
        protocol: port?.[3] ?? "any",
        in_interface: direction === "in"
            ? toInterface ?? fromInterface
            : direction === "fwd"
            ? fromInterface
            : null,
        out_interface: direction === "out"
            ? toInterface ?? fromInterface
            : direction === "fwd"
            ? toInterface
            : null,
        family: "v4",
    });
}
export function inspectionResult(input: z.infer<typeof Args>) {
    const args = Args.parse(input);
    if (new TextEncoder().encode(args.stdout).length >= Limit) {
        throw new Error("firewall.inspection_too_large");
    }
    const lines = args.stdout.split(/\r?\n/).map((l) => l.trim()).filter(
        Boolean,
    );
    const statuses = lines.filter((l) => /^Status: (active|inactive)$/.test(l));
    if (statuses.length !== 1) return unavailable();
    const backend = statuses[0] === "Status: active" ? "active" : "inactive";
    const live: Rule[] = [];
    for (const line of lines) {
        if (
            line === statuses[0] || /^To\s+Action\s+From$/.test(line) ||
            /^[-\s]+$/.test(line)
        ) continue;
        const row = parseRule(line);
        if (row) live.push(row);
    }
    if (live.length > 512) throw new Error("firewall.inspection_too_large");
    if (backend === "inactive" && live.length) return unavailable();
    const expected = args.expected.filter((e) => e.shape.family !== "v6");
    const named = (shape: Rule) =>
        /^orbit:node:\d+:firewall:/.test(shape.comment);
    const bounds = (port: string) => port.split(":").map(Number);
    const matches = (actual: Rule, intended: Rule, port = intended.port) =>
        Object.entries({ ...intended, port }).every(([key, value]) =>
            key === "family" && value === null ||
            key === "comment" && named(intended) ||
            actual[key as keyof Rule] === value
        );
    const exact = (actual: Rule, intended: Rule) => {
        if (matches(actual, intended)) return true;
        if (!named(intended)) return false;
        const [start, end = start] = bounds(intended.port);
        const [port] = bounds(actual.port);
        return !actual.port.includes(":") && port >= start && port <= end &&
            matches(actual, intended, actual.port);
    };
    const view = (shape: Rule, match: z.infer<typeof Row>["match"]) => {
        const name =
            expected.find((e) => e.shape.comment === shape.comment)?.name ??
                expected.find((e) => named(e.shape) && exact(shape, e.shape))
                    ?.name ??
                (/^orbit:node:\d+:firewall:(.+)$/.exec(shape.comment)?.[1] ??
                    (shape.comment || "unmanaged"));
        return Row.parse({
            name,
            comment: shape.comment,
            action: shape.action,
            source: shape.source,
            destination: shape.destination,
            port: shape.port,
            protocol: shape.protocol,
            interface: shape.in_interface,
            family: shape.family,
            match,
        });
    };
    const classified = live.map((rule) => {
        const owners = expected.filter((e) => e.shape.comment === rule.comment);
        const candidates = owners.length
            ? owners
            : expected.filter((e) => named(e.shape) && exact(rule, e.shape));
        return view(
            rule,
            candidates.length === 0
                ? "unmanaged"
                : candidates.some((e) => exact(rule, e.shape))
                ? "exact"
                : "drift",
        );
    });
    const missing = backend === "active"
        ? expected.filter((e) => {
            if (!named(e.shape)) {
                return !live.some((r) => r.comment === e.shape.comment);
            }
            if (live.some((r) => matches(r, e.shape))) return false;
            const [start, end = start] = bounds(e.shape.port);
            return Array.from({ length: end - start + 1 }, (_, index) =>
                start + index)
                .some((port) =>
                    !live.some((r) =>
                        matches(r, e.shape, String(port))
                    )
                );
        }).map((e) => view({ ...e.shape, family: "v4" }, "missing"))
        : [];
    const result = Snapshot.parse({
        backend,
        live,
        observed_at: new Date().toISOString(),
        comparison: { live: classified, missing },
    });
    if (new TextEncoder().encode(JSON.stringify(result)).length >= Limit) {
        throw new Error("firewall.inspection_too_large");
    }
    return result;
}
export const behavior = {
    type: "@nckrtl/firewall-inspection",
    version: "2026.11.01.2",
    resources: {
        result: {
            schema: z.object({ stdout: z.string(), exitCode: z.literal(0) }),
            lifetime: "1d",
            garbageCollection: 10,
        },
    },
    methods: {
        inspect: {
            description:
                "Parse and compare one native pinned SSH UFW observation against admitted intent",
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
            ) => ({
                dataHandles: [
                    await ctx.writeResource("result", "result", {
                        stdout: JSON.stringify(inspectionResult(args)),
                        exitCode: 0,
                    }),
                ],
            }),
        },
    },
};
