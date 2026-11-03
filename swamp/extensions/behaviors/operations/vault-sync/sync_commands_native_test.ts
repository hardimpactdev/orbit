import { assert, assertEquals, assertFalse } from "jsr:@std/assert@1.0.19";
import { parse, stringify } from "jsr:@std/yaml@1.0.12";
import { cipher, vaultFixture } from "../../../../tests/_support/vault_fixture.ts";
import { exists, stepsOf } from "../../../../tests/_support/native_history.ts";
const error = (steps: ReturnType<typeof stepsOf>) => JSON.parse(steps.result.outputs.stdout);
Deno.test("private intent mismatch refuses before CLI contact", async () => {
  await using f = await vaultFixture();
  f.privateInput.url = "https://different.example.test";
  await f.put();
  const { history, state } = await f.run(false);
  assertEquals(Object.values(stepsOf(history))[0].error, "vault.intent_changed");
  assertEquals(state.commands ?? [], []);
});
for (const stale of [false, true]) {
  Deno.test(`summary resume restores twelve receipts; expired outputs=${stale}`, async () => {
    await using f = await vaultFixture();
    const { repo, inputs, privateInput } = f;
    inputs.intent.ledger = [];
    inputs.intent.wanted = Array.from(
      { length: 12 },
      (_, i) => ({ source: `database-user:${i + 1}`, fingerprint: "new" }),
    );
    const template = privateInput.wanted[0];
    privateInput.wanted = inputs.intent.wanted.map((wanted: { source: string }) => {
      const item = { ...structuredClone(template), ...wanted };
      item.cipher.fields[0].value = wanted.source;
      return item;
    });
    await f.put();
    const path = `${repo.dir}/workflows/workflow-operation-vault-sync.yaml`;
    const original = await Deno.readTextFile(path);
    // deno-lint-ignore no-explicit-any
    const workflow = parse(original) as any;
    if (stale) {
      workflow.jobs.flatMap((j: { steps: { name: string }[] }) => j.steps).find((s: { name: string }) =>
        s.name === "write"
      )
        .task.behaviorName = '${{ "vault-sync-" + string(inputs.operation_id) + "-write" }}';
      f.write({ ...f.read(), fail_command: "logout" });
    } else workflow.jobs.at(-1).steps[0].task.inputs.writes = ["invalid-json"];
    await Deno.writeTextFile(path, stringify(workflow));
    const first = await f.run(false);
    assertEquals(f.read().writes.length, 12);
    if (!stale) await Deno.writeTextFile(path, original);
    await repo.cli(["workflow", "resume", "operation-vault-sync", "--run", first.run.id, "--from", "result"], {
      success: !stale,
    });
    const steps = stepsOf((await repo.history(first.run.id)).history);
    assertEquals(steps.result.status, "succeeded");
    if (stale) assertEquals(error(steps), { error_code: "vault.sync_failed" });
    else assertEquals(error(steps).data.sources.length, 12);
    assertEquals(f.read().writes.length, 12);
  });
}
Deno.test("missing recorded item creates without adopting a copy", async () => {
  await using f = await vaultFixture();
  f.write({ ...f.read(), ciphers: [cipher("copy", "database-user:7", { revision: "copy-revision" })] });
  await f.repo.cli(["workflow", "validate", "operation-vault-sync"]);
  const { state } = await f.run();
  assertEquals(state.writes, [{ source: "database-user:7", id: "new-cipher" }]);
  assertEquals(state.deletes, []);
});
Deno.test("shared trashed recorded item refuses before mutation", async () => {
  await using f = await vaultFixture();
  f.write({
    ...f.read(),
    ciphers: [cipher("published", "database-user:7", { collection_ids: ["collection", "other"], deleted: true })],
  });
  const { steps, state } = await f.run(false);
  assertEquals(steps.plan.status, "failed");
  assertEquals([state.writes, state.deletes], [[], []]);
});
Deno.test("unchanged cipher has empty fanouts", async () => {
  await using f = await vaultFixture();
  f.inputs.intent.wanted[0].fingerprint = "old";
  f.write({ ...f.read(), ciphers: [cipher()] });
  const { state } = await f.run();
  assertEquals([state.writes, state.deletes], [[], []]);
});
Deno.test("deletes recorded orphans and preserves unrecorded copy", async () => {
  await using f = await vaultFixture();
  f.inputs.intent.wanted = [];
  f.inputs.intent.ledger.push({ ...f.inputs.intent.ledger[0], source: "database-user:8", cipher_id: "second" });
  f.write({ ...f.read(), ciphers: [cipher(), cipher("second", "database-user:8"), cipher("copy")] });
  const { state } = await f.run();
  assertEquals(state.writes, []);
  assertEquals(state.deletes, ["published", "second"]);
  assertEquals(state.ciphers.map((row: { id: string }) => row.id), ["copy"]);
  for (let i = 0; i < 2; i++) await f.repo.cli(["model", "get", `vault-sync-delete-${i}`]);
});
for (const command of ["config", "login", "unlock", "logout", "write"]) {
  Deno.test(`failed ${command} clears cache and emits failure`, async () => {
    await using f = await vaultFixture();
    f.write({ ...f.read(), ...(command === "write" ? { fail_write: true } : { fail_command: command }) });
    const { steps, state } = await f.run(false);
    assertEquals(steps.result.status, "succeeded");
    assertEquals(error(steps), { error_code: "vault.sync_failed" });
    if (command === "write") {
      assert(state.commands.some((row: string[]) => row[0] === "logout"));
      assertFalse(JSON.stringify(state.commands).includes(f.secrets[2]));
    }
  });
}
Deno.test("native resume rebuilds the cleaned workspace", async () => {
  await using f = await vaultFixture();
  f.write({ ...f.read(), fail_write: true });
  const first = await f.run(false);
  f.write({ ...f.read(), fail_write: false });
  const run = JSON.parse(
    (await f.repo.cli(["workflow", "resume", "operation-vault-sync", "--run", first.run.id, "--from", "workspace"]))
      .stdout,
  );
  assertEquals(run.status, "succeeded");
  const steps = stepsOf((await f.repo.history(first.run.id)).history);
  assertFalse(exists(steps.workspace.outputs.stdout.trim()));
});
