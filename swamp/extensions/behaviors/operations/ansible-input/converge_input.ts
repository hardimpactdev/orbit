import { z } from "npm:zod@4.4.3";

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

/** The launcher of the recipe's pinned Deno on the Swamp Node (ansible/tasks/task-vm-deno.yml). It runs the Tool helper: this code runs in the
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

/** The files and folders the phases run beside their written input: the Ansible bundle, which also builds each role's variables, the
 * Tool helper and the shipped Tool files it imports. The input behaviour is bundled on a worker; its output is in the written files. */
export function phaseCode(builder: string, toolHelper: string): string[] {
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

/** The version of each certificate leaf in the intent: the Gateway mints a new one at each capture (NodeRoleCertificateInput), so it is a
 * value of the Operation, as its ID is, and not an input. */
export function leafVersions(intent: unknown): string[] {
    if (Array.isArray(intent)) return intent.flatMap(leafVersions);
    if (intent === null || typeof intent !== "object") return [];
    const record = intent as Record<string, unknown>;
    const leaves = (record.certificates as { leaves?: unknown } | undefined)?.leaves;
    const own = Array.isArray(leaves) ? leaves.map((leaf) => leaf?.version).filter((version) => typeof version === "string" && /^[a-f0-9]{16}$/.test(version)) : [];
    return [...own, ...Object.values(record).flatMap(leafVersions)];
}

/**
 * What the phases read (docs/reference/node-provisioning.md#skip-a-phase-whose-input-did-not-change): every file written for them, that is
 * the run folder without the Gateway's intent files and the digests, with the values of this Operation (its ID and the versions of its
 * certificate leaves) replaced by fixed marks, and their code.
 */
export async function readDigest(folder: string, operationId: number, code: string[]): Promise<string> {
    const mark = new RegExp(`operation-${operationId}(?![0-9])`, "g");
    const versions = [...new Set(leafVersions(JSON.parse(await Deno.readTextFile(`${folder}/intent.json`))))];
    const rows: string[] = [];
    const walk = async (path: string): Promise<void> => {
        const entries = [];
        for await (const entry of Deno.readDir(path)) entries.push(entry);
        for (const entry of entries.sort((a, b) => a.name < b.name ? -1 : 1)) {
            if (entry.isDirectory) await walk(`${path}/${entry.name}`);
            else if (entry.isFile && entry.name !== "intent.json" && entry.name !== "digests.json") {
                const text = versions.reduce((read, version) => read.replaceAll(version, "leaf-#"),
                    (await Deno.readTextFile(`${path}/${entry.name}`)).replace(mark, "operation-#"));
                rows.push(`${path.slice(folder.length)}/${entry.name}\0${await sha256(text)}`);
            }
        }
    };
    await walk(folder);
    return sha256([...rows, await codeDigest(code)].join("\n"));
}

/** Each skippable phase's digest, and the phases whose digest equals the one the Node records. The record holds the phases of the last
 * Operation that wrote it, a converge or a role Operation, so it may name phases of the other kind: they never match. */
const Hex = z.string().regex(/^[0-9a-f]{64}$/);
const DigestIntent = z.object({ // PHP sends an empty map as an empty list.
    recorded_digests: z.preprocess((value) => Array.isArray(value) && value.length === 0 ? {} : value, z.partialRecord(z.enum([...skippable, ...roleSkippable]), Hex)) });

export async function phaseDigests<Phase extends typeof skippable[number] | typeof roleSkippable[number]>(raw: unknown, read: string, phases: readonly Phase[]) {
    const intent = DigestIntent.parse(raw);
    const digests = {} as Record<Phase, string>;
    for (const phase of phases) digests[phase] = await sha256(`${phase}\0${read}`);
    return { digests, unchanged: phases.filter((phase) => intent.recorded_digests[phase] === digests[phase]) };
}

/** A role Operation's code: the converge's, and the plan of its Tool Managers beside the Tool helper, which runs after the digest. */
export function rolePhaseCode(builder: string, toolHelper: string): string[] {
    return [...phaseCode(builder, toolHelper), `${toolHelper.slice(0, toolHelper.lastIndexOf("/"))}/role_add_plans.ts`];
}

/** A role Operation's digests, after its playbook input is written; the Gateway records them after a succeeded run. */
export async function roleDigests(folder: string, operationId: number, code: string[]) {
    const intent = JSON.parse(await Deno.readTextFile(`${folder}/intent.json`));
    const phases = await phaseDigests(intent, await readDigest(folder, operationId, code), roleSkippable);
    await Deno.writeTextFile(`${folder}/digests.json`, JSON.stringify(phases), { mode: 0o600 });
    return phases;
}

export async function convergeInput(folder: string, operationId: number, builder: string, toolHelper: string, code: string[]) {
    const intent = JSON.parse(await Deno.readTextFile(`${folder}/intent.json`));
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
    const secrets: Record<string, string> = {};
    for await (const file of Deno.readDir(folder)) {
        if (!file.name.startsWith("secret-")) continue;
        secrets[file.name.slice(7)] = await Deno.readTextFile(`${folder}/${file.name}`);
        await Deno.remove(`${folder}/${file.name}`);
    }
    const plans = JSON.parse(await Deno.readTextFile(`${folder}/plans.json`));
    await Deno.writeTextFile(`${folder}/inventory.json`, JSON.stringify({ all: { hosts, children: { proof_seed_sender: { hosts: senders } } } }), { mode: 0o600 });
    await Deno.writeTextFile(`${folder}/known_hosts`, [...known].sort().join("\n") + "\n", { mode: 0o600 });
    // The recorded digests are the input of the skip only; Ansible reads none of them, and a digest must not read its own record.
    const { recorded_digests: _recorded, ...playIntent } = intent;
    await Deno.writeTextFile(`${folder}/vars.yml`, unsafeYaml({ converge_intent: playIntent, kind: 'node.converge', converge_tool_helper: toolHelper, converge_deno: toolRuntime, converge_secrets: secrets, converge_plans: plans }), { mode: 0o600 });
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
    }
    // After every file the phases read is written.
    const phases = await phaseDigests(intent, await readDigest(folder, operationId, code), skippable);
    await Deno.writeTextFile(`${folder}/digests.json`, JSON.stringify(phases), { mode: 0o600 });
    return phases;
}
