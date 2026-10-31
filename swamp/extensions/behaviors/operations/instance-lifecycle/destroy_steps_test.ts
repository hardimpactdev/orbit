import { assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { GatewayOperation } from "../_lib/direct.ts";
import { sha256 } from "../instance-deploy/app_step.ts";
import { runDestroyLocalStep } from "./destroy_steps.ts";
import { fixture as localFixture } from "./fixtures/destroy.ts";

function fixture() {
  const local = localFixture();
  const args = {
    operation_id: 71,
    kind: "instance.destroy",
    node_id: 9,
    run_id: "f9d20790-a796-4cbb-b01b-742068490315",
  };
  const current = {
    id: args.operation_id,
    kind: args.kind,
    node_id: args.node_id,
    status: "running",
    cancel_requested: false,
    input: {
      instance_id: 1,
      project_id: 12,
      node_id: 9,
      environment: "development",
      direct_destroy: {
        ...local,
        node_user: "orbit",
        teardown: local.members.map((member) => ({
          instance_id: member.instance_id,
          checkout: member.source.checkout,
          user: "orbit",
          steps: [] as {
            name: string;
            sha256: string;
            timeout_seconds: number;
          }[],
        })),
      },
    },
  };
  const calls: string[] = [];
  const effects: string[] = [];
  const material = {
    git_read: { token: "private-git-sentinel-7621" },
    teardown: { members: [] as Record<string, unknown>[] },
  };
  let beforeRead = () => {};
  const gateway = new GatewayOperation((method, path) => {
    calls.push(`${method} ${path}`);
    assertEquals(method, "GET");
    const privateRead = path.includes("/private-material?");
    if (!privateRead) beforeRead();
    return Promise.resolve({
      status: 200,
      body: { data: privateRead ? material : current },
    });
  }, args);
  const actions = {
    verifySource: () => Promise.resolve({ absent: true as const }),
    source: (command: string, input: Record<string, unknown>) => {
      effects.push(command);
      return Promise.resolve(
        command === "instance.read-source-removal-state"
          ? { state: "present" }
          : { state: "finalized", receipt: input.receipt },
      );
    },
    runtime: (command: string) => {
      effects.push(command);
      return Promise.resolve({});
    },
  };
  return {
    args,
    current,
    calls,
    effects,
    material,
    gateway,
    actions,
    onRead: (callback: () => void) => beforeRead = callback,
  };
}

Deno.test("local destroy phases consume the same root without projecting a child or parent", async () => {
  const f = fixture();
  const results = [];
  for (
    const step of [
      "prepare_sources",
      "teardown",
      "runtime",
      "verify_sources",
      "finalize_sources",
    ] as const
  ) {
    results.push(await runDestroyLocalStep(step, f.args, f.gateway, f.actions));
  }
  assertEquals(f.effects, [
    "instance.read-source-removal-state",
    "schedule.remove",
    "process.remove",
    "instance.read-source-removal-state",
    "instance.read-source-removal-state",
    "instance.finalize-source-removal",
  ]);
  assertEquals(results.at(-1), { finalized: true, instance_ids: [1] });
  assertEquals(f.calls.length, 7);
  assertEquals(
    f.calls.every((path) => path.endsWith(`run_id=${f.args.run_id}`)),
    true,
  );
  assertEquals(
    JSON.stringify(results).includes(f.material.git_read.token),
    false,
  );
});

Deno.test("foreign root identities refuse every local phase before private reads or effects", async () => {
  for (const field of ["id", "kind", "node_id", "source_node"] as const) {
    const f = fixture();
    if (field === "id") f.current.id++;
    if (field === "kind") f.current.kind = "instance.clone";
    if (field === "node_id") f.current.node_id = 209;
    if (field === "source_node") {
      f.current.input.direct_destroy.node_id = 209;
    }
    await assertRejects(() =>
      runDestroyLocalStep("finalize_sources", f.args, f.gateway, f.actions)
    );
    assertEquals(f.effects, []);
    assertEquals(f.calls.length, 1);
  }
});

Deno.test("missing or mismatched teardown member cannot become another checkout's script target", async () => {
  for (const change of ["missing", "checkout", "account"] as const) {
    const f = fixture();
    const rows = f.current.input.direct_destroy.teardown;
    if (change === "missing") rows.length = 0;
    if (change === "checkout") rows[0].checkout = "/srv/apps/foreign/main";
    if (change === "account") rows[0].user = "root";
    await assertRejects(() =>
      runDestroyLocalStep("teardown", f.args, f.gateway, f.actions)
    );
    assertEquals(f.effects, []);
    assertEquals(f.calls.length, 1);
  }
});

Deno.test("terminal or cancelled destroy never invokes private or local actions", async () => {
  for (const status of ["succeeded", "failed", "cancelled", "running"]) {
    const f = fixture();
    f.current.status = status;
    f.current.cancel_requested = status === "running";
    await assertRejects(() =>
      runDestroyLocalStep("finalize_sources", f.args, f.gateway, f.actions)
    );
    assertEquals(f.effects, []);
    assertEquals(f.calls.length, 1);
  }
});

Deno.test("native cancellation before a local step makes no Gateway or Node call", async () => {
  const f = fixture();
  await assertRejects(() =>
    runDestroyLocalStep(
      "runtime",
      f.args,
      f.gateway,
      f.actions,
      AbortSignal.abort(),
    )
  );
  assertEquals(f.calls, []);
  assertEquals(f.effects, []);
});

async function withTeardown() {
  const f = fixture();
  const command = "echo private-app-sentinel-2381";
  f.current.input.direct_destroy.teardown[0].steps.push({
    name: "cleanup",
    sha256: await sha256(command),
    timeout_seconds: 30,
  });
  f.material.teardown.members.push({
    instance_id: 1,
    steps: [{ name: "cleanup", timeout_seconds: 30, command }],
  });
  return { ...f, command };
}

Deno.test("composed teardown uses the admitted Node account and keeps scripts callback-local", async () => {
  const f = await withTeardown();
  let ran = false;
  const result = await runDestroyLocalStep("teardown", f.args, f.gateway, {
    source: f.actions.source,
    app: (step, command) => {
      ran = true;
      assertEquals(step.target, {
        variant: "checkout",
        user: "orbit",
        directory: "/srv/apps/proof/main",
        seed_path: "",
        seed_commit: "",
      });
      assertEquals(command, f.command);
      return Promise.resolve({
        exit_code: 0,
        timed_out: false,
        output_tail: command,
        truncated: false,
      });
    },
  });
  assertEquals(ran, true);
  assertEquals(result, { teardown_complete: true, instance_ids: [1] });
  assertEquals(JSON.stringify(result).includes(f.command), false);
});

Deno.test("cancel arriving before a private script read remains cancellation", async () => {
  const f = await withTeardown();
  let reads = 0;
  f.onRead(() => f.current.cancel_requested = ++reads === 2);
  await assertRejects(
    () => runDestroyLocalStep("teardown", f.args, f.gateway, f.actions),
    Error,
    "operation.cancelled",
  );
  assertEquals(f.calls.length, 2);
  assertEquals(f.effects, []);
});

Deno.test("private callback failure cannot report successful source finalization", async () => {
  const f = fixture();
  await assertRejects(() =>
    runDestroyLocalStep("finalize_sources", f.args, {
      read: (signal) => f.gateway.read(signal),
      withPrivateMaterial: () => Promise.resolve(),
    }, f.actions)
  );
  assertEquals(f.effects, []);
});

Deno.test("source failure inside the shared private callback exposes no Git material", async () => {
  const f = fixture();
  const error = await assertRejects(() =>
    runDestroyLocalStep("finalize_sources", f.args, f.gateway, {
      source: () => {
        throw new Error(f.material.git_read.token);
      },
    })
  );
  assertEquals(String(error).includes(f.material.git_read.token), false);
  assertEquals(f.calls.length, 2);
});

Deno.test("a replayed private callback finalizes the admitted source only once", async () => {
  const f = fixture();
  const result = await runDestroyLocalStep("finalize_sources", f.args, {
    read: (signal) => f.gateway.read(signal),
    withPrivateMaterial: async (use) => {
      await use(f.material);
      await use(f.material);
    },
  }, f.actions);

  assertEquals(result, { finalized: true, instance_ids: [1] });
  assertEquals(f.effects, [
    "instance.read-source-removal-state",
    "instance.finalize-source-removal",
  ]);
});

Deno.test("a private callback delivered after its scope closes cannot remove source", async () => {
  const f = fixture();
  let retained:
    | ((material: Record<string, unknown>) => Promise<void>)
    | undefined;
  await assertRejects(() =>
    runDestroyLocalStep("finalize_sources", f.args, {
      read: (signal) => f.gateway.read(signal),
      withPrivateMaterial: (use) => {
        retained = use;
        return Promise.resolve();
      },
    }, f.actions)
  );
  if (retained === undefined) throw new Error("Expected the private callback");
  await retained(f.material);

  assertEquals(f.effects, []);
});

Deno.test("a bounded source refusal survives private error suppression without exposing material", async () => {
  const f = fixture();
  await assertRejects(
    () =>
      runDestroyLocalStep("finalize_sources", f.args, f.gateway, {
        source: () =>
          Promise.resolve({ error_code: "instance.origin_mismatch" }),
      }),
    Error,
    "instance.origin_mismatch",
  );
});

Deno.test("retry after partial or completed source finalization never reruns teardown in the removed checkout", async () => {
  for (const state of ["quarantined", "receipt_pending_cleanup", "completed"]) {
    const f = fixture();
    f.current.input.direct_destroy.teardown[0].steps.push({
      name: "release",
      timeout_seconds: 30,
      sha256: "a".repeat(64),
    });
    const result = await runDestroyLocalStep("teardown", f.args, f.gateway, {
      source: (command) => {
        assertEquals(command, "instance.read-source-removal-state");
        return Promise.resolve({ state });
      },
      app: () => {
        throw new Error("Teardown must not run in finalized source");
      },
    });
    assertEquals(result, { teardown_complete: true, instance_ids: [1] });
    assertEquals(
      f.calls.some((call) => call.includes("private-material")),
      false,
    );
  }
});
