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

async function sha256(text: string | Uint8Array): Promise<string> {
    const bytes = typeof text === "string" ? new TextEncoder().encode(text) : text;
    return Array.from(new Uint8Array(await crypto.subtle.digest("SHA-256", bytes as BufferSource)), (byte) => byte.toString(16).padStart(2, "0")).join("");
}

/** The code part of a phase digest: every file of the given folders, by relative path and content, in path order. */
export async function codeDigest(folders: string[]): Promise<string> {
    const rows: string[] = [];
    const walk = async (root: string, path: string): Promise<void> => {
        const entries = [];
        for await (const entry of Deno.readDir(path)) entries.push(entry);
        for (const entry of entries.sort((a, b) => a.name < b.name ? -1 : 1)) {
            if (entry.isDirectory) await walk(root, `${path}/${entry.name}`);
            else if (entry.isFile) rows.push(`${path.slice(root.length)}/${entry.name}\0${await sha256(await Deno.readFile(`${path}/${entry.name}`))}`);
        }
    };
    for (const folder of folders) await walk(folder.slice(0, folder.lastIndexOf("/")), folder);
    return sha256(rows.join("\n"));
}

/** Each skippable phase's digest, and the phases whose digest equals the one the Node records. */
const Hex = z.string().regex(/^[0-9a-f]{64}$/);
const DigestIntent = z.object({ phase_input: Hex, // PHP sends an empty map as an empty list.
    recorded_digests: z.preprocess((value) => Array.isArray(value) && value.length === 0 ? {} : value, z.partialRecord(z.enum(skippable), Hex)) });

export async function phaseDigests(raw: unknown, secrets: Record<string, string>, code: string) {
    const intent = DigestIntent.parse(raw);
    const secretRows = await Promise.all(Object.keys(secrets).sort().map(async (name) => `${name}\0${await sha256(secrets[name])}`));
    const secretDigest = await sha256(secretRows.join("\n"));
    const digests: Record<string, string> = {};
    for (const phase of skippable) digests[phase] = await sha256([phase, intent.phase_input, secretDigest, code].join("\0"));
    return { digests, unchanged: skippable.filter((phase) => intent.recorded_digests[phase] === digests[phase]) };
}

export async function convergeInput(folder: string, builder: string, toolHelper: string, code: string[]) {
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
    const phases = await phaseDigests(intent, secrets, await codeDigest(code));
    await Deno.writeTextFile(`${folder}/digests.json`, JSON.stringify(phases), { mode: 0o600 });
    await Deno.writeTextFile(`${folder}/inventory.json`, JSON.stringify({ all: { hosts, children: { proof_seed_sender: { hosts: senders } } } }), { mode: 0o600 });
    await Deno.writeTextFile(`${folder}/known_hosts`, [...known].sort().join("\n") + "\n", { mode: 0o600 });
    await Deno.writeTextFile(`${folder}/vars.yml`, unsafeYaml({ converge_intent: intent, kind: 'node.converge', converge_tool_helper: toolHelper, converge_deno: toolRuntime, converge_secrets: secrets, converge_plans: plans }), { mode: 0o600 });
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
    return phases;
}
