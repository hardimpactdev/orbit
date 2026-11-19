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
    const last = tasks.findIndex((task) => task.name === "Create the btrfs pool on the Node's root");
    return tasks.slice(first, last + 1);
}

async function run(pools: Pool[], profiles: Record<string, unknown> = {}) {
    const folder = await Deno.makeTempDir();
    try {
        await Deno.mkdir(`${folder}/bin`);
        await Deno.writeTextFile(`${folder}/pools.json`, JSON.stringify(pools));
        for (const [name, profile] of Object.entries(profiles)) {
            await Deno.writeTextFile(`${folder}/profile-${name}.json`, JSON.stringify(profile));
        }
        await Deno.writeTextFile(`${folder}/bin/incus`, `#!/bin/sh
case "$1 $3" in
  "query /1.0/storage-pools?recursion=1") cat '${folder}/pools.json';;
  "query /1.0/profiles/"*) name="\${3#/1.0/profiles/}"; cat "${folder}/profile-\${name%%\\?*}.json";;
  *) echo "$*" >> '${folder}/calls';;
esac
exit 0
`);
        await Deno.chmod(`${folder}/bin/incus`, 0o755);
        await Deno.writeTextFile(`${folder}/tasks.yml`, JSON.stringify(poolTasks()));
        await Deno.writeTextFile(`${folder}/play.yml`, JSON.stringify([{
            hosts: "localhost", connection: "local", gather_facts: false,
            vars: { instances: { pool: "orbit-instances", project: "orbit-instances" } },
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
    const { code, calls } = await run([{ name: "default", driver: "dir", config: {}, used_by: [] }]);
    assertEquals(code, 0);
    assertEquals(calls, [create]);
});

Deno.test("a btrfs pool stays as it is", { ignore: !hasAnsible }, async () => {
    const { code, calls } = await run([pool("btrfs", ["/1.0/profiles/default?project=orbit-instances"])]);
    assertEquals(code, 0);
    assertEquals(calls, []);
});

const managed = {
    name: "default",
    devices: { root: { type: "disk", path: "/", pool: "orbit-instances" }, eth0: { type: "nic", network: "orbitinst0" } },
};

Deno.test("a directory pool used only by the managed root device is replaced by a btrfs pool", { ignore: !hasAnsible }, async () => {
    const { code, calls } = await run([pool("dir", ["/1.0/profiles/default?project=orbit-instances"])], { default: managed });
    assertEquals(code, 0);
    assertEquals(calls, ["profile device remove default root --project orbit-instances", "storage delete orbit-instances", create]);
});

Deno.test("a directory pool that another profile device uses refuses and stays", { ignore: !hasAnsible }, async () => {
    const { code, calls, out } = await run([pool("dir", ["/1.0/profiles/default?project=orbit-instances", "/1.0/profiles/spare"])], {
        default: { ...managed, devices: { ...managed.devices, data: { type: "disk", path: "/data", pool: "orbit-instances" } } },
        spare: { name: "spare", devices: { root: { type: "disk", path: "/", pool: "orbit-instances" } } },
    });
    assertEquals(code === 0, false);
    assertEquals(calls, []);
    assertStringIncludes(out, "the dir driver and holds orbit-instances/default data, default/spare root;");
});

Deno.test("a rerun after the root device went on deletes the unused pool", { ignore: !hasAnsible }, async () => {
    const { code, calls } = await run([pool("dir")]);
    assertEquals(code, 0);
    assertEquals(calls, ["storage delete orbit-instances", create]);
});

Deno.test("a directory pool that holds an Instance or a volume refuses and stays", { ignore: !hasAnsible }, async () => {
    const { code, calls, out } = await run([pool("dir", [
        "/1.0/profiles/default?project=orbit-instances",
        "/1.0/instances/instance-7?project=orbit-instances",
    ])], { default: managed });
    assertEquals(code === 0, false);
    assertEquals(calls, []);
    assertStringIncludes(out, "instance.host_unavailable: the Incus pool orbit-instances uses the dir driver and holds /1.0/instances/instance-7");
});
