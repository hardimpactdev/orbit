/** The T3 restart for the machine name (docs/cli/conn/environment-restart.mmd): the shipped workflow's steps, and its one phase run by real
 * Ansible against the Incus fixture with a T3 descriptor server. */
import { assert, assertEquals } from "jsr:@std/assert@1";
import { parse } from "jsr:@std/yaml@1.0.12";
import { Environment } from "npm:@marcbachmann/cel-js@7.6.1";

type Step = { name: string; dependsOn?: { step: string; condition: { type: string } }[]; task: { behaviorName?: string; inputs: { run?: string; env?: Record<string, string> } } };
const workflow = parse(Deno.readTextFileSync(new URL("../workflows/workflow-operation-conn-environment-restart.yaml", import.meta.url))) as {
    inputs: { properties: { intent: { required: string[]; properties: Record<string, unknown> } } };
    jobs: { name: string; steps: Step[] }[];
};
const cel = new Environment({ enableOptionalTypes: true, unlistedVariablesAreDyn: true, homogeneousAggregateLiterals: false });
const shipped = new URL("../ansible/", import.meta.url).pathname.replace(/\/$/, "");
const runId = "0f0e0d0c-0b0a-4908-8706-050403020100";

Deno.test("the restart runs one tagged phase of the shipped playbook between the input and the result, and takes no secret", () => {
    const steps = workflow.jobs.flatMap((job) => job.steps);
    assertEquals(workflow.jobs.map((job) => job.name), ["restart"]);
    assertEquals(steps.map((step) => step.name), ["input", "Restart T3", "result", "cleanup"]);
    assertEquals(steps[2].dependsOn, [{ step: "Restart T3", condition: { type: "always" } }]);
    assertEquals(workflow.inputs.properties.intent.required, ["instance_id", "url", "name", "read_at", "container", "ssh_execution"]);
    const phase = steps[1];
    assertEquals(phase.task.inputs.run, 'timeout 240 /opt/orbit/ansible/venv/bin/ansible-playbook -i localhost, --tags restart "$ORBIT_ANSIBLE/conn-environment-restart.yml"');
    const context = { inputs: { node_id: 9, intent: {} }, steps: { input: { status: "succeeded", outputs: { assets: shipped } } }, run: { id: runId } };
    const evaluate = (value: string) => value.startsWith("${{") ? String(cel.evaluate(value.slice(3, -2).trim(), context)) : value;
    const env = Object.fromEntries(Object.entries(phase.task.inputs.env!).map(([key, value]) => [key, evaluate(value)]));
    assertEquals([env.ANSIBLE_CONFIG, env.ORBIT_ANSIBLE, env.ORBIT_RUN_ID, env.ORBIT_RUN], [`${shipped}/ansible.cfg`, shipped, runId, `/tmp/orbit-ansible-${runId}`]);
    assertEquals(evaluate(phase.task.behaviorName!), "conn-environment-restart-9");
    assert(Deno.statSync(`${shipped}/conn-environment-restart.yml`).isFile);
    assert(!JSON.stringify(workflow).includes("vault.get"));
});

const available = new Deno.Command("sh", { args: ["-c", "command -v ansible-playbook"] }).outputSync().success;

/** One run of the phase against a container in `status`, whose T3 is active or not and answers `label`. */
async function restart(options: { status?: string; inactive?: boolean; label?: string }): Promise<{ ok: boolean; output: string; result: Record<string, unknown>; systemctl: string[][] }> {
    const folder = Deno.makeTempDirSync();
    const t3 = Deno.serve({ port: 0, hostname: "127.0.0.1", onListen() {} }, () => Response.json({ environmentId: "env", label: options.label ?? "clone.shop", serverVersion: "0.1.7" }));
    try {
        for (const dir of ["bin", "run", "containers/instance-7"]) Deno.mkdirSync(`${folder}/${dir}`, { recursive: true });
        Deno.writeTextFileSync(`${folder}/state.json`, JSON.stringify({ inactive: options.inactive ? ["t3.service"] : [],
            containers: { "instance-7": { status: options.status ?? "Running", t3: true } } }));
        Deno.writeTextFileSync(`${folder}/bin/incus`, `#!/bin/sh\nexec '${Deno.execPath()}' run -A '${new URL("fixtures/instance-container-incus.ts", import.meta.url).pathname}' "$@"\n`);
        Deno.chmodSync(`${folder}/bin/incus`, 0o755);
        Deno.writeTextFileSync(`${folder}/run/intent.json`, JSON.stringify({
            ssh_execution: { identity_file: "/fixture", target: { host: "127.0.0.1", port: 22, user: "orbit", host_keys: ["ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl"] } },
            instance_id: 7, url: `http://127.0.0.1:${t3.addr.port}`, name: "clone.shop", read_at: "2026-11-14T12:00:00.000Z",
            container: { project: "orbit-instances", name: "instance-7" },
        }));
        const done = await new Deno.Command("ansible-playbook", {
            args: ["-i", "localhost,", "--tags", "restart", "-e", "ansible_connection=local", "-e", "ansible_become=false",
                "-e", "ansible_python_interpreter={{ ansible_playbook_python }}", "-e", "restart_retries=2", "-e", "restart_delay=0", `${shipped}/conn-environment-restart.yml`],
            env: { HOST_FIXTURE: folder, ORBIT_RUN: `${folder}/run`, PATH: `${folder}/bin:${Deno.env.get("PATH")}`,
                ANSIBLE_HOME: `${folder}/ansible`, ANSIBLE_NOCOLOR: "1", ANSIBLE_CONFIG: `${shipped}/ansible.cfg` },
        }).output();
        return {
            ok: done.success,
            output: new TextDecoder().decode(done.stdout) + new TextDecoder().decode(done.stderr),
            result: JSON.parse(Deno.readTextFileSync(`${folder}/run/result.json`)),
            systemctl: JSON.parse(Deno.readTextFileSync(`${folder}/state.json`)).systemctl ?? [],
        };
    } finally {
        await t3.shutdown();
        Deno.removeSync(folder, { recursive: true });
    }
}

Deno.test({ name: "an idle running T3 restarts once, is active again and shows the name; the result has the restart time", ignore: !available, async fn() {
    const run = await restart({});
    assert(run.ok, run.output);
    assertEquals(run.result.answer, "restarted");
    assert(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/.test(String(run.result.restart_at)), String(run.result.restart_at));
    assertEquals(run.systemctl, [["try-restart", "--", "t3.service"], ["is-active", "--", "t3.service"]]);
} });

Deno.test({ name: "a container that froze or stopped after the read is skipped, and nothing restarts", ignore: !available, async fn() {
    for (const [status, reason] of [["Frozen", "frozen"], ["Stopped", "stopped"]]) {
        const run = await restart({ status });
        assert(run.ok, run.output);
        assertEquals(run.result, { answer: "skipped", reason });
        assertEquals(run.systemctl, []);
    }
} });

Deno.test({ name: "a T3 that is not active again fails with conn.t3_inactive, and the restart is not repeated", ignore: !available, async fn() {
    const run = await restart({ inactive: true });
    assert(!run.ok, run.output);
    assertEquals(run.result.error_code, "conn.t3_inactive");
    assertEquals(run.systemctl.filter((call) => call[0] === "try-restart").length, 1);
} });

Deno.test({ name: "a T3 that is active without the name fails with conn.t3_label_mismatch", ignore: !available, async fn() {
    const run = await restart({ label: "instance-7" });
    assert(!run.ok, run.output);
    assertEquals(run.result.error_code, "conn.t3_label_mismatch");
} });
