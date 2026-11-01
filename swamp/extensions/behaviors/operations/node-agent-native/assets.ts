import { z } from "npm:zod@4.4.3";
import { observerFiles } from "../fleet-recipe/observer_assets.ts";
import { assembleFootprint } from "../fleet-recipe/assets.ts";
const ObjectInput = z.record(z.string(), z.unknown());
const Sha = z.string().regex(/^[a-f0-9]{64}$/);
const Id = z.number().int().positive();
const Path = z.string().regex(/^\/(?:[^/\0\r\n]+\/)*[^/\0\r\n]+$/).refine((p) =>
    p.split("/").every((x) => x !== "." && x !== "..")
);
const User = z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/);
const Account = z.object({
    user: User,
    uid: z.number().int().nonnegative(),
    group: User,
    home: Path,
    shell: Path,
}).strict();
const Command = z.enum([
    "account.lookup",
    "node.prepare-storage-root",
    "agent.configure",
    "agent.install",
    "caddy.install-package",
    "node.install-private-dns-listener",
    "php.configure-runtime",
    "php.converge-shared-pools",
    "node.publish-tmpfiles",
]);
const Envelope = z.object({ command: Command, pin: Sha, input: ObjectInput })
    .strict();
const Storage = z.object({
    root: Path.nullable(),
    user: User,
    create_missing: z.boolean(),
    protected_roots: z.array(Path),
    managed_checkouts: z.array(Path),
}).strict();
const Agent = z.object({
    configuration: z.string(),
    certificate: z.string(),
    unit: z.string(),
    checkouts: z.array(Path),
    user: User,
    apps_root: Path.nullable(),
}).passthrough();
const Configure = z.object({
    configuration: Agent,
    pin: Sha,
    account: Account,
    changed: z.boolean(),
    rotate: z.boolean(),
    verifier_hash: Sha.nullable(),
}).strict();
const Step = z.object({ status: z.string(), outputs: ObjectInput.optional() })
    .passthrough();
const Steps = z.record(z.string(), Step);
const inside = (path: string, parent: string) => path.startsWith(parent + "/");
const overlaps = (a: string, b: string) =>
    a === b || inside(a, b) || inside(b, a);
export function envelope(raw: unknown) {
    const a = Envelope.parse(raw);
    const deadline = {
        "account.lookup": 10000,
        "node.prepare-storage-root": 30000,
        "agent.configure": 420000,
        "agent.install": 180000,
        "caddy.install-package": 600000,
        "node.install-private-dns-listener": 60000,
        "php.configure-runtime": 900000,
        "php.converge-shared-pools": 180000,
        "node.publish-tmpfiles": 30000,
    }[a.command];
    return {
        timeoutSec: deadline / 1000,
        stdin: JSON.stringify({
            agent_sha256: a.pin,
            deadline_ms: deadline,
            input: a.input,
        }),
    };
}
export function storage(raw: unknown) {
    const a = z.object({ storage: Storage, account: Account, pin: Sha })
        .strict().parse(raw);
    const { account, storage: s } = a, root = s.root ?? account.home + "/apps";
    if (
        account.user !== s.user || root === account.home ||
        inside(account.home, root) ||
        inside(root, account.home) &&
            root.slice(account.home.length + 1).split("/")[0].startsWith(".") ||
        s.protected_roots.some((p) => overlaps(root, p)) ||
        s.managed_checkouts.some((p) => root === p || inside(root, p))
    ) throw new Error("node.settings_root_failed");
    return envelope({
        command: "node.prepare-storage-root",
        pin: a.pin,
        input: {
            root,
            user: account.user,
            group: account.group,
            create_missing: s.create_missing,
        },
    });
}
export function configure(raw: unknown) {
    const a = Configure.parse(raw), c = a.configuration;
    if (c.user !== a.account.user) {
        throw new Error("node.agent_account_mismatch");
    }
    const files = observerFiles({
        configuration: c.configuration,
        certificate: c.certificate,
        unit: c.unit,
        apps_root: c.apps_root,
        home: a.account.home,
    });
    const root = c.apps_root ?? a.account.home + "/apps";
    return envelope({
        command: "agent.configure",
        pin: a.pin,
        input: {
            ...files,
            checkouts: c.checkouts.filter((p) => inside(p, root)),
            binary_changed: a.changed,
            allow_secret_rotation: a.rotate,
            secret_hash: a.rotate ? null : a.verifier_hash,
            worker_credentials: null,
        },
    });
}
export function pools(raw: unknown) {
    const a = z.object({
        pin: Sha,
        configurations: z.record(z.string(), z.string()),
        home: Path,
        group: User,
    }).strict().parse(raw);
    return envelope({
        command: "php.converge-shared-pools",
        pin: a.pin,
        input: {
            configurations: Object.fromEntries(
                Object.entries(a.configurations).map((
                    [version, text],
                ) => [
                    version,
                    text.replaceAll("__orbit_converge_group__", a.group)
                        .replaceAll("/__orbit_converge_home__", a.home),
                ]),
            ),
        },
    });
}
export function decode(stdout: string) {
    let raw: Record<string, unknown>;
    try {
        raw = ObjectInput.parse(JSON.parse(stdout));
    } catch {
        throw new Error("agent.result_invalid");
    }
    if (raw.ok !== true) throw new Error("agent.command_refused");
    const data = ObjectInput.parse(raw.data);
    // The frozen agent returns a public verifier, never the secret file's value.
    if ("secret_hash" in data) {
        const { secret_hash, ...rest } = data;
        return { ...rest, verifier_hash: Sha.parse(secret_hash) };
    }
    return data;
}
const output = (steps: z.infer<typeof Steps>, name: string) =>
    steps[name]?.outputs ?? {};
export function result(raw: unknown) {
    const { kind, intent, steps } = z.object({
        kind: z.enum(["node.settings", "node.agent_rekey", "node.converge"]),
        intent: ObjectInput,
        steps: Steps,
    }).strict().parse(raw);
    const failed = Object.entries(steps).find(([name, s]) =>
        s.status === "failed" && !name.startsWith("residue_")
    );
    if (kind === "node.settings") {
        return steps.prepare?.status === "succeeded"
            ? { prepared: true }
            : { error_code: "node.settings_root_failed" };
    }
    if (kind === "node.agent_rekey") {
        const agent = output(steps, "observer");
        return !failed && steps.observer?.status === "succeeded" &&
                Sha.safeParse(agent.verifier_hash).success
            ? {
                rekeyed: true,
                restarted: true,
                verifier_hash: agent.verifier_hash,
            }
            : { rekeyed: false, error_code: "operation.native_failed" };
    }
    const footprint = assembleFootprint(
        intent as Parameters<typeof assembleFootprint>[0],
        steps,
    );
    if (
        intent.first_visit &&
        footprint.artifacts.some((a) =>
            a.name === "caddy" && a.status === "skipped"
        ) &&
        (intent.artifacts as { name: string; repair: boolean }[]).some((a) =>
            a.name === "caddy" && !a.repair
        )
    ) {
        return {
            ...footprint,
            footprint_proven: false,
            error_code: "node.footprint_caddy_failed",
        };
    }
    return {
        ...footprint,
        ...(failed ? { error_code: "operation.native_failed" } : {}),
    };
}
type Context = {
    writeResource(s: string, n: string, v: unknown): Promise<unknown>;
};
const Data = { schema: ObjectInput, lifetime: "1d", garbageCollection: 10 };
const method = <T>(
    schema: z.ZodType<T>,
    transform: (a: T) => Record<string, unknown>,
) => ({
    description: "Shape admitted public agent input or native output only",
    arguments: schema,
    execute: async (a: T, ctx: Context) => {
        const value = transform(a);
        await ctx.writeResource("data", "data", value);
        return value;
    },
});
export const behavior = {
    type: "@nckrtl/node-agent-native",
    version: "2026.11.01.1",
    resources: { data: Data },
    methods: {
        pools: method(
            z.object({
                pin: Sha,
                configurations: z.record(z.string(), z.string()),
                home: Path,
                group: User,
            }).strict(),
            pools,
        ),
        envelope: method(Envelope, envelope),
        storage: method(
            z.object({ storage: Storage, account: Account, pin: Sha }).strict(),
            storage,
        ),
        configure: method(Configure, configure),
        decode: method(
            z.object({ stdout: z.string() }).strict(),
            (a) => decode(a.stdout),
        ),
        result: method(
            z.object({
                kind: z.enum([
                    "node.settings",
                    "node.agent_rekey",
                    "node.converge",
                ]),
                intent: ObjectInput,
                steps: Steps,
            }).strict(),
            (a) => {
                const value = result(a);
                return { ...value, stdout: JSON.stringify(value) };
            },
        ),
        validate: method(
            z.object({ node_id: Id, intent: ObjectInput }).strict(),
            (a) => {
                if (
                    a.intent.node_id !== a.node_id || a.intent.refusal
                ) throw new Error("node.intent_invalid");
                return { ready: true };
            },
        ),
    },
};
