/** UpCloud provision on the actual native graphs with disposable SSH, runtime and UpCloud API boundaries. */
import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { HostKey } from "../../../../tests/_support/task_vm_lifecycle.ts";
import { ForeignId, provisionComposition, ProviderId, resultOf, sshTo, stub, upcloudApi } from "../../../../tests/_support/task_vm_provision.ts";

const ForeignRuntime = { task_vm_id: 43, guest_node_id: 142, process_id: 33, ready: true };

async function fixture() {
  const vm = await provisionComposition();
  const cloud = await upcloudApi(vm.repo);
  await sshTo(vm.repo, "task-vm-destroy", { sshBinary: vm.ssh });
  Object.assign(vm.intent, cloud.intent);
  vm.inputs.node_id = 14;
  Object.assign(vm.inputs.execution_secrets, cloud.copies);
  const close = cloud[Symbol.asyncDispose];
  return Object.assign(vm, {
    cloud,
    async [Symbol.asyncDispose]() {
      await close();
      await vm.repo[Symbol.asyncDispose]();
    },
  });
}

Deno.test("a full cloud provision pins public then private SSH and runs no worker", async () => {
  await using vm = await fixture();
  const { history } = await vm.root();
  const result = resultOf(history);
  assert(result.ready);
  assertEquals(result.provider.provider_id, ProviderId);
  assertEquals(result.enrollment.node.ssh_host_key, HostKey.split(" ")[1]);
  const calls = await vm.eventsText();
  for (const present of ["93.184.216.34", "10.44.0.142"]) assert(calls.includes(present), present);
  assert(!calls.includes("worker connect"));
  assert(vm.cloud.exists);
  await vm.repo.assertPrivateAbsent();
});

Deno.test("cloud enrollment composes the actual runtime with only runtime descriptors", async () => {
  await using vm = await fixture();
  const secret = "native-cloud-runtime-" + "fixture-c98dce";
  for (const key of ["task-vm-pi-models-42", "task-vm-pi-token-42"]) {
    await vm.repo.cli(["vault", "put", "orbit-control-inputs", key], { input: secret });
  }
  vm.repo.secrets.push(secret);
  const build = "pinned pi build";
  Object.assign(vm.repo.env, { FIXTURE_HIDE: secret, FIXTURE_BUILD: build });
  Object.assign(vm.intent.runtime, { pi_sha256: "a".repeat(64), origin: "http://192.0.2.6" });
  Object.assign(vm.intent.runtime.process, {
    unit_name: "orbit-process-33.service",
    unit_path: "/etc/systemd/system/orbit-process-33.service",
  });
  // The runtime fetches the build to 209 and checks its digest there before it reaches the guest.
  const digest = await crypto.subtle.digest("SHA-256", new TextEncoder().encode(build));
  vm.intent.pi_artifact.sha256 = Array.from(new Uint8Array(digest), (b) => b.toString(16).padStart(2, "0")).join("");
  const scp = await vm.repo.fakeBinary("scp", `
const destination = Deno.args.at(-1);
if (!destination.includes(":")) Deno.writeTextFileSync(destination, Deno.env.get("FIXTURE_BUILD"));
`);
  await sshTo(vm.repo, "task-vm-runtime", { sshBinary: vm.ssh, scpBinary: scp });
  const { run } = await vm.root();
  assertEquals(run.status, "succeeded");
  assert(vm.cloud.exists);
  await vm.repo.assertPrivateAbsent();
});

Deno.test("a failure after the firewall revokes and deletes the server without a hub or guest restore", async () => {
  await using vm = await fixture();
  await stub(vm.repo, "task-vm-runtime", "result", ForeignRuntime, true);
  assertEquals((await vm.root(false)).run.status, "failed");
  assert(!vm.cloud.exists);
  const calls = await vm.eventsText();
  assert(calls.includes("10.44.0.15"));
  // Rule 22: no rollback chain. The published peer stays for diagnosis; the next hub convergence drops it.
  assert(!calls.includes("restor"));
  assertEquals(vm.cloud.deletes().length, 1);
});

Deno.test("a foreign model revocation refuses the provider delete", async () => {
  await using vm = await fixture();
  await stub(vm.repo, "task-vm-runtime", "result", ForeignRuntime, true);
  await stub(vm.repo, "task-vm-model-revoke", "result", { task_vm_id: 43, revoked: true }, true);
  await vm.root(false);
  assert(vm.cloud.exists);
  assertEquals(vm.cloud.deletes(), []);
});

Deno.test("UpCloud destroy uses the vault API and no Incus, seed or guest call", async () => {
  await using vm = await fixture();
  Object.assign(vm.cloud, { exists: true, state: "started" });
  const { bootstrap: _, pi_token: __, ...secrets } = vm.inputs.execution_secrets;
  const { run, steps } = await vm.repo.runWorkflow("task-vm-destroy", {
    operation_id: 7008,
    node_id: 14,
    kind: "task_vm.destroy",
    intent: {
      task_vm_id: 42,
      guest_node_id: null,
      provider: "upcloud",
      model_key_required: true,
      model_intent: vm.intent.model_intent,
      removal: null,
      provider_input: {
        task_vm_id: 42,
        name: "tvm-42",
        provider: "upcloud",
        provider_id: ProviderId,
        vm: null,
        host_ssh_execution: null,
      },
    },
    execution_secrets: secrets,
  });
  // No receipt step: the run's step statuses are the result, and only the UpCloud branch ran.
  assertEquals(run.status, "succeeded");
  for (const name of ["Revoke the model key", "Read the server", "Stop the server", "Delete the server"]) {
    assertEquals(steps[name], "succeeded", name);
  }
  for (
    const name of ["Delete the VM", "Delete the seed volume", "Remove the guest Node", "Read the host again", "Check the VM is gone"]
  ) assertEquals(steps[name] ?? "skipped", "skipped", name);
  assert(!vm.cloud.exists);
  assert(!await vm.eventsExist());
  assert(vm.cloud.calls.some(([method, path]) => method === "POST" && path.includes("/stop")));
  assertEquals(vm.cloud.deletes().length, 1);
});

Deno.test("a cancel after guest configuration runs provider compensation", async () => {
  await using vm = await fixture();
  await vm.repo.stubWorkflow("task-vm-runtime", "sleep 60");
  assertEquals((await vm.root(false, "--timeout", "25s")).run.status, "cancelled");
  assert(!vm.cloud.exists);
  assert(!vm.guest().some((event) => event.body.includes("def restore(data)")));
});

Deno.test("a rejected replacement UUID is not deleted by prefix compensation", async () => {
  await using vm = await fixture();
  Object.assign(vm.cloud, { exists: true, state: "started" });
  vm.intent.provider_id = ForeignId;
  await vm.root(false);
  assert(vm.cloud.exists);
  assertEquals(vm.cloud.nonGet(), []);
});

Deno.test("a created VM is compensated when prefix image verification fails", async () => {
  await using vm = await fixture();
  vm.cloud.wrongDetailImage = true;
  assertEquals((await vm.root(false)).run.status, "failed");
  assertEquals(vm.cloud.createDocuments.length, 1);
  assert(!vm.cloud.exists);
  assert(!await vm.eventsExist());
  assertEquals(vm.cloud.deletes().length, 1);
});

Deno.test("a prefix failure in a transitional state keeps the unknown UUID without guest actions", async () => {
  await using vm = await fixture();
  vm.cloud.state = "maintenance";
  await vm.root(false);
  // Transitional state cannot be safely powered/deleted. Discovery retains
  // the exact VM for normal native observation/resume, never false success.
  assert(vm.cloud.exists);
  assert(!await vm.eventsExist());
  assertEquals(vm.cloud.deletes(), []);
});
