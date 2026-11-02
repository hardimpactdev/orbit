import { encodeBase64 } from "jsr:@std/encoding@1.0.10/base64";
import { z } from "npm:zod@4.4.3";

const ObjectData = z.record(z.string(), z.unknown());
const PrivateText = z.string().meta({ sensitive: true });
const Directory = z.string().startsWith("/");
type Context = {
    modelId: string;
    writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
};
const resource = (description: string, schema: z.ZodType) => ({
    description,
    schema,
    lifetime: "infinite",
    garbageCollection: 10,
    vaultName: "orbit-control-inputs",
});
const save = async (ctx: Context, spec: string, value: unknown) => ({
    dataHandles: [await ctx.writeResource(spec, spec, value)],
});
async function read(directory: string, name: string) {
    try {
        return JSON.parse(await Deno.readTextFile(`${directory}/${name}`));
    } catch {
        throw new Error("vault.result_invalid");
    }
}
function configuration(intent: Record<string, unknown>, raw: string) {
    let value: Record<string, unknown>;
    try {
        value = JSON.parse(raw);
    } catch {
        throw new Error("vault.intent_changed");
    }
    for (
        const name of [
            "url",
            "client_id",
            "organization_id",
            "collection_id",
            "organization",
            "collection",
        ]
    ) {
        if (name in intent && value[name] !== intent[name]) {
            throw new Error("vault.intent_changed");
        }
    }
    return value;
}
const Exclusions = z.object({
    ORBIT_CLIENT_SECRET: PrivateText,
    ORBIT_MASTER_PASSWORD: PrivateText,
});
type ExclusionValues = {
    ORBIT_CLIENT_SECRET: string;
    ORBIT_MASTER_PASSWORD: string;
};
function publicMetadata(value: Record<string, string>, env: ExclusionValues) {
    if (
        Object.values(value).some((text) =>
            Object.values(env).some((secret) =>
                secret !== "" && text.includes(secret)
            )
        )
    ) throw new Error("vault.result_invalid");
    return value;
}
export const cliDataResources = {
    inputs: resource(
        "CLI credentials stored as native sensitive fields",
        z.object({
            intent_json: z.string(),
            client_secret_key: z.string(),
            master_password_key: z.string(),
            client_secret: PrivateText,
            master_password: PrivateText,
        }),
    ),
    session: resource(
        "Native sensitive CLI session",
        z.object({ value: PrivateText, session_key: z.string() }),
    ),
    payload: resource(
        "Native sensitive cipher stdin",
        z.object({ path: z.string() }),
    ),
    inventory: resource(
        "Public cipher inventory",
        z.object({ stdout: z.string() }),
    ),
    writeReceipt: resource(
        "Public cipher write receipt",
        z.object({ stdout: z.string() }),
    ),
    account: resource(
        "Public account and KDF metadata",
        z.object({ email: z.string() }),
    ),
    organization: resource(
        "Public selected organization",
        z.object({ id: z.string(), name: z.string() }),
    ),
    collection: resource(
        "Public selected collection",
        z.object({
            id: z.string(),
            name: z.string(),
            payload_path: z.string(),
        }),
    ),
    probe: resource(
        "Public setup probe identity",
        z.object({ id: z.string() }),
    ),
    setup: resource(
        "Public account verification",
        z.object({ stdout: z.string() }),
    ),
};
export const cliDataMethods = {
    prepare: {
        description:
            "Bind frozen public intent to native sensitive credential fields",
        arguments: z.object({
            intent: ObjectData,
            env: z.object({ ORBIT_VAULT_CONFIGURATION: PrivateText }),
        }),
        async execute(
            args: {
                intent: Record<string, unknown>;
                env: { ORBIT_VAULT_CONFIGURATION: string };
            },
            ctx: Context,
        ) {
            const config = configuration(
                args.intent,
                args.env.ORBIT_VAULT_CONFIGURATION,
            );
            return await save(ctx, "inputs", {
                intent_json: JSON.stringify(args.intent),
                client_secret_key:
                    `nckrtl-vault-sync-plan-${ctx.modelId}-prepare-inputs-inputs-client_secret`,
                master_password_key:
                    `nckrtl-vault-sync-plan-${ctx.modelId}-prepare-inputs-inputs-master_password`,
                client_secret: z.string().parse(config.client_secret),
                master_password: z.string().parse(config.master_password),
            });
        },
    },
    session: {
        description: "Shape private CLI stdout into a native sensitive session",
        arguments: z.object({ directory: Directory }),
        async execute(args: { directory: string }, ctx: Context) {
            let value: string;
            try {
                value = (await Deno.readTextFile(`${args.directory}/session`))
                    .trim();
            } catch {
                throw new Error("vault.login_failed");
            }
            if (!value) throw new Error("vault.login_failed");
            return await save(ctx, "session", {
                value,
                session_key:
                    `nckrtl-vault-sync-plan-${ctx.modelId}-session-session-session-value`,
            });
        },
    },
    inventory: {
        description: "Whitelist public metadata from private CLI inventory",
        arguments: z.object({
            directory: Directory,
            file: z.enum(["inventory", "trash"]),
            intent: ObjectData,
        }),
        async execute(
            args: {
                directory: string;
                file: string;
                intent: Record<string, unknown>;
            },
            ctx: Context,
        ) {
            const known = new Set(
                [
                    ...(args.intent.wanted as { source: string }[]),
                    ...(args.intent.ledger as { source: string }[]),
                ].map((row) => row.source),
            );
            const records = z.array(ObjectData).parse(
                await read(args.directory, args.file),
            );
            const output = records.map((row) => {
                const source = (row.fields as
                    | { name: string; value: string }[]
                    | undefined)?.find((field) => field.name === "orbit.source")
                    ?.value ?? null;
                return {
                    id: row.id,
                    source: known.has(source!) ? source : null,
                    organization_id: row.organizationId,
                    collection_ids: row.collectionIds ?? [],
                    revision: row.revisionDate ?? null,
                    deleted: row.deletedDate != null,
                };
            });
            return await save(ctx, "inventory", {
                stdout: JSON.stringify(output),
            });
        },
    },
    encode: {
        description: "Shape one admitted cipher as native sensitive CLI stdin",
        arguments: z.object({
            intent: ObjectData,
            env: z.object({ ORBIT_VAULT_CONFIGURATION: PrivateText }),
            item: ObjectData,
            directory: Directory,
            index: z.number().int().nonnegative(),
        }),
        async execute(
            args: {
                intent: Record<string, unknown>;
                env: { ORBIT_VAULT_CONFIGURATION: string };
                item: Record<string, unknown>;
                directory: string;
                index: number;
            },
            ctx: Context,
        ) {
            const config = configuration(
                args.intent,
                args.env.ORBIT_VAULT_CONFIGURATION,
            );
            const wanted = (config.wanted as Record<string, unknown>[]).find(
                (row) =>
                    row.source === args.item.source &&
                    row.fingerprint === args.item.fingerprint,
            );
            if (!wanted) throw new Error("vault.intent_changed");
            const cipher = wanted.cipher as {
                name: string;
                notes: string;
                fields: { name: string; value: string; hidden: boolean }[];
            };
            const payload = {
                type: 2,
                organizationId: args.intent.organization_id,
                collectionIds: [args.intent.collection_id],
                folderId: null,
                name: cipher.name,
                notes: cipher.notes,
                secureNote: { type: 0 },
                favorite: false,
                reprompt: 0,
                fields: cipher.fields.map((field) => ({
                    name: field.name,
                    value: field.value,
                    type: field.hidden ? 1 : 0,
                })),
            };
            const bytes = new TextEncoder().encode(JSON.stringify(payload));
            const path = `${args.directory}/payload-${args.index}`;
            await Deno.writeTextFile(path, encodeBase64(bytes), {
                mode: 0o600,
            });
            return await save(ctx, "payload", { path });
        },
    },
    writeReceipt: {
        description: "Whitelist one private CLI write response",
        arguments: z.object({
            directory: Directory,
            index: z.number().int().nonnegative(),
            item: ObjectData,
        }),
        async execute(
            args: {
                directory: string;
                index: number;
                item: Record<string, unknown>;
            },
            ctx: Context,
        ) {
            const value = await read(args.directory, `write-${args.index}`);
            const record = {
                source: args.item.source,
                cipher_id: z.string().parse(value.id),
                revision: z.string().parse(value.revisionDate ?? ""),
                fingerprint: args.item.fingerprint,
            };
            return await save(ctx, "writeReceipt", {
                stdout: JSON.stringify(record),
            });
        },
    },
    account: {
        description:
            "Shape public account metadata and enforce the supported CLI KDF",
        arguments: z.object({ directory: Directory, env: Exclusions }),
        async execute(
            args: { directory: string; env: ExclusionValues },
            ctx: Context,
        ) {
            const status = await read(args.directory, "status");
            const data = await read(args.directory, "data.json");
            const kdf = data[`user_${status.userId}_kdfConfig_kdfConfig`];
            if (
                kdf?.kdfType !== 0 || !Number.isSafeInteger(kdf.iterations) ||
                kdf.iterations < 1 || kdf.iterations > 2000000
            ) throw new Error("vault.kdf_unsupported");
            return await save(
                ctx,
                "account",
                publicMetadata({
                    email: z.string().max(1024).parse(status.userEmail),
                }, args.env),
            );
        },
    },
    organization: {
        description: "Select the admitted organization from private CLI data",
        arguments: z.object({
            directory: Directory,
            intent: ObjectData,
            env: Exclusions,
        }),
        async execute(
            args: {
                directory: string;
                intent: Record<string, unknown>;
                env: ExclusionValues;
            },
            ctx: Context,
        ) {
            const rows = (await read(args.directory, "organizations") as {
                id: string;
                name: string;
            }[]).filter((row) =>
                row.id === args.intent.organization ||
                row.name === args.intent.organization
            );
            if (rows.length !== 1) {
                throw new Error("vault.organization_missing");
            }
            return await save(
                ctx,
                "organization",
                publicMetadata({
                    id: rows[0].id,
                    name: rows[0].name,
                }, args.env),
            );
        },
    },
    collection: {
        description:
            "Select the admitted collection and shape the harmless write probe",
        arguments: z.object({
            directory: Directory,
            intent: ObjectData,
            organization: z.string(),
            env: Exclusions,
        }),
        async execute(
            args: {
                directory: string;
                intent: Record<string, unknown>;
                organization: string;
                env: ExclusionValues;
            },
            ctx: Context,
        ) {
            const rows = (await read(args.directory, "collections") as {
                id: string;
                name: string;
                organizationId: string;
            }[]).filter((row) =>
                row.organizationId === args.organization &&
                (row.id === args.intent.collection ||
                    row.name === args.intent.collection)
            );
            if (rows.length !== 1) throw new Error("vault.collection_missing");
            const previous = args.intent.previous as {
                organization_id: string;
                collection_id: string;
            } | undefined;
            if (
                args.intent.published && previous &&
                (previous.organization_id !== args.organization ||
                    previous.collection_id !== rows[0].id)
            ) throw new Error("vault.items_published");
            const payload = {
                type: 2,
                secureNote: { type: 0 },
                name: "Orbit setup check",
                organizationId: args.organization,
                collectionIds: [rows[0].id],
            };
            await Deno.writeTextFile(
                `${args.directory}/probe-input`,
                encodeBase64(JSON.stringify(payload)),
                { mode: 0o600 },
            );
            publicMetadata({ id: rows[0].id, name: rows[0].name }, args.env);
            return await save(ctx, "collection", {
                id: rows[0].id,
                name: rows[0].name,
                payload_path: `${args.directory}/probe-input`,
            });
        },
    },
    probe: {
        description: "Shape the harmless probe identity for declared cleanup",
        arguments: z.object({ directory: Directory }),
        async execute(args: { directory: string }, ctx: Context) {
            return await save(ctx, "probe", {
                id: z.string().min(1).parse(
                    (await read(args.directory, "probe")).id,
                ),
            });
        },
    },
    setup: {
        description:
            "Shape bounded public setup metadata after native account checks",
        arguments: z.object({
            intent: ObjectData,
            env: z.object({ ORBIT_VAULT_CONFIGURATION: PrivateText }),
            email: z.string(),
            organization: z.object({ id: z.string(), name: z.string() }),
            collection: z.object({ id: z.string(), name: z.string() }),
        }),
        async execute(
            args: {
                intent: Record<string, unknown>;
                env: { ORBIT_VAULT_CONFIGURATION: string };
                email: string;
                organization: { id: string; name: string };
                collection: { id: string; name: string };
            },
            ctx: Context,
        ) {
            const config = configuration(
                args.intent,
                args.env.ORBIT_VAULT_CONFIGURATION,
            );
            const data = {
                backend: "configured",
                email: args.email,
                organization_id: args.organization.id,
                organization_name: args.organization.name,
                collection_id: args.collection.id,
                collection_name: args.collection.name,
            };
            const secrets = [
                config.client_secret,
                config.master_password,
            ] as string[];
            if (
                Object.values(data).some((value) =>
                    new TextEncoder().encode(value).length > 1024 ||
                    secrets.some((secret) =>
                        secret !== "" && value.includes(secret)
                    )
                )
            ) throw new Error("vault.result_invalid");
            return await save(ctx, "setup", {
                stdout: JSON.stringify({ outcome: "succeeded", data }),
            });
        },
    },
};
