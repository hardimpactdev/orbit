/**
 * The btrfs pool of an Instance fleet Node (ansible/tasks/instance-fleet-node.yml, docs/reference/development-containers.md#instance-host-in-the-fleet):
 * its pool tasks, run by Ansible against a stub `incus` that answers queries from files and logs each other call.
 */
import { assertEquals, assertStringIncludes } from "jsr:@std/assert@1";
import { parse } from "jsr:@std/yaml@1";

const file = new URL("../ansible/tasks/instance-fleet-node.yml", import.meta.url);
const hasAnsible = new Deno.Command("sh", { args: ["-c", "command -v ansible-playbook"] }).outputSync().success;

type Task = { name?: string };
type Pool = { name: string; driver: string; config: Record<string, string>; used_by: string[] };

// The pool tasks only: the rest of the file installs Incus and writes to /etc.
function poolTasks(): Task[] {
    const tasks = parse(Deno.readTextFileSync(file)) as Task[];
    const first = tasks.findIndex((task) => task.name === "Read the Incus pools");
    const last = tasks.findIndex((task) => task.name === "Create the pool on the Node's root");
    return tasks.slice(first, last + 1);
}

async function run(pools: Pool[], instances: Record<string, string> = { pool: "orbit-instances", project: "orbit-instances" }) {
    const folder = await Deno.makeTempDir();
    try {
        await Deno.mkdir(`${folder}/bin`);
        await Deno.writeTextFile(`${folder}/pools.json`, JSON.stringify(pools));
        await Deno.writeTextFile(`${folder}/bin/incus`, `#!/bin/sh
case "$1 $3" in
  "query /1.0/storage-pools?recursion=1") cat '${folder}/pools.json';;
  *) echo "$*" >> '${folder}/calls';;
esac
exit 0
`);
        await Deno.chmod(`${folder}/bin/incus`, 0o755);
        await Deno.writeTextFile(`${folder}/tasks.yml`, JSON.stringify(poolTasks()));
        await Deno.writeTextFile(`${folder}/play.yml`, JSON.stringify([{
            hosts: "localhost", connection: "local", gather_facts: false,
            vars: { instances },
            tasks: [{ name: "The pool of the fleet Node", "ansible.builtin.include_tasks": `${folder}/tasks.yml` }],
        }]));
        const result = await new Deno.Command("ansible-playbook", {
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

const create = "storage create orbit-instances btrfs source=/var/lib/incus/storage-pools/orbit-instances";
const pool = (driver: string, used_by: string[] = []): Pool => ({ name: "orbit-instances", driver, config: {}, used_by });

Deno.test("a fleet Node without the pool gets a btrfs pool on its root", { ignore: !hasAnsible }, async () => {
    const { code, calls } = await run([{ name: "default", driver: "zfs", config: {}, used_by: [] }]);
    assertEquals(code, 0);
    assertEquals(calls, [create]);
});

Deno.test("a btrfs pool stays as it is", { ignore: !hasAnsible }, async () => {
    const { code, calls } = await run([pool("btrfs", ["/1.0/profiles/default?project=orbit-instances"])]);
    assertEquals(code, 0);
    assertEquals(calls, []);
});

Deno.test("a pool of another driver refuses and stays, even an unused one", { ignore: !hasAnsible }, async () => {
    for (const driver of ["dir", "zfs"]) {
        for (const used_by of [[], ["/1.0/profiles/default?project=orbit-instances"]]) {
            const { code, calls, out } = await run([pool(driver, used_by)]);
            assertEquals(code === 0, false);
            assertEquals(calls, []);
            assertStringIncludes(out, `instance.host_unavailable: the Incus pool orbit-instances uses the ${driver} driver; a fleet Node pool must be btrfs`);
        }
    }
});

Deno.test("the release pool of a nested proof Node is btrfs and keeps the containers it holds", { ignore: !hasAnsible }, async () => {
    const releases = (driver: string) => ({ name: "orbit-releases", driver, config: {}, used_by: ["/1.0/instances/release-1?project=orbit-releases"] });
    const held = await run([releases("btrfs")], { pool: "orbit-releases" });
    assertEquals([held.code, held.calls], [0, []]);
    const missing = await run([], { pool: "orbit-releases" });
    assertEquals([missing.code, missing.calls], [0, ["storage create orbit-releases btrfs source=/var/lib/incus/storage-pools/orbit-releases"]]);
    const dir = await run([releases("dir")], { pool: "orbit-releases" });
    assertEquals([dir.code === 0, dir.calls], [false, []]);
    assertStringIncludes(dir.out, "recreate orbit-releases as btrfs");
});
