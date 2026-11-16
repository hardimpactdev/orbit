/**
 * The Incus pool of a CI-only Node (ansible/tasks/instance-pool-create.yml, docs/reference/node-provisioning.md#proof-copies-on-a-ci-node),
 * run by Ansible against a stub `incus` that answers the pool list from a file and logs each other call.
 */
import { assertEquals, assertStringIncludes } from "jsr:@std/assert@1";
import { fileURLToPath } from "node:url";

const tasks = fileURLToPath(new URL("../ansible/tasks/instance-pool-create.yml", import.meta.url));
const venv = [...Deno.readDirSync("/opt/orbit-shape-gate")].find((entry) => entry.name.startsWith("venv-"))?.name;
const playbook = venv ? `/opt/orbit-shape-gate/${venv}/bin/ansible-playbook` : "ansible-playbook";

type Pool = { name: string; driver: string; config: Record<string, string> };

async function run(pools: Pool[]) {
    const folder = await Deno.makeTempDir();
    try {
        await Deno.mkdir(`${folder}/bin`);
        await Deno.writeTextFile(`${folder}/pools.json`, JSON.stringify(pools));
        await Deno.writeTextFile(`${folder}/bin/incus`, `#!/bin/sh
case "$1" in
  query) cat '${folder}/pools.json';;
  *) echo "$*" >> '${folder}/calls';;
esac
exit 0
`);
        await Deno.chmod(`${folder}/bin/incus`, 0o755);
        await Deno.writeTextFile(`${folder}/play.yml`, JSON.stringify([{
            hosts: "localhost", connection: "local", gather_facts: false,
            vars: { instances: { pool: "orbit-instances", create_pool: true } },
            tasks: [{ name: "Create the Instance pool of a CI-only Node", "ansible.builtin.include_tasks": tasks }],
        }]));
        const result = await new Deno.Command(playbook, {
            args: ["-i", "localhost,", `${folder}/play.yml`], stdout: "piped", stderr: "piped",
            env: { PATH: `${folder}/bin:${Deno.env.get("PATH")}`, ANSIBLE_LOCALHOST_WARNING: "False" },
        }).output();
        let calls: string[] = [];
        try { calls = Deno.readTextFileSync(`${folder}/calls`).trim().split("\n"); } catch { /* no call */ }
        return { code: result.code, calls, out: new TextDecoder().decode(result.stdout) };
    } finally {
        await Deno.remove(folder, { recursive: true });
    }
}

const zfs = (name: string, dataset: string): Pool => ({ name, driver: "zfs", config: { "zfs.pool_name": dataset, source: dataset } });

Deno.test("a CI-only Node without the pool gets it on a new dataset of the zpool its ZFS Incus pool uses", async () => {
    const { code, calls } = await run([zfs("default", "tank/incus"), { name: "scratch", driver: "dir", config: { source: "/var/lib/x" } }]);
    assertEquals(code, 0);
    assertEquals(calls, ["storage create orbit-instances zfs source=tank/orbit-instances"]);
});

Deno.test("two ZFS Incus pools on the same zpool still name one zpool", async () => {
    const { code, calls } = await run([zfs("default", "tank/incus"), zfs("ci", "tank")]);
    assertEquals(code, 0);
    assertEquals(calls, ["storage create orbit-instances zfs source=tank/orbit-instances"]);
});

Deno.test("an existing pool stays as it is", async () => {
    const { code, calls } = await run([zfs("default", "tank/incus"), zfs("orbit-instances", "fast/orbit-instances")]);
    assertEquals(code, 0);
    assertEquals(calls, []);
});

Deno.test("no ZFS Incus pool, or two zpools, refuses with instance.host_unavailable and creates nothing", async (t) => {
    for (const [name, pools] of [["none", [{ name: "default", driver: "dir", config: {} }]],
        ["two", [zfs("default", "tank/incus"), zfs("fast", "fast/incus")]]] as const) {
        await t.step(name, async () => {
            const { code, calls, out } = await run(pools as unknown as Pool[]);
            assertEquals(code === 0, false);
            assertEquals(calls, []);
            assertStringIncludes(out, "instance.host_unavailable");
        });
    }
});
