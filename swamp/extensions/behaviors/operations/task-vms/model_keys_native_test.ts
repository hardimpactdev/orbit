import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import type { Http } from "../../automatic-deploy/gateway.ts";
import { taskVmModelKeys } from "./model_keys_native.ts";

const key = "a".repeat(64),
  anchor = "b".repeat(64),
  management = "private-wrapper-management";
const run = "11111111-1111-4111-8111-111111111111";
type Kind = Parameters<
  ReturnType<typeof taskVmModelKeys>["methods"]["issue"]["execute"]
>[0]["kind"];
function fixture(kind: Kind = "task_vm.enroll") {
  const input = {
    task_vm_id: 71,
    model_router_node_id: 17,
    gateway_node_id: 14,
    model_origin: "http://models.orbit:8317",
  };
  const operation = {
    id: 7,
    kind,
    node_id: 9,
    status: "running",
    cancel_requested: false,
    input,
  };
  const paths: string[] = [], requests: string[] = [];
  const proofs: unknown[] = [], writes: unknown[] = [];
  let keys = kind === "task_vm.destroy" ? [anchor, key] : [anchor];
  let reads = 0, changeAtRead = 0, privateError = false, identityError = false;
  const http: Http = (_method, path) => {
    paths.push(path);
    if (path.includes("/private-material")) {
      if (privateError) return Promise.reject(new Error(management));
      return Promise.resolve({
        status: 200,
        body: {
          data: {
            model_key: key,
            model_router: { management_key: management, anchor_key: anchor },
          },
        },
      });
    }
    reads++;
    if (reads === changeAtRead) {
      input.model_origin = "http://different.orbit:8317";
    }
    return Promise.resolve({
      status: 200,
      body: { data: structuredClone(operation) },
    });
  };
  const request: typeof fetch = (url, options = {}) => {
    requests.push(String(url));
    const token = new Headers(options.headers).get("Authorization")?.slice(7);
    if (String(url).endsWith("/v0/management/api-keys")) {
      assertEquals(token, management);
      if (options.method === "PUT") keys = JSON.parse(String(options.body));
      if (options.method === "PATCH") {
        const body = JSON.parse(String(options.body));
        const index = keys.indexOf(body.old);
        if (index === -1) keys.push(body.new);
        else keys[index] = body.new;
      }
      return Promise.resolve(Response.json({ "api-keys": keys }));
    }
    return Promise.resolve(
      new Response("", { status: token && keys.includes(token) ? 200 : 401 }),
    );
  };
  const behavior = taskVmModelKeys(() => http, (proof) => {
    proofs.push(proof);
    if (identityError) return Promise.reject(new Error(management));
    return Promise.resolve({
      ...proof,
      hostname: "gateway",
      effective_user: "orbit",
      sudo_ready: true,
    });
  }, request);
  const args = { operation_id: 7, kind, node_id: 9, run_id: run };
  const ctx = {
    globalArgs: { gatewayUrl: "https://gateway.orbit", caFile: "/test/ca" },
    definition: { name: "task-vm-model-router-17" },
    writeResource: (spec: string, name: string, value: unknown) => {
      writes.push({ spec, name, value });
      return Promise.resolve({ name });
    },
  };
  return {
    args,
    ctx,
    behavior,
    operation,
    paths,
    proofs,
    writes,
    requests,
    change: (value: number) => changeAtRead = value,
    privateError: () => privateError = true,
    identityError: () => identityError = true,
  };
}

Deno.test("shared model-key behavior issues for every admitted Task VM parent without another Operation", async () => {
  for (
    const kind of [
      "task_vm.enroll",
      "task_vm.prepare_runtime",
      "task_vm.provision",
      "tasks.provision",
    ] as const
  ) {
    const f = fixture(kind);
    await f.behavior.methods.issue.execute(f.args, f.ctx);
    assertEquals(f.proofs, [{ node_id: 14, user: "orbit" }]);
    assertEquals(f.writes, [{
      spec: "result",
      name: "issue-" + run,
      value: { task_vm_id: 71, issued: true },
    }]);
    assertEquals(
      f.paths.every((path) => path.startsWith("/api/v1/direct-operations/7")),
      true,
    );
    assertEquals(
      f.paths.some((path) => path.includes("/private-material?run_id=" + run)),
      true,
    );
    assertEquals(
      f.paths.some((path) =>
        path.includes("/result") || path.includes("children")
      ),
      false,
    );
    for (const secret of [key, anchor, management]) {
      assertEquals(JSON.stringify(f.writes).includes(secret), false);
    }
  }
});

Deno.test("destroy revocation uses the same stable behavior and private parent binding", async () => {
  const f = fixture("task_vm.destroy");
  await f.behavior.methods.revoke.execute(f.args, f.ctx);
  assertEquals(f.writes, [{
    spec: "result",
    name: "revoke-" + run,
    value: { task_vm_id: 71, revoked: true },
  }]);
  assertEquals(f.proofs, [{ node_id: 14, user: "orbit" }]);
});

Deno.test("a per-run or wrong-router behavior name cannot read private keys", async () => {
  for (const name of ["task-vm-enrollment-" + run, "task-vm-model-router-18"]) {
    const f = fixture();
    f.ctx.definition.name = name;
    await assertRejects(
      () => f.behavior.methods.issue.execute(f.args, f.ctx),
      Error,
      "task_vm.model_key_failed",
    );
    assertEquals(
      f.paths.some((path) => path.includes("private-material")),
      false,
    );
    assertEquals(f.requests, []);
  }
});

Deno.test("wrong worker identity refuses before private material or router requests", async () => {
  const f = fixture();
  f.identityError();
  await assertRejects(
    () => f.behavior.methods.issue.execute(f.args, f.ctx),
    Error,
    "task_vm.model_key_failed",
  );
  assertEquals(
    f.paths.some((path) => path.includes("private-material")),
    false,
  );
  assertEquals(f.requests, []);
});

Deno.test("changed admitted origin refuses before the first router request", async () => {
  const f = fixture();
  f.change(3);
  await assertRejects(
    () => f.behavior.methods.issue.execute(f.args, f.ctx),
    Error,
    "task_vm.model_key_failed",
  );
  assertEquals(f.requests, []);
  assertEquals(f.writes, []);
});

Deno.test("cancelled or terminal parents cannot issue or revoke", async () => {
  for (const status of ["running", "failed", "cancelled", "succeeded"]) {
    const f = fixture();
    f.operation.status = status;
    f.operation.cancel_requested = status === "running";
    await assertRejects(
      () => f.behavior.methods.issue.execute(f.args, f.ctx),
      Error,
      "task_vm.model_key_failed",
    );
    assertEquals(
      f.paths.some((path) => path.includes("private-material")),
      false,
    );
    assertEquals(f.requests, []);
  }
});

Deno.test("method and parent kind must match before any private read", async () => {
  const f = fixture();
  await assertRejects(
    () => f.behavior.methods.revoke.execute(f.args, f.ctx),
    Error,
    "task_vm.model_key_failed",
  );
  const d = fixture("task_vm.destroy");
  await assertRejects(
    () => d.behavior.methods.issue.execute(d.args, d.ctx),
    Error,
    "task_vm.model_key_failed",
  );
  assertEquals(f.paths, []);
  assertEquals(d.paths, []);
});

Deno.test("private material failures produce no resources and reveal only the fixed code", async () => {
  const f = fixture();
  f.privateError();
  const error = await assertRejects(() =>
    f.behavior.methods.issue.execute(f.args, f.ctx)
  );
  assertEquals(
    error instanceof Error && error.message,
    "task_vm.model_key_failed",
  );
  assertEquals(f.writes, []);
  assertEquals(f.requests, []);
});
