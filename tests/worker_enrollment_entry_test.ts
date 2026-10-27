import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { behavior } from "../extensions/behaviors/orbit_gateway.ts";
import { WEBSITE_PROJECT } from "./_support/dependency_projects.ts";

async function enrollmentFixture() {
  const caFile = await Deno.makeTempFile({ prefix: "worker-http-ca-" });
  const data = new Map<string, unknown>();
  const records: Record<string, unknown>[] = [];
  const requests: string[] = [];
  const group = {
    project_id: 57,
    brief: "Detect Horizon upstream parity.\n\nSwamp driver: orbit-gateway" as
      | string
      | undefined,
    status: "running",
    taskable_id: 789 as number | null,
  };
  const settings = {
    repository_url: "https://github.com/nckrtl/horizon-new-dawn.git",
    task_check: "composer format:test && composer analyse && composer test",
    default_branch: "main",
  };
  const calls = { minted: 0, refreshed: 0, failConfiguration: false };
  const server = Deno.serve(
    { hostname: "127.0.0.1", port: 0, onListen() {} },
    async (request) => {
      const path = new URL(request.url).pathname;
      requests.push(`${request.method} ${path}`);
      if (path === "/api/v1/task-groups/456") {
        return Response.json({
          data: { id: 456, orchestration: "external", ...group },
        });
      }
      if (path === `/api/v1/projects/${group.project_id}`) {
        return Response.json({ data: { id: group.project_id, ...settings } });
      }
      if (path === "/api/v1/task-groups/456/worker") {
        assertEquals(request.method, "POST");
        assertEquals(
          request.headers.get("Idempotency-Key"),
          "tg-456.worker.c1",
        );
        const body = await request.json();
        assertEquals(
          body.worker.enrollment_token,
          "task-group-456-1.fixture-secret",
        );
        assertEquals(
          body.worker.server_token,
          "task-group-456-1-access.fixture-secret",
        );
        return Response.json({ data: { id: 99 } }, { status: 202 });
      }
      if (path === "/api/v1/operations/99") {
        return Response.json({
          data: {
            id: 99,
            kind: "tasks.worker",
            target_type: "task_group",
            target_id: 456,
            status: "succeeded",
            result: { started: true },
          },
        });
      }
      throw new Error(`Unexpected worker operation ${request.method} ${path}`);
    },
  );
  const context = {
    globalArgs: behavior.globalArguments.parse({
      gatewayUrl: `http://127.0.0.1:${server.addr.port}`,
      caFile,
    }),
    definition: { name: "orbit-gateway" },
    logger: { info() {} },
    queryData: (predicate: string) => {
      const match = /^modelName == ("[^"]+") && name == ("[^"]+")$/.exec(
        predicate,
      )!;
      const value = data.get(`${JSON.parse(match[1])}:${JSON.parse(match[2])}`);
      return Promise.resolve(value ? [value] : []);
    },
    writeResource: (
      _spec: string,
      name: string,
      value: Record<string, unknown>,
    ) => {
      behavior.resources.worker.schema.parse(value);
      records.push(value);
      data.set(`orbit-gateway:${name}`, value);
      return Promise.resolve({ name });
    },
    runModel: (
      args: {
        name?: string;
        definition?: string;
        modelType?: string;
        method: string;
        arguments?: Record<string, unknown>;
      },
    ) => {
      if (args.method === "configuration") {
        assertEquals(args.modelType, "@nckrtl/project-nckrtl-com");
        calls.refreshed++;
        if (calls.failConfiguration) return Promise.resolve({ ok: false });
        data.set(`${args.name}:configuration`, {
          schema: 3,
          ...WEBSITE_PROJECT,
        });
        return Promise.resolve({ ok: true });
      }
      assertEquals(args.method, "mint");
      assertEquals(
        ["swamp/enrollment-token", "swamp/server-token"].includes(
          args.modelType!,
        ),
        true,
      );
      calls.minted++;
      data.set(`${args.name}:token-main`, {
        state: "active",
        expiresAt: new Date(Date.now() + 600000).toISOString(),
        vaultName: "default",
        secretKey: "fixture",
      });
      return Promise.resolve({ ok: true });
    },
    vaultService: { get: () => Promise.resolve("fixture-secret") },
  };
  return {
    context,
    data,
    records,
    requests,
    group,
    settings,
    calls,
    run: () => behavior.methods.ensure_worker.execute({ group: 456 }, context),
    noEnrollment: () => {
      assertEquals(calls.minted, 0);
      assertEquals(records, []);
      assertEquals(requests.some((r) => r.startsWith("POST")), false);
    },
    configuredProject: () => {
      group.project_id = WEBSITE_PROJECT.projectId;
      settings.repository_url = WEBSITE_PROJECT.repository;
      settings.task_check = WEBSITE_PROJECT.check;
      settings.default_branch = WEBSITE_PROJECT.defaultBranch;
      context.globalArgs.projectExtensions = [{
        projectId: WEBSITE_PROJECT.projectId,
        type: "@nckrtl/project-nckrtl-com",
      }];
    },
    close: async () => {
      await server.shutdown();
      await Deno.remove(caFile);
    },
  };
}

Deno.test("operator enrollment uses shared worker receipts and keyed startup for Horizon Project 57 without task work", async () => {
  const f = await enrollmentFixture();
  try {
    await f.run();
    assertEquals(f.records.at(-1)?.operationId, 99);
    assertEquals(f.records.at(-1)?.ready, false);
    f.data.set("worker-task-group-456-1:state-main", {
      tokenName: "task-group-456-1",
      status: "idle",
    });
    await f.run();
    assertEquals(f.records.at(-1)?.ready, true);
    assertEquals(JSON.stringify(f.records).includes("fixture-secret"), false);
    assertEquals(f.requests.filter((r) => r.startsWith("POST")), [
      "POST /api/v1/task-groups/456/worker",
    ]);
    const previous = f.records.length;
    await assertRejects(f.run, Error, "already enrolled");
    f.group.status = "cancelled";
    await assertRejects(f.run, Error, "open, provisioned external");
    f.group.status = "running";
    f.group.taskable_id = null;
    await assertRejects(f.run, Error, "open, provisioned external");
    assertEquals(f.records.length, previous);
  } finally {
    await f.close();
  }
});

Deno.test("unconfigured Projects cannot mint worker credentials or start a worker", async () => {
  const f = await enrollmentFixture();
  try {
    f.group.project_id = 999;
    await assertRejects(f.run, Error, "Worker Project 999 is not configured");
    f.noEnrollment();
    assertEquals(f.calls.refreshed, 0);
  } finally {
    await f.close();
  }
});

Deno.test("configured project extensions refresh configuration and check Gateway settings before enrollment", async () => {
  const f = await enrollmentFixture();
  try {
    f.configuredProject();
    f.group.status = "reviewing";
    await f.run();
    assertEquals(f.calls.refreshed, 1);
    assertEquals(f.calls.minted, 2);
    assertEquals(f.records.at(-1)?.operationId, 99);
  } finally {
    await f.close();
  }
});

Deno.test("failed configuration refresh cannot admit a configured Project from stale data", async () => {
  const f = await enrollmentFixture();
  try {
    f.configuredProject();
    f.data.set("project-nckrtl-com:configuration", {
      schema: 3,
      ...WEBSITE_PROJECT,
    });
    f.calls.failConfiguration = true;
    await assertRejects(f.run, Error, "cannot read project extension");
    f.noEnrollment();
  } finally {
    await f.close();
  }
});

Deno.test("groups without the driver brief cannot mint worker credentials", async () => {
  const f = await enrollmentFixture();
  try {
    for (const brief of [undefined, "", "   ", "An ordinary task group."]) {
      f.group.brief = brief;
      await assertRejects(f.run, Error, "Swamp driver brief");
      f.noEnrollment();
    }
  } finally {
    await f.close();
  }
});

Deno.test("settling and waiting_for_review groups cannot enroll workers", async () => {
  const f = await enrollmentFixture();
  try {
    for (const status of ["settling", "waiting_for_review"]) {
      f.group.status = status;
      await assertRejects(f.run, Error, "open, provisioned external");
      f.noEnrollment();
    }
  } finally {
    await f.close();
  }
});

Deno.test("Horizon Project settings must match its configured repository before minting", async () => {
  const f = await enrollmentFixture();
  try {
    f.settings.repository_url =
      "https://github.com/nckrtl/horizon-new-dawn-website.git";
    await assertRejects(f.run, Error, "settings differ from config");
    f.noEnrollment();
  } finally {
    await f.close();
  }
});
