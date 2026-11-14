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
const turn = { t3: "/opt/orbit-agent/current/agent/bin/t3", node: "/opt/orbit-runtime/current/bin/node", thread: "new", provider: "claudeMedium", model: "claude-opus-5-5", candidate: null, settle: [] as { operation_id: number; title: string; branch: string | null }[], seconds: 3600, title: "Orbit task 4970 thread 77", prompt: "Subtask: Doctor note\n\nRun Doctor." };
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
    // The turn and its kill grace, six T3 or helper calls of up to 60 s (the settle's list, the list of a continued turn, the question's
    // read and stop, the list and show after it) and sixteen Git commands of up to 20 s (the settle's restore, a reviewer's set-up and restore).
    assert(Number(/^timeout (\d+) /.exec(run.task.inputs.run!)![1]) > turn.seconds + 30 + 6 * 60 + 16 * 20);
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

// Validates the Incus boundary, then answers as T3's CLI, the question helper and Git would. `T3_START` picks the start's or the send's
// behaviour; `waiting` asks a question, `missing` lists no thread of the turn's title and `listfail` fails the list. `T3_ASK` is the
// helper's read: `questions`, `none` (no pending request), `approval` or `fail`. `T3_TREE` is the checkout: `dirty` has changes before the turn,
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
    earlier = [json.loads(line)['args'][20:] for line in open(os.environ['T3_CALLS'])][:-1]
    restored = any('reset' in args for args in earlier)
    # The copy's detach mark: T3_MARK sets one before the phase; the phase's own update-ref calls set and clear it.
    mark = os.environ.get('T3_MARK', '')
    for args in earlier:
        if args[:1] == ['update-ref']:
            mark = '' if args[1] == '-d' else args[2]
    if git == ['status', '--porcelain']:
        print(' M app.php' if (tree == 'dirty' and not restored) or (tree == 'stuck' and restored) else '', end='')
    elif git == ['symbolic-ref', '--quiet', '--short', 'HEAD']:
        # T3_DETACHED leaves the checkout detached until the phase checks the task branch out.
        if os.environ.get('T3_DETACHED') and ['checkout', '--quiet', 'task-4970'] not in earlier:
            sys.exit(1)
        print('task-4970')
    elif git == ['rev-parse', '--verify', '--quiet', 'refs/orbit/review-detach']:
        if mark == '':
            sys.exit(1)
        print(mark)
    elif git in (['update-ref', 'refs/orbit/review-detach', 'a' * 40], ['update-ref', '-d', 'refs/orbit/review-detach']):
        pass
    elif git == ['rev-parse', '--verify', 'HEAD']:
        print('e' * 40 if tree == 'moved' and restored else 'a' * 40)
    elif git == ['reset', '--quiet', '--hard'] and tree == 'locked':
        sys.stderr.write('index.lock exists')
        sys.exit(128)
    else:
        assert git in (['checkout', '--quiet', '--detach', 'c' * 40], ['reset', '--quiet', '--hard'], ['clean', '--quiet', '-fd'], ['checkout', '--quiet', 'task-4970']), git
    sys.exit(0)
if rest[:4] == ['/usr/bin/timeout', '60', '/opt/orbit-runtime/current/bin/node', '-']:
    assert stdin == open(os.environ['T3_HELPER']).read(), 'the shipped helper arrives on stdin'
    assert rest[4] == '/opt/orbit-agent/current/agent/bin/t3', rest
    if rest[5:] == ['read', thread]:
        ask = os.environ.get('T3_ASK', 'questions')
        if ask == 'fail':
            sys.exit(1)
        questions = [{'id': 'Which color?', 'header': 'Color', 'question': 'Which color?', 'options': [{'label': 'Red', 'description': 'Choose red'}]}]
        print(json.dumps({'runs': [{'id': 'run:1', 'status': 'running'}], 'stoppable': ask == 'questions',
                          'requests': [] if ask == 'none' else [{'request_id': 'request:1', 'kind': 'user_input' if ask == 'questions' else 'approval',
                                                                  'questions': questions if ask == 'questions' else []}]}), end='')
        sys.exit(0)
    assert rest[5:] == ['interrupt', thread, 'run:1', 'request:1'], rest
    # The question is stored before the run is stopped.
    assert os.path.exists(os.environ['ORBIT_RUN'] + '/ask.json'), 'ask.json before the stop'
    print(json.dumps({'interrupted': True, 'error': None, 'runs': [], 'pending': 0}), end='')
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
        sys.stderr.write('server not running')
        sys.exit(1)
    print(json.dumps({'threadId': thread, 'runId': 'run:1', 'status': mode, 'reply': 'done', 'error': None}))
    sys.exit({'completed': 0, 'waiting': 2}.get(mode, 1))
assert rest[0] == '/usr/bin/timeout' and rest[1] == '60' and rest[2] == '/opt/orbit-agent/current/agent/bin/t3', rest
if rest[3:5] == ['thread', 'list']:
    if os.environ['T3_START'] == 'listfail':
        sys.stderr.write('server not running')
        sys.exit(1)
    titles = [] if os.environ['T3_START'] in ('refused', 'missing') else ['Orbit task 4970 thread 77']
    if os.environ['T3_START'] == 'garbled':
        print('not json')
        sys.exit(0)
    earlier = [{'threadId': 'earlier', 'status': os.environ['T3_SETTLE'], 'title': 'Orbit task 4970 thread 76'}] if os.environ.get('T3_SETTLE') else []
    print(json.dumps([{'threadId': thread, 'status': 'completed', 'title': t} for t in titles] + [{'threadId': 'other', 'status': 'completed', 'title': 'Another'}] + earlier))
    sys.exit(0)
assert rest[3:8] == ['thread', 'show', '--messages', '200', '--json'] and rest[8] == thread, rest
print(json.dumps({'thread': {'threadId': thread, 'status': 'running'}, 'messages': [{'role': 'user', 'runId': 'run:1', 'text': 'Subtask: Doctor note'}]}))
`;

type Phase = { ok: boolean; output: string; resumed: Record<string, unknown> | null; review: Record<string, unknown> | null; detached: boolean; launched: boolean;
    start: Record<string, unknown> | null; settle: Record<string, unknown> | null; ask: Record<string, unknown> | null; interrupt: Record<string, unknown> | null; read: Record<string, unknown> | null;
    restore: Record<string, unknown> | null; calls: { args: string[]; stdin: string }[] };
async function phase(start: string, change: Record<string, unknown> = {}, tree = "clean", ask = "questions", settle = "", copy: Record<string, string> = {}): Promise<Phase> {
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
            env: { ORBIT_RUN: `${folder}/run`, PATH: `${folder}/bin:${Deno.env.get("PATH")}`, T3_CALLS: `${folder}/calls`, T3_START: start, T3_TREE: tree, T3_ASK: ask, T3_SETTLE: settle, ...copy,
                T3_HELPER: `${shipped}/t3_copy_rpc.mjs`,
                ANSIBLE_HOME: `${folder}/ansible`, ANSIBLE_NOCOLOR: "1", ANSIBLE_CONFIG: `${shipped}/ansible.cfg` },
        }).output();
        const read = (path: string) => { try { return Deno.readTextFileSync(path); } catch { return null; } };
        const json = (name: string) => { const text = read(`${folder}/run/${name}`); return text === null ? null : JSON.parse(text); };
        return {
            ok: done.success,
            output: new TextDecoder().decode(done.stdout) + new TextDecoder().decode(done.stderr),
            resumed: json("continue.json"),
            review: json("review.json"),
            detached: read(`${folder}/run/detach.json`) !== null,
            launched: read(`${folder}/run/launch.json`) !== null,
            start: json("start.json"),
            ask: json("ask.json"),
            settle: json("settle.json"),
            interrupt: json("interrupt.json"),
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
    assertEquals(done.calls.length, 4);
    const start = done.calls[1];
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
    // The copy holds a detach mark at the branch's tip from before the detach until a verified restore clears it.
    assertEquals(git(done.calls), ["status --porcelain", "symbolic-ref --quiet --short HEAD", "rev-parse --verify HEAD", `update-ref refs/orbit/review-detach ${"a".repeat(40)}`,
        `checkout --quiet --detach ${candidate}`, "reset --quiet --hard", "clean --quiet -fd", "checkout --quiet task-4970", "status --porcelain",
        "symbolic-ref --quiet --short HEAD", "rev-parse --verify HEAD", "update-ref -d refs/orbit/review-detach"]);
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
    assertEquals([refused.start!.start_exit, refused.read!.list_exit, refused.read!.found, refused.read!.status, refused.read!.show_exit, refused.calls.length], [1, 0, 0, null, null, 3]);
    // A copy the phase cannot reach launches nothing, so no turn can hold its group.
    const unreachable = await phase("unreachable");
    assert(!unreachable.ok, unreachable.output);
    assertEquals([unreachable.launched, unreachable.start, unreachable.read, unreachable.calls.length], [false, null, null, 1]);
} });

Deno.test({ name: "an intent outside the admitted shape runs nothing in the copy", ignore: !available, async fn() {
    for (const change of [{ t3: "/tmp/t3" }, { node: "/usr/bin/node" }, { title: "Something else" }, { seconds: 7200 }, { model: "a b" }, { thread: "resume" }, { provider: null }, { model: null },
        { candidate: "HEAD" }, { settle: [{ operation_id: "9", title: "Orbit task 4970 thread 76", branch: null }] },
        { settle: [{ operation_id: 9, title: "Orbit task 4970 thread 76", branch: "main; rm" }] }, { settle: null }]) {
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

const helper = (calls: Phase["calls"]) => calls.map((call) => call.args.slice(15)).filter((args) => args[2] === turn.node).map((args) => args.slice(5).join(" "));

Deno.test({ name: "a run that asks has its question stored before the run is stopped, and the thread is read back after", ignore: !available, async fn() {
    const asked = await phase("waiting");
    assert(asked.ok, asked.output);
    // The stop names the stored request, so the helper stops the run only while it waits for exactly that one.
    assertEquals(helper(asked.calls), ["read mcp:66105c2c", "interrupt mcp:66105c2c run:1 request:1"]);
    assertEquals([asked.start!.start_exit, asked.ask!.ask_exit, asked.interrupt!.interrupt_exit], [2, 0, 0]);
    assertEquals(JSON.parse(asked.ask!.ask as string).requests[0].questions[0].question, "Which color?");
    assertEquals(JSON.parse(asked.interrupt!.interrupt as string).interrupted, true);
    const order = asked.calls.map((call) => call.args.slice(15).join(" "));
    assert(order.findIndex((args) => args.includes(" interrupt ")) < order.findIndex((args) => args.includes("thread list")), "the read follows the stop");
    // The question stays in the run folder, never in the phase's output.
    assertEquals(asked.output.includes("Which color?"), false);
    // A turn that ends other than at a question runs no helper.
    assertEquals(helper((await phase("completed")).calls), []);
} });

Deno.test({ name: "a question the helper cannot read stops nothing; a reviewer that asks is stopped before its checkout is restored", ignore: !available, async fn() {
    // A read that fails, finds no request, or finds an approval stops nothing.
    for (const ask of ["fail", "none", "approval"]) {
        const unread = await phase("waiting", {}, "clean", ask);
        assert(unread.ok, unread.output);
        assertEquals([helper(unread.calls).length, unread.ask!.ask_exit, unread.interrupt, unread.read!.found], [1, ask === "fail" ? 1 : 0, null, 1], ask);
    }
    const reviewer = await phase("waiting", { candidate });
    assert(reviewer.ok, reviewer.output);
    const order = reviewer.calls.map((call) => call.args.slice(15).join(" "));
    assert(order.findIndex((args) => args.includes(" interrupt ")) < order.findIndex((args) => args.includes("reset --quiet --hard")));
    assertEquals([reviewer.interrupt!.interrupt_exit, reviewer.restore], [0, { restored: true }]);
} });

const earlier = { operation_id: 9, title: "Orbit task 4970 thread 76", branch: null };

Deno.test({ name: "an earlier turn whose run T3 shows over is settled before the launch; one still running launches nothing", ignore: !available, async fn() {
    // A cut wait whose run T3 later completed, and a run whose stop failed and that later ended, are settled; the turn runs.
    for (const status of ["completed", "interrupted"]) {
        const settled = await phase("completed", { settle: [earlier] }, "clean", "questions", status);
        assert(settled.ok, settled.output);
        assertEquals([settled.settle, settled.launched], [{ list_exit: 0, settled: [9], open: [] }, true], status);
    }
    // T3 holds no thread of the title: no run can go on.
    const gone = await phase("completed", { settle: [earlier] });
    assertEquals([gone.ok, gone.settle!.settled], [true, [9]]);
    // A run that still goes on, or still waits for an answer, holds: nothing is launched and the work stays.
    for (const status of ["running", "waiting"]) {
        const held = await phase("completed", { settle: [earlier] }, "clean", "questions", status);
        assert(!held.ok && held.output.includes("tasks.copy_turn_unended"), held.output);
        assertEquals([held.settle, held.launched, held.start], [{ list_exit: 0, settled: [], open: [9] }, false, null], status);
    }
    // A list that fails settles nothing.
    const unread = await phase("listfail", { settle: [earlier] });
    assertEquals([unread.ok, unread.settle, unread.launched], [false, { list_exit: 1, settled: [], open: [9] }, false]);
} });

Deno.test({ name: "a reviewer's detach the copy still holds is undone once every run is over; without it the tree is never touched", ignore: !available, async fn() {
    const reviewer = { ...earlier, branch: "task-4970" };
    const mark = { T3_MARK: "a".repeat(40), T3_DETACHED: "1" };
    // A reviewer cut by its bound whose run went on writing files: the restore cleans the checkout, checks it, and clears the mark.
    const written = await phase("completed", { settle: [reviewer] }, "dirty", "questions", "completed", mark);
    assert(written.ok, written.output);
    assertEquals([written.settle!.settled, git(written.calls).slice(0, 9)], [[9], ["rev-parse --verify --quiet refs/orbit/review-detach",
        "symbolic-ref --quiet --short HEAD", "reset --quiet --hard", "clean --quiet -fd", "checkout --quiet task-4970", "status --porcelain",
        "symbolic-ref --quiet --short HEAD", "rev-parse --verify HEAD", "update-ref -d refs/orbit/review-detach"]]);
    // Changes the restore cannot remove, or the branch at another commit than the mark's, keep the turn open and the mark set.
    for (const tree of ["stuck", "moved"]) {
        const held = await phase("completed", { settle: [reviewer] }, tree, "questions", "completed", mark);
        assertEquals([held.ok, held.settle!.open, held.launched, git(held.calls).includes("update-ref -d refs/orbit/review-detach")], [false, [9], false, false], tree);
    }
    // While the run goes on, the checkout is not touched.
    const running = await phase("completed", { settle: [reviewer] }, "dirty", "questions", "running", mark);
    assertEquals([running.ok, git(running.calls), running.settle!.open], [false, [], [9]]);
    // (A) A reviewer turn that never detached, cancelled before its result, beside an implementer's uncommitted work: no mark, so the
    // settle touches nothing, the work stays, and the turn is settled once its run is over.
    const kept = await phase("completed", { settle: [earlier, reviewer] }, "dirty", "questions", "completed");
    assert(kept.ok, kept.output);
    assertEquals([kept.settle!.settled, git(kept.calls).slice(0, 2)], [[9, 9], ["rev-parse --verify --quiet refs/orbit/review-detach", "symbolic-ref --quiet --short HEAD"]]);
    assertEquals(git(kept.calls).some((call) => call.startsWith("reset") || call.startsWith("clean") || call.startsWith("checkout")), false);
    // (B) A later turn already undid the detach and moved the branch on: no mark, no old tip to meet, and the turn is settled.
    const moved = await phase("completed", { settle: [reviewer] }, "moved", "questions", "completed");
    assertEquals([moved.ok, moved.settle!.settled], [true, [9]]);
    // A mark left by a detach cut before the checkout: the checkout is on its branch, so it is only checked, and the mark cleared.
    const undetached = await phase("completed", { settle: [reviewer] }, "clean", "questions", "completed", { T3_MARK: "a".repeat(40) });
    assertEquals([undetached.settle!.settled, git(undetached.calls).slice(0, 6)], [[9], ["rev-parse --verify --quiet refs/orbit/review-detach",
        "symbolic-ref --quiet --short HEAD", "status --porcelain", "symbolic-ref --quiet --short HEAD", "rev-parse --verify HEAD", "update-ref -d refs/orbit/review-detach"]]);
} });
