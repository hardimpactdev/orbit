/** A turn in a task copy: the shipped workflow's step expressions, and task-copy-turn.yml run by real Ansible against an Incus shim that
 * plays T3's CLI. No live container. */
import { assert, assertEquals } from "jsr:@std/assert@1";
import { parse } from "jsr:@std/yaml@1.0.12";
import { Environment } from "npm:@marcbachmann/cel-js@7.6.1";

type Step = { name: string; dependsOn?: { step: string; condition: { type: string } }[]; task: { behaviorName?: string; behaviorType?: string; expr?: string; inputs: Record<string, unknown> & { run?: string; env?: Record<string, string> } } };
const workflow = parse(Deno.readTextFileSync(new URL("../workflows/workflow-operation-task-copy-turn.yaml", import.meta.url))) as { jobs: { name: string; steps: Step[] }[] };
const steps = workflow.jobs.flatMap((job) => job.steps);
const cel = new Environment({ enableOptionalTypes: true, unlistedVariablesAreDyn: true, homogeneousAggregateLiterals: false });
const shipped = new URL("../ansible/", import.meta.url).pathname.replace(/\/$/, "");
const runId = "0f0e0d0c-0b0a-4908-8706-050403020100";
const ssh = { identity_file: "/fixture", target: { host: "127.0.0.1", port: 22, user: "orbit", host_keys: ["ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl"] } };
const turn = { t3: "/opt/orbit-agent/current/agent/bin/t3", provider: "claudeAgent", model: "claude-opus-5-5", seconds: 3600, title: "Orbit task 4970 thread 77", prompt: "Subtask: Doctor note\n\nRun Doctor." };
// What TaskCopyTurn admits; the workflow passes only the runtime, the SSH target and the turn to the phase.
const intent = { subtask: 4971, role: "implementer", thread: "new", group_id: 4970, instance_id: 2685, node_id: 9, ssh_execution: ssh,
    runtime: { project: "orbit-instances-nested", name: "instance-2685" }, branch: "task-4970", thread_id: 77, turn };

Deno.test("the workflow writes only the turn's target into the phase input, runs the shipped playbook with its settings, and asserts the shaped result", () => {
    const context = { inputs: { node_id: 9, intent, execution_secrets: {} }, steps: { input: { status: "succeeded", outputs: { assets: shipped } }, result: { outputs: { successful: true } } }, run: { id: runId } };
    const evaluate = (value: unknown) => typeof value === "string" && value.startsWith("${{") ? cel.evaluate(value.slice(3, -2).trim(), context) : value;
    assertEquals(steps.map((step) => step.name), ["input", "Run the turn", "result", "cleanup", "outcome"]);
    const input = steps[0], run = steps[1], result = steps[2], outcome = steps[4];
    assertEquals(evaluate(input.task.inputs.intent), { runtime: intent.runtime, ssh_execution: ssh, turn });
    assertEquals(evaluate(input.task.inputs.path), `/tmp/orbit-ansible-${runId}/intent.json`);
    assert(run.task.inputs.run!.endsWith('"$ORBIT_ANSIBLE/task-copy-turn.yml"'));
    assert(Number(/^timeout (\d+) /.exec(run.task.inputs.run!)![1]) > turn.seconds + 30 + 240);
    const env = Object.fromEntries(Object.entries(run.task.inputs.env!).map(([key, value]) => [key, String(evaluate(value))]));
    assertEquals([env.ANSIBLE_CONFIG, env.ORBIT_ANSIBLE, env.ORBIT_RUN], [`${shipped}/ansible.cfg`, shipped, `/tmp/orbit-ansible-${runId}`]);
    assertEquals(String(evaluate(run.task.behaviorName)), "task-copy-turn-9");
    assert(Deno.statSync(`${shipped}/task-copy-turn.yml`).isFile);
    assertEquals([result.task.behaviorType, evaluate(result.task.inputs.folder), result.dependsOn![0].condition.type], ["@nckrtl/task-copy-turn", `/tmp/orbit-ansible-${runId}`, "always"]);
    assertEquals(cel.evaluate(outcome.task.expr!, context), true);
    const manifest = Deno.readTextFileSync(new URL("../manifest.yaml", import.meta.url));
    assert(manifest.includes("  - ansible/task-copy-turn.yml\n"), "the playbook ships to the worker");
});

const available = new Deno.Command("sh", { args: ["-c", "command -v ansible-playbook"] }).outputSync().success;

// Validates the Incus boundary, then answers as T3's CLI would. `T3_START` picks the start's behaviour.
const SHIM = `#!/usr/bin/env python3
import json, os, sys
args = sys.argv[1:]
stdin = sys.stdin.read()
with open(os.environ['T3_CALLS'], 'a') as calls:
    calls.write(json.dumps({'args': args, 'stdin': stdin}) + '\\n')
assert args[:7] == ['--project', 'orbit-instances-nested', 'exec', '--cwd', '/srv/workspace', 'instance-2685', '--'], args
assert args[7:11] == ['/usr/sbin/runuser', '-u', 'agent', '--'], args
assert args[11:15] == ['/usr/bin/env', '-i', 'HOME=/home/agent', 'PATH=/usr/local/bin:/usr/bin:/bin'], args
rest = args[15:]
thread = 'mcp:66105c2c'
if rest == ['/usr/bin/true']:
    sys.exit(1 if os.environ['T3_START'] == 'unreachable' else 0)
if rest[0] == '/usr/bin/timeout' and rest[1] == '--kill-after=30':
    assert rest[2] == '3600' and rest[3] == '/opt/orbit-agent/current/agent/bin/t3' and rest[4:6] == ['thread', 'start'], rest
    assert rest[-4:] == ['--wait', '--json', '/srv/workspace', '-'], rest
    mode = os.environ['T3_START']
    if mode == 'garbled':
        mode = 'completed'
    if mode == 'timeout':
        sys.exit(124)
    if mode == 'refused':
        sys.stderr.write('server not running')
        sys.exit(1)
    print(json.dumps({'threadId': thread, 'runId': 'run:1', 'status': mode, 'reply': 'done', 'error': None}))
    sys.exit(0 if mode == 'completed' else 1)
assert rest[0] == '/usr/bin/timeout' and rest[1] == '120' and rest[2] == '/opt/orbit-agent/current/agent/bin/t3', rest
if rest[3:5] == ['thread', 'list']:
    titles = [] if os.environ['T3_START'] == 'refused' else ['Orbit task 4970 thread 77']
    if os.environ['T3_START'] == 'garbled':
        print('not json')
        sys.exit(0)
    print(json.dumps([{'threadId': thread, 'status': 'completed', 'title': t} for t in titles] + [{'threadId': 'other', 'status': 'completed', 'title': 'Another'}]))
    sys.exit(0)
assert rest[3:8] == ['thread', 'show', '--messages', '200', '--json'] and rest[8] == thread, rest
print(json.dumps({'thread': {'threadId': thread, 'status': 'running'}, 'messages': [{'role': 'user', 'runId': 'run:1', 'text': 'Subtask: Doctor note'}]}))
`;

async function phase(start: string, change: Record<string, unknown> = {}): Promise<{ ok: boolean; output: string; launched: boolean; start: Record<string, unknown> | null; read: Record<string, unknown> | null; calls: { args: string[]; stdin: string }[] }> {
    const folder = Deno.makeTempDirSync();
    try {
        Deno.mkdirSync(`${folder}/bin`);
        Deno.mkdirSync(`${folder}/run`);
        Deno.writeTextFileSync(`${folder}/bin/incus`, SHIM);
        Deno.chmodSync(`${folder}/bin/incus`, 0o755);
        Deno.writeTextFileSync(`${folder}/run/intent.json`, JSON.stringify({ runtime: intent.runtime, ssh_execution: ssh, turn: { ...turn, ...change } }));
        const done = await new Deno.Command("ansible-playbook", {
            args: ["-i", "localhost,", "-e", "ansible_connection=local", "-e", "ansible_become=false", "-e", "ansible_python_interpreter={{ ansible_playbook_python }}",
                `${shipped}/task-copy-turn.yml`],
            env: { ORBIT_RUN: `${folder}/run`, PATH: `${folder}/bin:${Deno.env.get("PATH")}`, T3_CALLS: `${folder}/calls`, T3_START: start,
                ANSIBLE_HOME: `${folder}/ansible`, ANSIBLE_NOCOLOR: "1", ANSIBLE_CONFIG: `${shipped}/ansible.cfg` },
        }).output();
        const read = (path: string) => { try { return Deno.readTextFileSync(path); } catch { return null; } };
        const json = (name: string) => { const text = read(`${folder}/run/${name}`); return text === null ? null : JSON.parse(text); };
        return {
            ok: done.success,
            output: new TextDecoder().decode(done.stdout) + new TextDecoder().decode(done.stderr),
            launched: read(`${folder}/run/launch.json`) !== null,
            start: json("start.json"),
            read: json("read.json"),
            calls: (read(`${folder}/calls`) ?? "").split("\n").filter(Boolean).map((line) => JSON.parse(line)),
        };
    } finally {
        Deno.removeSync(folder, { recursive: true });
    }
}

Deno.test({ name: "a completed turn sends the prompt on stdin with the copy's model, finds its thread by title and reads it back", ignore: !available, async fn() {
    const done = await phase("completed");
    assert(done.ok, done.output);
    assertEquals(done.calls.length, 4);
    const start = done.calls[1];
    assertEquals(start.stdin, turn.prompt);
    assertEquals(start.args.slice(21, 29), ["--provider", "claudeAgent", "--model", "claude-opus-5-5", "--title", turn.title, "--wait", "--json"]);
    assertEquals([done.launched, done.start!.start_exit, JSON.parse(done.start!.start as string).status], [true, 0, "completed"]);
    assertEquals([done.read!.list_exit, done.read!.found, done.read!.status, done.read!.show_exit], [0, 1, "completed", 0]);
    // The phase never prints the agent's text: every T3 reply stays in the run folder.
    assertEquals(done.output.includes("Subtask: Doctor note"), false);
} });

Deno.test({ name: "a turn cut by its bound still reads the thread it started; a refused start reads none; an unreachable copy launches nothing", ignore: !available, async fn() {
    const cut = await phase("timeout");
    assert(cut.ok, cut.output);
    assertEquals([cut.launched, cut.start!.start_exit, cut.start!.start, cut.read!.found, cut.read!.show_exit], [true, 124, "", 1, 0]);
    const refused = await phase("refused");
    assert(refused.ok, refused.output);
    assertEquals([refused.start!.start_exit, refused.read!.list_exit, refused.read!.found, refused.read!.status, refused.read!.show_exit, refused.calls.length], [1, 0, 0, null, null, 3]);
    // A copy the phase cannot reach launches nothing, so no turn can hold its group.
    const unreachable = await phase("unreachable");
    assert(!unreachable.ok, unreachable.output);
    assertEquals([unreachable.launched, unreachable.start, unreachable.read, unreachable.calls.length], [false, null, null, 1]);
} });

Deno.test({ name: "an intent outside the admitted shape runs nothing in the copy", ignore: !available, async fn() {
    for (const change of [{ t3: "/tmp/t3" }, { title: "Something else" }, { seconds: 7200 }, { model: "a b" }]) {
        const refused = await phase("completed", change);
        assert(!refused.ok, JSON.stringify(change));
        assertEquals([refused.launched, refused.start, refused.read, refused.calls.length], [false, null, null, 0]);
    }
} });

Deno.test({ name: "a thread list that is not JSON reads as no thread, and the start's end is still written", ignore: !available, async fn() {
    const garbled = await phase("garbled");
    assert(garbled.ok, garbled.output);
    assertEquals([JSON.parse(garbled.start!.start as string).status, garbled.read!.list_exit, garbled.read!.found, garbled.read!.show_exit, garbled.calls.length],
        ["completed", null, 0, null, 3]);
} });
