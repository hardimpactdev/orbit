import { assert, assertEquals } from "jsr:@std/assert@1";
import { parse } from "jsr:@std/yaml@1.0.12";
import { Environment } from "npm:@marcbachmann/cel-js@7.6.1";

const text = (path: string) => Deno.readTextFileSync(new URL(path, import.meta.url));
const play = text("../ansible/conn-launch.yml");
const helper = text("../ansible/t3_copy_rpc.mjs");
const workflow = parse(text("../workflows/workflow-operation-conn-launch.yaml")) as {
    jobs: { steps: { name: string; guard?: string; writes?: boolean; task: { inputs: { run?: string } } }[] }[];
};
const steps = workflow.jobs[0].steps;
const cel = new Environment({ enableOptionalTypes: true, unlistedVariablesAreDyn: true, homogeneousAggregateLiterals: false });

Deno.test("the Conn launch workflow stages private input only on the dispatch and recovery paths", () => {
    const guard = (name: string, kind: string, action: string, hasDispatch: boolean) => cel.evaluate(
        steps.find((step) => step.name === name)!.guard!.slice(3, -2).trim(),
        { inputs: { kind, intent: { action }, execution_secrets: hasDispatch ? { dispatch_request: {} } : {} } },
    );
    const secret = "Stage the private launch request";
    assertEquals(guard("Inspect T3", "conn.launch_inspect", "inspect-launch", false), false);
    assertEquals(guard(secret, "conn.launch_inspect", "inspect-launch", false), true);
    for (const action of ["launch", "reconcile-launch"]) {
        assertEquals(guard("Inspect T3", "conn.launch_dispatch", action, true), true);
        assertEquals(guard(secret, "conn.launch_dispatch", action, true), false);
        assertEquals(guard(secret, "conn.launch_dispatch", action, false), true);
    }
    assertEquals(steps.find((step) => step.name === "Hand off or reconcile T3")?.writes, true);
    assertEquals(steps.find((step) => step.name === "result")?.writes, true);
    assert(text("../manifest.yaml").includes("  - ansible/conn-launch.yml\n"));

    const directory = Deno.makeTempDirSync();
    try {
        const run = steps.find((step) => step.name === secret)!.task.inputs.run!;
        const payload = '{"text":"line 1\\n$line 2"}';
        const stage = (request: string) => new Deno.Command("sh", {
            args: ["-c", run],
            env: { ORBIT_RUN: directory, ORBIT_LAUNCH_REQUEST: request },
            stdout: "piped",
            stderr: "piped",
        }).outputSync();
        const path = `${directory}/launch.json`;
        const created = stage(payload);
        assert(created.success);
        assertEquals(created.stdout.length + created.stderr.length, 0);
        assertEquals(Deno.statSync(path).mode! & 0o777, 0o600);
        assert(!stage("replacement").success);
        assertEquals(Deno.readTextFileSync(path), payload);
    } finally {
        Deno.removeSync(directory, { recursive: true });
    }
});

const available = new Deno.Command("sh", { args: ["-c", "command -v ansible-playbook"] }).outputSync().success;
const fakeIncus = '#!/usr/bin/env python3\n'
    + 'from pathlib import Path\n'
    + 'import json, sys\n'
    + 'root = Path(__file__).parent\n'
    + 'args = sys.argv[1:]\n'
    + 'assert args[:7] == ["--project", "orbit-instances", "exec", "--cwd", "/srv/workspace", "instance-17", "--"]\n'
    + 'action = args[args.index("orbit-rpc") + 2:]\n'
    + '(root / "call.json").write_text(json.dumps(action))\n'
    + '(root / "stdin").write_bytes(sys.stdin.buffer.read())\n'
    + 'assert not (root / "launch.json").exists()\n'
    + 'print(json.dumps({"accepted_at": "2026-11-17T22:00:00.000Z", "error_code": None}))\n';

Deno.test({ name: "the Conn Ansible phase preserves private stdin and refuses a repeat of the same send", ignore: !available, async fn() {
    const folder = await Deno.makeTempDir({ prefix: "conn-launch-phase-" });
    try {
        const local = "---\n" + play.slice(play.indexOf("- name: Run the scoped T3 call")).replace(
            "  hosts: conn_launch_node\n", "  hosts: localhost\n  connection: local\n",
        ).replace("  become: true\n", "  become: false\n");
        await Deno.writeTextFile(folder + "/local.yml", local);
        await Deno.writeTextFile(folder + "/t3_copy_rpc.mjs", helper);
        await Deno.writeTextFile(folder + "/incus", fakeIncus, { mode: 0o700 });
        const run = () => new Deno.Command("ansible-playbook", {
            args: ["-i", "localhost,", "--tags", "launch", folder + "/local.yml"],
            env: { ORBIT_RUN: folder, PATH: folder + ":" + (Deno.env.get("PATH") ?? "") },
            stdout: "piped", stderr: "piped",
        }).output();

        const payload = JSON.stringify({ request: { initialMessage: { text: "  private β {{ literal }}\n".repeat(6000) } } });
        for (const action of ["launch", "reconcile-launch"]) {
            await Deno.writeTextFile(folder + "/intent.json", JSON.stringify({
                action, instance_id: 17, runtime: { project: "orbit-instances", name: "instance-17" },
            }));
            await Deno.writeTextFile(folder + "/launch.json", payload, { mode: 0o600 });
            const result = await run();
            assert(result.success, new TextDecoder().decode(result.stdout));
            assertEquals(JSON.parse(await Deno.readTextFile(folder + "/call.json")), [action]);
            assert((await Deno.readTextFile(folder + "/stdin")) === payload);
            assert(!new TextDecoder().decode(result.stdout).includes("private β"));
            assert(!new TextDecoder().decode(result.stderr).includes("private β"));
            if (action === "launch") {
                await Deno.remove(folder + "/call.json");
                const blocked = await run();
                assert(!blocked.success);
                await assertNoCall(folder + "/call.json");
            }
        }
    } finally {
        await Deno.remove(folder, { recursive: true });
    }
} });

async function assertNoCall(path: string) {
    try {
        await Deno.stat(path);
    } catch (error) {
        if (error instanceof Deno.errors.NotFound) return;
        throw error;
    }
    throw new Error("The replay reached the Instance");
}

