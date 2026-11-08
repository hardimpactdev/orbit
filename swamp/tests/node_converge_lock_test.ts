import { assert, assertEquals, assertMatch } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";

type Task = Record<string, unknown>;
const taskUrl = (name: string) => new URL(`../ansible/tasks/${name}.yml`, import.meta.url);
const base = parse(await Deno.readTextFile(taskUrl("node-converge-base"))) as Task[];
const cli = base.find((task) => task.name === "Reconcile the recorded CLI release")!.block as Task[];
const lock = "/run/lock/orbit-self-update.lock";
const decoder = new TextDecoder();

for (const [name, task] of [
    ["agent", base.find((task) => task.name === "Reconcile the pinned agent binary")!],
    ["CLI artifact", cli.find((task) => task.name === "Install the pinned CLI")!],
    ["CLI selection", cli.find((task) => task.name === "Select the pinned CLI release")!],
] as const) {
    Deno.test(`${name} replacement waits for the existing update lock, cleans failed staging and repeats without changes`, async () => {
        // On the old implementation this fails before any fixture command can run:
        // get_url/file wrote the final destination without taking the update lock.
        assertEquals(task["ansible.builtin.import_tasks"], "node-converge-replace.yml");
        const production = parse(await Deno.readTextFile(taskUrl("node-converge-replace"))) as Task[];
        const transaction = production.find((item) => item.name === "Stage and commit a different artifact")!;
        const commit = (transaction.block as Task[]).find((item) => item.name === "Commit the replacement under the update lock")!;
        assertEquals((commit["ansible.builtin.command"] as { argv: string[] }).argv.slice(0, 4), ["flock", "--wait", "120", lock]);
        const footprint = await Deno.readTextFile(new URL("../../apps/gateway/app/Infrastructure/Nodes/NodeAgentFootprint.php", import.meta.url));
        assert(footprint.includes(`UpdateLockPath = '${lock}'`));
        assertEquals((task.vars as { converge_replacement: { link?: string } }).converge_replacement.link !== undefined, name === "CLI selection");

        const folder = await Deno.makeTempDir({ prefix: "orbit-converge-lock-test-" });
        let holder: Deno.ChildProcess | undefined;
        try {
            const old = `${folder}/old`;
            const source = `${folder}/source`;
            const target = `${folder}/target`;
            const fixtureLock = `${folder}/update.lock`;
            await Deno.writeTextFile(old, "old release\n");
            await Deno.writeTextFile(source, "admitted release\n");
            const link = name === "CLI selection";
            if (link) await Deno.symlink(old, target);
            else await Deno.writeTextFile(target, "old release\n");
            const digest = Array.from(new Uint8Array(await crypto.subtle.digest("SHA-256", await Deno.readFile(source))))
                .map((byte) => byte.toString(16).padStart(2, "0")).join("");
            // Run the actual tasks on temporary paths as the test user. Shorten only
            // the wait; a genuine flock failure must leave the selected artifact intact.
            const tasks = JSON.parse(JSON.stringify(production)
                .replaceAll(lock, fixtureLock).replace('"--wait","120"', '"--wait","1"')
                .replaceAll('"owner":"root"', `"owner":"${Deno.uid()}"`)
                .replaceAll('"group":"root"', `"group":"${Deno.gid()}"`));
            if (name === "agent") {
                tasks.push(base.find((item) => item.name === "Remember whether the agent binary changed")!);
                tasks.push({
                    name: "Check the agent restart decision before subsequent replacements",
                    "ansible.builtin.assert": { that: ["agent_binary_changed == expected_agent_change"] },
                });
            }
            const fixtureTasks = `${folder}/replacement.json`;
            await Deno.writeTextFile(fixtureTasks, JSON.stringify(tasks));
            const play = `${folder}/play.json`;
            await Deno.writeTextFile(play, JSON.stringify([{
                hosts: "localhost", connection: "local", gather_facts: false,
                vars: { converge_replacement: { path: target, ...(link ? { link: source } : { url: `file://${source}`, sha256: digest }) } },
                tasks: [{ name: "Apply the tagged base replacement", "ansible.builtin.import_tasks": fixtureTasks, tags: ["agent"] }],
            }]));
            const run = async (expectedAgentChange = true) => {
                const result = await new Deno.Command("ansible-playbook", {
                    args: ["-i", "localhost,", "--tags", "agent", "--extra-vars", JSON.stringify({ expected_agent_change: expectedAgentChange }), play],
                    stdout: "piped", stderr: "piped",
                    env: { ANSIBLE_NOCOLOR: "1", ANSIBLE_STDOUT_CALLBACK: "ansible.posix.json" },
                }).output();
                const output = decoder.decode(result.stdout);
                const report = JSON.parse(output);
                return { code: result.code, report, output: output + decoder.decode(result.stderr) };
            };
            holder = new Deno.Command("flock", {
                args: ["--exclusive", fixtureLock, "sh", "-c", "printf ready; read release"],
                stdin: "piped", stdout: "piped", stderr: "piped",
            }).spawn();
            const reader = holder.stdout.getReader();
            assertEquals(decoder.decode((await reader.read()).value), "ready");
            reader.releaseLock();
            const refused = await run();
            assertEquals(refused.code, 2, refused.output);
            const failed = refused.report.plays[0].tasks.find((item: { task: { name: string } }) => item.task.name === "Commit the replacement under the update lock");
            assertEquals(failed.hosts.localhost.rc, 1, refused.output);
            assertEquals(failed.hosts.localhost.cmd.slice(0, 4), ["flock", "--wait", "1", fixtureLock]);
            assertEquals(await Deno.readTextFile(target), "old release\n");
            if (link) assertEquals(await Deno.readLink(target), old);
            const staging = async () => {
                const names: string[] = [];
                for await (const item of Deno.readDir(folder)) if (item.name.startsWith(".orbit-converge-")) names.push(item.name);
                return names;
            };
            assertEquals(await staging(), []);
            const writer = holder.stdin.getWriter();
            await writer.write(new TextEncoder().encode("release\n"));
            await writer.close();
            assertEquals((await holder.status).code, 0);
            holder = undefined;
            const applied = await run();
            assertEquals(applied.code, 0, applied.output);
            assert(applied.report.stats.localhost.changed > 0, applied.output);
            assertEquals(await Deno.readTextFile(target), "admitted release\n");
            if (link) assertEquals(await Deno.readLink(target), source);
            else assertEquals((await Deno.stat(target)).mode! & 0o777, 0o755);
            assertEquals(await staging(), []);
            const repeated = await run(false);
            assertEquals(repeated.code, 0, repeated.output);
            assertEquals(repeated.report.stats.localhost.changed, 0, repeated.output);
            assertEquals(await Deno.readTextFile(old), "old release\n");
        } finally {
            if (holder) {
                await holder.stdin.close();
                await holder.status;
            }
            await Deno.remove(folder, { recursive: true });
        }
    });
}

Deno.test("an agent replacement still restarts only for managed changes", () => {
    const start = base.find((task) => task.name === "Start the agent and restart only for managed changes")!;
    assertMatch((start["ansible.builtin.systemd"] as { state: string }).state, /agent_binary_changed.*agent_files is changed/);
    const snapshot = base.findIndex((task) => task.name === "Remember whether the agent binary changed");
    assert(snapshot > base.findIndex((task) => task.name === "Reconcile the pinned agent binary"));
    assert(snapshot < base.findIndex((task) => task.name === "Reconcile the recorded CLI release"));
});
