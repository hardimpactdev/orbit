import { assertEquals, assertRejects } from "jsr:@std/assert@1";
import { AnalysisInput, analyze } from "./analysis.ts";
import { AnalysisProgram } from "./analysis_program.ts";
import { workspaceProgram } from "./script.ts";
import { DirectOperationCancelled } from "../_lib/direct.ts";

const adapter = String.raw`
import json, os, signal, sys
value = json.load(sys.stdin)
scope = {'__name__': 'orbit_analysis'}
exec(value['program'], scope)
def command(root, path, args, unit, tmp, ssr):
    return [os.path.join(root, path), *args]
def stop():
    process = scope['PROCESS']
    if process is not None:
        try: os.killpg(process.pid, signal.SIGKILL)
        except ProcessLookupError: pass
scope['command'] = command
scope['stop_unit'] = stop
try:
    if 'report' in value:
        result = scope['report'](value['report'].encode(), value['identity'], value['code'])
    else:
        result = scope['analyze'](value['input'])
except scope['Refusal'] as error: result = {'error_code': str(error)}
except (ValueError, UnicodeError): result = {'error_code': 'tasks.analysis_report_invalid'}
except scope['Cancelled']: result = {'cancelled': True}
print(json.dumps(result))
`;
const program = String.raw`#!/usr/bin/python3
import json, pathlib, sys
value = json.load(sys.stdin)
with open('.git/invocations', 'a') as f: f.write('1\n')
result = {key: value[key] for key in ('execution_id', 'plan_sha256', 'base', 'head', 'tree')}
result.update({'schema': 1, 'status': 'ready', 'diagnostics': [], 'report': {'schema': 'test.v1', 'data': {'complete': 'é' * 6000}}})
print(json.dumps(result, ensure_ascii=False))
`;
async function fixture(source = program) {
  const root = await Deno.makeTempDir({ prefix: "task-analysis-native-" });
  const git = async (...args: string[]) => {
    const output = await new Deno.Command("git", {
      args: ["-C", root, ...args],
      stdout: "piped",
      stderr: "null",
    }).output();
    if (!output.success) throw new Error("Fixture git failed");
    return new TextDecoder().decode(output.stdout).trim();
  };
  await git("init", "-qb", "task-1");
  await git("config", "user.email", "test@example.test");
  await git("config", "user.name", "Test");
  await git("remote", "add", "origin", "https://github.com/acme/test.git");
  await Deno.mkdir(root + "/bin");
  await Deno.writeTextFile(root + "/bin/task-analyze", source, { mode: 0o755 });
  await git("add", ".");
  await git("commit", "-qm", "baseline");
  const head = await git("rev-parse", "HEAD");
  const input = AnalysisInput.parse({
    group_id: 1,
    instance_id: 2,
    node_id: 3,
    subtask: 4,
    checkout_path: root,
    ssr_port: null,
    plan: {
      id: "test",
      revision: 1,
      paths: [{ change: "add", path: "new.php" }],
    },
    plan_sha256: "b".repeat(64),
    base: head,
    head,
    tree: await git("rev-parse", "HEAD^{tree}"),
    repository: "https://github.com/acme/test.git",
    branch: "task-1",
  });
  return {
    root,
    git,
    input,
    invoke: (input: Record<string, unknown>, signal?: AbortSignal) =>
      workspaceProgram(adapter, { program: AnalysisProgram, input }, signal),
    dispose: () => Deno.remove(root, { recursive: true }),
  };
}
Deno.test("analysis retains exact complete UTF-8 bytes and keyed replay scans once", async () => {
  const f = await fixture();
  try {
    const result = await analyze(f.input, 17, f.invoke);
    assertEquals(result.outcome, "ready");
    const artifact = result.artifact as {
      content: string;
      bytes: number;
      sha256: string;
    };
    assertEquals(artifact.bytes > 8192, true);
    assertEquals(
      artifact.bytes,
      new TextEncoder().encode(artifact.content).length,
    );
    assertEquals(
      JSON.parse(artifact.content).report.data.complete,
      "é".repeat(6000),
    );
    assertEquals(
      (result.provenance as Record<string, unknown>).head,
      f.input.head,
    );
    await Deno.writeTextFile(f.root + "/untracked", "moved candidate");
    assertEquals(await analyze(f.input, 17, f.invoke), result);
    assertEquals(await Deno.readTextFile(f.root + "/.git/invocations"), "1\n");
  } finally {
    await f.dispose();
  }
});
Deno.test("an untracked change, wrong base or changed fixed executable prevents a scan", async () => {
  const f = await fixture();
  try {
    await Deno.writeTextFile(f.root + "/untracked", "dirty");
    assertEquals(
      (await analyze(f.input, 18, f.invoke)).error_code,
      "tasks.analysis_stale",
    );
    await Deno.remove(f.root + "/untracked");
    assertEquals(
      (await analyze({ ...f.input, base: "f".repeat(40) }, 19, f.invoke))
        .error_code,
      "tasks.analysis_stale",
    );
    await Deno.writeTextFile(
      f.root + "/bin/task-analyze",
      program + "\n# changed\n",
    );
    f.input.tree = await f.git("add", ".").then(() => f.git("write-tree"));
    assertEquals(
      (await analyze(f.input, 20, f.invoke)).error_code,
      "tasks.analysis_unavailable",
    );
    assertEquals(
      await Deno.stat(f.root + "/.git/invocations").then(
        () => true,
        () => false,
      ),
      false,
    );
  } finally {
    await f.dispose();
  }
});
Deno.test("candidate movement makes a complete report unusable", async () => {
  const f = await fixture(
    program.replace(
      "print(json.dumps",
      "pathlib.Path('untracked').write_text('moved')\nprint(json.dumps",
    ),
  );
  try {
    assertEquals((await analyze(f.input, 21, f.invoke)).outcome, "changed");
  } finally {
    await f.dispose();
  }
});
Deno.test("a typed adapter failure stays a closed failure without an artifact", async () => {
  const f = await fixture(
    '#!/usr/bin/python3\nprint(\'{"schema":1,"error":{"code":"tasks.analysis_report_too_large","required_bytes":216325}}\')\nraise SystemExit(1)\n',
  );
  try {
    assertEquals(await analyze(f.input, 22, f.invoke), {
      error_code: "tasks.analysis_report_too_large",
      schema: 1,
      error: {
        code: "tasks.analysis_report_too_large",
        required_bytes: 216325,
      },
    });
  } finally {
    await f.dispose();
  }
});
Deno.test("an interrupted local attempt cannot dispatch the scanner again", async () => {
  const f = await fixture();
  try {
    await analyze(f.input, 23, f.invoke);
    await Deno.remove(f.root + "/.git/orbit/analysis/operation-23/result.json");
    assertEquals(
      (await analyze(f.input, 23, f.invoke)).error_code,
      "tasks.analysis_interrupted",
    );
    assertEquals(await Deno.readTextFile(f.root + "/.git/invocations"), "1\n");
  } finally {
    await f.dispose();
  }
});
Deno.test("cancellation stops the owned analyzer before reporting cancellation", async () => {
  const f = await fixture(
    "#!/usr/bin/python3\nimport pathlib,time\npathlib.Path('.git/started').write_text('yes')\ntime.sleep(20)\n",
  );
  const controller = new AbortController();
  try {
    const result = analyze(f.input, 24, f.invoke, controller.signal);
    const deadline = Date.now() + 3000;
    while (Date.now() < deadline) {
      if (
        await Deno.stat(f.root + "/.git/started").then(() => true, () => false)
      ) break;
      await new Promise((resolve) => setTimeout(resolve, 10));
    }
    controller.abort();
    await assertRejects(() => result, DirectOperationCancelled);
    assertEquals(
      JSON.parse(
        await Deno.readTextFile(
          f.root + "/.git/orbit/analysis/operation-24/result.json",
        ),
      ),
      { cancelled: true },
    );
  } finally {
    await f.dispose();
  }
});
const identity = {
  execution_id: "a".repeat(32),
  plan_sha256: "b".repeat(64),
  base: "c".repeat(40),
  head: "d".repeat(40),
  tree: "e".repeat(40),
};
const envelope = () =>
  JSON.stringify({
    schema: 1,
    ...identity,
    status: "ready",
    diagnostics: [],
    report: { schema: "test.v1", data: {} },
  });
const validate = (report: string, code = 0) =>
  workspaceProgram(adapter, {
    program: AnalysisProgram,
    report,
    identity,
    code,
  });
Deno.test("strict reports reject duplicate fields, trailing bytes, nonfinite numbers and deep JSON", async () => {
  for (
    const report of [
      envelope().replace('"schema":1', '"schema":2,"schema":1'),
      envelope() + " trailing",
      envelope().replace('"data":{}', '"data":{"x":1e999}'),
      envelope().replace('"data":{}', '"data":{"x":NaN}'),
      envelope().replace(
        '"data":{}',
        '"data":{"x":' + "[".repeat(25) + "1" + "]".repeat(25) + "}",
      ),
      envelope().replace('"data":{}', '"data":{"x":1,"x":2}'),
      envelope().replace(identity.head, "f".repeat(40)),
      envelope().replace('"data":{}', '"data":{"API_KEY":"sensitive-value"}'),
    ]
  ) {
    assertEquals(await validate(report), {
      error_code: "tasks.analysis_report_invalid",
    });
  }
});
Deno.test("reports preserve the exact 64KiB boundary and refuse oversized evidence", async () => {
  const value = JSON.parse(envelope());
  value.report.data.text = "";
  value.report.data.text = "x".repeat(65536 - JSON.stringify(value).length);
  assertEquals(
    (await validate(JSON.stringify(value)) as { status: string }).status,
    "ready",
  );
  assertEquals(await validate(JSON.stringify(value) + " "), {
    error_code: "tasks.analysis_report_too_large",
    schema: 1,
    error: { code: "tasks.analysis_report_too_large", required_bytes: null },
  });
});
Deno.test("only safe typed failures on nonzero exits become public diagnostics", async () => {
  for (
    const size of [
      "null",
      "true",
      '"70000"',
      "70000.0",
      "-1",
      "65536",
      "9007199254740992",
    ]
  ) {
    assertEquals(
      await validate(
        '{"schema":1,"error":{"code":"tasks.analysis_report_too_large","required_bytes":' +
          size + "}}",
        1,
      ),
      { error_code: "tasks.analysis_report_invalid" },
    );
  }
  const failure =
    '{"schema":1,"error":{"code":"tasks.analysis_unavailable","required_bytes":null}}';
  assertEquals(await validate(failure, 0), {
    error_code: "tasks.analysis_report_invalid",
  });
  assertEquals(await validate(failure, 1), {
    schema: 1,
    error: { code: "tasks.analysis_unavailable", required_bytes: null },
    error_code: "tasks.analysis_unavailable",
  });
});
