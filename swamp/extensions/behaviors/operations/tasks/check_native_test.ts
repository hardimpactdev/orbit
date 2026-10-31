import { assertEquals, assertNotEquals } from "jsr:@std/assert@1";
import { check, CheckInput } from "./check.ts";
import { CheckProgram } from "./check_program.ts";
import { workspaceProgram } from "./script.ts";

const adapter = String.raw`
import json, os, signal, subprocess, sys
value = json.load(sys.stdin)
namespace = {'__name__': 'orbit_check'}
exec(value['program'], namespace)
def command(root, path, args, unit, tmp, ssr):
    return [path, *args]
def stop():
    process = namespace['PROCESS']
    if process is not None:
        try: os.killpg(process.pid, signal.SIGKILL)
        except ProcessLookupError: pass
namespace['command'] = command
namespace['stop_unit'] = stop
try: result = namespace['check'](value['input'])
except namespace['Cancelled']: result = {'cancelled': True}
except namespace['Refusal'] as error: result = {'error_code': str(error)}
print(json.dumps(result))
`;
async function fixture() {
  const root = await Deno.makeTempDir({ prefix: "task-check-native-" });
  const git = async (...args: string[]) => {
    const output = await new Deno.Command("git", {
      args: ["-C", root, ...args],
      stdout: "piped",
      stderr: "null",
    }).output();
    if (!output.success) throw new Error("Fixture git failed");
    return new TextDecoder().decode(output.stdout).trim();
  };
  await git("init", "-q");
  await git("config", "user.email", "test@example.test");
  await git("config", "user.name", "Test");
  await Deno.writeTextFile(root + "/code", "old\n");
  await Deno.writeTextFile(root + "/.gitignore", "caches\n");
  await git("add", ".");
  await git("commit", "-qm", "baseline");
  const base = await git("rev-parse", "HEAD");
  const input = CheckInput.parse({
    group_id: 1,
    instance_id: 2,
    node_id: 3,
    subtask: 4,
    check_id: 5,
    checkout_path: root,
    ssr_port: null,
    phase: "baseline",
    command: "test -f code",
    setup: [],
    deliverables: [],
    base,
    message: "Task handoff",
    repository: null,
    check_name: null,
    branch: "task-1-handoff",
  });
  const invoke = (value: Record<string, unknown>, signal?: AbortSignal) =>
    workspaceProgram(adapter, { program: CheckProgram, input: value }, signal);
  return {
    root,
    git,
    input,
    invoke,
    dispose: () => Deno.remove(root, { recursive: true }),
  };
}
Deno.test("baseline setup becomes the checked tree and replay does not execute it again", async () => {
  const f = await fixture();
  try {
    f.input.setup = [{
      name: "setup",
      command: "printf setup > caches",
      timeout_seconds: 10,
    }];
    f.input.command = "test -f caches";
    const result = await check(f.input, 11, f.invoke);
    assertEquals(result.outcome, "passed");
    assertEquals(result.commit, null);
    await Deno.remove(f.root + "/caches");
    assertEquals(await check(f.input, 11, f.invoke), result);
    assertEquals(
      await Deno.stat(f.root + "/caches").then(() => true, () => false),
      false,
    );
  } finally {
    await f.dispose();
  }
});
Deno.test("handoff verifies file, command and failing-base evidence before its candidate commit", async () => {
  const f = await fixture();
  try {
    await Deno.mkdir(f.root + "/tests");
    await Deno.writeTextFile(
      f.root + "/tests/assert.sh",
      'test "$(cat code)" = new\n',
    );
    await Deno.writeTextFile(f.root + "/code", "new\n");
    f.input.phase = "handoff";
    f.input.deliverables = CheckInput.shape.deliverables.parse([
      { id: "file", type: "file", path: "code", change: "modified" },
      {
        id: "command",
        type: "command",
        command: "bash tests/assert.sh",
        directory: ".",
        fails_on_base: true,
        paths: ["tests/assert.sh"],
      },
    ]);
    const result = await check(f.input, 12, f.invoke);
    assertEquals(result.outcome, "passed");
    assertNotEquals(result.commit, f.input.base);
    assertEquals(await f.git("rev-parse", "HEAD"), result.commit);
    assertEquals(
      await f.git("rev-parse", "refs/orbit/check-operations/12"),
      result.commit,
    );
    assertEquals(await check(f.input, 12, f.invoke), result);
    assertEquals(
      (result.evidence as {
        commands: Record<string, { base_exit_code: number }>;
      }).commands.command.base_exit_code,
      1,
    );
  } finally {
    await f.dispose();
  }
});
Deno.test("a mutating check creates no candidate", async () => {
  const f = await fixture();
  try {
    f.input.phase = "handoff";
    f.input.command = "printf changed > code";
    const result = await check(f.input, 13, f.invoke);
    assertEquals(result.outcome, "changed");
    assertEquals(result.commit, null);
    assertEquals(await f.git("rev-parse", "HEAD"), f.input.base);
  } finally {
    await f.dispose();
  }
});
Deno.test("unmet file evidence and a failed command both prevent a candidate", async () => {
  const f = await fixture();
  try {
    f.input.phase = "handoff";
    f.input.deliverables = CheckInput.shape.deliverables.parse([{
      id: "required",
      type: "file",
      path: "missing",
      change: "created",
    }]);
    assertEquals((await check(f.input, 14, f.invoke)).outcome, "failed");
    f.input.deliverables = [];
    f.input.command = "exit 23";
    assertEquals((await check(f.input, 15, f.invoke)).outcome, "failed");
  } finally {
    await f.dispose();
  }
});
Deno.test("an overlay outside the workspace fails before any project command", async () => {
  const f = await fixture();
  try {
    f.input.command = "touch should-not-run";
    f.input.deliverables = CheckInput.shape.deliverables.parse([{
      id: "bad",
      type: "command",
      command: "true",
      paths: ["../outside"],
      fails_on_base: true,
    }]);
    assertEquals(await check(f.input, 16, f.invoke), {
      error_code: "tasks.deliverable_invalid",
    });
    assertEquals(
      await Deno.stat(f.root + "/should-not-run").then(() => true, () => false),
      false,
    );
  } finally {
    await f.dispose();
  }
});
Deno.test("moved workspace cannot be committed under a saved passing check", async () => {
  const f = await fixture();
  try {
    f.input.phase = "handoff";
    const invoke: typeof f.invoke = async (value, signal) => {
      if (value.mode === "commit") {
        await Deno.writeTextFile(f.root + "/code", "moved\n");
      }
      return f.invoke(value, signal);
    };
    assertEquals((await check(f.input, 17, invoke)).outcome, "changed");
    assertEquals(await f.git("rev-parse", "HEAD"), f.input.base);
  } finally {
    await f.dispose();
  }
});

Deno.test("baseline uses the canonical CI cache importer", async () => {
  const f = await fixture();
  try {
    await Deno.mkdir(f.root + "/bin");
    await Deno.writeTextFile(
      f.root + "/bin/ci-cache-ref",
      'import sys\nprint("cache import " + " ".join(sys.argv[1:]))\n',
    );
    await f.git("add", ".");
    await f.git("commit", "-qm", "cache tooling");
    const head = await f.git("rev-parse", "HEAD");
    await f.git("update-ref", "refs/remotes/origin/main", head);
    await f.git(
      "symbolic-ref",
      "refs/remotes/origin/HEAD",
      "refs/remotes/origin/main",
    );
    f.input.setup = [{
      name: "cache",
      command: "@orbit/ci-caches",
      timeout_seconds: 10,
    }];
    const result = await check(f.input, 18, f.invoke);
    assertEquals(result.outcome, "passed");
    assertEquals(
      String(result.output).includes("cache import import --commit " + head),
      true,
    );
  } finally {
    await f.dispose();
  }
});
