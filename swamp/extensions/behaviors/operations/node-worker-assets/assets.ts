import { z } from "npm:zod@4.4.3";

const ALERT_HOURS = 72;
const SOON_HOURS = 6;
// Expiry alerts skip credentials that expired over a week ago.
const STALE_HOURS = -168;
// orbit-orchestrator-read has no fleet consumer, so nothing renews it: the operator gets ten days' notice instead.
const NOTICE = { family: "orbit-orchestrator-read", hours: 240 } as const;
// A renewal mints `<base>-<YYYYMMDD>[-<n>]` (or an earlier `<base>-<UTC stamp>`); the plain base is the first generation.
const family = (name: string) => name.replace(/-\d{8}(T\d{6}Z)?(-\d+)?$/, "");
// Keeps one bounded alert list in the step output.
export const MAXIMUM_ROWS = 200;
// Retired per-Node workers (orbit-node-209) are no longer expected.
const EXPECTED: readonly (readonly [string, string])[] = [];

const Expiry = z.string().nullable();
const AccessRow = z.object({
    name: z.string(),
    principalId: z.string(),
    state: z.string(),
    expiresAt: Expiry,
}).passthrough();
const EnrollmentRow = z.object({
    name: z.string(),
    state: z.string(),
    expiresAt: Expiry,
}).passthrough();
const Lists = {
    access: z.union([
        z.array(AccessRow),
        z.object({ tokens: z.array(AccessRow) }).passthrough(),
    ]),
    enrollment: z.array(EnrollmentRow),
};
export const ReportArgs = z.object({
    access: z.string(),
    enrollment: z.string(),
    certificate: z.string(),
    // The token names the Swamp Node's consumers hold, one per line: serve-driver and each worker slot's two files.
    held: z.string(),
}).strict();
const Alert = z.object({
    kind: z.string(),
    name: z.string(),
    reason: z.enum(["within_10d", "within_72h", "within_6h", "expired", "missing"]),
    expires_at: z.string().nullable(),
    hours_left: z.number().nullable(),
}).strict();
const Report = z.object({ checked_at: z.string(), alerts: z.array(Alert) })
    .strict();

type Credential = {
    kind: string;
    name: string;
    expires_at: string | null;
    hours_left: number | null;
    class: string;
};

function parsed<T>(kind: keyof typeof Lists, stdout: string): T[] {
    let value: unknown;
    try {
        value = JSON.parse(stdout);
    } catch {
        throw new Error(`${kind} list is not JSON`);
    }
    const result = Lists[kind].safeParse(value);
    if (!result.success) throw new Error(`${kind} list changed shape`);
    const data = result.data;
    return (Array.isArray(data) ? data : data.tokens) as T[];
}

function record(
    kind: string,
    name: string,
    expires: string | null,
    now: Date,
): Credential {
    if (expires === null) {
        return {
            kind,
            name,
            expires_at: null,
            hours_left: null,
            class: "no_expiry",
        };
    }
    if (!/(Z|[+-]\d\d:\d\d)$/.test(expires)) {
        throw new Error(`${kind} ${name} expiry has no time zone`);
    }
    const end = new Date(expires);
    if (Number.isNaN(end.getTime())) {
        throw new Error(`${kind} ${name} expiry is not a date`);
    }
    const hours = Math.round((end.getTime() - now.getTime()) / 360_000) / 10;
    const category = hours <= 0
        ? "expired"
        : hours <= SOON_HOURS
        ? "within_6h"
        : hours <= ALERT_HOURS
        ? "within_72h"
        : family(name) === NOTICE.family && hours <= NOTICE.hours
        ? "within_10d"
        : "ok";
    return {
        kind,
        name,
        expires_at: end.toISOString(),
        hours_left: hours,
        class: category,
    };
}

function certificateEnd(stdout: string): string {
    const text = stdout.trim();
    const end = new Date(text.replace(/^notAfter=/, ""));
    if (!text.startsWith("notAfter=") || Number.isNaN(end.getTime())) {
        throw new Error("openssl returned no notAfter");
    }
    return end.toISOString();
}

/** Classifies the three read-only native outputs; reads nothing itself. */
export function report(
    args: z.infer<typeof ReportArgs>,
    now: Date,
): z.infer<typeof Report> {
    const listed: Credential[] = [];
    for (const kind of ["access", "enrollment"] as const) {
        // A superseded generation stays listed until its revoke. It is skipped only when a consumer holds a newer generation of
        // its family and principal; the Gateway env is not visible here, so its generations all count (the Gateway raises its own assistance).
        const rows = parsed<
            {
                name: string;
                principalId?: string;
                state: string;
                expiresAt: string | null;
            }
        >(kind, args[kind]).filter((row) => row.state !== "revoked");
        const byName = new Map(rows.map((row) => [row.name, row]));
        const held = args.held.split("\n").map((name) => name.trim())
            .filter((name) => byName.has(name));
        for (const row of rows) {
            const superseded = held.some((name) => {
                const current = byName.get(name)!;
                return name !== row.name &&
                    family(name) === family(row.name) &&
                    (kind !== "access" ||
                        (typeof current.principalId === "string" &&
                            current.principalId === row.principalId)) &&
                    row.expiresAt !== null &&
                    (current.expiresAt === null ||
                        current.expiresAt > row.expiresAt);
            });
            if (!superseded) listed.push(record(kind, row.name, row.expiresAt, now));
        }
    }
    listed.push(
        record("tls", "swamp.orbit", certificateEnd(args.certificate), now),
    );
    listed.sort((a, b) =>
        (a.hours_left === null ? 1 : 0) - (b.hours_left === null ? 1 : 0) ||
        (a.hours_left ?? 0) - (b.hours_left ?? 0)
    );
    const names = new Set(listed.map((item) => `${item.kind}/${item.name}`));
    const alerts = [
        ...EXPECTED.filter(([kind, name]) => !names.has(`${kind}/${name}`))
            .map(([kind, name]) => ({
                kind,
                name,
                reason: "missing" as const,
                expires_at: null,
                hours_left: null,
            })),
        ...listed.filter((item) =>
            item.hours_left === null || item.hours_left > STALE_HOURS
        )
            .filter((item) =>
                ["within_10d", "within_72h", "within_6h", "expired"].includes(
                    item.class,
                )
            )
            .map((item) => ({
                kind: item.kind,
                name: item.name,
                reason: item.class as
                    | "within_10d"
                    | "within_72h"
                    | "within_6h"
                    | "expired",
                expires_at: item.expires_at,
                hours_left: item.hours_left,
            })),
    ];
    return Report.parse({
        checked_at: now.toISOString(),
        alerts: alerts.slice(0, MAXIMUM_ROWS),
    });
}

export const behavior = {
    type: "@nckrtl/node-worker-assets",
    version: "2026.11.21.3",
    resources: {
        credential_report: {
            schema: Report,
            lifetime: "infinite",
            garbageCollection: 10,
        },
    },
    methods: {
        credential_report: {
            description:
                "Classify Swamp credential expiry from the read-only native lists",
            arguments: ReportArgs,
            execute: async (raw: unknown, ctx: {
                writeResource: (
                    spec: string,
                    name: string,
                    value: unknown,
                ) => Promise<unknown>;
            }) => {
                const result = report(ReportArgs.parse(raw), new Date());
                await ctx.writeResource("credential_report", "report", result);
                return result;
            },
        },
    },
};
