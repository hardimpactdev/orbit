/**
 * Plans and checks one Orbit allow or deny from `ufw status numbered`. A typed step on 209: official
 * `@swamp/cfgmgmt/firewall` cannot insert a rule at a position (Lab #4334) or name a port range, so each change
 * runs as one `ufw` argv over `@swamp/ssh exec`, and this behavior only reads ufw's output.
 *
 * Lines match by tuple; the comment is only for people. UFW stops at the first match, so each line sits above
 * Orbit's own managed lines (comment `orbit:<name>`) and above every broader operator line, and below every
 * narrower one: one address, then a CIDR, then anywhere. Each family is placed on its own.
 */
import { z } from "npm:zod@4.4.3";

const Ufw = "/usr/sbin/ufw";
const Rule = z.object({
    action: z.enum(["allow", "deny"]),
    port: z.string().regex(/^\d{1,5}(?::\d{1,5})?$/),
    protocol: z.enum(["tcp", "udp", "any"]),
    source: z.string(),
    comment: z.string().regex(/^orbit(?: [\x20-\x7e]{1,64})?$/).refine((
        value,
    ) => !/['"]/.test(value)),
    retain: z.boolean().default(false),
}).passthrough();
const Intent = z.object({ rule: Rule, ensure: z.enum(["present", "absent"]) })
    .passthrough();

export type Row = {
    to: string;
    action: string;
    source: string;
    comment: string;
    direction?: string;
};
type Valid = z.infer<typeof Rule> & { protocols: string[] };

export class Refusal extends Error {}

function validated(intent: unknown): { rule: Valid; ensure: string } {
    const parsed = Intent.safeParse(intent);
    if (!parsed.success) throw new Refusal("firewall.input_invalid");
    const { rule, ensure } = parsed.data;
    const [first, last = first] = rule.port.split(":").map(Number);
    if (!(1 <= first && first <= last && last <= 65535)) {
        throw new Refusal("firewall.input_invalid");
    }
    if (rule.source !== "any") network(rule.source);
    return {
        rule: {
            ...rule,
            protocols: rule.protocol === "any"
                ? ["tcp", "udp"]
                : [rule.protocol],
        },
        ensure,
    };
}

/** The rows of `ufw status numbered`, in order; refuses an inactive firewall. */
export function inventory(stdout: string): Row[] {
    const lines = stdout.split("\n");
    if (lines.filter((line) => line.trim() === "Status: active").length !== 1) {
        throw new Refusal("firewall.backend_inactive");
    }
    return lines.flatMap((line) => {
        const numbered = line.trim().match(/^\[\s*\d+\]\s+(.+)$/);
        if (!numbered) return [];
        const match = numbered[1].match(
            /^(.+?)\s+(ALLOW|DENY|LIMIT|REJECT)(?:\s+(IN|OUT|FWD))?\s+(.+?)(?:\s+#\s*(.*))?$/,
        );
        // Every numbered row occupies an insert position, including unrelated actions and directions.
        return [
            match
                ? {
                    to: match[1].trim(),
                    action: match[2].toLowerCase(),
                    direction: (match[3] ?? "IN").toLowerCase(),
                    source: match[4].trim(),
                    comment: (match[5] ?? "").trim(),
                }
                : { to: numbered[1], action: "", source: "", comment: "" },
        ];
    });
}

type Network = { six: boolean; base: bigint; bits: number };

function network(text: string): Network {
    let [address, prefix] = text.split("/");
    const six = address.includes(":");
    const width = six ? 128 : 32;
    let value = 0n;
    if (six) {
        if (address.includes(".")) {
            const colon = address.lastIndexOf(":");
            const ipv4 = network(address.slice(colon + 1));
            address = address.slice(0, colon + 1) +
                (ipv4.base >> 16n).toString(16) + ":" +
                (ipv4.base & 65535n).toString(16);
        }
        const halves = address.split("::");
        const [head, tail = ""] = halves;
        const left = head ? head.split(":") : [];
        const right = address.includes("::")
            ? (tail ? tail.split(":") : [])
            : [];
        if (
            halves.length > 2 ||
            (halves.length === 1
                ? left.length !== 8
                : left.length + right.length >= 8)
        ) {
            throw new Refusal("firewall.input_invalid");
        }
        const groups = [
            ...left,
            ...Array(8 - left.length - right.length).fill("0"),
            ...right,
        ];
        if (
            groups.length !== 8 ||
            !groups.every((group) => /^[0-9a-f]{1,4}$/i.test(group))
        ) {
            throw new Refusal("firewall.input_invalid");
        }
        for (const group of groups) {
            value = (value << 16n) | BigInt(parseInt(group, 16));
        }
    } else {
        const octets = address.split(".");
        if (
            octets.length !== 4 ||
            !octets.every((octet) =>
                /^\d{1,3}$/.test(octet) && Number(octet) <= 255
            )
        ) {
            throw new Refusal("firewall.input_invalid");
        }
        for (const octet of octets) value = (value << 8n) | BigInt(octet);
    }
    const bits = prefix === undefined ? width : Number(prefix);
    if (!Number.isInteger(bits) || bits < 0 || bits > width) {
        throw new Refusal("firewall.input_invalid");
    }
    const host = (1n << BigInt(width - bits)) - 1n;
    return { six, base: value & ~host, bits };
}

function contains(wide: Network, narrow: Network) {
    if (wide.six !== narrow.six || narrow.bits < wide.bits) return false;
    const width = wide.six ? 128 : 32;
    const host = (1n << BigInt(width - wide.bits)) - 1n;
    return (narrow.base & ~host) === wide.base;
}

/** (argument for `from`, `ufw status` row) per family: anywhere is one explicit line per family. */
function lines(rule: Valid, protocol: string): [string, Row][] {
    const to = `${rule.port}/${protocol}`;
    const row = (
        source: string,
        suffix: string,
        shown: string,
    ): [string, Row] => [
        source,
        {
            to: to + suffix,
            action: rule.action,
            source: shown,
            comment: rule.comment,
        },
    ];
    if (rule.source === "any") {
        return [
            row("0.0.0.0/0", "", "Anywhere"),
            row("::/0", " (v6)", "Anywhere (v6)"),
        ];
    }
    const source = network(rule.source);
    if (source.bits === 0) {
        return [
            row(
                rule.source,
                source.six ? " (v6)" : "",
                source.six ? "Anywhere (v6)" : "Anywhere",
            ),
        ];
    }
    // ufw marks only a line for anywhere with `(v6)`; a line from one IPv6 address shows the address alone.
    return [row(rule.source, "", rule.source)];
}

const incoming = (row: Row) =>
    (row.direction ?? "in") === "in" && ["allow", "deny"].includes(row.action);
const key = (row: Row) => {
    let source = row.source;
    if (incoming(row) && ports(row.to) && !source.includes(" on ")) {
        const address = scope(source);
        source = `${address.six}:${address.base}/${address.bits}`;
    }
    return `${row.to}\0${row.action}\0${row.direction ?? "in"}\0${source}`;
};
const v6 = (row: Row) =>
    row.to.includes("(v6)") || row.source.includes("(v6)") ||
    row.source.includes(":");
const managed = (row: Row) => row.comment.startsWith("orbit:");

function ports(to: string): [number, number, string | null] | null {
    const match = to.match(
        /^(\d{1,5})(?::(\d{1,5}))?(?:\/(tcp|udp))?(?: \(v6\))?$/,
    );
    return match
        ? [Number(match[1]), Number(match[2] ?? match[1]), match[3] ?? null]
        : null;
}

function scope(source: string): Network {
    const text = source.split(" on ")[0].replace(/ \(v6\)$/, "");
    if (text === "Anywhere") {
        return network(source.endsWith("(v6)") ? "::/0" : "0.0.0.0/0");
    }
    return network(text);
}

/** Prefer `row` below `other`: a managed line, or a broader overlapping operator line. */
function below(row: Row, other: Row): boolean {
    if (!incoming(row) || !incoming(other) || v6(row) !== v6(other)) {
        return false;
    }
    const a = ports(row.to), b = ports(other.to);
    if (
        a && b &&
        (a[1] < b[0] || b[1] < a[0] ||
            (a[2] !== null && b[2] !== null && a[2] !== b[2]))
    ) return false;
    if (managed(row) || managed(other)) {
        return managed(row) && !managed(other);
    }
    if (!a || !b) return false;
    const wide = scope(row.source), narrow = scope(other.source);
    return !(wide.base === narrow.base && wide.bits === narrow.bits) &&
        contains(wide, narrow);
}

function ordered(rows: Row[], mine: Set<string>) {
    return rows.every((row, index) =>
        !mine.has(key(row)) ||
        rows.every((other, position) => {
            if (
                !((position < index && below(other, row)) ||
                    (position > index && below(row, other)))
            ) return true;
            // A placement preference requires a move only when both sources can match the same packet.
            const a = scope(row.source), b = scope(other.source);
            return !contains(a, b) && !contains(b, a);
        })
    );
}

function command(
    rule: Valid,
    protocol: string,
    source: string,
    ...tail: string[]
) {
    return [
        rule.action,
        "in",
        "proto",
        protocol,
        "from",
        source,
        "to",
        "any",
        "port",
        rule.port,
        ...tail,
    ];
}

/** The `ufw` argv lists that converge the rule, in order; numbers count the rows as they will be. */
export function plan(intent: unknown, stdout: string): string[][] {
    const { rule, ensure } = validated(intent);
    let rows = inventory(stdout);
    const commands: string[][] = [];
    const run = (...args: string[]) =>
        commands.push(["timeout", "60", Ufw, ...args]);
    for (const protocol of rule.protocols) {
        for (const [source, row] of lines(rule, protocol)) {
            let exists = rows.some((item) => key(item) === key(row));
            if (ensure === "absent") {
                if (exists && !rule.retain) {
                    run(
                        "--force",
                        "delete",
                        ...command(rule, protocol, source),
                    );
                    rows = rows.filter((item) => key(item) !== key(row));
                }
                continue;
            }
            if (exists && !ordered(rows, new Set([key(row)]))) {
                run("--force", "delete", ...command(rule, protocol, source));
                rows = rows.filter((item) => key(item) !== key(row));
                exists = false;
            }
            if (exists) continue;
            const index = rows.findIndex((other) =>
                below(other, { ...row, comment: "" })
            );
            if (index >= 0) {
                run(
                    "insert",
                    String(index + 1),
                    ...command(rule, protocol, source, "comment", rule.comment),
                );
                rows.splice(index, 0, row);
            } else {
                run(...command(
                    rule,
                    protocol,
                    source,
                    "comment",
                    rule.comment,
                ));
                // ufw appends an IPv4 line after the last IPv4 line and an IPv6 line at the end.
                const end = v6(row) ? rows.length : rows.findIndex(v6);
                rows.splice(end < 0 ? rows.length : end, 0, row);
            }
        }
    }
    return commands;
}

/** The public result read back from ufw after the changes; a rule that needed none is skipped as already applied. */
export function check(
    intent: unknown,
    stdout: string,
    changes = 1,
): { backend: string; skipped?: string } {
    const { rule, ensure } = validated(intent);
    const wanted = new Set(
        rule.protocols.flatMap((protocol) =>
            lines(rule, protocol).map(([, row]) => key(row))
        ),
    );
    const rows = inventory(stdout);
    const present = rows.filter((row) => wanted.has(key(row))).length;
    if (ensure === "absent") {
        if (!rule.retain && present) {
            throw new Refusal("firewall.remove_verify_failed");
        }
        return { backend: "absent" };
    }
    if (present !== wanted.size || !ordered(rows, wanted)) {
        throw new Refusal("firewall.verify_failed");
    }
    return changes === 0
        ? { backend: "active", skipped: "firewall.rule_exists" }
        : { backend: "active" };
}

function outcome<T>(compute: () => T): T | { error_code: string } {
    try {
        return compute();
    } catch (error) {
        if (error instanceof Refusal) return { error_code: error.message };
        throw error;
    }
}

type Context = {
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
const Result = z.object({
    stdout: z.string(),
    ok: z.boolean(),
    commands: z.array(z.array(z.string())),
});

export const behavior = {
    type: "@nckrtl/firewall-plan",
    version: "2026.11.07.2",
    resources: {
        result: { schema: Result, lifetime: "1d", garbageCollection: 10 },
    },
    methods: {
        plan: {
            description:
                "Plan the ufw argv lists for one admitted rule from `ufw status numbered`",
            arguments: z.object({
                intent: z.record(z.string(), z.unknown()),
                stdout: z.string(),
            }),
            execute: async (
                args: { intent: unknown; stdout: string },
                ctx: Context,
            ) => {
                const planned = outcome(() => plan(args.intent, args.stdout));
                const value = Array.isArray(planned)
                    ? { stdout: "", ok: true, commands: planned }
                    : {
                        stdout: JSON.stringify(planned),
                        ok: false,
                        commands: [],
                    };
                await ctx.writeResource("result", "plan", value);
                return value;
            },
        },
        check: {
            description:
                "Check the rule against ufw after the changes and print the public result",
            arguments: z.object({
                intent: z.record(z.string(), z.unknown()),
                stdout: z.string(),
                refusal: z.string(),
                changes: z.number().int().min(0),
            }),
            execute: async (
                args: {
                    intent: unknown;
                    stdout: string;
                    refusal: string;
                    changes: number;
                },
                ctx: Context,
            ) => {
                const result = args.refusal
                    ? JSON.parse(args.refusal)
                    : outcome(() =>
                        check(args.intent, args.stdout, args.changes)
                    );
                const value = {
                    stdout: JSON.stringify(result),
                    ok: !("error_code" in result),
                    commands: [],
                };
                await ctx.writeResource("result", "check", value);
                return value;
            },
        },
    },
};
