import { z } from "npm:zod@4.4.3";

const ALERT_HOURS = 72;
const SOON_HOURS = 6;
// Expiry alerts skip credentials that expired over a week ago.
const STALE_HOURS = -168;
// Keeps one bounded alert list in the step output.
export const MAXIMUM_ROWS = 200;
// The Swamp host's own worker pair must always be listed.
const EXPECTED = [["access", "orbit-node-209-access"], [
    "enrollment",
    "orbit-node-209",
]] as const;

const Expiry = z.string().nullable();
const Lists = {
    access: z.array(
        z.object({ name: z.string(), state: z.string(), expiresAt: Expiry })
            .passthrough(),
    ),
    enrollment: z.array(
        z.object({ name: z.string(), state: z.string(), expiresAt: Expiry })
            .passthrough(),
    ),
};
export const ReportArgs = z.object({
    access: z.string(),
    enrollment: z.string(),
    certificate: z.string(),
}).strict();
const Alert = z.object({
    kind: z.string(),
    name: z.string(),
    reason: z.enum(["within_72h", "within_6h", "expired", "missing"]),
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
    return result.data as T[];
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
        for (
            const row of parsed<
                { name: string; state: string; expiresAt: string | null }
            >(kind, args[kind])
        ) {
            if (row.state !== "revoked") {
                listed.push(record(kind, row.name, row.expiresAt, now));
            }
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
                ["within_72h", "within_6h", "expired"].includes(item.class)
            )
            .map((item) => ({
                kind: item.kind,
                name: item.name,
                reason: item.class as "within_72h" | "within_6h" | "expired",
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
    version: "2026.11.13.1",
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
