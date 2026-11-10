import { assertEquals, assertMatch } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { fileURLToPath } from "node:url";

const tasksPath = fileURLToPath(new URL("../ansible/tasks/", import.meta.url));

Deno.test("converge retires only managed CLI files, is idempotent and detects their return", async () => {
    const folder = await Deno.makeTempDir();
    try {
        const bin = `${folder}/bin`;
        const root = `${folder}/root/.orbit`;
        await Deno.mkdir(bin);
        await Deno.mkdir(root, { recursive: true });
        for (const name of ["orbit-0.1.0", "orbit-0.2.0", "orbit-agent", "orbit.bak.1", "orbit-0.bad.0", "orbit-0.0.0", "orbit-0.01.0", "orbit-0.12345678901.0"]) {
            await Deno.writeTextFile(`${bin}/${name}`, name);
        }
        await Deno.mkdir(`${bin}/orbit-0.3.0`);
        await Deno.writeTextFile(`${bin}/custom-cli`, "custom");
        await Deno.symlink(`${bin}/custom-cli`, `${bin}/orbit-0.4.0`);
        await Deno.symlink(`${bin}/orbit-0.2.0`, `${bin}/orbit`);
        await Deno.writeTextFile(`${root}/config.json`, '{"active_gateway":"gateway"}');
        await Deno.writeTextFile(`${root}/other.json`, "keep");
        // Run the production CLI portion of the base phase, with its guarded installer present.
        // Redirect only its filesystem roots to the disposable fixture.
        const base = parse(await Deno.readTextFile(`${tasksPath}/node-converge-base.yml`)) as Record<string, unknown>[];
        const cli = base.slice(base.findIndex((task) => task.name === "Require a valid recorded CLI release"));
        for (const file of ["node-converge-cli-absent.yml", "node-converge-replace.yml"]) {
            const source = await Deno.readTextFile(`${tasksPath}/${file}`);
            await Deno.writeTextFile(`${folder}/${file}`, source.replaceAll("/usr/local/bin", bin).replaceAll("/root/.orbit", root).replaceAll("/run/lock/orbit-self-update.lock", `${folder}/update.lock`));
        }
        const play = async (tasks: unknown[], cliIntent: unknown = null) => {
            const path = `${folder}/play.json`;
            await Deno.writeTextFile(path, JSON.stringify([{
                hosts: "localhost", connection: "local", gather_facts: false, vars: { intent: { cli: cliIntent } }, tasks,
            }]));
            const result = await new Deno.Command("ansible-playbook", {
                args: ["-i", "localhost,", path], stdout: "piped", stderr: "piped",
                env: { ANSIBLE_NOCOLOR: "1", ANSIBLE_STDOUT_CALLBACK: "default" },
            }).output();
            return { code: result.code, output: new TextDecoder().decode(result.stdout) + new TextDecoder().decode(result.stderr) };
        };
        const first = await play(cli);
        assertEquals(first.code, 0, first.output);
        assertMatch(first.output, /changed=1\s/);
        assertEquals([...Deno.readDirSync(bin)].map((file) => file.name).sort(), ["custom-cli", "orbit-0.0.0", "orbit-0.01.0", "orbit-0.12345678901.0", "orbit-0.3.0", "orbit-0.4.0", "orbit-0.bad.0", "orbit-agent", "orbit.bak.1"]);
        assertEquals([...Deno.readDirSync(root)].map((file) => file.name), ["other.json"]);
        const second = await play(cli);
        assertEquals(second.code, 0, second.output);
        assertMatch(second.output, /changed=0\s/);
        const check = [{ name: "Check CLI absence", "ansible.builtin.import_tasks": "node-converge-cli-absent.yml", vars: { cli_remove: false } }];
        assertEquals((await play(check)).code, 0);
        await Deno.writeTextFile(`${bin}/orbit-0.9.0`, "returned drift");
        assertEquals((await play(check)).code, 2);
        assertEquals(await Deno.readTextFile(`${bin}/orbit-0.9.0`), "returned drift");
        // A custom executable at the selection path is outside managed version-link ownership.
        await Deno.symlink(`${bin}/custom-cli`, `${bin}/orbit`);
        const repaired = await play(cli);
        assertEquals(repaired.code, 0, repaired.output);
        assertEquals(await Deno.readLink(`${bin}/orbit`), `${bin}/custom-cli`);
        assertEquals((await play(check)).code, 0);
    } finally {
        await Deno.remove(folder, { recursive: true });
    }
});
