type Ssh = { target: { host: string; port: number; user: string; host_keys: string[] }; identity_file: string };

/** Every string is literal, including nested Tool values and private environment files. */
export function unsafeYaml(value: unknown, depth = 0): string {
    const pad = "  ".repeat(depth);
    if (typeof value === "string") return `!unsafe ${JSON.stringify(value)}`;
    if (value === null || typeof value !== "object") return JSON.stringify(value);
    const entries = Array.isArray(value) ? value.map((item) => [null, item]) : Object.entries(value);
    if (!entries.length) return Array.isArray(value) ? "[]" : "{}";
    return "\n" + entries.map(([key, item]) => `${pad}${key === null ? "-" : JSON.stringify(key) + ":"} ${unsafeYaml(item, depth + 1)}`).join("\n");
}

export async function convergeInput(folder: string, builder: string, toolHelper: string) {
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
    const secrets: Record<string, string> = {};
    for await (const file of Deno.readDir(folder)) {
        if (!file.name.startsWith("secret-")) continue;
        secrets[file.name.slice(7)] = await Deno.readTextFile(`${folder}/${file.name}`);
        await Deno.remove(`${folder}/${file.name}`);
    }
    const plans = JSON.parse(await Deno.readTextFile(`${folder}/plans.json`));
    await Deno.writeTextFile(`${folder}/inventory.json`, JSON.stringify({ all: { hosts } }), { mode: 0o600 });
    await Deno.writeTextFile(`${folder}/known_hosts`, [...known].sort().join("\n") + "\n", { mode: 0o600 });
    await Deno.writeTextFile(`${folder}/vars.yml`, unsafeYaml({ intent, kind: 'node.converge', converge_tool_helper: toolHelper, converge_deno: Deno.execPath(), secrets, plans }), { mode: 0o600 });
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
}
