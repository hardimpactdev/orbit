// Throwaway helper of swamp/proof/spike/shape-live (shape spike, #3134). Delete it with the spike.
//
//   deno run -A withdraw.ts states ORIG_CADDYFILE OUT_DIR NODE_IP
//       OUT_DIR gets orig.txt, before.txt (ORIG plus two synthetic sites) and states.json: the four captured states of a destroy
//       that removes one of the two sites. The states after the first are equal, as the chart says: no DNS grace.
//   deno run -A withdraw.ts compose RUN_DIR NODE_IP STATES_JSON
//       The TypeScript composition of the Route withdrawal against RUN_DIR/read.json; writes RUN_DIR/plan.json.
import { composeWithdrawal } from "../../extensions/behaviors/operations/routes/publication-command/assets.ts";

const header = "# Managed by Orbit: Node Caddy build\n";
const hex = (bytes: Uint8Array) =>
    Array.from(bytes, (b) => b.toString(16).padStart(2, "0")).join("");
const version = async (text: string) =>
    hex(
        new Uint8Array(
            await crypto.subtle.digest(
                "SHA-256",
                new TextEncoder().encode(text),
            ),
        ),
    ).slice(0, 32);
const site = (name: string, port: number) =>
    `# orbit: routes ${name}\nhttp://${name}.test:${port} {\n\trespond "${name}"\n}\n`;

const [command, first, second, third] = Deno.args;
if (command === "states") {
    const original = await Deno.readTextFile(first);
    if (!original.startsWith(header)) {
        console.error("foreign Caddyfile: it is not managed by Orbit");
        Deno.exit(3);
    }
    const [dir, ip] = [second, third];
    const base = original.replace(/\n+$/, "\n") + "\n";
    const both = base + site("shape-a", 18081) + "\n" + site("shape-b", 18082);
    const kept = base + site("shape-b", 18082);
    const section = async (content: string) => ({
        content,
        version: await version(content),
        listen_addresses: [ip],
        unserved: false,
        had_served_groups: true,
    });
    const after = await section(kept);
    await Deno.writeTextFile(`${dir}/orig.txt`, original);
    await Deno.writeTextFile(`${dir}/before.txt`, both);
    await Deno.writeTextFile(
        `${dir}/states.json`,
        JSON.stringify({
            before: await section(both),
            prepared: after,
            withdrawn: after,
            after,
        }),
    );
} else if (command === "compose") {
    const [dir, ip, states] = [first, second, third];
    const plan = await composeWithdrawal(
        {
            node_id: 3,
            user: "orbit",
            control_node_id: 209,
            host_keys: ["ssh-ed25519 AAAA"],
            connection: {
                nodeHost: ip,
                nodePort: 22,
                nodeUser: "orbit",
                nodeIdentityFile: "/tmp/shape-live/none",
                become: true,
            },
            plan_path: `${dir}/plan.json`,
            read_path: `${dir}/read.json`,
            php: {},
            caddy: JSON.parse(await Deno.readTextFile(states)),
            dns: {},
            cleanup: [],
        },
        await Deno.readTextFile(`${dir}/read.json`),
    );
    await Deno.writeTextFile(`${dir}/plan.json`, JSON.stringify(plan), {
        mode: 0o600,
    });
} else {
    console.error("usage: withdraw.ts states|compose ...");
    Deno.exit(64);
}
