import { assert, assertEquals } from "jsr:@std/assert@1";

const available = new Deno.Command("sh", { args: ["-c", "command -v ansible-playbook"] }).outputSync().success;
const shipped = new URL("../ansible/tasks/executor-power-relay.yml", import.meta.url);
const runId = "0f0e0d0c-0b0a-4908-8706-050403020100";
const outcome = { status: "done", operation: "41", target: "7", action: "sleep", receipt: { sleep: "frozen" } };

async function relay(lines: string[], unboundFirst = false, exitCode = 0) {
    const folder = Deno.makeTempDirSync();
    const calls: { path: string; method: string; data: unknown }[] = [];
    const signer = Deno.serve({ hostname: "127.0.0.1", port: 0, onListen() {} }, async (request) => {
        calls.push({ path: new URL(request.url).pathname, method: request.method, data: await request.json() });
        if (unboundFirst && calls.length === 1) {
            return Response.json({ error: { code: "operation.run_unbound" } }, { status: 409 });
        }
        return Response.json({ data: { order: "fixture-opaque-input" } });
    });
    try {
        Deno.writeTextFileSync(`${folder}/relay.yml`, Deno.readTextFileSync(shipped)
            .replace("https://gateway.orbit", `http://127.0.0.1:${signer.addr.port}`)
            .replace(/^    ca_path: .*\n/m, ""));
        Deno.writeTextFileSync(`${folder}/reply`, lines.join("\n") + "\n");
        Deno.writeTextFileSync(`${folder}/sudo`, `#!/bin/sh
test "$*" = '-n -u orbit-executor-relay /usr/local/libexec/orbit-executor relay' || exit 21
test "$(cat)" = 'fixture-opaque-input' || exit 22
cat '${folder}/reply'
exit ${exitCode}
`, { mode: 0o700 });
        Deno.writeTextFileSync(`${folder}/fixture.yml`, `---
- name: Run the shipped relay phase
  hosts: node_fixture
  gather_facts: false
  environment:
    PATH: "${folder}:{{ lookup('ansible.builtin.env', 'PATH') }}"
  vars:
    container: {instance_id: 7}
    executor_phase: power
    executor_action: sleep
    ansible_python_interpreter: "{{ ansible_playbook_python }}"
  tasks:
    - name: Capture the public outcome
      block:
        - ansible.builtin.include_tasks: relay.yml
        - ansible.builtin.copy:
            content: "{{ executor_receipt | to_json }}"
            dest: "${folder}/result.json"
      rescue:
        - ansible.builtin.copy:
            content: "{{ {'code': ansible_failed_result.msg | default('invalid_line')} | to_json }}"
            dest: "${folder}/result.json"
`);
        Deno.writeTextFileSync(`${folder}/inventory`, "node_fixture ansible_connection=local\n");
        const run = await new Deno.Command("ansible-playbook", {
            args: ["-i", `${folder}/inventory`, `${folder}/fixture.yml`],
            env: { ORBIT_OPERATION_ID: "41", ORBIT_RUN_ID: runId, ANSIBLE_FORCE_COLOR: "0" },
            cwd: folder,
            stdout: "piped",
            stderr: "piped",
        }).output();
        const output = new TextDecoder().decode(run.stdout) + new TextDecoder().decode(run.stderr);
        assert(!output.includes("fixture-opaque-input"));
        assert(run.success, output);
        assertEquals(calls, Array.from({ length: unboundFirst ? 2 : 1 }, () => ({
            path: "/api/v1/direct-operations/41/executor/sign",
            method: "POST",
            data: { phase: "power", run_id: runId },
        })));
        return JSON.parse(Deno.readTextFileSync(`${folder}/result.json`));
    } finally {
        await signer.shutdown();
        Deno.removeSync(folder, { recursive: true });
    }
}

const accepted = JSON.stringify({ status: "accepted" });

Deno.test({ name: "a delayed binding signs on the worker and sends the order only on relay stdin", ignore: !available, async fn() {
    assertEquals(await relay([accepted, JSON.stringify(outcome)], true), { ...outcome, delivery: "swamp" });
} });

Deno.test({ name: "a crossed delivery uses the executor's first matching receipt", ignore: !available, async fn() {
    assertEquals(await relay([accepted, JSON.stringify({ status: "refused", reason: "replayed_nonce", first: outcome })]),
        { ...outcome, delivery: "swamp" });
} });

Deno.test({ name: "an executor refusal is not a successful or lost delivery", ignore: !available, async fn() {
    assertEquals(await relay([accepted, JSON.stringify({ status: "refused", reason: "wrong_node" })]), { code: "executor.order_refused" });
} });

Deno.test({ name: "a terminal reply wins over a relay process error after delivery", ignore: !available, async fn() {
    assertEquals(await relay([accepted, JSON.stringify({ status: "refused", reason: "wrong_node" })], false, 1),
        { code: "executor.order_refused" });
} });

Deno.test({ name: "a missing terminal line or mismatched target is a transport failure", ignore: !available, async fn() {
    assertEquals(await relay([accepted]), { code: "executor.relay_transport" });
    assertEquals(await relay([accepted, JSON.stringify({ ...outcome, target: "99" })]), { code: "executor.relay_transport" });
} });
