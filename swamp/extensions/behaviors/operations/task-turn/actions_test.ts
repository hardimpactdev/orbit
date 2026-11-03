import { assert, assertEquals, assertRejects, assertStringIncludes } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { nativeRepo, ROOT, trackedStep } from "../../../../tests/_support/native_harness.ts";
import { command } from "../../../../tests/_support/task_remote.ts";
import { piFixture } from "../../../../tests/_support/task_turn.ts";
import { difference, receipt, terminal } from "../tasks/turn.ts";

async function fixture() {
  const repo = await nativeRepo();
  const git = async (...args: string[]) => (await command(["git", ...args], repo.dir)).stdout.trim();
  await git("init", "-q");
  await git("config", "user.email", "test@example.invalid");
  await git("config", "user.name", "Task fixture");
  await Deno.writeTextFile(`${repo.dir}/file`, "original\n");
  await git("add", ".");
  await git("commit", "-qm", "Initial");
  const root = `${repo.dir}/.git/orbit`;
  const token = ["synthetic-pi-test-token-", "with-at-least-32-characters"].join("");
  const tools = `${repo.dir}/worker-tools`;
  await Deno.mkdir(tools);
  for (const name of ["sh", "curl", "awk"]) {
    const path = (await command(["sh", "-c", `command -v ${name}`])).stdout.trim();
    await Deno.symlink(path, `${tools}/${name}`);
  }
  async function step(name: string, extra: Record<string, string> = {}, success = true) {
    const inputs = trackedStep("operation-task-turn", name).task.inputs;
    if (["receipt_prepare", "receipt_clear"].includes(name)) {
      const argv = (inputs.argv as string[]).map((arg) =>
        arg.replace(/^\$\{\{ inputs.intent.checkout_path \+ "([^"]*)" \}\}$/, (_, suffix) => repo.dir + suffix)
      );
      assert(!argv.some((arg) => arg.includes("${{")));
      return await command(argv, undefined, undefined, success);
    }
    await Deno.mkdir(`${repo.dir}/local`, { recursive: true });
    const result = await command(["sh", "-c", inputs.run as string], undefined, {
      PATH: tools, ORBIT_PI_PATH: ({ session_create: "/sessions", message_send: "/sessions/session-14/messages", interrupt: "/sessions/session-14/interrupt" } as Record<string, string>)[name] ?? "",
      ORBIT_PI_SESSION: "session-14", ORBIT_PI_TURN: "operation-15", ORBIT_PI_BODY: "{}",
      ORBIT_TERMINAL: `${repo.dir}/local/terminal.json`, ...extra,
    }, success);
    assert(!JSON.stringify(result).includes(token));
    if (name === "observe" && success) assertEquals(result.stdout, "");
    return result;
  }
  return {
    repo, root, token, tools, git, step,
    read: async () => JSON.parse(await receipt({ path: `${root}/receipt.json` })),
    terminal: async () => JSON.parse(await terminal({ path: `${repo.dir}/local/terminal.json`, turn: "operation-15" })),
    diff: async () => JSON.parse(await difference({ counts: `${root}/counts`, patch: `${root}/patch` })),
    env: (url: string) => ({ ORBIT_PI_TOKEN: token, ORBIT_PI_URL: url }),
    [Symbol.asyncDispose]: () => repo[Symbol.asyncDispose](),
  };
}

Deno.test("agent receipt is read from a prepared private workspace", async () => {
  await using t = await fixture();
  await t.step("receipt_prepare");
  assertEquals((await Deno.stat(t.root)).mode! & 0o777, 0o700);
  await Deno.writeTextFile(`${t.root}/receipt.json`, '{"thread":14,"outcome":"blocked"}');
  await t.step("receipt_prepare");
  await t.step("receipt_clear");
  assertEquals(await t.read(), { receipt: null, error: null });
  const value = { thread: 14, outcome: "ready_for_review", summary: "Implemented", deliverables: { done: "evidence" } };
  await Deno.writeTextFile(`${t.root}/receipt.json`, JSON.stringify(value));
  assertEquals(await t.read(), { receipt: value, error: null });
  assertEquals(Array.from(Deno.readDirSync(t.root), (entry) => entry.name), ["receipt.json"]);
});

Deno.test("separate Pi actions ignore another turn and strip private usage", async () => {
  await using t = await fixture();
  await using pi = piFixture(t.token);
  pi.state.usage = { total: 4, provider_key: t.token };
  await t.step("session_create", { ...t.env(pi.url), ORBIT_PI_BODY: '{"id":"session-14"}' });
  await t.step("message_send", { ...t.env(pi.url), ORBIT_PI_BODY: '{"key":"operation-15","text":"Do the task"}' });
  await t.step("observe", t.env(pi.url));
  const event = await t.terminal();
  assertEquals(event.turnId, "operation-15");
  assertEquals(event.usage.total, 4);
  assert(!JSON.stringify(event).includes(t.token));
  assert(!("provider_key" in event.usage));
  assertEquals(pi.state.calls.length, 3);
  await t.step("interrupt", t.env(pi.url));
  assertEquals(pi.state.calls.at(-1)![0], "/sessions/session-14/interrupt");
});

Deno.test("disconnect without a matching terminal event fails without retry", async () => {
  await using t = await fixture();
  await using pi = piFixture(t.token);
  pi.state.disconnect = true;
  assertStringIncludes((await t.step("observe", t.env(pi.url), false)).stderr, "tasks.pi_completion_unconfirmed");
  assertEquals(pi.state.calls.length, 1);
});

Deno.test("Pi redirects fail without forwarding credentials", async () => {
  await using t = await fixture();
  await using pi = piFixture(t.token);
  pi.state.redirect = true;
  await t.step("session_create", t.env(pi.url), false);
  assertEquals(pi.state.calls.map(([path]) => path), ["/sessions"]);
});

Deno.test("public receipts redact private formats recursively", async () => {
  await using t = await fixture();
  await t.step("receipt_prepare");
  const secrets = ["a1".repeat(32), "user:pass", "ghp_" + "a".repeat(30), "github_pat_" + "b".repeat(30), "private-value", "pem-private"];
  const text = `${secrets[0]} https://${secrets[1]}@example.invalid ${secrets[2]} ${secrets[3]}\nAuthorization: Bearer private-value\nMODEL_KEY=private-value\n-----BEGIN PRIVATE KEY-----\npem-private\n-----END PRIVATE KEY-----`;
  await Deno.writeTextFile(`${t.root}/receipt.json`, JSON.stringify({ summary: text, deliverables: { key: text } }));
  const value = (await t.read()).receipt;
  for (const secret of secrets) assert(!JSON.stringify(value).includes(secret));
  assertStringIncludes(value.summary, "[REDACTED]");
  assert("key" in value.deliverables);
});

Deno.test("stale receipt links are removed without touching their targets", async () => {
  await using t = await fixture();
  await Deno.mkdir(t.root);
  const target = `${t.repo.dir}/protected`;
  await Deno.writeTextFile(target, "keep");
  await Deno.symlink(target, `${t.root}/receipt.json`);
  await t.step("receipt_prepare");
  await t.step("receipt_clear");
  await assertRejects(() => Deno.lstat(`${t.root}/receipt.json`), Deno.errors.NotFound);
  assertEquals(await Deno.readTextFile(target), "keep");
});

Deno.test("receipts and differences sanitize VM credential formats", async () => {
  await using t = await fixture();
  const credential = "a1".repeat(32), before = await t.git("write-tree");
  await t.step("receipt_prepare");
  await Deno.writeTextFile(`${t.root}/receipt.json`, JSON.stringify({ thread: 14, outcome: "ready_for_review", summary: `key ${credential}`, deliverables: {} }));
  const value = (await t.read()).receipt;
  assert(!JSON.stringify(value).includes(credential));
  assertStringIncludes(value.summary, "[REDACTED]");
  await Deno.writeTextFile(`${t.repo.dir}/file`, `MODEL_KEY=${credential}\n`);
  await t.git("add", "--all");
  const after = await t.git("write-tree");
  await Deno.writeTextFile(`${t.root}/counts`, await t.git("diff", "--numstat", "-z", before, after, "--"));
  await Deno.writeTextFile(`${t.root}/patch`, await t.git("diff", before, after, "--"));
  const diff = await t.diff();
  assert(!JSON.stringify(diff).includes(credential));
  assertStringIncludes(diff.diff, "[REDACTED]");
});

Deno.test("Pi credential reaches curl only through its environment", async () => {
  await using t = await fixture();
  await using pi = piFixture(t.token);
  const real = (await command(["sh", "-c", "command -v curl"])).stdout.trim();
  const record = `${t.repo.dir}/curl-argv`;
  const fake = await t.repo.fakeBinary("curl", `
Deno.writeTextFileSync(${JSON.stringify(record)}, Deno.args.join("\\n")+"\\n", {append:true});
const child = new Deno.Command(${JSON.stringify(real)}, {args:Deno.args,stdin:"inherit",stdout:"inherit",stderr:"inherit"}).spawn();
Deno.exit((await child.status).code);
`);
  for (const name of ["session_create", "message_send", "observe", "interrupt"]) {
    await t.step(name, { ...t.env(pi.url), PATH: `${fake.slice(0, fake.lastIndexOf("/"))}:${t.tools}` });
  }
  assertEquals(pi.state.calls.length, 4);
  const argv = await Deno.readTextFile(record);
  assertStringIncludes(argv, "%ORBIT_PI_TOKEN");
  assert(!argv.includes(t.token));
});

Deno.test("malformed Pi events report one fixed code", async () => {
  await using t = await fixture();
  await using pi = piFixture(t.token);
  pi.state.disconnect = true;
  pi.state.malformed = '{"state":"done","turnId":"operation-15", private-pi-text';
  await t.step("observe", t.env(pi.url));
  assertEquals((await assertRejects(() => t.terminal(), Error)).message, "tasks.pi_event_invalid");
});

Deno.test("observe works with the task VM tool set against the proof fixture", async () => {
  await using t = await fixture();
  const source = await Deno.readTextFile(`${ROOT}/proof/tasks.turn/pi_fixture.py`);
  assertStringIncludes(source, 'HTTPServer(("0.0.0.0", 3774), Handler)');
  const listener = Deno.listen({ hostname: "127.0.0.1", port: 0 });
  const port = listener.addr.port;
  listener.close();
  const path = `${t.repo.dir}/pi_fixture.py`;
  await Deno.writeTextFile(path, source.replace('HTTPServer(("0.0.0.0", 3774), Handler)', `turn = "operation-15"\nHTTPServer(("127.0.0.1", ${port}), Handler)`));
  const child = new Deno.Command("python3", { args: ["-I", path], stdout: "null", stderr: "piped" }).spawn();
  try {
    for (let attempt = 0; attempt < 100; attempt++) {
      try { const connection = await Deno.connect({ hostname: "127.0.0.1", port }); connection.close(); break; }
      catch { await new Promise((resolve) => setTimeout(resolve, 20)); }
    }
    await t.step("observe", t.env(`http://127.0.0.1:${port}`));
    const event = await t.terminal();
    assertEquals(event.state, "done");
    assertEquals(event.usage.total, 4);
    await assertRejects(() => Deno.stat(`${t.tools}/jq`), Deno.errors.NotFound);
  } finally { child.kill(); await child.output(); }
});

Deno.test("malformed receipt and counts report fixed codes", async () => {
  await using t = await fixture();
  await Deno.mkdir(t.root);
  await Deno.writeTextFile(`${t.root}/receipt.json`, '{"summary":private-receipt-text}');
  assertEquals(await t.read(), { receipt: null, error: "tasks.receipt_invalid" });
  await Deno.writeTextFile(`${t.root}/counts`, "private-count\t1\tfile\0");
  await Deno.writeTextFile(`${t.root}/patch`, "");
  assertEquals((await assertRejects(() => t.diff(), Error)).message, "tasks.difference_invalid");
});

Deno.test("task turn ships no scripts", async () => {
  const root = `${ROOT}/extensions/behaviors/operations/task-turn`;
  await assertRejects(() => Deno.stat(`${root}/scripts`), Deno.errors.NotFound);
  assert(!("additionalFiles" in (parse(await Deno.readTextFile(`${root}/manifest.yaml`)) as object)));
});

Deno.test("no turn step copies a file to the Node", async () => {
    const flow = parse(
        await Deno.readTextFile(
            `${ROOT}/workflows/workflow-operation-task-turn.yaml`,
        ),
    ) as {
        jobs: {
            steps: {
                task: { methodName?: string; inputs: { direction?: string } };
            }[];
        }[];
    };
    assertEquals(
        flow.jobs.flatMap((job) => job.steps).filter((step) =>
            step.task.methodName === "copy" &&
            step.task.inputs.direction !== "from"
        ),
        [],
    );
    for (const marker of ["/scripts", "python3", ".git/orbit/turn"]) {
        assert(!JSON.stringify(flow).includes(marker));
    }
});
