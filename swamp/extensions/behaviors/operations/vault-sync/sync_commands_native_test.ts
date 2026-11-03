import { assert, assertEquals, assertFalse } from "jsr:@std/assert@1.0.19";
import { parse, stringify } from "jsr:@std/yaml@1.0.12";
import { cipher, vaultFixture } from "../../../../tests/_support/vault_fixture.ts";
import { exists, stepsOf } from "../../../../tests/_support/native_history.ts";

Deno.test("failed write skips receipts; workspace resume rebuilds; summary resume restores twelve receipts", async () => {
  await using f = await vaultFixture();
  const { repo, inputs, privateInput } = f;
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
  // The recorded item is missing; an unrecorded marker copy must stay untouched.
  inputs.intent.ledger = [
    { ...inputs.intent.ledger[0], source: "database-user:1" },
  ];
  await f.put();
  f.write({ ...f.read(), fail_write: true, fail_command: "logout", ciphers: [
    cipher("copy", "database-user:1"),
  ] });
  await repo.cli(["workflow", "validate", "operation-vault-sync"]);
  const first = await f.run(false);
  assertEquals(first.steps["Apply cipher-0"].status, "failed");
  assertEquals(first.steps["write-0"].status, "skipped");
  assertEquals(JSON.parse(first.steps.result.outputs.stdout), { error_code: "vault.sync_failed" });
  assertEquals(first.steps.logout.status, "failed");
  assertEquals(first.steps["remove-cache"].status, "succeeded");
  assert(first.state.commands.some((row: string[]) => row[0] === "logout"));
  assertFalse(JSON.stringify(first.state.commands).includes(f.secrets[2]));
  assertEquals(first.state.deletes, []);
  assertEquals(first.state.ciphers.map((row: { id: string }) => row.id), ["copy"]);

  // Rebuild the removed workspace, then fail only the summary input so the
  // final resume must use retained receipts without repeating CLI mutations.
  const path = `${repo.dir}/workflows/workflow-operation-vault-sync.yaml`;
  const original = await Deno.readTextFile(path);
  // deno-lint-ignore no-explicit-any
  const workflow = parse(original) as any;
  workflow.jobs.at(-1).steps[0].task.inputs.writes = ["invalid-json"];
  await Deno.writeTextFile(path, stringify(workflow));
  f.write({ ...f.read(), fail_write: false, fail_command: "", writes: [] });
  await repo.cli(["workflow", "resume", "operation-vault-sync", "--run", first.run.id, "--from", "workspace"], { success: false });
  const rebuilt = stepsOf((await repo.history(first.run.id)).history);
  assertEquals(rebuilt.result.status, "failed");
  assertEquals(rebuilt["write-11"].status, "succeeded");
  assertFalse(exists(rebuilt.workspace.outputs.stdout.trim()));
  assertEquals(f.read().writes.length, 12);
  assertEquals(f.read().writes.map((row: { id: string }) => row.id), Array(12).fill("new-cipher"));

  await Deno.writeTextFile(path, original);
  const resumed = JSON.parse((await repo.cli([
    "workflow", "resume", "operation-vault-sync", "--run", first.run.id, "--from", "result",
  ])).stdout);
  assertEquals(resumed.status, "succeeded");
  const steps = stepsOf((await repo.history(first.run.id)).history);
  assertEquals(JSON.parse(steps.result.outputs.stdout).data.sources.toSorted(), inputs.intent.wanted.map((row: { source: string }) => row.source).toSorted());
  assertEquals(f.read().writes.length, 12);
});
