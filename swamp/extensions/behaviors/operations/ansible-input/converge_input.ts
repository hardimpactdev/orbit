import { z } from "npm:zod@4.4.3";
import { importNames, resolvePhases } from "./phase_code.ts";

type Ssh = { target: { host: string; port: number; user: string; host_keys: string[] }; identity_file: string };

/** Every string is literal, including nested Tool values and private environment files. */
export function unsafeYaml(value: unknown, depth = 0): string {
    const pad = "  ".repeat(depth);
    if (typeof value === "string") return `${["{{", "{%", "{#"].some((marker) => value.includes(marker)) ? "!unsafe " : ""}${JSON.stringify(value)}`;
    if (value === null || typeof value !== "object") return JSON.stringify(value);
    const entries = Array.isArray(value) ? value.map((item) => [null, item]) : Object.entries(value);
    if (!entries.length) return Array.isArray(value) ? "[]" : "{}";
    return "\n" + entries.map(([key, item]) => `${pad}${key === null ? "-" : JSON.stringify(key) + ":"} ${unsafeYaml(item, depth + 1)}`).join("\n");
}

/** The launcher of the recipe's pinned Deno on the Swamp Node (ansible/tasks/node-converge-deno.yml). It runs the Tool helper: this code runs in the
 * compiled Swamp binary, so `Deno.execPath()` names Swamp, which cannot run a script. */
export const toolRuntime = "/usr/local/bin/deno";

/** The phases that skip when their digest is unchanged (docs/reference/node-provisioning.md#skip-a-phase-whose-input-did-not-change). */
export const skippable = ["agent", "software", "roles", "tools", "network"] as const;
/** The same for a role addition or convergence: the phases that change only the role's Node. */
export const roleSkippable = ["prerequisites", "tools", "node", "firewall"] as const;

async function sha256(text: string | Uint8Array): Promise<string> {
    const bytes = typeof text === "string" ? new TextEncoder().encode(text) : text;
    return Array.from(new Uint8Array(await crypto.subtle.digest("SHA-256", bytes as BufferSource)), (byte) => byte.toString(16).padStart(2, "0")).join("");
}

/** The files outside `ansible/` that a converge's roles read beside the playbook, by path from the bundle root: the release controllers
 * that production-release-host.yml copies from `{{ playbook_dir }}/../scripts`, and the selection rules that `ansible/files/instance-release-vp.ts`
 * imports from `../../extensions`. A converge stages only `ansible/` from the worker's cache (write.ts), so it stages these at the same paths
 * beside it. A role Operation reads them from the worker's bundle. Both count them in the phases' code. */
export const roleSiblings = [
    "extensions/behaviors/operations/instance-deploy/vp_requirements.ts",
    "extensions/behaviors/operations/instance-vp-command/requirements.ts",
    "scripts/production-release-phase",
    "scripts/release-container.py",
] as const;

/** A converge's code: the Ansible folder, the Tool helper, the Tool folder and the staged role siblings; or none (phaseCodeDigests). */
export type ConvergeCode = [string, string, string, ...string[]] | [];

/** The files and folders the phases run beside their written input: the Ansible bundle, which also builds each role's variables, the
 * Tool helper and the shipped Tool files it imports. The input behaviour is bundled on a worker; its output is in the written files. */
export function phaseCode(builder: string, toolHelper: string): [string, string, string] {
    return [builder.slice(0, builder.lastIndexOf("/")), toolHelper, `${toolHelper.slice(0, toolHelper.lastIndexOf("/ansible-input/"))}/tools`];
}

/** Every file of the given files and folders, by relative path and content, in path order. */
export async function codeDigest(paths: string[]): Promise<string> {
    const rows: string[] = [];
    const walk = async (root: string, path: string): Promise<void> => {
        if ((await Deno.stat(path)).isFile) {
            rows.push(`${path.slice(root.length)}\0${await sha256(await Deno.readFile(path))}`);
            return;
        }
        const entries = [];
        for await (const entry of Deno.readDir(path)) entries.push(entry);
        for (const entry of entries.sort((a, b) => a.name < b.name ? -1 : 1)) {
            if (entry.isDirectory || entry.isFile) await walk(root, `${path}/${entry.name}`);
        }
    };
    for (const path of paths) await walk(path.slice(0, path.lastIndexOf("/")), path);
    return sha256(rows.join("\n"));
}

/** The values the Gateway mints for the Operation at each capture, so they are of the Operation, as its ID is, and not inputs: the version of
 * each certificate leaf (NodeRoleCertificateInput) and the admission time of the Instance bridge (InstanceHostIntent). */
export function admissionValues(intent: unknown): string[] {
    if (Array.isArray(intent)) return intent.flatMap(admissionValues);
    if (intent === null || typeof intent !== "object") return [];
    const record = intent as Record<string, unknown>;
    const leaves = (record.certificates as { leaves?: unknown } | undefined)?.leaves;
    const versions = Array.isArray(leaves) ? leaves.map((leaf) => leaf?.version).filter((version) => typeof version === "string" && /^[a-f0-9]{16}$/.test(version)) : [];
    const admitted = Number.isSafeInteger(record.bridge_admitted_at) && (record.bridge_admitted_at as number) > 0 ? [String(record.bridge_admitted_at)] : [];
    return [...versions, ...admitted, ...Object.values(record).flatMap(admissionValues)];
}

/** A role as the roles digest reads it: an Instance host's per-Instance lists (InstanceHostIntent) change with every Instance that comes or
 * goes, so they count only by what the role writes from them for any container. `machine_names` names each container's presented name,
 * which the `check` tag of every converge writes where it differs (tasks/instance-container-names.yml); it counts not at all. Each
 * entry of `t3_files` holds the drop-ins of one server program, the same text for every container that runs it
 * (InstanceContainer::t3DropIns); it counts as its distinct entries without their container, so a changed drop-in still moves the roles phase
 * (docs/reference/node-provisioning.md#skip-a-phase-whose-input-did-not-change). */
export function churnFreeRole(role: unknown): unknown {
    const runtime = (role as { runtime?: { instances?: Record<string, unknown> } } | null)?.runtime;
    const instances = runtime?.instances;
    if (instances === null || typeof instances !== "object" || Array.isArray(instances)) return role;
    const { machine_names: _names, t3_files: t3, ...rest } = instances;
    // Every field of an entry but its container counts, a field this code does not know included.
    const server = (entry: unknown) => {
        if (entry === null || typeof entry !== "object" || Array.isArray(entry)) return JSON.stringify(entry);
        const { container: _container, ...rest } = entry as Record<string, unknown>;
        return JSON.stringify(rest);
    };
    const servers = Array.isArray(t3) ? [...new Set(t3.map(server))].sort().map((text) => JSON.parse(text)) : t3;
    return { ...(role as Record<string, unknown>), runtime: { ...runtime, instances: { ...rest, ...(t3 === undefined ? {} : { t3_files: servers }) } } };
}

/** The inputs that one skippable node.converge phase alone reads, besides the `input` and `check` tags, which never skip: intent fields and
 * run folder entries by path from the run folder. A release moves the agent, executor and CLI pins, so it moves only the agent phase. Every
 * other field and file, a new one included, is read by every phase. node_converge_test proves the table against the shipped bundle
 * (docs/reference/node-provisioning.md#skip-a-phase-whose-input-did-not-change). */
export const phaseInputs: Partial<Record<typeof skippable[number], { fields: readonly string[]; entries: RegExp | null }>> = {
    agent: { fields: ["agent", "executor", "cli"], entries: null },
    roles: { fields: ["roles"], entries: /^\/role-[0-9]+$/ },
};

/**
 * What the phases read besides code (docs/reference/node-provisioning.md#skip-a-phase-whose-input-did-not-change): every file written for
 * them, that is the run folder without the Gateway's intent files and the digests, with the values of this Operation (its ID, its run folder,
 * the versions of its certificate leaves and the admission time of its Instance bridge) replaced by fixed marks. `view` gives one phase's
 * view by path from the run folder: an entry it does not read (null) or the text it reads in place of the file.
 */
export async function runDigest(folder: string, operationId: number, view: Record<string, string | null> = {}): Promise<string> {
    const mark = new RegExp(`operation-${operationId}(?![0-9])`, "g");
    const own = [...new Set(admissionValues(JSON.parse(await Deno.readTextFile(`${folder}/intent.json`))))];
    const rows: string[] = [];
    const walk = async (path: string): Promise<void> => {
        const entries = [];
        for await (const entry of Deno.readDir(path)) entries.push(entry);
        for (const entry of entries.sort((a, b) => a.name < b.name ? -1 : 1)) {
            const relative = `${path.slice(folder.length)}/${entry.name}`;
            if (view[relative] === null) continue;
            if (entry.isDirectory) await walk(`${path}/${entry.name}`);
            else if (entry.isFile && entry.name !== "intent.json" && entry.name !== "digests.json") {
                const text = own.reduce((read, value) => read.replaceAll(value, "admitted-#"),
                    (view[relative] ?? await Deno.readTextFile(`${path}/${entry.name}`)).replaceAll(folder, "orbit-ansible-#").replace(mark, "operation-#"));
                rows.push(`${path.slice(folder.length)}/${entry.name}\0${await sha256(text)}`);
            }
        }
    };
    await walk(folder);
    return sha256(rows.join("\n"));
}

/** What a role Operation's phases read: the run folder and all their code. */
export async function readDigest(folder: string, operationId: number, code: string[]): Promise<string> {
    return sha256([await runDigest(folder, operationId), await codeDigest(code)].join("\n"));
}

/** The bundle files of each converge phase by relative path and content, or the whole bundle for a phase the resolver cannot bound
 * (phase_code.ts), with the files the phase names above the bundle and the modules they import, read beside it from the same tree. The
 * Tool helper and the Tool files it imports are in the tools and roles phases. `code` may end in the role siblings that a converge stages
 * beside the bundle (roleSiblings): one that no phase names is in every phase. A file above the bundle that cannot be read gives its phase
 * a digest that always moves, and `moving` records why. A caller that ships no code (the Gateway's materializer tests) passes an empty
 * list: every phase then reads the same empty code, as codeDigest([]) does. */
export async function phaseCodeDigests(code: ConvergeCode, moving: Partial<Record<typeof skippable[number], string>> = {}): Promise<Record<typeof skippable[number], string>> {
    const digests = {} as Record<typeof skippable[number], string>;
    if (code.length === 0) {
        const none = await codeDigest([]);
        for (const phase of skippable) digests[phase] = none;
        return digests;
    }
    const [ansible, helper, tools, ...siblings] = code as [string, string, string, ...string[]];
    const resolved = await resolvePhases(ansible);
    const root = ansible.slice(0, ansible.lastIndexOf("/"));
    // A staged sibling by its path from the bundle, as the resolver names a file above it.
    const unnamed = siblings.map((sibling) => `../${sibling.slice(root.length + 1)}`)
        .filter((path) => !skippable.some((phase) => resolved[phase].outside.includes(path)));
    for (const phase of skippable) {
        const resolution = resolved[phase];
        const rows = resolution.whole ? [await codeDigest([ansible])]
            : await Promise.all(resolution.files.map(async (file) => `${file}\0${await sha256(await Deno.readFile(`${ansible}/${file}`))}`));
        const outside = [...resolution.outside, ...unnamed];
        for (const path of outside) {
            try {
                const bytes = await Deno.readFile(`${ansible}/${path}`);
                rows.push(`${path}\0${await sha256(bytes)}`);
                for (const module of importNames(path, new TextDecoder().decode(bytes))) if (module.startsWith("../") && !outside.includes(module)) outside.push(module);
            } catch (error) {
                moving[phase] = `${path} cannot be read: ${(error as Error).name}`;
                rows.push(`${path}\0${crypto.randomUUID()}`);
            }
        }
        if (phase === "tools" || phase === "roles") rows.push(await codeDigest([helper, tools]));
        digests[phase] = await sha256(rows.join("\n"));
    }
    return digests;
}

/** Each skippable phase's digest, and the phases whose digest equals the one the Node records. The record holds the phases of the last
 * Operation that wrote it, a converge or a role Operation, so it may name phases of the other kind: they never match. */
const Hex = z.string().regex(/^[0-9a-f]{64}$/);
const DigestIntent = z.object({ // PHP sends an empty map as an empty list.
    recorded_digests: z.preprocess((value) => Array.isArray(value) && value.length === 0 ? {} : value, z.partialRecord(z.enum([...skippable, ...roleSkippable]), Hex)) });

export async function phaseDigests<Phase extends typeof skippable[number] | typeof roleSkippable[number]>(raw: unknown, read: string | Record<Phase, string>, phases: readonly Phase[]) {
    const intent = DigestIntent.parse(raw);
    const digests = {} as Record<Phase, string>;
    for (const phase of phases) digests[phase] = await sha256(`${phase}\0${typeof read === "string" ? read : read[phase]}`);
    return { digests, unchanged: phases.filter((phase) => intent.recorded_digests[phase] === digests[phase]) };
}

/** A role Operation's code: the converge's, and the plan of its Tool Managers beside the Tool helper, which runs after the digest. */
export function rolePhaseCode(builder: string, toolHelper: string): string[] {
    const root = builder.slice(0, builder.lastIndexOf("/ansible/"));
    return [...phaseCode(builder, toolHelper), `${toolHelper.slice(0, toolHelper.lastIndexOf("/"))}/role_add_plans.ts`,
        ...roleSiblings.map((path) => `${root}/${path}`)];
}

/** A role Operation's digests, after its playbook input is written; the Gateway records them after a succeeded run. */
export async function roleDigests(folder: string, operationId: number, code: string[]) {
    const intent = JSON.parse(await Deno.readTextFile(`${folder}/intent.json`));
    const phases = await phaseDigests(intent, await readDigest(folder, operationId, code), roleSkippable);
    await Deno.writeTextFile(`${folder}/digests.json`, JSON.stringify(phases), { mode: 0o600 });
    return phases;
}

async function writeInventory(folder: string, intent: { ssh: Ssh; node_id: number; network?: { hub?: { hub_id: number; ssh: Ssh } }; roles: Array<{ runtime: { proof_seed?: { present: boolean; sender: { node_id: number; ssh: Ssh } } } }> }) {
    const hosts: Record<string, unknown> = {};
    const known = new Set<string>();
    const host = (name: string, ssh: Ssh) => {
        if (!ssh.target.host_keys.length) throw new Error("Converge requires pinned SSH host keys");
        for (const key of ssh.target.host_keys) known.add(`${ssh.target.port === 22 ? ssh.target.host : `[${ssh.target.host}]:${ssh.target.port}`} ${key}`);
        hosts[name] = { ansible_host: ssh.target.host, ansible_user: ssh.target.user, ansible_port: ssh.target.port,
            ansible_ssh_private_key_file: ssh.identity_file };
    };
    host("target", intent.ssh);
    if (intent.network?.hub && intent.network.hub.hub_id !== intent.node_id) host("hub", intent.network.hub.ssh);
    // The default's Node, whose certificate the seed listener of a CI Node trusts (docs/reference/node-provisioning.md#the-seed-listener).
    const senders: Record<string, Record<string, never>> = {};
    for (const role of intent.roles) {
        const seed = role.runtime.proof_seed;
        if (!seed?.present) continue;
        host(`n${seed.sender.node_id}`, seed.sender.ssh);
        senders[`n${seed.sender.node_id}`] = {};
    }
    await Deno.writeTextFile(`${folder}/inventory.json`, JSON.stringify({ all: { hosts, children: { proof_seed_sender: { hosts: senders } } } }), { mode: 0o600 });
    await Deno.writeTextFile(`${folder}/known_hosts`, [...known].sort().join("\n") + "\n", { mode: 0o600 });
}

export async function toolConvergeInput(folder: string, toolHelper: string) {
    const intent = JSON.parse(await Deno.readTextFile(`${folder}/intent.json`));
    await writeInventory(folder, { ...intent, roles: [] });
    await Deno.writeTextFile(`${folder}/vars.yml`, JSON.stringify({ converge_intent: intent,
        converge_tool_helper: toolHelper, converge_deno: toolRuntime }), { mode: 0o600 });
}

export async function convergeInput(folder: string, operationId: number, builder: string, toolHelper: string, code: ConvergeCode) {
    const intent = JSON.parse(await Deno.readTextFile(`${folder}/intent.json`));
    await writeInventory(folder, intent);
    const secrets: Record<string, string> = {};
    for await (const file of Deno.readDir(folder)) {
        if (!file.name.startsWith("secret-")) continue;
        secrets[file.name.slice(7)] = await Deno.readTextFile(`${folder}/${file.name}`);
        await Deno.remove(`${folder}/${file.name}`);
    }
    const plans = JSON.parse(await Deno.readTextFile(`${folder}/plans.json`));
    // The recorded digests are the input of the skip only; Ansible reads none of them, and a digest must not read its own record.
    const { recorded_digests: _recorded, ...playIntent } = intent;
    const vars = { converge_intent: playIntent, kind: 'node.converge', converge_tool_helper: toolHelper, converge_deno: toolRuntime, converge_secrets: secrets, converge_plans: plans };
    await Deno.writeTextFile(`${folder}/vars.yml`, unsafeYaml(vars), { mode: 0o600 });
    const roleViews: Record<string, string> = {};
    // Reuse the shipped role materializer. Only its literal variables are consumed: its cross-Node
    // inventory and publication plays are deliberately never used by a Node converge.
    for (const role of intent.roles) {
        const path = `${folder}/role-${role.assignment_id}`;
        await Deno.mkdir(path, { mode: 0o700 });
        await Deno.writeTextFile(`${path}/intent.json`, JSON.stringify(role), { mode: 0o600 });
        await Deno.writeTextFile(`${path}/plans.json`, JSON.stringify(plans), { mode: 0o600 });
        for (const [name, value] of Object.entries(secrets)) {
            await Deno.writeTextFile(`${path}/secret-${name}`, value, { mode: 0o600 });
        }
        const outcome = await new Deno.Command('timeout', { args: ['60', 'python3', builder, path, 'node.converge'], stdout: 'null', stderr: 'null' }).output();
        if (!outcome.success) throw new Error('The recorded role input could not be materialized');
        await Deno.chmod(`${path}/vars.yml`, 0o600);
        // The same role folder from the churn-free role, which only the roles digest reads (churnFreeRole).
        const shadow = await Deno.makeTempDir({ prefix: "orbit-converge-digest-" });
        try {
            await Deno.writeTextFile(`${shadow}/intent.json`, JSON.stringify(churnFreeRole(role)), { mode: 0o600 });
            await Deno.writeTextFile(`${shadow}/plans.json`, JSON.stringify(plans), { mode: 0o600 });
            for (const [name, value] of Object.entries(secrets)) await Deno.writeTextFile(`${shadow}/secret-${name}`, value, { mode: 0o600 });
            const built = await new Deno.Command('timeout', { args: ['60', 'python3', builder, shadow, 'node.converge'], stdout: 'null', stderr: 'null' }).output();
            if (!built.success) throw new Error('The recorded role input could not be materialized');
            for await (const file of Deno.readDir(shadow)) {
                if (file.isFile) roleViews[`/role-${role.assignment_id}/${file.name}`] = (await Deno.readTextFile(`${shadow}/${file.name}`)).replaceAll(shadow, path);
            }
        } finally { await Deno.remove(shadow, { recursive: true }); }
    }
    // After every file the phases read is written. Each phase reads the run folder without the inputs of another phase (phaseInputs)
    // and only its own code.
    const moving: Partial<Record<typeof skippable[number], string>> = {};
    const codes = await phaseCodeDigests(code, moving);
    const entries: string[] = [];
    for await (const entry of Deno.readDir(folder)) entries.push(`/${entry.name}`);
    const reads = Object.fromEntries(await Promise.all(skippable.map(async (phase) => {
        const others = Object.entries(phaseInputs).filter(([owner]) => owner !== phase).map(([, inputs]) => inputs!);
        const fields = others.flatMap((inputs) => inputs.fields);
        const view: Record<string, string | null> = Object.fromEntries(entries.filter((entry) => others.some((inputs) => inputs.entries?.test(entry))).map((entry) => [entry, null]));
        const read = Object.fromEntries(Object.entries(playIntent).filter(([field]) => !fields.includes(field)));
        if (phase === "roles") {
            if (Array.isArray(read.roles)) read.roles = read.roles.map(churnFreeRole);
            Object.assign(view, roleViews);
        }
        view["/vars.yml"] = unsafeYaml({ ...vars, converge_intent: read });
        return [phase, await sha256(`${await runDigest(folder, operationId, view)}\n${codes[phase]}`)];
    }))) as Record<typeof skippable[number], string>;
    // A phase whose code above the bundle cannot be read runs every time; the result names the file.
    const phases = { ...await phaseDigests(intent, reads, skippable), ...(Object.keys(moving).length ? { moving } : {}) };
    await Deno.writeTextFile(`${folder}/digests.json`, JSON.stringify(phases), { mode: 0o600 });
    return phases;
}
