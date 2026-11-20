/** A turn in a task copy: the shipped workflow's step expressions, and task-copy-turn.yml run by real Ansible against an Incus shim that
 * plays T3's CLI. No live container. */
import { assert, assertEquals } from "jsr:@std/assert@1";
import { parse } from "jsr:@std/yaml@1.0.12";
import { Environment } from "npm:@marcbachmann/cel-js@7.6.1";
import { behavior, type Turn } from "../extensions/behaviors/operations/task-copy-turn/mod.ts";

type Step = { name: string; dependsOn?: { step: string; condition: { type: string } }[]; task: { behaviorName?: string; behaviorType?: string; expr?: string; inputs: Record<string, unknown> & { run?: string; env?: Record<string, string> } } };
const workflow = parse(Deno.readTextFileSync(new URL("../workflows/workflow-operation-task-copy-turn.yaml", import.meta.url))) as { jobs: { name: string; steps: Step[] }[] };
const steps = workflow.jobs.flatMap((job) => job.steps);
const cel = new Environment({ enableOptionalTypes: true, unlistedVariablesAreDyn: true, homogeneousAggregateLiterals: false });
const shipped = new URL("../ansible/", import.meta.url).pathname.replace(/\/$/, "");
const runId = "0f0e0d0c-0b0a-4908-8706-050403020100";
const ssh = { identity_file: "/fixture", target: { host: "127.0.0.1", port: 22, user: "orbit", host_keys: ["ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl"] } };
const turn = { t3: "/opt/orbit-agent/current/agent/bin/t3", node: "/opt/orbit-runtime/current/bin/node", thread: "new", provider: "claudeMedium", model: "claude-opus-5-5", options: [] as { id: string; value: string }[], candidate: null, answered: [] as string[], settle: [] as { operation_id: number; title: string; branch: string | null; t3_thread_id?: string; admitted?: boolean; run_id?: string; sent?: boolean }[], seconds: 3600, title: "Orbit task 4970 thread 77", prompt: "Subtask: Doctor note\n\nRun Doctor." };
// What TaskTurn admits; the workflow passes only the runtime, the SSH target and the turn to the phase.
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
    // The turn and its kill grace, nine T3 or helper calls of up to 60 s (the settle's list and its stops of two earlier runs, the list and
    // answered-request resolution of a continued turn, the question's read and stop or a cut run's list and stop, the list and show after it) and sixteen Git commands of
    // up to 20 s (the settle's restore, a reviewer's set-up and restore), two catalog reads and the Codex model probe.
    assert(Number(/^timeout (\d+) /.exec(run.task.inputs.run!)![1]) > turn.seconds + 30 + 9 * 60 + 16 * 20 + 2 * 20 + 25);
    const env = Object.fromEntries(Object.entries(run.task.inputs.env!).map(([key, value]) => [key, String(evaluate(value))]));
    assertEquals([env.ANSIBLE_CONFIG, env.ORBIT_ANSIBLE, env.ORBIT_RUN], [`${shipped}/ansible.cfg`, shipped, `/tmp/orbit-ansible-${runId}`]);
    assertEquals(String(evaluate(run.task.behaviorName)), "task-copy-turn-9-2685");
    assert(Deno.statSync(`${shipped}/task-copy-turn.yml`).isFile);
    assertEquals([result.task.behaviorType, evaluate(result.task.inputs.folder), result.dependsOn![0].condition.type], ["@nckrtl/task-copy-turn", `/tmp/orbit-ansible-${runId}`, "always"]);
    assertEquals(cel.evaluate(outcome.task.expr!, context), true);
    const manifest = Deno.readTextFileSync(new URL("../manifest.yaml", import.meta.url));
    assert(manifest.includes("  - ansible/task-copy-turn.yml\n"), "the playbook ships to the worker");
    assert(manifest.includes("  - ansible/task_copy_codex_model.py\n"), "the model probe ships to the worker");
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
// helper's read: `questions`, `none` (no pending request), `approval`, `large` (not stoppable) or `fail`. `T3_STOP` is the helper's stop:
// `end` ends the run, `refuse` is a stop T3 refused, `idle` finds the run already over,
// `vanish` is an interrupt T3 accepted whose readback omits the still-active run. `T3_TITLE` renames the turn's thread in T3, and
// `T3_CUT_STATUS` is how T3 lists a cut run's thread. A run whose wait the bound cut (`timeout`), and an earlier
// turn's run, never end on their own: T3 lists their thread active until a stop ends it. `T3_TREE` is the checkout: `dirty` has changes before the turn,
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
# The threads whose run an earlier stop ended.
stopped = [] if os.environ.get('T3_STOP') in ('refuse', 'vanish') else [
    json.loads(line)['args'][21] for line in list(open(os.environ['T3_CALLS']))[:-1] if json.loads(line)['args'][15:16] == ['/usr/bin/timeout']
    and json.loads(line)['args'][17:21] == ['/opt/orbit-runtime/current/bin/node', '-', os.environ['T3_PROGRAM'], 'stop']]
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
# The handoff receipt the turn left: T3_RECEIPT_DELIVERY and T3_RECEIPT_REVIEW hold its text; the phase reads it once and removes it.
if rest[:4] == ['/usr/bin/timeout', '20', '/bin/sh', '-c']:
    assert rest[5:6] == ['sh'] and 'rm -rf -- "$f"' in rest[4], rest
    # Before the launch the phase clears both receipts; after the turn it reads and removes each.
    if rest[6:] == []:
        sys.exit(int(os.environ.get('T3_RECEIPT_CLEAR', '0')))
    assert rest[6] in ('delivery', 'review'), rest
    print(os.environ.get('T3_RECEIPT_' + rest[6].upper(), ''), end='')
    sys.exit(0)
if rest[:3] == ['/usr/bin/timeout', '20', '/usr/bin/cat']:
    catalog = os.environ.get('T3_CATALOG', 'cached')
    if rest[3] == '/home/agent/.t3/userdata/settings.json':
        if os.environ.get('T3_SETTINGS') == 'unreadable':
            sys.exit(1)
        print(json.dumps({'providerInstances': {'claudeMedium': {'driver': 'claudeAgent', 'displayName': 'Claude, medium effort'},
                                                'codexHigh': {'driver': os.environ.get('T3_CODEX_DRIVER', 'codex'),
                                                              'displayName': 'Codex, high effort'}}}))
        sys.exit(0)
    assert rest[3:] == ['/home/agent/.t3/userdata/model-manifest.json'], rest
    if catalog == 'missing':
        sys.stderr.write('No such file or directory')
        sys.exit(1)
    models = [{'slug': 'claude-opus-5-5', 'aliases': ['opus']}, {'slug': 'claude-sonnet-5-5', 'aliases': ['sonnet']}, {'slug': 'claude-haiku-5-5'}]
    if catalog == 'noslug':
        models = [{'name': 'No slug'}, 'claude-fable-5-2', {'slug': 7}] + models
    if catalog == 'notlist':
        models = {'claude-opus-5-5': {}}
    if catalog == 'noentry':
        models = [{'name': 'No slug'}, 'claude-fable-5-2']
    print(json.dumps({'fetchedAtMs': 1, 'manifest': {'providers': {'claudeAgent': {'models': models}, 'codex': {'models': [{'slug': 'other-model'}, {'slug': 'gpt-6.1-sol'}]}}}}))
    sys.exit(0)
if rest[:4] == ['/usr/bin/timeout', '25', '/usr/bin/python3', '-']:
    assert rest[4:] == [os.environ['T3_MODEL']], rest
    assert stdin == open(os.environ['T3_MODEL_HELPER']).read(), 'the model probe arrives on stdin'
    sys.exit(int(os.environ['T3_PROBE_EXIT']))
if rest[:4] == ['/usr/bin/timeout', '60', '/opt/orbit-runtime/current/bin/node', '-']:
    assert stdin == open(os.environ['T3_HELPER']).read(), 'the shipped helper arrives on stdin'
    assert rest[4] == os.environ['T3_PROGRAM'], rest
    if rest[5] == 'stop':
        assert rest[6] == 'earlier' and len(rest) == 8, rest
        run = rest[7]
        # The helper reads only the exact run it is named on the captured thread: the earlier thread's run is run:earlier, so another
        # ID, or a thread T3 does not hold, is refused. It answers over only when it reads that run in a status that ends it.
        listed = os.environ.get('T3_SETTLE', '')
        if run != 'run:earlier' or listed == '':
            print(json.dumps({'stopped': False, 'over': False, 'run': run, 'state': None, 'error': 'no such run'}), end='')
            sys.exit(0)
        if listed in ('completed', 'failed', 'cancelled', 'interrupted', 'rolled_back', 'idle'):
            print(json.dumps({'stopped': False, 'over': True, 'run': run, 'state': 'completed' if listed == 'idle' else listed}), end='')
            sys.exit(0)
        if listed not in ('running', 'waiting'):
            print(json.dumps({'stopped': False, 'over': False, 'run': run, 'state': listed}), end='')
            sys.exit(0)
        print(json.dumps({'end': {'stopped': True, 'over': True, 'run': run, 'state': 'interrupted'}, 'refuse': {'stopped': False, 'over': False, 'run': run, 'state': 'running'},
                          'idle': {'stopped': False, 'over': True, 'run': run, 'state': 'completed'},
                          'vanish': {'stopped': False, 'over': False, 'run': run, 'state': None}}[os.environ.get('T3_STOP', 'end')]), end='')
        sys.exit(0)
    if rest[5] == 'answered':
        assert rest[6:] == [thread, 'run:1', 'request:1', 'request:2'], rest
        assert not os.path.exists(os.environ['ORBIT_RUN'] + '/launch.json'), 'resolve before launch'
        assert not os.path.exists(os.environ['ORBIT_RUN'] + '/detach.json'), 'resolve before reviewer checkout'
        mode = os.environ.get('T3_ANSWERED', 'resolved')
        if mode == 'fail':
            sys.exit(1)
        if mode == 'garbled':
            print('not json')
            sys.exit(0)
        print(json.dumps({'resolved': mode == 'resolved', 'pending': [] if mode == 'resolved' else ['request:1'], 'error': None if mode == 'resolved' else 'Still pending.'}), end='')
        sys.exit(0)
    if rest[5] == 'create':
        # T3_CREATE: 'refuse' is a create T3 answers with its refusal, 'fail' a helper that printed nothing.
        assert rest[6:9] == ['Orbit task 4970 thread 77', os.environ.get('T3_INSTANCE', 'claudeMedium'), os.environ.get('T3_MODEL', 'claude-opus-5-5')], rest
        assert json.loads(rest[9]) == json.loads(os.environ.get('T3_OPTIONS', '[]')), rest
        create = os.environ.get('T3_CREATE', 'made')
        if create == 'fail':
            sys.stderr.write('T3 refused a WebSocket ticket (HTTP 401).')
            sys.exit(1)
        print(json.dumps({'thread_id': thread, 'error': None} if create == 'made' else {'thread_id': None, 'error': 'Unknown provider instance codexHigh.'}), end='')
        sys.exit(0)
    if rest[5:] == ['read', thread]:
        ask = os.environ.get('T3_ASK', 'questions')
        if ask == 'fail':
            sys.exit(1)
        questions = [{'id': 'Which color?', 'header': 'Color', 'question': 'Which color?', 'options': [{'label': 'Red', 'description': 'Choose red'}]}]
        # 'large' holds questions alone but more than the result can hold: the read says the run may not be stopped.
        asking = ask in ('questions', 'large')
        print(json.dumps({'runs': [{'id': 'run:1', 'status': 'running'}], 'stoppable': ask == 'questions',
                          'requests': [] if ask == 'none' else [{'request_id': 'request:1', 'kind': 'user_input' if asking else 'approval', 'run_id': 'run:1',
                                                                  'questions': questions if asking else []}]}), end='')
        sys.exit(0)
    assert rest[5:] == ['interrupt', thread, 'run:1', 'request:1'], rest
    # The question is stored before the run is stopped.
    assert os.path.exists(os.environ['ORBIT_RUN'] + '/ask.json'), 'ask.json before the stop'
    print(json.dumps({'interrupted': True, 'error': None, 'runs': [], 'pending': 1}), end='')
    sys.exit(0)
if rest == ['/usr/bin/true']:
    sys.exit(1 if os.environ['T3_START'] == 'unreachable' else 0)
if rest[0] == '/usr/bin/timeout' and rest[1] == '--kill-after=30':
    assert rest[2] == '3600' and rest[3] == os.environ['T3_PROGRAM'], rest
    assert rest[4:] == ['thread', 'send', '--wait', '--json', thread, '-'], rest
    mode = os.environ['T3_START']
    if os.environ.get('T3_ANSWERED') and not any(json.loads(line)['args'][20:21] == ['answered'] for line in open(os.environ['T3_CALLS'])):
        mode = 'waiting'  # A stale request cuts the CLI wait before the real work completes.
    if mode == 'garbled':
        mode = 'completed'
    if mode == 'timeout':
        sys.exit(124)
    if mode == 'refused':
        sys.stderr.write('Provider instance "claudeMedium" is disabled for project p.')
        sys.exit(1)
    if mode == 'refusedlong':
        sys.stderr.write('x' * 10000 + 'the end')
        sys.exit(1)
    if mode == 'chatty':
        sys.stderr.write('progress\\n' * 2000)
        mode = 'completed'
    print(json.dumps({'threadId': thread, 'runId': 'run:1', 'status': mode, 'reply': 'done', 'error': None}))
    sys.exit({'completed': 0, 'waiting': 2}.get(mode, 1))
assert rest[0] == '/usr/bin/timeout' and rest[1] == '60' and rest[2] == os.environ['T3_PROGRAM'], rest
if rest[3:5] == ['thread', 'list']:
    if os.environ['T3_START'] == 'listfail' or os.environ.get('T3_LISTFAIL'):
        sys.stderr.write('server not running')
        sys.exit(1)
    # T3_TITLE renames the turn's thread in T3; its ID stays.
    titles = [] if os.environ['T3_START'] in ('refused', 'missing') else [os.environ.get('T3_TITLE', 'Orbit task 4970 thread 77')]
    if os.environ['T3_START'] == 'garbled':
        print('not json')
        sys.exit(0)
    earlier = [{'threadId': 'earlier', 'status': 'interrupted' if 'earlier' in stopped else os.environ['T3_SETTLE'], 'title': 'Orbit task 4970 thread 76'}] if os.environ.get('T3_SETTLE') else []
    # T3_CUT_STATUS is how T3 lists a cut run's thread: running by default, since no stop ends it.
    status = ('interrupted' if thread in stopped else os.environ.get('T3_CUT_STATUS', 'running')) if os.environ['T3_START'] == 'timeout' else 'completed'
    print(json.dumps([{'threadId': thread, 'status': status, 'title': t} for t in titles] + [{'threadId': 'other', 'status': 'completed', 'title': 'Another'}] + earlier))
    sys.exit(0)
assert rest[3:8] == ['thread', 'show', '--messages', '200', '--json'] and rest[8] == thread, rest
print(json.dumps({'thread': {'threadId': thread, 'status': 'running'}, 'messages': [{'role': 'user', 'runId': 'run:1', 'text': 'Subtask: Doctor note'}]}))
`;

type Phase = { ok: boolean; output: string; resumed: Record<string, unknown> | null; review: Record<string, unknown> | null; model: Record<string, unknown> | null; detached: boolean; launched: boolean;
    start: Record<string, unknown> | null; settle: Record<string, unknown> | null; ask: Record<string, unknown> | null; interrupt: Record<string, unknown> | null;
    stop: Record<string, unknown> | null; read: Record<string, unknown> | null; restore: Record<string, unknown> | null; calls: { args: string[]; stdin: string }[];
    // The turn as the result step reads this run folder.
    result: Turn };
async function phase(start: string, change: Record<string, unknown> = {}, tree = "clean", ask = "questions", settle = "", copy: Record<string, string> = {}): Promise<Phase> {
    const folder = Deno.makeTempDirSync();
    // The run folder in the result step's form, so the shipped result step reads what the phase wrote.
    const run = `/tmp/orbit-ansible-${crypto.randomUUID()}`;
    try {
        Deno.mkdirSync(`${folder}/bin`);
        Deno.mkdirSync(run);
        Deno.writeTextFileSync(`${folder}/calls`, "");
        Deno.writeTextFileSync(`${folder}/bin/incus`, SHIM);
        Deno.chmodSync(`${folder}/bin/incus`, 0o755);
        Deno.writeTextFileSync(`${run}/intent.json`, JSON.stringify({ runtime: intent.runtime, ssh_execution: ssh, turn: { ...turn, ...change } }));
        const done = await new Deno.Command("ansible-playbook", {
            args: ["-i", "localhost,", "-e", "ansible_connection=local", "-e", "ansible_become=false", "-e", "ansible_python_interpreter={{ ansible_playbook_python }}",
                `${shipped}/task-copy-turn.yml`],
            env: { ORBIT_RUN: run, PATH: `${folder}/bin:${Deno.env.get("PATH")}`, T3_CALLS: `${folder}/calls`, T3_START: start, T3_TREE: tree, T3_ASK: ask, T3_SETTLE: settle, T3_PROGRAM: String(change.t3 ?? turn.t3), ...copy,
                T3_HELPER: `${shipped}/t3_copy_rpc.mjs`,
                T3_MODEL_HELPER: `${shipped}/task_copy_codex_model.py`,
                ANSIBLE_HOME: `${folder}/ansible`, ANSIBLE_NOCOLOR: "1", ANSIBLE_CONFIG: `${shipped}/ansible.cfg` },
        }).output();
        const read = (path: string) => { try { return Deno.readTextFileSync(path); } catch { return null; } };
        const json = (name: string) => { const text = read(`${run}/${name}`); return text === null ? null : JSON.parse(text); };
        return {
            ok: done.success,
            output: new TextDecoder().decode(done.stdout) + new TextDecoder().decode(done.stderr),
            resumed: json("continue.json"),
            review: json("review.json"),
            model: json("model.json"),
            detached: read(`${run}/detach.json`) !== null,
            launched: read(`${run}/launch.json`) !== null,
            start: json("start.json"),
            ask: json("ask.json"),
            settle: json("settle.json"),
            interrupt: json("interrupt.json"),
            stop: json("stop.json"),
            read: json("read.json"),
            restore: json("restore.json"),
            calls: (read(`${folder}/calls`) ?? "").split("\n").filter(Boolean).map((line) => JSON.parse(line)),
            result: (await behavior.methods.result.execute({ folder: run }, { writeResource: () => Promise.resolve() })).turn,
        };
    } finally {
        Deno.removeSync(folder, { recursive: true });
        Deno.removeSync(run, { recursive: true });
    }
}

Deno.test({ name: "a completed turn sends the prompt on stdin with the copy's model, finds its thread by title and reads it back", ignore: !available, async fn() {
    const done = await phase("completed");
    assert(done.ok, done.output);
    assertEquals(done.calls.length, 10);
    // The new thread is made first with its title, instance, model and model options; its first turn is a send to it.
    // The receipts of an earlier run go first (calls[3]).
    const create = done.calls[4];
    assertEquals(create.args.slice(20), ["create", turn.title, "claudeMedium", "claude-opus-5-5", "[]"]);
    const start = done.calls[5];
    assertEquals(start.stdin, turn.prompt);
    assertEquals(start.args.slice(19), ["thread", "send", "--wait", "--json", "mcp:66105c2c", "-"]);
    // An implementer's turn leaves the checkout to the agent: no Git command, no review or restore evidence.
    assertEquals([done.review, done.restore], [null, null]);
    assertEquals([done.launched, done.start!.start_exit, JSON.parse(done.start!.start as string).status], [true, 0, "completed"]);
    assertEquals([done.read!.list_exit, done.read!.found, done.read!.status, done.read!.show_exit], [0, 1, "completed", 0]);
    // The phase never prints the agent's text: every T3 reply stays in the run folder.
    assertEquals(done.output.includes("Subtask: Doctor note"), false);
} });

Deno.test({ name: "a turn reads and removes the handoff receipts it left, and returns their text", ignore: !available, async fn() {
    const receipt = { commit: "a".repeat(40), tree: "b".repeat(40), parts: [{ name: "pest", result: "passed" as const, seconds: 1.5 }] };
    const delivery = JSON.stringify(receipt);
    const done = await phase("completed", {}, "clean", "questions", "", { T3_RECEIPT_DELIVERY: delivery });
    assert(done.ok, done.output);
    const receipts = (call: { args: string[] }) => call.args.slice(15, 19).concat(call.args.slice(20));
    // Both receipts are cleared before the launch, so only what this turn's run writes counts.
    const send = done.calls.findIndex((call) => call.args.includes("send"));
    const clear = done.calls.findIndex((call) => receipts(call).join(" ") === "/usr/bin/timeout 20 /bin/sh -c sh");
    assert(clear >= 0 && clear < send, JSON.stringify(done.calls.map(receipts)));
    // One read-and-remove per receipt type, in the copy as agent, after the turn.
    assertEquals(done.calls.slice(-2).map(receipts),
        [["/usr/bin/timeout", "20", "/bin/sh", "-c", "sh", "delivery"], ["/usr/bin/timeout", "20", "/bin/sh", "-c", "sh", "review"]]);
    assertEquals(done.result.receipts, { delivery: receipt, review: null });
    // The phase never prints the receipt.
    assertEquals(done.output.includes(delivery), false);
    // A copy whose earlier receipts cannot go launches nothing.
    const stuck = await phase("completed", {}, "clean", "questions", "", { T3_RECEIPT_CLEAR: "1" });
    assert(!stuck.ok, stuck.output);
    assertEquals([stuck.launched, stuck.start], [false, null]);
} });
Deno.test({ name: "a continued turn finds its thread by title and sends the prompt to it, with no model and no title", ignore: !available, async fn() {
    // An intent admitted before answered was added omits it; JSON serialization drops undefined.
    const done = await phase("completed", { thread: "continue", provider: null, model: "claude-opus-5-5", answered: undefined });
    assert(done.ok, done.output);
    assertEquals(done.calls.length, 8);
    assertEquals(done.calls[1].args.slice(18), ["thread", "list", "--project", "/srv/workspace", "--json"]);
    assertEquals([done.calls[3].args.slice(19), done.calls[3].stdin], [["thread", "send", "--wait", "--json", "mcp:66105c2c", "-"], turn.prompt]);
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

// The container server the copy's runtime recorded (docs/reference/development-containers.md#container-server): every call runs its program.
Deno.test({ name: "a turn in a Conn Server copy runs the Conn Server's program for every call, and a program outside the agent folder is refused", ignore: !available, async fn() {
    const conn = "/opt/orbit-agent/current/agent/bin/conn-server";
    const done = await phase("completed", { t3: conn });
    assert(done.ok, done.output);
    const programs = done.calls.map((call) => call.args.slice(15)).filter((args) => args[0] === "/usr/bin/timeout" && args.includes("thread"))
        .map((args) => args.find((arg) => arg.startsWith("/opt/orbit-agent/")));
    assertEquals([programs.length > 0, programs.every((program) => program === conn)], [true, true]);

    const refused = await phase("completed", { t3: "/usr/local/bin/conn-server" });
    assert(!refused.ok && refused.output.includes("tasks.copy_turn_invalid"), refused.output);
    assertEquals([refused.calls.length, refused.launched], [0, false]);
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
    // A run cut by its bound is restored once T3 lists its thread, by its ID, in a state that runs nothing.
    const cut = await phase("timeout", { candidate }, "clean", "questions", "", { T3_CUT_STATUS: "interrupted" });
    assert(cut.ok, cut.output);
    assertEquals([cut.start!.start_exit, cut.restore, cut.result.ended], [124, { restored: true }, true]);
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
    assertEquals([refused.start!.start_exit, refused.read!.list_exit, refused.read!.found, refused.read!.status, refused.read!.show_exit, refused.calls.length], [1, 0, 0, null, null, 9]);
    // T3 refuses a start on stderr only; the evidence keeps it.
    assertEquals(refused.start!.error, 'Provider instance "claudeMedium" is disabled for project p.');
    // A copy the phase cannot reach launches nothing, so no turn can hold its group.
    const unreachable = await phase("unreachable");
    assert(!unreachable.ok, unreachable.output);
    assertEquals([unreachable.launched, unreachable.start, unreachable.read, unreachable.calls.length], [false, null, null, 1]);
} });

Deno.test({ name: "a turn cut by its bound stops no run, since Orbit cannot name its run, and holds", ignore: !available, async fn() {
    const cut = await phase("timeout");
    assert(cut.ok, cut.output);
    // The start printed nothing, so no run ID is known: no stop runs, and the thread T3 still lists running holds the turn.
    assertEquals([helper(cut.calls), cut.stop], [[], null]);
    assertEquals([cut.read!.status, cut.result.state, cut.result.error_code, cut.result.ended], ["running", "failed", "tasks.turn_timeout", false]);
    // Rule (c): a cut reviewer whose thread T3 still lists running may still write. Its checkout is not touched and its detach mark
    // stays, so no later write lands on a restored branch; the turn stays unrestored and held.
    const reviewer = await phase("timeout", { candidate });
    assertEquals([helper(reviewer.calls), reviewer.restore, reviewer.result.ended, reviewer.result.error_code], [[], { restored: false }, false, "tasks.copy_review_unrestored"]);
    assertEquals(git(reviewer.calls), ["status --porcelain", "symbolic-ref --quiet --short HEAD", "rev-parse --verify HEAD", `update-ref refs/orbit/review-detach ${"a".repeat(40)}`,
        `checkout --quiet --detach ${candidate}`]);
    // The next turn finds that reviewer's run-less turn by its captured thread: while T3 lists it running, the dirty checkout and the
    // mark stay untouched and nothing launches; once T3 lists it over, the checkout is restored, verified and the mark cleared first.
    const held = { operation_id: 9, title: "Orbit task 4970 thread 76", branch: "task-4970", t3_thread_id: "earlier" };
    const mark = { T3_MARK: "a".repeat(40), T3_DETACHED: "1" };
    const waiting = await phase("completed", { settle: [held] }, "dirty", "questions", "running", mark);
    assertEquals([waiting.ok, waiting.launched, waiting.settle!.open, git(waiting.calls), helper(waiting.calls)], [false, false, [9], [], []]);
    const freed = await phase("completed", { settle: [held] }, "dirty", "questions", "completed", mark);
    assertEquals([freed.ok, freed.settle!.settled, git(freed.calls).slice(2, 9)], [true, [9], ["reset --quiet --hard", "clean --quiet -fd", "checkout --quiet task-4970",
        "status --porcelain", "symbolic-ref --quiet --short HEAD", "rev-parse --verify HEAD", "update-ref -d refs/orbit/review-detach"]]);
} });

Deno.test({ name: "rule (a): the turn's thread is read back and continued by its ID whatever its title; a title is used only without an ID", ignore: !available, async fn() {
    // A running cut thread renamed before the bound: the readback finds it by its ID, still running, and the turn holds.
    const renamed = await phase("timeout", {}, "clean", "questions", "", { T3_TITLE: "Renamed by a person" });
    assertEquals([renamed.read!.thread_id, renamed.read!.found, renamed.read!.status, renamed.result.ended], ["mcp:66105c2c", 1, "running", false]);
    // A thread whose ID a turn reported is continued by that ID, also renamed.
    const known = { thread: "continue", provider: null, t3_thread_id: "mcp:66105c2c", admission: "overwritten" };
    const continued = await phase("completed", known, "clean", "questions", "", { T3_TITLE: "Renamed by a person" });
    assert(continued.ok, continued.output);
    assertEquals([continued.resumed, continued.calls[3].args.slice(19)], [{ list_exit: 0, found: 1 }, ["thread", "send", "--wait", "--json", "mcp:66105c2c", "-"]]);
    // The admitted UUID that T3 never received names no thread in T3: the title still finds the thread, and a renamed one is missing.
    const admitted = await phase("completed", { ...known, t3_thread_id: "0b1c", admission: "admitted" }, "clean", "questions", "", { T3_TITLE: "Renamed by a person" });
    assertEquals([admitted.ok, admitted.resumed, admitted.launched], [false, { list_exit: 0, found: 0 }, false]);
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
        ["completed", null, 0, null, 9]);
} });

Deno.test({ name: "a Codex thread is created with its effort as the reasoningEffort option; a create T3 refuses runs nothing and fails the start", ignore: !available, async fn() {
    const options = [{ id: "reasoningEffort", value: "high" }];
    const codex = { T3_INSTANCE: "codexHigh", T3_MODEL: "gpt-6.1-sol", T3_OPTIONS: JSON.stringify(options) };
    const done = await phase("completed", { provider: "codexHigh", model: "gpt-6.1-sol", options }, "clean", "questions", "", codex);
    assert(done.ok, done.output);
    assert(!done.calls.some((call) => call.args.includes("/usr/bin/python3")), "listed Codex models need no probe");
    const create = done.calls.find((call) => call.args[20] === "create")!;
    assertEquals([...create.args.slice(20, 24), JSON.parse(create.args[24])], ["create", turn.title, "codexHigh", "gpt-6.1-sol", options]);
    assertEquals([done.start!.start_exit, done.result.state], [0, "done"]);
    // A create that T3 refuses, or a helper that fails, makes no thread: no send runs, and the start carries T3's message.
    for (const [create, error] of [["refuse", "T3 could not create the thread: Unknown provider instance codexHigh."],
        ["fail", "T3 could not create the thread: T3 refused a WebSocket ticket (HTTP 401)."]]) {
        const refused = await phase("completed", { provider: "codexHigh", model: "gpt-6.1-sol", options }, "clean", "questions", "", { ...codex, T3_CREATE: create });
        assert(refused.ok, refused.output);
        assertEquals([refused.launched, refused.start, refused.calls.some((call) => call.args.includes("send"))],
            [true, { start_exit: 1, start: "", error }, false], create);
        assertEquals([refused.result.state, refused.result.error_code], ["failed", "tasks.copy_turn_failed"], create);
    }
} });

Deno.test({ name: "an uncatalogued Codex model needs provider confirmation before launch or reviewer checkout", ignore: !available, async fn() {
    for (const [model, probeExit, probe, errorCode] of [
        ["gpt-6.2-sol", "0", "confirmed", undefined],
        ["gpt-6.2-sol-typo", "2", "rejected", "tasks.copy_turn_model_unoffered"],
        ["gpt-6.2-sol", "124", "unavailable", "tasks.copy_turn_model_unverified"],
    ] as const) {
        const provider = "codexHigh";
        const accepted = probeExit === "0";
        const turnCandidate = accepted ? null : candidate;
        const done = await phase("completed", { provider, model, candidate: turnCandidate }, "clean", "questions", "",
            { T3_INSTANCE: provider, T3_MODEL: model, T3_PROBE_EXIT: probeExit });
        assertEquals(done.model, { model, offered: ["other-model", "gpt-6.1-sol"], accepted, probe }, done.output);
        assertEquals([done.ok, done.launched, done.result.error_code], [accepted, accepted, errorCode]);
        assertEquals(done.calls.filter((call) => call.args.includes("/usr/bin/python3")).length, 1);
        assertEquals(git(done.calls), []);
        if (probe === "rejected") assertEquals(done.result.error, "The copy's Codex provider rejected the thread's model.");
    }
    const model = "gpt-6.2-sol-typo";
    for (const settings of [{ T3_SETTINGS: "unreadable", T3_CODEX_DRIVER: "codex" }, { T3_SETTINGS: "readable", T3_CODEX_DRIVER: "   " }]) {
        const unresolved = await phase("completed", { provider: "codexHigh", model, candidate }, "clean", "questions", "", settings);
        assertEquals(unresolved.model, { model, offered: null, accepted: false, driver_unresolved: true }, unresolved.output);
        assertEquals([unresolved.launched, unresolved.result.error_code, git(unresolved.calls)], [false, "tasks.copy_turn_model_unverified", []]);
        assert(!unresolved.calls.some((call) => call.args.includes("/usr/bin/python3")), unresolved.output);
    }
} });

Deno.test("the Codex model probe requires a usable response for the exact requested model", async () => {
    const home = Deno.makeTempDirSync();
    const model = "gpt-6.2-sol";
    const requests: unknown[] = [];
    let response = { status: 200, body: {} as Record<string, unknown> };
    const server = Deno.serve({ hostname: "127.0.0.1", port: 0, onListen() {} }, async (request) => {
        requests.push([request.method, new URL(request.url).pathname, await request.json()]);
        return Response.json(response.body, { status: response.status });
    });
    try {
        const origin = `http://127.0.0.1:${server.addr.port}`;
        Deno.mkdirSync(`${home}/.codex`);
        Deno.mkdirSync(`${home}/.config/orbit`, { recursive: true });
        Deno.writeTextFileSync(`${home}/.codex/config.toml`,
            `model_provider = "orbit"\n[model_providers.orbit]\nbase_url = "${origin}/v1"\nenv_key = "ORBIT_MODEL_KEY"\nwire_api = "responses"\n`);
        Deno.writeTextFileSync(`${home}/.config/orbit/model.env`, `ANTHROPIC_BASE_URL=${origin}\nORBIT_MODEL_KEY=fixture\n`);
        for (const [status, body, exit] of [
            [200, { object: "response", status: "completed", model }, 0],
            [200, { object: "response", status: "incomplete", model, incomplete_details: { reason: "max_output_tokens" } }, 0],
            [200, { object: "response", status: "completed", model: "different-model" }, 1],
            [400, { error: { code: "model_not_found", message: "Provider detail" } }, 2],
            [503, { error: { message: "Provider unavailable" } }, 1],
        ] as const) {
            response = { status, body };
            const result = await new Deno.Command("python3", { args: [`${shipped}/task_copy_codex_model.py`, model], env: { HOME: home } }).output();
            assertEquals([result.code, result.stdout.length, result.stderr.length], [exit, 0, 0]);
        }
        assertEquals(requests, Array.from({ length: 5 }, () =>
            ["POST", "/v1/responses", { model, input: "Reply OK.", max_output_tokens: 16, store: false }]));
    } finally {
        await server.shutdown();
        Deno.removeSync(home, { recursive: true });
    }
});

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
    const done = await phase("completed", { model: "claude-opus-9" }, "clean", "questions", "", { T3_CATALOG: "missing" });
    assert(done.ok, done.output);
    assertEquals([done.model, done.launched], [{ model: "claude-opus-9", offered: null, accepted: true }, true]);
} });

Deno.test({ name: "a continued thread keeps its model: the catalog is not read", ignore: !available, async fn() {
    const done = await phase("completed", { thread: "continue", provider: null, model: "claude-opus-9" });
    assert(done.ok, done.output);
    assertEquals([reads(done.calls), done.model], [[], null]);
} });

Deno.test({ name: "a catalog entry without a slug is left out, and a model list that is no list or holds no valid entry skips the check", ignore: !available, async fn() {
    const offered = await phase("completed", { model: "claude-opus-5-5" }, "clean", "questions", "", { T3_CATALOG: "noslug" });
    assert(offered.ok, offered.output);
    assertEquals(offered.model, { model: "claude-opus-5-5", offered: ["claude-opus-5-5", "claude-sonnet-5-5", "claude-haiku-5-5"], accepted: true });
    const refused = await phase("completed", { model: "claude-fable-5-2" }, "clean", "questions", "", { T3_CATALOG: "noslug" });
    assert(!refused.ok && refused.output.includes("tasks.copy_turn_model_unoffered"), refused.output);
    // A list without one valid entry is no catalog either.
    for (const catalog of ["notlist", "noentry"]) {
        const skipped = await phase("completed", { model: "claude-opus-9" }, "clean", "questions", "", { T3_CATALOG: catalog });
        assert(skipped.ok, skipped.output);
        assertEquals([skipped.model, skipped.launched], [{ model: "claude-opus-9", offered: null, accepted: true }, true], catalog);
    }
} });

Deno.test({ name: "the start evidence keeps stderr only when the start failed, and only its last 4096 characters", ignore: !available, async fn() {
    const chatty = await phase("chatty");
    assert(chatty.ok, chatty.output);
    assertEquals([chatty.start!.start_exit, chatty.start!.error], [0, ""]);
    const refused = await phase("refusedlong");
    assert(refused.ok, refused.output);
    const error = refused.start!.error as string;
    assertEquals([refused.start!.start_exit, error.length, error.endsWith("the end")], [1, 4096, true]);
} });

// The helper's reads and stops; a new thread's create is checked on its own.
const helper = (calls: Phase["calls"]) => calls.map((call) => call.args.slice(15)).filter((args) => args[2] === turn.node && args[5] !== "create")
    .map((args) => args.slice(5).join(" "));

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
    // The turn as Orbit records it: the question stored with its owner run, its run stopped and over, and no error.
    assertEquals([asked.result.state, asked.result.error, asked.result.error_code, asked.result.ended], ["asking_for_input", null, undefined, true]);
    assertEquals(asked.result.questions.map((question) => [question.request_id, question.run_id, question.question]), [["request:1", "run:1", "Which color?"]]);
    // A turn that ends other than at a question runs no helper.
    assertEquals(helper((await phase("completed")).calls), []);
    // The confirmed interrupt of the run that asks ends the turn, also when the list after it fails.
    const unlisted = await phase("waiting", {}, "clean", "questions", "", { T3_LISTFAIL: "1" });
    assertEquals([unlisted.ok, unlisted.read!.list_exit, unlisted.result.state, unlisted.result.error, unlisted.result.ended, unlisted.result.questions.length],
        [true, 1, "asking_for_input", null, true, 1]);
} });

Deno.test({ name: "a question the helper cannot read stops nothing; a reviewer that asks is stopped before its checkout is restored", ignore: !available, async fn() {
    // A read that fails, finds no request, finds an approval, or finds questions too large to store whole stops nothing.
    for (const ask of ["fail", "none", "approval", "large"]) {
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

const earlier = { operation_id: 9, title: "Orbit task 4970 thread 76", branch: null, t3_thread_id: "earlier", run_id: "run:earlier" };

Deno.test({ name: "an earlier turn passed with its admitted thread ID is found by its title, never by that ID", ignore: !available, async fn() {
    // An admitted UUID passed for the RPC path is not one T3 was seen to receive: the CLI finds that turn by its title, never by the UUID.
    const admitted = { operation_id: 9, title: earlier.title, branch: null, t3_thread_id: "4b0e7d1c-5d2a-4f43-9a51-0c3f2b7e8a10" };
    const titled = await phase("completed", { settle: [{ ...admitted, admitted: true }] }, "clean", "questions", "completed");
    assertEquals([titled.ok, titled.settle!.settled], [true, [9]]);
    const held = await phase("completed", { settle: [{ ...admitted, admitted: true }] }, "clean", "questions", "running", { T3_STOP: "refuse" });
    assertEquals([held.ok, held.settle!.open, held.launched], [false, [9], false]);
    const observed = await phase("completed", { settle: [admitted] }, "clean", "questions", "completed");
    assertEquals([observed.ok, observed.settle!.open], [false, [9]]);
} });

Deno.test({ name: "an earlier turn whose run T3 shows over is settled before the launch, and stops nothing", ignore: !available, async fn() {
    // A cut wait whose run T3 later completed, and a run whose stop failed and that later ended, are settled; the turn runs.
    for (const status of ["completed", "interrupted"]) {
        const settled = await phase("completed", { settle: [earlier] }, "clean", "questions", status);
        assert(settled.ok, settled.output);
        // Rule (b): the thread's state is no proof of the recorded run; the helper reads that exact run over and stops nothing.
        assertEquals([settled.settle, settled.launched, helper(settled.calls)], [{ list_exit: 0, settled: [9], open: [], stopped: [] }, true, ["stop earlier run:earlier"]], status);
    }
    // T3 does not list the captured thread: that proves nothing, so the turn holds and nothing launches.
    const gone = await phase("completed", { settle: [earlier] });
    assertEquals([gone.ok, gone.settle!.open, gone.launched], [false, [9], false]);
    // A turn with no captured thread or run is found by title alone: T3 holds no thread of the title, so no run can go on.
    const untitled = await phase("completed", { settle: [{ operation_id: 9, title: earlier.title, branch: null }] });
    assertEquals([untitled.ok, untitled.settle!.settled], [true, [9]]);
    // A turn that sent nothing has no run: it is settled without a read of T3's run.
    const unsent = await phase("completed", { settle: [{ ...earlier, sent: false }] });
    assertEquals([unsent.ok, unsent.settle!.settled, helper(unsent.calls)], [true, [9], []]);
    // A list that fails settles nothing and stops nothing.
    const unread = await phase("listfail", { settle: [earlier] });
    assertEquals([unread.ok, unread.settle, unread.launched, helper(unread.calls)], [false, { list_exit: 1, settled: [], open: [9], stopped: [] }, false, []]);
    // A state T3 lists that Orbit does not know settles nothing, stops nothing and launches nothing.
    const paused = await phase("completed", { settle: [earlier] }, "clean", "questions", "paused");
    assertEquals([paused.ok, paused.settle, paused.launched, helper(paused.calls)], [false, { list_exit: 0, settled: [], open: [9], stopped: [] }, false, ["stop earlier run:earlier"]]);
    // A turn with no recorded thread falls back to its title.
    const legacy = await phase("completed", { settle: [{ operation_id: 9, title: earlier.title, branch: null }] }, "clean", "questions", "completed");
    assertEquals([legacy.ok, legacy.settle!.settled], [true, [9]]);
    // The captured thread listed under another title is still the turn's: running, its title missing settles nothing.
    const renamed = await phase("completed", { settle: [{ ...earlier, title: "Orbit task 4970 thread 99" }] }, "clean", "questions", "running", { T3_STOP: "refuse" });
    assertEquals([renamed.ok, renamed.settle, renamed.launched], [false, { list_exit: 0, settled: [], open: [9], stopped: [] }, false]);
    // Only the captured run is stopped: with another run active on the thread, the helper refuses, and nothing is settled or launched.
    const foreign = await phase("completed", { settle: [{ ...earlier, run_id: "orbit-run-A" }] }, "clean", "questions", "running");
    assertEquals([foreign.ok, foreign.settle, foreign.launched, helper(foreign.calls)],
        [false, { list_exit: 0, settled: [], open: [9], stopped: [] }, false, ["stop earlier orbit-run-A"]]);
    // A turn with no captured run is never stopped: it stays open while its thread runs.
    const unnamed = await phase("completed", { settle: [{ ...earlier, run_id: undefined }] }, "clean", "questions", "running");
    assertEquals([unnamed.ok, unnamed.settle!.open, unnamed.launched, helper(unnamed.calls)], [false, [9], false, []]);
} });

Deno.test({ name: "an earlier turn whose run T3 still runs is stopped, settled, and the turn runs; a stop T3 refuses launches nothing", ignore: !available, async fn() {
    // A cancelled or cut turn whose run never ends, or still waits for an answer: its turn is over in Orbit, so its run is stopped.
    for (const status of ["running", "waiting"]) {
        const stopped = await phase("completed", { settle: [earlier] }, "clean", "questions", status);
        assert(stopped.ok, stopped.output);
        assertEquals([stopped.settle, stopped.launched, helper(stopped.calls)],
            [{ list_exit: 0, settled: [9], open: [], stopped: [{ operation_id: 9, run: "run:earlier" }] }, true, ["stop earlier run:earlier"]], status);
        const order = stopped.calls.map((call) => call.args.slice(15).join(" "));
        assert(order.findIndex((args) => args.includes(" stop earlier")) < order.findIndex((args) => args.includes("--kill-after=30")), "the stop precedes the launch");
        assertEquals([stopped.result.state, stopped.result.settled], ["done", [9]]);
        // A stop that T3 refuses leaves the turn unsettled: nothing is touched or launched, and the work stays.
        const held = await phase("completed", { settle: [earlier] }, "clean", "questions", status, { T3_STOP: "refuse" });
        assert(!held.ok && held.output.includes("tasks.copy_turn_unended"), held.output);
        assertEquals([held.settle, held.launched, held.start], [{ list_exit: 0, settled: [], open: [9], stopped: [] }, false, null], status);
        assertEquals([held.result.error_code, held.result.settled], ["tasks.copy_turn_unended", []]);
    }
    // The run ended between the list and the stop: the helper reads it over, so the turn is settled with no run stopped.
    const idle = await phase("completed", { settle: [earlier] }, "clean", "questions", "running", { T3_STOP: "idle" });
    assertEquals([idle.ok, idle.settle], [true, { list_exit: 0, settled: [9], open: [], stopped: [] }]);
    // Two turns of one thread: its run is stopped once, and both are settled.
    const two = await phase("completed", { settle: [earlier, { ...earlier, operation_id: 10 }] }, "clean", "questions", "running");
    assertEquals([two.ok, helper(two.calls), two.settle!.settled, two.settle!.stopped],
        [true, ["stop earlier run:earlier"], [9, 10], [{ operation_id: 9, run: "run:earlier" }, { operation_id: 10, run: "run:earlier" }]]);
    // Rule (b): T3 accepted the interrupt, but its readback omits the still-active run. That is no proof: nothing is settled, touched or launched.
    const vanished = await phase("completed", { settle: [{ ...earlier, branch: "task-4970" }] }, "dirty", "questions", "running", { T3_STOP: "vanish", T3_MARK: "a".repeat(40), T3_DETACHED: "1" });
    assertEquals([vanished.ok, vanished.settle, vanished.launched, git(vanished.calls)], [false, { list_exit: 0, settled: [], open: [9], stopped: [] }, false, []]);
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
    // While the run goes on because T3 refused its stop, the checkout is not touched.
    const running = await phase("completed", { settle: [reviewer] }, "dirty", "questions", "running", { ...mark, T3_STOP: "refuse" });
    assertEquals([running.ok, git(running.calls), running.settle!.open], [false, [], [9]]);
    // A run the stop ended can no longer write: the checkout is restored after the stop.
    const ended = await phase("completed", { settle: [reviewer] }, "dirty", "questions", "running", mark);
    assertEquals([ended.ok, ended.settle!.settled, git(ended.calls).slice(2, 5)], [true, [9], ["reset --quiet --hard", "clean --quiet -fd", "checkout --quiet task-4970"]]);
    const steps = ended.calls.map((call) => call.args.slice(15).join(" "));
    assert(steps.findIndex((args) => args.includes(" stop earlier")) < steps.findIndex((args) => args.includes("reset --quiet --hard")), "the stop precedes the restore");
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

Deno.test({ name: "a refused model while an earlier turn waits to be settled settles nothing, touches no checkout and launches nothing", ignore: !available, async fn() {
    const reviewer = { operation_id: 9, title: "Orbit task 4970 thread 76", branch: "task-4970" };
    const refused = await phase("completed", { model: "claude-opus-9", settle: [reviewer] }, "dirty", "questions", "completed",
        { T3_MARK: "a".repeat(40), T3_DETACHED: "1" });
    assert(!refused.ok && refused.output.includes("tasks.copy_turn_model_unoffered"), refused.output);
    assertEquals([refused.model!.accepted, refused.settle, refused.launched, refused.start, git(refused.calls)], [false, null, false, null, []]);
    assertEquals(refused.calls.some((call) => call.args.includes("--kill-after=30") || call.args.includes("list")), false);
} });

Deno.test({ name: "a continued answer resolves its stored requests before the send so a stale question cannot cut the work", ignore: !available, async fn() {
    const done = await phase("completed", { thread: "continue", provider: null, answered: ["request:1", "request:2"], answered_run: "run:1" }, "clean", "questions", "", { T3_ANSWERED: "resolved" });
    assert(done.ok, done.output + JSON.stringify(done.calls.map(({ args }) => args)));
    assertEquals([done.result.state, done.launched, done.result.ended], ["done", true, true]);
    assertEquals(done.calls[2].args.slice(20), ["answered", "mcp:66105c2c", "run:1", "request:1", "request:2"]);
    assertEquals(done.calls[4].args.slice(19), ["thread", "send", "--wait", "--json", "mcp:66105c2c", "-"]);
} });

Deno.test({ name: "an answered request that stays pending refuses the turn before launch or reviewer checkout", ignore: !available, async fn() {
    const done = await phase("completed", { thread: "continue", provider: null, answered: ["request:1", "request:2"], answered_run: "run:1", candidate: "c".repeat(40) }, "clean", "questions", "", { T3_ANSWERED: "pending" });
    assert(!done.ok, done.output);
    assert(done.output.includes("tasks.copy_question_unresolved"), done.output);
    assertEquals([done.result.error_code, done.result.ended, done.launched, done.detached, done.start], ["tasks.copy_question_unresolved", true, false, false, null]);
    assertEquals(done.calls.length, 3);
} });

Deno.test({ name: "a failed or unreadable answered resolution launches nothing and names the resolution failure", ignore: !available, async fn() {
    for (const mode of ["fail", "garbled"]) {
        const done = await phase("completed", { thread: "continue", provider: null, answered: ["request:1", "request:2"], answered_run: "run:1" }, "clean", "questions", "", { T3_ANSWERED: mode });
        assert(!done.ok, done.output);
        assertEquals([done.result.error_code, done.result.ended, done.launched, done.start], ["tasks.copy_question_unresolved", true, false, null]);
        assertEquals(done.calls.length, 3);
    }
} });
