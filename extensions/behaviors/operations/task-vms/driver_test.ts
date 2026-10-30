import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { createModelTestContext } from "jsr:@swamp-club/swamp-testing@0.20261016.58";
import { FamilyDriver, FamilyWaiting } from "./driver.ts";
import { type Context, ExecutorError } from "../../operation-runner/mod.ts";
import type { Http } from "../../automatic-deploy/gateway.ts";

class Fixture {
  resources = new Map<string, Record<string, unknown>>();
  calls: { method: string; path: string; body: Record<string, unknown> }[] = [];
  status = "queued";
  children: { id: number; key: string; status: string }[] = [];
  loseAdmission = false;
  revoked = false;
  context(): Context {
    const { context } = createModelTestContext({
      globalArgs: {
        gatewayUrl: "https://gateway.example.test",
        caFile: "/unused",
      },
    });
    return Object.assign(context, {
      globalArgs: {
        gatewayUrl: "https://gateway.example.test",
        caFile: "/unused",
      },
      readResource: (name: string) =>
        Promise.resolve(this.resources.get(name) ?? null),
      writeResource: (
        spec: string,
        name: string,
        value: Record<string, unknown>,
      ) => {
        this.resources.set(name, structuredClone(value));
        return Promise.resolve({ name: spec });
      },
    });
  }
  http: Http = (method, path, raw) => {
    const body = (raw ?? {}) as Record<string, unknown>;
    this.calls.push({ method, path, body });
    if (method === "GET" && path.endsWith("/children")) {
      return Promise.resolve({ status: 200, body: { data: this.children } });
    }
    if (path.endsWith("/children")) {
      if (this.loseAdmission) {
        this.loseAdmission = false;
        return Promise.reject(new Error("lost admission response"));
      }
      return Promise.resolve({
        status: 200,
        body: { data: { id: 56, status: this.status } },
      });
    }
    if (path.includes("operations/56")) {
      return Promise.resolve({
        status: 200,
        body: {
          data: {
            id: 56,
            status: this.status,
            result: { ready: true },
            finished_at: "2026-10-28T12:00:00Z",
          },
        },
      });
    }
    return Promise.resolve({ status: 200, body: { data: {} } });
  };
  driver() {
    return new FamilyDriver(
      7,
      "parent-worker",
      4_000_000_000,
      this.context(),
      this.http,
      "task_vm.job_failed",
      () => {
        if (this.revoked) throw new ExecutorError("operation.lease_lost");
      },
    );
  }
}
Deno.test("child admission sends only the server-built key and yields without claiming or executing children", async () => {
  const fixture = new Fixture();
  await assertRejects(
    () =>
      fixture.driver().child("provider-create", "task_vm.incus_create", 3, {
        untrusted: "ignored",
      }),
    FamilyWaiting,
  );
  assertEquals(
    fixture.calls.find((call) => call.path.endsWith("/children"))!.body,
    { worker: "parent-worker", key: "provider-create" },
  );
  assertEquals(fixture.calls.at(-1)!.body, {
    worker: "parent-worker",
    keys: ["provider-create"],
  });
  assertEquals(
    fixture.calls.some((call) =>
      call.path.endsWith("/claim") || call.path.endsWith("/run")
    ),
    false,
  );
  fixture.status = "succeeded";
  assertEquals(
    await fixture.driver().child(
      "provider-create",
      "task_vm.incus_create",
      3,
      {},
    ),
    { ready: true, effect_finished_at: "2026-10-28T12:00:00Z" },
  );
  assertEquals(
    fixture.calls.filter((call) => call.path.endsWith("/children")).length,
    1,
  );
});
Deno.test("a lost child admission reply retries the same key after a process restart", async () => {
  const fixture = new Fixture();
  fixture.loseAdmission = true;
  await assertRejects(
    () => fixture.driver().child("runtime", "task_vm.prepare_runtime", 3, {}),
    ExecutorError,
    "operation.outcome_unknown",
  );
  fixture.status = "succeeded";
  await fixture.driver().child("runtime", "task_vm.prepare_runtime", 3, {
    different: "ignored",
  });
  assertEquals(
    fixture.calls.filter((call) => call.path.endsWith("/children")).map((
      call,
    ) => call.body),
    [
      { worker: "parent-worker", key: "runtime" },
      { worker: "parent-worker", key: "runtime" },
    ],
  );
});
Deno.test("durable stage checkpoints skip observations of a builder already removed", async () => {
  const fixture = new Fixture();
  let calls = 0;
  await fixture.driver().stage("boot", () => {
    calls++;
    return Promise.resolve({ ready: true });
  });
  await fixture.driver().stage("boot", () => {
    throw new Error("The builder is already removed");
  });
  assertEquals(calls, 1);
});
Deno.test("parent lease loss prevents another child admission or stage", async () => {
  const fixture = new Fixture();
  fixture.revoked = true;
  await assertRejects(
    () =>
      fixture.driver().child("provider-delete", "task_vm.incus_delete", 3, {}),
    ExecutorError,
    "operation.lease_lost",
  );
  assertEquals(fixture.calls, []);
});

Deno.test("parent cancellation first settles every admitted child", async () => {
  const fixture = new Fixture();
  fixture.children = [{ id: 56, key: "provider-create", status: "running" }, {
    id: 57,
    key: "earlier",
    status: "succeeded",
  }];
  await assertRejects(() => fixture.driver().cancelChildren(), FamilyWaiting);
  assertEquals(
    fixture.calls.filter((call) => call.path.endsWith("/cancel")).map((call) =>
      call.path
    ),
    ["/api/v1/operations/56/cancel"],
  );
  assertEquals(fixture.calls.at(-1)!.body, {
    worker: "parent-worker",
    keys: ["provider-create"],
  });
});
Deno.test("a progressing child outlives the parent's observation budget without cancellation", async () => {
  const fixture = new Fixture();
  fixture.resources.set("child-7-node-enrollment", { id: 56 });
  const driver = () =>
    new FamilyDriver(7, "parent-worker", 1, fixture.context(), fixture.http);
  for (const status of ["queued", "running", "waiting"]) {
    fixture.status = status;
    await assertRejects(
      () => driver().child("node-enrollment", "node.add", 3, {}),
      FamilyWaiting,
    );
  }
  assertEquals(
    fixture.calls.some((call) => call.path.endsWith("/cancel")),
    false,
  );
  fixture.status = "succeeded";
  assertEquals(await driver().child("node-enrollment", "node.add", 3, {}), {
    ready: true,
    effect_finished_at: "2026-10-28T12:00:00Z",
  });
});

Deno.test("later child admission is allowed after the parent's observation budget elapsed", async () => {
  const fixture = new Fixture();
  const driver = new FamilyDriver(
    7,
    "parent-worker",
    1,
    fixture.context(),
    fixture.http,
  );
  await assertRejects(
    () => driver.child("runtime", "task_vm.prepare_runtime", 3, {}),
    FamilyWaiting,
  );
  assertEquals(
    fixture.calls.find((call) => call.path.endsWith("/children"))?.body,
    {
      worker: "parent-worker",
      key: "runtime",
    },
  );
  assertEquals(
    fixture.calls.some((call) => call.path.endsWith("/cancel")),
    false,
  );
  await assertRejects(
    () => driver.wait(),
    ExecutorError,
    "task_vm.runtime_timeout",
  );
});

Deno.test("an expired parent preserves its child's terminal failure instead of replacing it with a timeout", async () => {
  const fixture = new Fixture();
  fixture.resources.set("child-7-node-enrollment", { id: 56 });
  fixture.status = "cancelled";
  const driver = new FamilyDriver(
    7,
    "parent-worker",
    1,
    fixture.context(),
    fixture.http,
  );
  await assertRejects(
    () => driver.child("node-enrollment", "node.add", 3, {}),
    ExecutorError,
    "operation.cancelled",
  );
});
