/** Actual official graphs and HTTP requests against a disposable UpCloud API; see `upcloudApi`. */
import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { nativeRepo } from "../../../../tests/_support/native_harness.ts";
import { HostKey } from "../../../../tests/_support/task_vm_lifecycle.ts";
import { ProviderId, upcloudApi } from "../../../../tests/_support/task_vm_provision.ts";

async function fixture() {
  const repo = await nativeRepo();
  repo.timeoutMs = 120_000;
  await repo.copyBehaviors("operations/task-vm-lifecycle");
  const cloud = await upcloudApi(repo);
  const close = cloud[Symbol.asyncDispose];
  const power = (operation: number, providerId: string | null) => (action: string, success = true) =>
    repo.runWorkflow("task-vm-provider-power", {
      operation_id: operation,
      node_id: 14,
      kind: "task_vm.destroy",
      intent: { task_vm_id: 42, name: "tvm-42", provider: "upcloud", provider_id: providerId, vm: null, host_ssh_execution: null },
      execution_secrets: { upcloud: cloud.copies.upcloud },
      action,
    }, { success }).then(({ run }) => run.status);
  return Object.assign(cloud, {
    repo,
    create: (success = true) =>
      repo.runWorkflow("task-vm-provider-upcloud", {
        operation_id: 7005,
        node_id: 14,
        kind: "tasks.provision",
        intent: cloud.intent,
        execution_secrets: cloud.copies,
      }, { success }),
    discover: power(7006, null),
    power: power(7007, ProviderId),
    async [Symbol.asyncDispose]() {
      await close();
      await repo[Symbol.asyncDispose]();
    },
  });
}

Deno.test("create and lost-response observation never duplicate and never expose bootstrap", async () => {
  await using cloud = await fixture();
  cloud.lostCreate = true;
  const { run, history } = await cloud.create();
  assertEquals(run.status, "succeeded");
  assertEquals(history.inputs.kind, "tasks.provision");
  const result = history.jobs[0].steps.at(-1)!.outputs!;
  assertEquals(result.provider_id, ProviderId);
  assertEquals(result.ssh_host_key, HostKey);
  assertEquals(cloud.createDocuments.length, 1);
  const config = JSON.parse((cloud.createDocuments[0].server.user_data as string).replace(/^#cloud-config\n/, ""));
  assertEquals(config.ssh_keys.ed25519_private, cloud.privateKey);
  assertEquals(config.users[0].ssh_authorized_keys, [HostKey]);
  await cloud.create();
  assertEquals(cloud.createDocuments.length, 1);
  await cloud.repo.assertPrivateAbsent();
});

for (
  const [name, flags] of [
    ["a foreign label refuses before create or power", { foreign: true }],
    ["a foreign detail UUID never reaches start or ready", { wrongDetailUuid: true }],
  ] as const
) {
  Deno.test(name, async () => {
    await using cloud = await fixture();
    Object.assign(cloud, { exists: true, ...flags });
    assertEquals((await cloud.create(false)).run.status, "failed");
    assertEquals(cloud.nonGet(), []);
  });
}

Deno.test("a label-less list is verified by detail before start", async () => {
  await using cloud = await fixture();
  Object.assign(cloud, { exists: true, omitListLabels: true });
  assertEquals((await cloud.create()).run.status, "succeeded");
  assertEquals(cloud.createDocuments, []);
  assertEquals(cloud.state, "started");
});

Deno.test("unknown UUID discovery, stop, delete and absence are native steps", async () => {
  await using cloud = await fixture();
  Object.assign(cloud, { exists: true, state: "started", omitListLabels: true });
  for (const action of ["inspect", "stop", "delete", "delete"]) assertEquals(await cloud.discover(action), "succeeded");
  assert(!cloud.exists);
  assertEquals(cloud.deletes().length, 1);
  assert(cloud.deletes()[0].includes("storages=1&backups=delete"));
  await cloud.repo.assertPrivateAbsent();
});

Deno.test("a failed detail read is never absence", async () => {
  await using cloud = await fixture();
  cloud.exists = true;
  for (const status of [500, 403]) {
    cloud.detailStatus = status;
    assertEquals(await cloud.power("delete", false), "failed");
  }
  assert(cloud.exists);
  assertEquals(cloud.nonGet(), []);
  cloud.detailStatus = null;
  assertEquals(await cloud.power("delete"), "succeeded");
  assert(!cloud.exists);
});

Deno.test("a known UUID soft-stops, then reads 404 as deleted", async () => {
  await using cloud = await fixture();
  Object.assign(cloud, { exists: true, state: "started" });
  assertEquals(await cloud.power("stop"), "succeeded");
  assertEquals(cloud.state, "stopped");
  assert(cloud.calls.some(([method, path]) => method === "POST" && path === `/1.3/server/${ProviderId}/stop`));
  assertEquals(await cloud.power("delete"), "succeeded");
  assertEquals(cloud.deletes(), [`/1.3/server/${ProviderId}?storages=1&backups=delete`]);
  assertEquals(await cloud.power("inspect"), "succeeded");
  await cloud.repo.assertPrivateAbsent();
});
