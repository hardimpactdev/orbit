import { assert, assertEquals } from "jsr:@std/assert@1.0.14";
import { parse } from "jsr:@std/yaml@1.0.12";

// deno-lint-ignore no-explicit-any
type Task = Record<string, any>;
const read = (path: string) => parse(Deno.readTextFileSync(new URL(`../ansible/${path}`, import.meta.url))) as Task[];
const named = (tasks: Task[], name: string) => {
    const task = tasks.find((task) => task.name === name);
    assert(task, `no task named ${name}`);
    return task;
};
const position = (tasks: Task[], name: string) => tasks.indexOf(named(tasks, name));

Deno.test("the shared start enables and starts token renewal, then fails unless its timer is active and enabled", () => {
    const tasks = read("tasks/swamp-token-renewal-start.yml");
    assertEquals(named(tasks, "Start token renewal")["ansible.builtin.systemd"],
        { name: "orbit-swamp-token-renewal.timer", state: "started", enabled: true });
    const check = named(tasks, "Check token renewal is active and enabled");
    assertEquals(check["ansible.builtin.command"].argv, ["systemctl", "{{ item }}", "orbit-swamp-token-renewal.timer"]);
    assertEquals(check.loop, ["is-active", "is-enabled"]);
    assertEquals(check.failed_when, undefined);
    assert(position(tasks, "Start token renewal") < tasks.indexOf(check));
    assert(!JSON.stringify(tasks).includes("orbit-swamp-worker-renewal.timer"));
});

// Operation 32394: the roles phase wrote the units, but only the role add started the timer.
Deno.test("the converge roles phase starts token renewal after the runtime writes and reloads its units", () => {
    const target = read("operation-node-converge.yml").find((play) => play.hosts === "target")!;
    const roles = named(target.tasks, "Reconcile selected recorded roles");
    assertEquals(roles["ansible.builtin.include_tasks"].file, "tasks/node-converge-role.yml");
    assertEquals(roles.tags, ["roles"]);

    const role = read("tasks/node-converge-role.yml");
    const runtime = position(role, "Reconcile the shared role runtime, never publication or other-Node plays");
    assertEquals(role[runtime]["ansible.builtin.include_tasks"], "node-role-runtime.yml");
    const start = named(role, "Start token renewal after its units");
    assertEquals(start["ansible.builtin.import_tasks"], "swamp-token-renewal-start.yml");
    assertEquals(start.when, "scoped_role.intent.runtime.swamp is not none");
    assert(runtime < role.indexOf(start));

    const shared = read("tasks/node-role-runtime.yml");
    assert(position(shared, "Install Swamp worker renewal") < position(shared, "Reload units before services start"));
});

Deno.test("the role add starts token renewal through the same file, and worker renewal only after all workers", () => {
    const swamp = read("tasks/role-swamp.yml");
    assertEquals(named(swamp, "Start token renewal")["ansible.builtin.import_tasks"], "swamp-token-renewal-start.yml");
    assert(position(swamp, "Reload Swamp units") < position(swamp, "Start token renewal"));
    assert(position(swamp, "Start the Swamp workers") < position(swamp, "Start worker renewal after all workers"));

    // The role add reconciles this runtime before its workers start, so neither the runtime nor its unit file starts a timer.
    for (const path of ["tasks/node-role-runtime.yml", "tasks/swamp-worker-renewal.yml"]) {
        const text = JSON.stringify(read(path));
        assert(!text.includes("swamp-token-renewal-start.yml"), path);
        assert(!/"ansible\.builtin\.systemd":\{"name":"orbit-swamp-[a-z-]+\.timer"/.test(text), path);
    }
});
