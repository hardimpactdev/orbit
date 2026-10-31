import { assertEquals } from "jsr:@std/assert@1.0.19";
import type { NodeCall } from "../_lib/node-agent.ts";
import { DeploySourceInput, prepareDeploySource } from "./deploy_source.ts";

const commit = "a".repeat(40),
  previous = { name: "previous", commit: "b".repeat(40) };
function fixture(development = false) {
  const input = DeploySourceInput.parse({
    instance_id: 12,
    project_id: 9,
    agent_sha256: "c".repeat(64),
    commit,
    release_name: "20261030200000-op-00000000000000000044",
    deployment: {
      variant: development ? "development" : "production",
      home: development ? "/home/orbit/apps/default" : "/home/orbit-app-9",
      repository: "https://github.com/example/project.git",
      branch: "main",
      node_user: "orbit",
      protocols: ["https"],
      user: development ? null : "orbit-app-9",
      relative_root: development ? null : "public",
      application_suffix: development ? null : "",
      served: [],
      shared_paths: development ? [] : ["storage"],
    },
  });
  const calls: NodeCall[] = [];
  const port = {
    invoke: (call: NodeCall) => {
      calls.push(call);
      return Promise.resolve(
        call.name === "instance.inspect-release"
          ? { release: previous }
          : call.name === "instance.prepare-release"
          ? { name: input.release_name, commit }
          : { commit },
      );
    },
    git: async (
      action: (git: { github_token: string | null }) => Promise<void>,
    ) => {
      await action({ github_token: "private-read-token" });
    },
    current: () => Promise.resolve({ cancel_requested: false }),
    now: () => 1000,
  };
  return { input, calls, port };
}

Deno.test("native production source inspects selection then prepares only the bound release", async () => {
  const f = fixture();
  const result = await prepareDeploySource(f.input, 901000, f.port);
  assertEquals(result, {
    commit,
    release: { name: f.input.release_name, commit },
    previous,
  });
  assertEquals(f.calls.map((x) => x.name), [
    "instance.inspect-release",
    "instance.prepare-release",
  ]);
  assertEquals(
    f.calls.every((x) =>
      x.sudo && x.pin === f.input.agent_sha256 && x.deadlineMs === 900000
    ),
    true,
  );
  assertEquals(f.calls[1].input.shared_paths, ["storage"]);
  assertEquals(JSON.stringify(result).includes("private-read-token"), false);
});

Deno.test("native development source resolves admitted pin then checks out the same commit", async () => {
  const f = fixture(true);
  assertEquals(await prepareDeploySource(f.input, 901000, f.port), {
    commit,
    release: null,
    previous: null,
  });
  assertEquals(f.calls.map((x) => x.name), [
    "instance.resolve-development-target",
    "instance.checkout-development-default",
  ]);
  assertEquals(f.calls[0].input.protocols, ["https"]);
  assertEquals(f.calls[1].input.commit, commit);
  assertEquals(f.calls[1].input.git_read, undefined);
});

Deno.test("source cancellation and deadline expiry stop before local effects", async () => {
  for (const cancel_requested of [true, false]) {
    const f = fixture(true);
    const result = await prepareDeploySource(
      f.input,
      cancel_requested ? 901000 : 1000,
      {
        ...f.port,
        current: () => Promise.resolve({ cancel_requested }),
      },
    );
    assertEquals(result, {
      error_code: cancel_requested
        ? "operation.cancelled"
        : "deployment.deadline_exceeded",
    });
    assertEquals(f.calls, []);
  }
});

Deno.test("changed production account and home refuse before any source action", async () => {
  for (const field of ["user", "home"] as const) {
    const f = fixture();
    f.input.deployment[field] = field === "user"
      ? "orbit-app-999"
      : "/home/foreign";
    assertEquals(await prepareDeploySource(f.input, 901000, f.port), {
      error_code: "deployment.identity_changed",
    });
    assertEquals(f.calls, []);
  }
});

Deno.test("a mismatched pinned source never reaches checkout or returns a successful release", async () => {
  const f = fixture(true);
  f.port.invoke = (call) => {
    f.calls.push(call);
    return Promise.resolve({ commit: "d".repeat(40) });
  };
  assertEquals(await prepareDeploySource(f.input, 901000, f.port), {
    error_code: "deployment.commit_not_on_branch",
  });
  assertEquals(f.calls.length, 1);
});

Deno.test("cancellation after source fetch prevents checkout", async () => {
  const f = fixture(true);
  f.port.current = () =>
    Promise.resolve({ cancel_requested: f.calls.length > 0 });
  assertEquals(await prepareDeploySource(f.input, 901000, f.port), {
    error_code: "operation.cancelled",
  });
  assertEquals(f.calls.map((x) => x.name), [
    "instance.resolve-development-target",
  ]);
});

Deno.test("private material and native diagnostics cannot become source results", async () => {
  const f = fixture();
  assertEquals(
    await prepareDeploySource(f.input, 901000, {
      ...f.port,
      git: () => {
        throw new Error("private-read-token");
      },
    }),
    { error_code: "deployment.interrupted" },
  );
  assertEquals(
    await prepareDeploySource(f.input, 901000, {
      ...f.port,
      invoke: () => {
        throw new Error("private native diagnostic");
      },
    }),
    { error_code: "deployment.interrupted" },
  );
});

Deno.test("private Git callback replay cannot prepare the release twice", async () => {
  const f = fixture();
  f.port.git = async (action) => {
    await action({ github_token: "private-read-token" });
    await action({ github_token: "private-read-token" });
  };
  assertEquals(await prepareDeploySource(f.input, 901000, f.port), {
    commit,
    release: { name: f.input.release_name, commit },
    previous,
  });
  assertEquals(f.calls.map((x) => x.name), [
    "instance.inspect-release",
    "instance.prepare-release",
  ]);
});

Deno.test("a private delivery failure preserves the confirmed local source result", async () => {
  const f = fixture(true);
  f.port.git = async (action) => {
    await action({ github_token: "private-read-token" });
    throw new Error("private delivery failed after local preparation");
  };
  assertEquals(await prepareDeploySource(f.input, 901000, f.port), {
    commit,
    release: null,
    previous: null,
  });
  assertEquals(f.calls.map((x) => x.name), [
    "instance.resolve-development-target",
    "instance.checkout-development-default",
  ]);
});

Deno.test("suppressed local uncertainty cannot be replayed into success", async () => {
  const f = fixture(true);
  f.port.invoke = (call) => {
    f.calls.push(call);
    if (f.calls.length === 1) throw new Error("private native uncertainty");
    return Promise.resolve({ commit });
  };
  f.port.git = async (action) => {
    try {
      await action({ github_token: "private-read-token" });
    } catch { /* The transport does not decide the local outcome. */ }
    await action({ github_token: "private-read-token" });
  };
  assertEquals(await prepareDeploySource(f.input, 901000, f.port), {
    error_code: "deployment.interrupted",
  });
  assertEquals(f.calls.length, 1);
});

Deno.test("a private callback retained after delivery closes cannot start source work", async () => {
  const f = fixture(true);
  let retained:
    | ((git: { github_token: string | null }) => Promise<void>)
    | undefined;
  f.port.git = (action) => {
    retained = action;
    return Promise.resolve();
  };
  assertEquals(await prepareDeploySource(f.input, 901000, f.port), {
    error_code: "deployment.interrupted",
  });
  await retained!({ github_token: "private-read-token" });
  assertEquals(f.calls, []);
});
