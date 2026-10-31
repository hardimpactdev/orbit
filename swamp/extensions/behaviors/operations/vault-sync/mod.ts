import { z } from "npm:zod@4.4.3";

const Text = z.string().refine(
    (value) => new TextEncoder().encode(value).length <= 1024,
    "Public text exceeds 1 KiB",
);
const Desired = z.object({ source: Text, fingerprint: Text }).strict();
const Ledger = z.object({
    source: Text,
    cipher_id: Text,
    revision_date: Text,
    fingerprint: Text,
}).strict();
export const Intent = z.object({
    url: z.string().url(),
    client_id: Text,
    organization_id: Text,
    collection_id: Text,
    ca_file: z.string().startsWith("/"),
    wanted: z.array(Desired).max(4096),
    ledger: z.array(Ledger).max(4096),
}).strict().superRefine((value, ctx) => {
    for (const rows of [value.wanted, value.ledger]) {
        if (new Set(rows.map((row) => row.source)).size !== rows.length) {
            ctx.addIssue({ code: "custom", message: "Duplicate source" });
        }
    }
});
const Cipher = z.object({
    id: Text,
    source: Text.nullable(),
    organization_id: Text,
    collection_ids: z.array(Text),
    revision: Text.nullable(),
    deleted: z.boolean(),
}).strict();
const Record = z.object({
    source: Text,
    cipher_id: Text,
    revision: Text,
    fingerprint: Text,
}).strict();
export const Plan = z.object({
    deletes: z.array(
        z.object({ id: Text, source: Text, input_json: z.string() }).strict(),
    ),
    writes: z.array(
        z.object({
            source: Text,
            fingerprint: Text,
            cipher_id: Text.nullable(),
            input_json: z.string(),
        }).strict(),
    ),
    unchanged: z.array(Record),
}).strict();

/** Public metadata only. Each remote mutation is a separate native command step. */
export function planSync(raw: unknown, inventory: unknown) {
    const intent = Intent.parse(raw);
    const ciphers = z.array(Cipher).max(16384).parse(inventory).filter((item) =>
        item.organization_id === intent.organization_id &&
        item.collection_ids.includes(intent.collection_id)
    );
    const wanted = new Map(intent.wanted.map((row) => [row.source, row]));
    const ledger = new Map(intent.ledger.map((row) => [row.source, row]));
    const kept = new Map<string, z.infer<typeof Cipher>>();
    const deletes: z.infer<typeof Plan>["deletes"] = [];
    for (const item of ciphers) {
        if (item.source === null) continue;
        const previous = ledger.get(item.source);
        if (previous && previous.cipher_id !== item.id) continue;
        if (item.deleted || !wanted.has(item.source)) {
            if (previous?.cipher_id === item.id) {
                if (
                    item.collection_ids.some((id) =>
                        id !== intent.collection_id
                    )
                ) {
                    throw new Error("vault.item_shared");
                }
                deletes.push({
                    id: item.id,
                    source: item.source,
                    input_json: JSON.stringify({
                        id: item.id,
                        source: item.source,
                    }),
                });
            }
            continue;
        }
        if (!kept.has(item.source)) kept.set(item.source, item);
    }
    const writes: z.infer<typeof Plan>["writes"] = [];
    const unchanged: z.infer<typeof Plan>["unchanged"] = [];
    for (const item of intent.wanted) {
        const previous = ledger.get(item.source),
            existing = kept.get(item.source);
        if (
            existing && previous?.cipher_id === existing.id &&
            previous.fingerprint === item.fingerprint &&
            previous.revision_date === existing.revision
        ) {
            unchanged.push({
                source: item.source,
                cipher_id: existing.id,
                revision: existing.revision ?? "",
                fingerprint: item.fingerprint,
            });
        } else {
            writes.push({
                ...item,
                cipher_id: existing?.id ?? null,
                input_json: JSON.stringify({
                    ...item,
                    cipher_id: existing?.id ?? null,
                }),
            });
        }
    }
    return Plan.parse({ deletes, writes, unchanged });
}

export function resultSync(raw: unknown, writes: unknown[]) {
    const plan = Plan.parse(raw);
    const records = [
        ...plan.unchanged,
        ...writes.map((value) => Record.parse(value)),
    ];
    const expected = new Map(plan.writes.map((row) => [row.source, row]));
    for (const row of records.slice(plan.unchanged.length)) {
        const wanted = expected.get(row.source);
        if (!wanted || wanted.fingerprint !== row.fingerprint) {
            throw new Error("vault.result_invalid");
        }
        expected.delete(row.source);
    }
    if (expected.size) throw new Error("vault.sync_failed");
    const result = {
        outcome: "succeeded",
        data: {
            backend: "synced",
            sources: records.map((row) => row.source),
            cipher_ids: records.map((row) => row.cipher_id),
            revisions: records.map((row) => row.revision),
            fingerprints: records.map((row) => row.fingerprint),
        },
    };
    if (new TextEncoder().encode(JSON.stringify(result)).length > 262144) {
        throw new Error("vault.result_invalid");
    }
    return result;
}

type Context = {
    extensionFile(name: string): string;
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
export const behavior = {
    type: "@nckrtl/vault-sync-plan",
    version: "2026.10.31.1",
    resources: {
        assets: {
            description: "Shipped CLI action paths and public intent",
            lifetime: "infinite",
            garbageCollection: 10,
            schema: z.object({
                scripts: z.record(z.string(), z.string()),
                intent_json: z.string(),
            }),
        },
        plan: {
            description: "Public per-cipher reconciliation plan",
            schema: Plan,
            lifetime: "infinite",
            garbageCollection: 10,
        },
        result: {
            description: "Public terminal ledger JSON",
            schema: z.object({ stdout: z.string() }),
            lifetime: "infinite",
            garbageCollection: 10,
        },
    },
    methods: {
        assets: {
            description: "Resolve CLI scripts without execution",
            arguments: z.object({ intent: Intent }),
            execute: async (
                args: { intent: z.infer<typeof Intent> },
                ctx: Context,
            ) => {
                const assets = {
                    scripts: Object.fromEntries(
                        [
                            "configure",
                            "login",
                            "unlock",
                            "sync",
                            "inventory",
                            "delete",
                            "write",
                            "logout",
                        ].map((
                            name,
                        ) => [
                            name,
                            ctx.extensionFile("scripts/" + name + ".py"),
                        ]),
                    ),
                    intent_json: JSON.stringify(args.intent),
                };
                await ctx.writeResource("assets", "assets", assets);
                return assets;
            },
        },
        plan: {
            description: "Plan changes from public inventory",
            arguments: z.object({
                intent: Intent,
                inventory: z.string(),
                trash: z.string(),
            }),
            execute: async (
                args: {
                    intent: z.infer<typeof Intent>;
                    inventory: string;
                    trash: string;
                },
                ctx: Context,
            ) => {
                const plan = planSync(args.intent, [
                    ...JSON.parse(args.inventory),
                    ...JSON.parse(args.trash),
                ]);
                await ctx.writeResource("plan", "plan", plan);
                return plan;
            },
        },
        result: {
            description: "Assemble public reported ledger",
            arguments: z.object({
                plan: Plan.nullable(),
                writes: z.array(z.string()),
                successful: z.boolean(),
            }),
            execute: async (
                args: {
                    plan: z.infer<typeof Plan> | null;
                    writes: string[];
                    successful: boolean;
                },
                ctx: Context,
            ) => {
                const result = {
                    stdout: JSON.stringify(
                        args.successful && args.plan
                            ? resultSync(
                                args.plan,
                                args.writes.map((row) => JSON.parse(row)),
                            )
                            : { error_code: "vault.sync_failed" },
                    ),
                };
                await ctx.writeResource("result", "result", result);
                return result;
            },
        },
    },
};
