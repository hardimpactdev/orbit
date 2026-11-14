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
const turn = { t3: "/opt/orbit-agent/current/agent/bin/t3", thread: "new", provider: "claudeMedium", model: "claude-opus-5-5", candidate: null, seconds: 3600, title: "Orbit task 4970 thread 77", prompt: "Subtask: Doctor note\n\nRun Doctor." };
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
    assertEquals(String(evaluate(run.task.behaviorName)), "task-copy-turn-9-2685");
    assert(Deno.statSync(`${shipped}/task-copy-turn.yml`).isFile);
    assertEquals([result.task.behaviorType, evaluate(result.task.inputs.folder), result.dependsOn![0].condition.type], ["@nckrtl/task-copy-turn", `/tmp/orbit-ansible-${runId}`, "always"]);
    assertEquals(cel.evaluate(outcome.task.expr!, context), true);
    const manifest = Deno.readTextFileSync(new URL("../manifest.yaml", import.meta.url));
    assert(manifest.includes("  - ansible/task-copy-turn.yml\n"), "the playbook ships to the worker");
});

// Swamp locks a behavior by name for the whole step; a second run waits 60 s and then fails. The turn holds its lock for the whole
// turn, so the name decides what can run at once: two turns in one copy share a lock, turns in two copies on one Node do not.
Deno.test("the turn's lock is its copy's: two copies on one Node turn side by side, one copy still takes one turn at a time", () => {
    const run = steps.find((step) => step.name === "Run the turn")!;
    const lock = (instance: number, node = 9) => String(cel.evaluate(run.task.behaviorName!.slice(3, -2).trim(),
        { inputs: { node_id: node, intent: { ...intent, instance_id: instance, runtime: { ...intent.runtime, name: `instance-${instance}` } }, execution_secrets: {} } }));
    assertEquals(lock(2685), lock(2685));
    assert(lock(2685) !== lock(2686), "two copies on one Node share no lock");
    assert(lock(2685, 9) !== lock(2685, 17), "the Node stays in the name");
    assert(lock(999999999).length <= 64, "Swamp names stay at 64 characters or fewer");
});

const available = new Deno.Command("sh", { args: ["-c", "command -v ansible-playbook"] }).outputSync().success;

// Validates the Incus boundary, then answers as T3's CLI and Git would. `T3_START` picks the start's or the send's behaviour; `missing`
// lists no thread of the turn's title and `listfail` fails the list. `T3_TREE` is the checkout: `dirty` has changes before the turn,
// `stuck` keeps changes after the restore, `moved` has the branch at another commit after it, and `locked` fails the reset.
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
if rest[:5] == ['/usr/bin/timeout', '20', 'git', '-C', '/srv/workspace']:
    git = rest[5:]
    tree = os.environ.get('T3_TREE', 'clean')
    restored = any('reset' in json.loads(line)['args'] for line in open(os.environ['T3_CALLS']))
    if git == ['status', '--porcelain']:
        print(' M app.php' if (tree == 'dirty' and not restored) or (tree == 'stuck' and restored) else '', end='')
    elif git == ['symbolic-ref', '--quiet', '--short', 'HEAD']:
        print('task-4970')
    elif git == ['rev-parse', '--verify', 'HEAD']:
        print('e' * 40 if tree == 'moved' and restored else 'a' * 40)
    elif git == ['reset', '--quiet', '--hard'] and tree == 'locked':
        sys.stderr.write('index.lock exists')
        sys.exit(128)
    else:
        assert git in (['checkout', '--quiet', '--detach', 'c' * 40], ['reset', '--quiet', '--hard'], ['clean', '--quiet', '-fd'], ['checkout', '--quiet', 'task-4970']), git
    sys.exit(0)
if rest[:3] == ['/usr/bin/timeout', '20', '/usr/bin/cat']:
    catalog = os.environ.get('T3_CATALOG', 'cached')
    if rest[3] == '/home/agent/.t3/userdata/settings.json':
        print(json.dumps({'providerInstances': {'claudeMedium': {'driver': 'claudeAgent', 'displayName': 'Claude, medium effort'}}}))
        sys.exit(0)
    assert rest[3:] == ['/home/agent/.t3/userdata/model-manifest.json'], rest
    if catalog == 'missing':
        sys.stderr.write('No such file or directory')
        sys.exit(1)
    models = [{'slug': 'claude-opus-5-5', 'aliases': ['opus']}, {'slug': 'claude-sonnet-5-5', 'aliases': ['sonnet']}, {'slug': 'claude-haiku-5-5'}]
    print(json.dumps({'fetchedAtMs': 1, 'manifest': {'providers': {'claudeAgent': {'models': models}, 'codex': {'models': [{'slug': 'other-model'}]}}}}))
    sys.exit(0)
if rest == ['/usr/bin/true']:
    sys.exit(1 if os.environ['T3_START'] == 'unreachable' else 0)
if rest[0] == '/usr/bin/timeout' and rest[1] == '--kill-after=30':
    assert rest[2] == '3600' and rest[3] == '/opt/orbit-agent/current/agent/bin/t3', rest
    assert rest[4:] == ['thread', 'send', '--wait', '--json', thread, '-'] or (rest[4:6] == ['thread', 'start'] and rest[-4:] == ['--wait', '--json', '/srv/workspace', '-']), rest
    mode = os.environ['T3_START']
    if mode == 'garbled':
        mode = 'completed'
    if mode == 'timeout':
        sys.exit(124)
    if mode == 'refused':
        sys.stderr.write('Provider instance "claudeMedium" is disabled for project p.')
        sys.exit(1)
    print(json.dumps({'threadId': thread, 'runId': 'run:1', 'status': mode, 'reply': 'done', 'error': None}))
    sys.exit(0 if mode == 'completed' else 1)
assert rest[0] == '/usr/bin/timeout' and rest[1] == '60' and rest[2] == '/opt/orbit-agent/current/agent/bin/t3', rest
if rest[3:5] == ['thread', 'list']:
    if os.environ['T3_START'] == 'listfail':
        sys.stderr.write('server not running')
        sys.exit(1)
    titles = [] if os.environ['T3_START'] in ('refused', 'missing') else ['Orbit task 4970 thread 77']
    if os.environ['T3_START'] == 'garbled':
        print('not json')
        sys.exit(0)
    print(json.dumps([{'threadId': thread, 'status': 'completed', 'title': t} for t in titles] + [{'threadId': 'other', 'status': 'completed', 'title': 'Another'}]))
    sys.exit(0)
assert rest[3:8] == ['thread', 'show', '--messages', '200', '--json'] and rest[8] == thread, rest
print(json.dumps({'thread': {'threadId': thread, 'status': 'running'}, 'messages': [{'role': 'user', 'runId': 'run:1', 'text': 'Subtask: Doctor note'}]}))
`;

type Phase = { ok: boolean; output: string; resumed: Record<string, unknown> | null; review: Record<string, unknown> | null; model: Record<string, unknown> | null; detached: boolean; launched: boolean;
    start: Record<string, unknown> | null; read: Record<string, unknown> | null; restore: Record<string, unknown> | null; calls: { args: string[]; stdin: string }[] };
async function phase(start: string, change: Record<string, unknown> = {}, tree = "clean", catalog = "cached"): Promise<Phase> {
    const folder = Deno.makeTempDirSync();
    try {
        Deno.mkdirSync(`${folder}/bin`);
        Deno.mkdirSync(`${folder}/run`);
        Deno.writeTextFileSync(`${folder}/calls`, "");
        Deno.writeTextFileSync(`${folder}/bin/incus`, SHIM);
        Deno.chmodSync(`${folder}/bin/incus`, 0o755);
        Deno.writeTextFileSync(`${folder}/run/intent.json`, JSON.stringify({ runtime: intent.runtime, ssh_execution: ssh, turn: { ...turn, ...change } }));
        const done = await new Deno.Command("ansible-playbook", {
            args: ["-i", "localhost,", "-e", "ansible_connection=local", "-e", "ansible_become=false", "-e", "ansible_python_interpreter={{ ansible_playbook_python }}",
                `${shipped}/task-copy-turn.yml`],
            env: { ORBIT_RUN: `${folder}/run`, PATH: `${folder}/bin:${Deno.env.get("PATH")}`, T3_CALLS: `${folder}/calls`, T3_START: start, T3_TREE: tree, T3_CATALOG: catalog,
                ANSIBLE_HOME: `${folder}/ansible`, ANSIBLE_NOCOLOR: "1", ANSIBLE_CONFIG: `${shipped}/ansible.cfg` },
        }).output();
        const read = (path: string) => { try { return Deno.readTextFileSync(path); } catch { return null; } };
        const json = (name: string) => { const text = read(`${folder}/run/${name}`); return text === null ? null : JSON.parse(text); };
        return {
            ok: done.success,
            output: new TextDecoder().decode(done.stdout) + new TextDecoder().decode(done.stderr),
            resumed: json("continue.json"),
            review: json("review.json"),
            model: json("model.json"),
            detached: read(`${folder}/run/detach.json`) !== null,
            launched: read(`${folder}/run/launch.json`) !== null,
            start: json("start.json"),
            read: json("read.json"),
            restore: json("restore.json"),
            calls: (read(`${folder}/calls`) ?? "").split("\n").filter(Boolean).map((line) => JSON.parse(line)),
        };
    } finally {
        Deno.removeSync(folder, { recursive: true });
    }
}

Deno.test({ name: "a completed turn sends the prompt on stdin with the copy's model, finds its thread by title and reads it back", ignore: !available, async fn() {
    const done = await phase("completed");
    assert(done.ok, done.output);
    assertEquals(done.calls.length, 6);
    const start = done.calls[3];
    assertEquals(start.stdin, turn.prompt);
    assertEquals(start.args.slice(21, 29), ["--provider", "claudeMedium", "--model", "claude-opus-5-5", "--title", turn.title, "--wait", "--json"]);
    // An implementer's turn leaves the checkout to the agent: no Git command, no review or restore evidence.
    assertEquals([done.review, done.restore], [null, null]);
    assertEquals([done.launched, done.start!.start_exit, JSON.parse(done.start!.start as string).status], [true, 0, "completed"]);
    assertEquals([done.read!.list_exit, done.read!.found, done.read!.status, done.read!.show_exit], [0, 1, "completed", 0]);
    // The phase never prints the agent's text: every T3 reply stays in the run folder.
    assertEquals(done.output.includes("Subtask: Doctor note"), false);
} });

Deno.test({ name: "a continued turn finds its thread by title and sends the prompt to it, with no model and no title", ignore: !available, async fn() {
    const done = await phase("completed", { thread: "continue", provider: null, model: "claude-opus-5-5" });
    assert(done.ok, done.output);
    assertEquals(done.calls.length, 5);
    assertEquals(done.calls[1].args.slice(18), ["thread", "list", "--project", "/srv/workspace", "--json"]);
    assertEquals([done.calls[2].args.slice(19), done.calls[2].stdin], [["thread", "send", "--wait", "--json", "mcp:66105c2c", "-"], turn.prompt]);
    assertEquals([done.resumed, done.launched, JSON.parse(done.start!.start as string).status, done.read!.found], [{ list_exit: 0, found: 1 }, true, "completed", 1]);
} });

Deno.test({ name: "a continued turn whose thread T3 does not hold launches nothing and says so", ignore: !available, async fn() {
    const missing = await phase("missing", { thread: "continue", provider: null });
    assert(!missing.ok, missing.output);
    assert(missing.output.includes("tasks.copy_thread_missing"), missing.output);
    assertEquals([missing.resumed, missing.launched, missing.start, missing.read, missing.calls.length], [{ list_exit: 0, found: 0 }, false, null, null, 2]);
} });

Deno.test({ name: "a continued turn whose thread list fails launches nothing and names T3's failure, not a missing thread", ignore: !available, async fn() {
    const failed = await phase("listfail", { thread: "continue", provider: null });
    assert(!failed.ok, failed.output);
    assert(failed.output.includes("tasks.copy_turn_failed") && !failed.output.includes("tasks.copy_thread_missing"), failed.output);
    assertEquals([failed.resumed, failed.launched, failed.start, failed.calls.length], [{ list_exit: 1, found: 0 }, false, null, 2]);
} });

const candidate = "c".repeat(40);
const git = (calls: Phase["calls"]) => calls.map((call) => call.args.slice(15)).filter((args) => args[2] === "git").map((args) => args.slice(5).join(" "));

Deno.test({ name: "a reviewer works on the candidate, detached, and the task branch is checked out again after its turn", ignore: !available, async fn() {
    const done = await phase("completed", { candidate });
    assert(done.ok, done.output);
    assertEquals(git(done.calls), ["status --porcelain", "symbolic-ref --quiet --short HEAD", "rev-parse --verify HEAD", `checkout --quiet --detach ${candidate}`,
        "reset --quiet --hard", "clean --quiet -fd", "checkout --quiet task-4970", "status --porcelain", "symbolic-ref --quiet --short HEAD", "rev-parse --verify HEAD"]);
    // The candidate is checked out before the launch, and the branch only after the read.
    const order = done.calls.map((call) => call.args.slice(15).join(" "));
    assert(order.findIndex((args) => args.includes("--detach")) < order.findIndex((args) => args.includes("--kill-after=30")));
    assert(order.findIndex((args) => args.includes("thread show")) < order.findIndex((args) => args.includes("reset --quiet --hard")));
    assertEquals([done.review, done.detached, done.launched, done.restore], [{ ready: true, error_code: null }, true, true, { restored: true }]);
    // A run cut by its bound is restored too.
    const cut = await phase("timeout", { candidate });
    assert(cut.ok, cut.output);
    assertEquals([cut.start!.start_exit, cut.restore], [124, { restored: true }]);
} });

Deno.test({ name: "a reviewer turn on a checkout with changes launches nothing and touches nothing; a restore that leaves changes says so", ignore: !available, async fn() {
    const dirty = await phase("completed", { candidate }, "dirty");
    assert(!dirty.ok, dirty.output);
    assert(dirty.output.includes("tasks.copy_tree_dirty"), dirty.output);
    assertEquals([dirty.review, dirty.detached, dirty.launched, dirty.restore], [{ ready: false, error_code: "tasks.copy_tree_dirty" }, false, false, null]);
    assertEquals(git(dirty.calls), ["status --porcelain", "symbolic-ref --quiet --short HEAD"]);
    // The phase records the failed restore; the result maps it to tasks.copy_review_unrestored.
    const stuck = await phase("completed", { candidate }, "stuck");
    assert(stuck.ok, stuck.output);
    assertEquals([stuck.review, stuck.launched, stuck.restore], [{ ready: true, error_code: null }, true, { restored: false }]);
    // A branch at another commit after the turn, or a reset that fails (a run that ended holding the index lock), is unrestored too.
    for (const tree of ["moved", "locked"]) {
        const unrestored = await phase(tree === "locked" ? "failed" : "completed", { candidate }, tree);
        assert(unrestored.ok, unrestored.output);
        assertEquals([unrestored.detached, unrestored.restore], [true, { restored: false }], tree);
    }
} });

Deno.test({ name: "a turn cut by its bound still reads the thread it started; a refused start reads none; an unreachable copy launches nothing", ignore: !available, async fn() {
    const cut = await phase("timeout");
    assert(cut.ok, cut.output);
    assertEquals([cut.launched, cut.start!.start_exit, cut.start!.start, cut.read!.found, cut.read!.show_exit], [true, 124, "", 1, 0]);
    const refused = await phase("refused");
    assert(refused.ok, refused.output);
    assertEquals([refused.start!.start_exit, refused.read!.list_exit, refused.read!.found, refused.read!.status, refused.read!.show_exit, refused.calls.length], [1, 0, 0, null, null, 5]);
    // T3 refuses a start on stderr only; the evidence keeps it.
    assertEquals(refused.start!.error, 'Provider instance "claudeMedium" is disabled for project p.');
    // A copy the phase cannot reach launches nothing, so no turn can hold its group.
    const unreachable = await phase("unreachable");
    assert(!unreachable.ok, unreachable.output);
    assertEquals([unreachable.launched, unreachable.start, unreachable.read, unreachable.calls.length], [false, null, null, 1]);
} });

Deno.test({ name: "an intent outside the admitted shape runs nothing in the copy", ignore: !available, async fn() {
    for (const change of [{ t3: "/tmp/t3" }, { title: "Something else" }, { seconds: 7200 }, { model: "a b" }, { thread: "resume" }, { provider: null }, { model: null },
        { candidate: "HEAD" }]) {
        const refused = await phase("completed", change);
        assert(!refused.ok, JSON.stringify(change));
        assertEquals([refused.launched, refused.start, refused.read, refused.calls.length], [false, null, null, 0]);
    }
} });

Deno.test({ name: "a thread list that is not JSON reads as no thread, and the start's end is still written", ignore: !available, async fn() {
    const garbled = await phase("garbled");
    assert(garbled.ok, garbled.output);
    assertEquals([JSON.parse(garbled.start!.start as string).status, garbled.read!.list_exit, garbled.read!.found, garbled.read!.show_exit, garbled.calls.length],
        ["completed", null, 0, null, 5]);
} });

const reads = (calls: Phase["calls"]) => calls.map((call) => call.args.slice(15)).filter((args) => args[2] === "/usr/bin/cat").map((args) => args[3]);

Deno.test({ name: "a new thread reads T3's catalog first and runs a model it offers, by slug or alias", ignore: !available, async fn() {
    for (const model of ["claude-sonnet-5-5", "opus"]) {
        const done = await phase("completed", { model });
        assert(done.ok, done.output);
        assertEquals(reads(done.calls), ["/home/agent/.t3/userdata/settings.json", "/home/agent/.t3/userdata/model-manifest.json"]);
        assertEquals(done.model, { model, offered: ["claude-opus-5-5", "claude-sonnet-5-5", "claude-haiku-5-5"], accepted: true });
        assertEquals(done.launched, true);
    }
} });

Deno.test({ name: "a new thread whose model T3's catalog does not list launches nothing, before a reviewer's checkout", ignore: !available, async fn() {
    // The other driver's model is not one the instance's driver offers.
    for (const model of ["claude-opus-9", "other-model"]) {
        const refused = await phase("completed", { model, candidate });
        assert(!refused.ok, refused.output);
        assert(refused.output.includes("tasks.copy_turn_model_unoffered"), refused.output);
        assertEquals(refused.model, { model, offered: ["claude-opus-5-5", "claude-sonnet-5-5", "claude-haiku-5-5"], accepted: false });
        assertEquals([refused.review, refused.detached, refused.launched, refused.start, git(refused.calls)], [null, false, false, null, []]);
    }
} });

Deno.test({ name: "without T3's cached catalog the check is skipped and the turn runs", ignore: !available, async fn() {
    const done = await phase("completed", { model: "claude-opus-9" }, "clean", "missing");
    assert(done.ok, done.output);
    assertEquals([done.model, done.launched], [{ model: "claude-opus-9", offered: null, accepted: true }, true]);
} });

Deno.test({ name: "a continued thread keeps its model: the catalog is not read", ignore: !available, async fn() {
    const done = await phase("completed", { thread: "continue", provider: null, model: "claude-opus-9" });
    assert(done.ok, done.output);
    assertEquals([reads(done.calls), done.model], [[], null]);
} });
