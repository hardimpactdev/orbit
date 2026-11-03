import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { publication } from "../../../../tests/_support/task_repository_publication.ts";
import { exists, git, write } from "../../../../tests/_support/task_repository.ts";

Deno.test("publication pushes without force and records the PR", async () => {
  await using t = await publication();
  const { run, result } = await t.publish();
  assertEquals(run.status, "succeeded");
  assertEquals(result.pull_request_number, 12);
  assertEquals(await git(t.remote, "rev-parse", "refs/heads/task-1"), t.head);
  assertEquals(t.api.calls.map((c) => c[0]), ["GET", "POST", "DELETE"]);
  assert(t.api.calls[0][1].includes("head=acme%3Atask-1"));
  assertEquals(t.api.calls[1][2], {
    ...t.inputs.intent.pull_request,
    head: "task-1",
    base: "main",
  });
  assert(!await exists(`${t.workspace}/inert`));
  assertEquals(await t.scratch("publish-"), []);
});
Deno.test("push only skips PR actions", async () => {
  await using t = await publication();
  t.inputs.intent.pull_request = null;
  const { result } = await t.publish();
  assertEquals(result.pull_request_url, null);
  assertEquals(t.api.calls.map((c) => c[0]), ["DELETE"]);
});
Deno.test("identical tip updates the open PR", async () => {
  await using t = await publication();
  await t.publish();
  t.inputs.operation_id = 16;
  const { result } = await t.publish();
  assertEquals(result.pull_request_number, 12);
  assertEquals(t.api.calls.map((c) => c[0]), [
    "GET",
    "POST",
    "DELETE",
    "GET",
    "PATCH",
    "DELETE",
  ]);
  assertEquals(t.api.calls[4][2], t.inputs.intent.pull_request);
});
Deno.test("divergent tip is never forced", async () => {
  await using t = await publication();
  await t.publish();
  await t.git("checkout", "-q", "--orphan", "other");
  await t.git("commit", "--allow-empty", "-qm", "Other root");
  Object.assign(t.inputs.intent, {
    commit: await t.git("rev-parse", "HEAD"),
    tree: await t.git("rev-parse", "HEAD^{tree}"),
  });
  t.inputs.operation_id = 16;
  t.inputs.intent.refspec = t.inputs.intent.commit + ":refs/heads/task-1";
  const { result } = await t.publish(false);
  assertEquals(result, { error_code: "tasks.not_fast_forward" });
  assertEquals(await git(t.remote, "rev-parse", "refs/heads/task-1"), t.head);
});
Deno.test("workflow permission refusal keeps its code", async () => {
  await using t = await publication();
  const hook = `${t.remote}/hooks/pre-receive`;
  await write(
    hook,
    "#!/bin/sh\necho 'refusing to allow a GitHub App to create or update workflow .github/workflows/ci.yml' >&2\nexit 1\n",
  );
  await Deno.chmod(hook, 0o755);
  const { result } = await t.publish(false);
  assertEquals(result, { error_code: "tasks.workflows_permission" });
  assertEquals(t.api.calls.map((c) => c[0]), ["DELETE"]);
});
Deno.test("workspace Git config and hooks never run with the token", async () => {
  await using t = await publication();
  const marker = `${t.repo.dir}/untrusted-ran`, hooks = `${t.repo.dir}/hooks`;
  await Deno.mkdir(hooks);
  await write(
    `${hooks}/pre-push`,
    "#!/bin/sh\ntouch '" + marker + "'\nexit 1\n",
  );
  await Deno.chmod(`${hooks}/pre-push`, 0o755);
  await t.git("config", "core.hooksPath", hooks);
  await t.git("config", "credential.helper", "!false");
  const { run } = await t.publish();
  assertEquals(run.status, "succeeded");
  assert(!await exists(marker));
});
Deno.test("moved workspace refuses before any remote push", async () => {
  await using t = await publication();
  await write(`${t.workspace}/code`, "changed\n");
  const { result } = await t.publish(false);
  assertEquals(result, { error_code: "tasks.head_moved" });
  assertEquals(t.api.calls.map((c) => c[0]), ["DELETE"]);
  assertEquals(await git(t.remote, "for-each-ref"), "");
});
Deno.test("a lost PR reply is reconciled on fresh admission", async () => {
  await using t = await publication();
  t.api.loseReply = true;
  const { result } = await t.publish(false);
  assertEquals(result, { error_code: "tasks.publish_failed" });
  t.api.loseReply = false;
  t.inputs.operation_id = 16;
  assertEquals((await t.publish()).result.pull_request_number, 12);
  assertEquals(t.api.calls.filter((c) => c[0] === "POST").length, 1);
  assertEquals(t.api.calls.filter((c) => c[0] === "PATCH").length, 1);
});
