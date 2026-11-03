import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { checkFixture, checkResult, command, exists, write } from "../../../../tests/_support/task_repository.ts";

Deno.test("SSH preparation failure skips checks without evaluating missing outputs", async () => {
  await using t = await checkFixture();
  await t.repo.fakeBinary(
    "tools/ssh",
    'if(Deno.args.includes("-M")||Deno.args.includes("-O")) Deno.exit(0);console.error("fixture SSH unavailable");Deno.exit(255);',
  );
  Object.assign(t.inputs.intent, {
    phase: "handoff",
    command: "touch should-not-run",
    deliverables: [
      command({ id: "base-command", command: "touch should-not-run" }),
      command({
        id: "current-command",
        command: "touch should-not-run",
        fails_on_base: false,
      }),
    ],
  });
  const { history, steps } = await t.check(false, false);
  assertEquals(history.status, "failed");
  assertEquals(steps["Build every argv"].status, "failed");
  for (
    const name of [
      "original",
      "setup_result",
      "snapshot-before-index",
      "project",
      "base_archive-0",
      "base_archive-1",
      "base-0",
      "base-1",
      "deliverable-0",
      "deliverable-1",
      "snapshot-after-index",
      "local",
      "mint_github",
      "push",
    ]
  ) assertEquals(steps[name]?.status ?? "skipped", "skipped", name);
  assert(
    JSON.stringify(checkResult(history)).includes(
      "tasks.repository_execution_unknown",
    ),
  );
  assert(!await exists(`${t.workspace}/should-not-run`));
  assert(!JSON.stringify(history).includes("Invalid expression"));
  assert(!JSON.stringify(history).includes("InvalidExpressionError"));
  assertEquals(steps.cleanup_local.status, "succeeded");
});
Deno.test("baseline setup is ordered before the checked snapshot and preserves the index", async () => {
  await using t = await checkFixture();
  t.inputs.intent.setup = [{
    name: "first",
    command: "printf new > code",
    timeout_seconds: 30,
  }, {
    name: "second",
    command: "test $(cat code) = new",
    timeout_seconds: 30,
  }];
  const index = await Deno.readFile(`${t.workspace}/.git/index`);
  const { history, result } = await t.check();
  assertEquals(result.outcome, "passed");
  assertEquals(result.commit, null);
  assertEquals(history.status, "succeeded");
  assertEquals(result.head_before, await t.git("rev-parse", "HEAD"));
  assertEquals(result.subtask_start_commit, await t.git("rev-parse", "HEAD"));
  assertEquals(result.changed_paths, []);
  assertEquals(await Deno.readFile(`${t.workspace}/.git/index`), index);
  assertEquals(result.tree_before, result.tree);
  assertEquals(await t.scratch("check-"), []);
});
Deno.test("setup failure skips later setup and the project command", async () => {
  await using t = await checkFixture();
  t.inputs.intent.setup = [{
    name: "failure",
    command: "exit 23",
    timeout_seconds: 30,
  }, {
    name: "should-skip",
    command: "touch should-not-run",
    timeout_seconds: 30,
  }];
  const { result } = await t.check();
  assertEquals(result.outcome, "failed");
  assertEquals(result.failed_step, "failure");
  assert(!await exists(`${t.workspace}/should-not-run`));
});
Deno.test("missing overlay or base directory refuses without running the base", async () => {
  await using t = await checkFixture();
  for (
    const [paths, directory] of [[["tests/missing.sh"], "."], [
      [],
      "new",
    ]] as [string[], string][]
  ) {
    await Deno.mkdir(`${t.workspace}/new`, { recursive: true });
    await write(`${t.workspace}/new/keep`, "");
    Object.assign(t.inputs.intent, {
      phase: "handoff",
      deliverables: [
        command({
          id: "bad",
          command: "touch base-ran",
          directory,
          paths,
        }),
      ],
    });
    const before = await t.git("rev-parse", "HEAD");
    const { steps, result } = await t.check(false);
    assertEquals(result.error_code, "tasks.deliverable_invalid");
    assert(!("base-0" in steps));
    assertEquals(await t.git("rev-parse", "HEAD"), before);
  }
});
Deno.test("missing base cannot count as a reproduced failure", async () => {
  await using t = await checkFixture();
  Object.assign(t.inputs.intent, {
    phase: "handoff",
    base: "f".repeat(40),
    deliverables: [command({ id: "bad" })],
  });
  const before = await t.git("rev-parse", "HEAD");
  const { result } = await t.check(false);
  assert("error_code" in result);
  assertEquals(await t.git("rev-parse", "HEAD"), before);
});
Deno.test("handoff file and base command evidence precedes the candidate commit", async () => {
  await using t = await checkFixture();
  await write(`${t.workspace}/code`, "new\n");
  await write(`${t.workspace}/.gitignore`, "vendor/\n");
  await Deno.mkdir(`${t.workspace}/vendor`);
  await write(`${t.workspace}/vendor/expect.sh`, "expected=new\n");
  await write(
    `${t.workspace}/test.sh`,
    '. vendor/expect.sh\ntest "$(cat code)" = "$expected"\n',
  );
  Object.assign(t.inputs.intent, {
    phase: "handoff",
    deliverables: [
      {
        id: "file",
        type: "file",
        path: "code",
        change: "modified",
        command: "",
        directory: ".",
        fails_on_base: false,
        paths: [],
      },
      command({
        path: "",
        change: "any",
        command: "bash test.sh",
        paths: ["test.sh"],
      }),
    ],
  });
  t.repo.secrets = ["ghp_" + "C".repeat(36), "c2".repeat(32)];
  const emit = await t.repo.fakeBinary(
    "emit",
    'console.log("ghp_"+"C".repeat(36));console.log("c2".repeat(32));',
  );
  t.inputs.intent.setup = [{ name: "first", command: emit, timeout_seconds: 30 }];
  t.inputs.intent.command = emit + " && test -f code";
  t.inputs.intent.deliverables[1].command = emit + " >&2; bash test.sh";
  const start = await t.git("rev-parse", "HEAD");
  const { steps, result } = await t.check();
  assertEquals(result.outcome, "passed");
  assertEquals(result.commit, await t.git("rev-parse", "HEAD"));
  assertEquals(result.evidence.commands.test.base_exit_code, 1);
  assertEquals(
    result.evidence.diff.sort((a: { path: string }, b: { path: string }) => a.path.localeCompare(b.path)),
    [{ status: "A", path: ".gitignore" }, { status: "M", path: "code" }, {
      status: "A",
      path: "test.sh",
    }],
  );
  assertEquals(await t.git("rev-parse", "HEAD^"), start);
  assertEquals(await t.git("rev-parse", "HEAD^{tree}"), result.tree);
  assertEquals(
    await t.git("log", "-1", "--format=%an <%ae>|%cn <%ce>|%B"),
    "Orbit <orbit@users.noreply.github.com>|Orbit <orbit@users.noreply.github.com>|Check",
  );
  assertEquals(
    await t.git("rev-parse", "refs/orbit/check-operations/91001"),
    result.commit,
  );
  assertEquals(await t.git("status", "--porcelain"), "");
  for (const name of ["setup-0", "project", "base-0", "deliverable-0"]) {
    assertEquals(steps[name].outputs.stdout, "", name);
  }
  assertEquals(result.output.split("[REDACTED]").length - 1, 4);
  const evidence = result.evidence.commands.test;
  assertEquals(evidence.output.split("[REDACTED]").length - 1, 2);
  assertEquals(evidence.base_output.split("[REDACTED]").length - 1, 2);
  assertEquals(await t.scratch("check-"), []);
});
