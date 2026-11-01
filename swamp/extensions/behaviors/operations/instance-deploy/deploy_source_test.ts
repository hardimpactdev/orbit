import { assertEquals } from "jsr:@std/assert@1.0.19";
import { type LocalCall, localFixture } from "./deploy_test_fixture.ts";
import { DeploySourceInput, prepareDeploySource } from "./deploy_source.ts";

const commit = "a".repeat(40);
function fixture() {
  const input = DeploySourceInput.parse({
    instance_id: 12,
    project_id: 9,
    agent_sha256: "c".repeat(64),
    commit,
    deployment: {
      variant: "development",
      home: "/home/orbit/apps/default",
      repository: "https://github.com/example/project.git",
      branch: "main",
      node_user: "orbit",
      protocols: ["https"],
    },
  });
  const calls: LocalCall[] = [];
  const port = {
    dispatch: (call: LocalCall): Promise<Record<string, unknown>> => {
      calls.push(call);
      return Promise.resolve({ commit });
    },
    git: async (
      action: (git: { github_token: string | null }) => Promise<void>,
    ) => {
      await action({ github_token: "private-read-token" });
    },
    current: () => Promise.resolve({ cancel_requested: false }),
    now: () => 1000,
  };
  const local = localFixture((call) => port.dispatch(call));
  return {
    input,
    calls,
    port: {
      ...port,
      local,
      get dispatch() {
        return port.dispatch;
      },
      set dispatch(value) {
        port.dispatch = value;
      },
    },
  };
}

function productionFixture() {
  const f = fixture();
  const input = DeploySourceInput.parse({
    ...f.input,
    release_name: "admitted",
    deployment: {
      ...f.input.deployment,
      variant: "production",
      home: "/home/orbit-app-9",
      user: "orbit-app-9",
      relative_root: "server/web/public",
      application_suffix: "server/web",
      served: [{ web_root: "apps/docs/public", directory: "apps/docs" }],
      shared_paths: ["storage"],
    },
  });
  const previous = { name: "previous", commit: "b".repeat(40) };
  f.port.dispatch = (call) => {
    f.calls.push(call);
    return Promise.resolve(
      call.name === "inspectRelease"
        ? { release: previous }
        : { name: "admitted", commit },
    );
  };
  return { ...f, input, previous };
}

Deno.test("production source inspects previous selection then prepares admitted release under app identity", async () => {
  const f = productionFixture();
  const result = await prepareDeploySource(f.input, 901000, f.port);
  assertEquals(result, {
    commit,
    release: { name: "admitted", commit },
    previous: f.previous,
  });
  assertEquals(f.calls.map((call) => call.name), [
    "inspectRelease",
    "prepareRelease",
  ]);
  assertEquals(f.calls[0].mutates, false);
  assertEquals(f.calls[1].mutates, true);
  assertEquals(f.calls[1].input.user, "orbit-app-9");
  assertEquals(f.calls[1].input.name, "admitted");
  assertEquals(f.calls[1].input.shared_paths, ["storage"]);
  assertEquals(f.calls[1].input.served, f.input.deployment.served);
  assertEquals(JSON.stringify(result).includes("private-read-token"), false);
});

Deno.test("production source refuses changed ownership or missing release identity before local work", async () => {
  for (const fault of ["user", "home", "name", "root"] as const) {
    const f = productionFixture();
    if (fault === "user") f.input.deployment.user = "nckrtl";
    if (fault === "home") f.input.deployment.home = "/home/foreign";
    if (fault === "name") f.input.release_name = undefined;
    if (fault === "root") f.input.deployment.relative_root = undefined;
    assertEquals(await prepareDeploySource(f.input, 901000, f.port), {
      error_code: fault === "name"
        ? "deployment.interrupted"
        : "deployment.identity_changed",
    });
    assertEquals(f.calls, []);
  }
});

Deno.test("production source cancellation after inspection prevents preparation", async () => {
  const f = productionFixture();
  f.port.current = () =>
    Promise.resolve({ cancel_requested: f.calls.length > 0 });
  assertEquals(await prepareDeploySource(f.input, 901000, f.port), {
    error_code: "operation.cancelled",
  });
  assertEquals(f.calls.map((call) => call.name), ["inspectRelease"]);
});

Deno.test("production source retains uncertainty and prevents repeated private delivery effects", async () => {
  const f = productionFixture();
  f.port.dispatch = (call) => {
    f.calls.push(call);
    if (call.mutates) throw new Error("private diagnostic");
    return Promise.resolve({ release: f.previous });
  };
  f.port.git = async (use) => {
    await use({ github_token: "private-read-token" });
    await use({ github_token: "private-read-token" });
  };
  assertEquals(await prepareDeploySource(f.input, 901000, f.port), {
    error_code: "deployment.execution_unconfirmed",
  });
  assertEquals(f.calls.length, 2);
});

Deno.test("production source rejects wrong prepared name and never performs rollback Git work", async () => {
  const f = productionFixture();
  f.port.dispatch = (call) => {
    f.calls.push(call);
    return Promise.resolve(
      call.mutates ? { name: "foreign", commit } : { release: null },
    );
  };
  assertEquals(await prepareDeploySource(f.input, 901000, f.port), {
    error_code: "deployment.identity_changed",
  });
  f.calls.length = 0;
  f.input.deployment.variant = "rollback";
  assertEquals(await prepareDeploySource(f.input, 901000, f.port), {
    error_code: "deployment.identity_changed",
  });
  assertEquals(f.calls, []);
});
