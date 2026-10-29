/** Disposable fake Gateway for native B wait/re-entry tests. No Node connection. */
type FixtureOperation = {
  id: number;
  kind: string;
  node_id: number;
  input: Record<string, unknown>;
  status: string;
  cancel_requested: boolean;
  steps: { name: string; status: string }[];
  step_results: Record<string, unknown>;
  parent_operation_id?: number;
  child_key?: unknown;
  worker?: unknown;
  error_code?: unknown;
  result?: unknown;
  prepared?: boolean;
  active?: string | null;
  completed?: string[];
  waiting_for?: { key: string; id: number }[];
  runs?: Record<string, { identity: string; result: unknown }>;
};
type FixtureState = {
  operations: Record<string, FixtureOperation>;
  childAdmissions: number;
  nodeEffects: number;
  waits: number;
  phaseCommits: string[];
  completions: number[];
  loseChildReply?: boolean;
  loseCommitReply?: boolean;
};

export async function fixtureHttp(method: string, path: string, raw?: unknown) {
  const state: FixtureState = JSON.parse(
    await Deno.readTextFile("native-parent.json"),
  );
  const body = (raw ?? {}) as Record<string, unknown>;
  const operations = state.operations;
  const parent = operations["17"];
  const save = () =>
    Deno.writeTextFile("native-parent.json", JSON.stringify(state));
  const eligible = (operation: FixtureOperation) =>
    operation.status === "queued" || (operation.status === "waiting" &&
      operation.waiting_for?.every((child) =>
        ["succeeded", "failed", "cancelled"].includes(
          operations[String(child.id)].status,
        )
      ));
  if (path.endsWith("/claimable")) {
    if (method !== "GET" || raw !== undefined) {
      throw new Error("Discovery must be a read");
    }
    const counts = new Map<string, number>();
    for (const operation of Object.values(operations)) {
      if (eligible(operation)) {
        counts.set(operation.kind, (counts.get(operation.kind) ?? 0) + 1);
      }
    }
    return {
      status: 200,
      body: {
        data: {
          kinds: [...counts].map(([kind, claimable]) => ({
            kind,
            claimable,
            oldest_at: "2026-10-29T00:00:00Z",
          })),
        },
      },
    };
  }
  if (path.endsWith("/claim")) {
    const operation = Object.values(operations).find((operation) =>
      operation.kind === body.kind &&
      (body.operation_id === undefined || body.operation_id === operation.id) &&
      eligible(operation)
    );
    if (!operation) return { status: 200, body: { data: null } };
    operation.status = "running";
    operation.worker = body.worker;
    await save();
    return { status: 200, body: { data: operation } };
  }
  const id = path.match(/operation-executor\/(\d+)/)?.[1];
  const operation = id ? operations[id] : undefined;
  if (!operation) throw new Error("Unknown fixture Operation");
  if (method === "GET" && path.endsWith("/children")) {
    return {
      status: 200,
      body: {
        data: Object.values(operations).filter((child) =>
          child.parent_operation_id === operation.id
        )
          .map((child) => ({
            id: child.id,
            key: child.child_key,
            kind: child.kind,
            node_id: child.node_id,
            status: child.status,
            error_code: child.error_code ?? null,
            result: child.result ?? null,
          })),
      },
    };
  }
  if (body.worker !== operation.worker) {
    return { status: 409, body: { error: { code: "operation.lease_lost" } } };
  }
  let data: unknown = operation;
  const phases = ["dns", "caddy", "certificates", "firewall", "record"];
  const prepare = {
    prepared: true,
    mode: "withdraw",
    production: false,
    public: false,
    remote_router: false,
    web_root: false,
    targeted: false,
    child_phases: [{ stage: "caddy", key: "caddy.node-2" }],
  };
  const descriptors = operation.id === 17 ? [] : [
    {
      key: "prepare",
      step: "prepare",
      transport: "gateway",
      name: "route.caddy-prepare",
      input: operation.input,
      resumable: true,
      required: true,
    },
    ...(operation.prepared
      ? [{
        key: "caddy-publish",
        step: "caddy-publish",
        transport: "route-caddy",
        name: "caddy.publish",
        input: {
          content: "# private proof\n",
          version: "a".repeat(32),
          listen_addresses: ["10.44.0.3"],
          unserved: false,
        },
        resumable: true,
        required: true,
      }]
      : []),
  ];
  if (path.endsWith("/heartbeat")) {
    data = { ...operation, execution_context: { descriptors } };
  }
  if (path.endsWith("/children")) {
    if (
      operation.id !== 17 || body.key !== "caddy.node-2" ||
      (!operations["44"] && parent.active !== "caddy") ||
      Object.keys(body).sort().join() !== "key,worker"
    ) {
      throw new Error("Unadmitted parent child");
    }
    if (!operations["44"]) {
      operations["44"] = {
        id: 44,
        kind: "route.caddy_converge",
        node_id: 2,
        input: { reason: "private withdrawal" },
        parent_operation_id: 17,
        child_key: body.key,
        status: "queued",
        cancel_requested: false,
        steps: [],
        step_results: {},
        result: null,
      };
      state.childAdmissions++;
    }
    data = operations["44"];
    await save();
    if (state.loseChildReply) {
      state.loseChildReply = false;
      await save();
      throw new Error("lost child reply");
    }
    return { status: 202, body: { data } };
  }
  if (path.endsWith("/wait")) {
    if (
      operation.id !== 17 ||
      JSON.stringify(body.keys) !== JSON.stringify(["caddy.node-2"])
    ) throw new Error("Foreign wait");
    operation.status = "waiting";
    operation.worker = null;
    operation.waiting_for = [{ key: "caddy.node-2", id: 44 }];
    state.waits++;
    data = operation;
  }
  if (path.endsWith("/step")) {
    const step = operation.steps.find((step: { name: string }) =>
      step.name === body.name
    );
    if (step) step.status = String(body.status);
    else {operation.steps.push({
        name: String(body.name),
        status: String(body.status),
      });}
    if (body.status === "succeeded") {
      operation.step_results[String(body.name)] = body.result;
    }
  }
  if (path.endsWith("/run")) {
    if ("node_id" in body || "effect_key" in body) {
      throw new Error("Caller supplied transport authority");
    }
    operation.runs ??= {};
    const key = String(body.key),
      identity = JSON.stringify({
        transport: body.transport,
        name: body.name,
        input: body.input,
      });
    const previous = operation.runs[key];
    if (previous) {
      if (previous.identity !== identity) {
        throw new Error("Changed run identity");
      }
      data = previous.result;
    } else if (operation.id === 17) {
      if (body.transport !== "gateway" || body.name !== "route.parent") {
        throw new Error("Unlisted parent command");
      }
      const input = body.input as { action: string; stage?: string };
      if (input.action === "prepare") data = prepare;
      else if (input.action === "prove") {
        if (!phases.every((phase) => parent.completed?.includes(phase))) {
          throw new Error("Unproven Route completion");
        }
        data = { ready: true, route_id: 8, retained_on_nodes: [] };
      } else {
        const stage = String(input.stage);
        if (
          phases.find((phase) => !parent.completed?.includes(phase)) !== stage
        ) throw new Error("Out-of-order phase");
        if (input.action === "open") {
          parent.active = stage;
          data = { opened: true };
        } else {
          if (
            parent.active !== stage ||
            (stage === "caddy" && operations["44"]?.status !== "succeeded")
          ) throw new Error("Unproven child");
          parent.completed = [...(parent.completed ?? []), stage];
          parent.active = null;
          state.phaseCommits.push(stage);
          data = { ready: true, stage };
        }
      }
      operation.runs[key] = { identity, result: data };
      if (state.loseCommitReply && key === "stage-commit.caddy") {
        state.loseCommitReply = false;
        await save();
        throw new Error("lost phase commit reply");
      }
    } else {
      const descriptor = descriptors.find((descriptor) =>
        descriptor.key === key
      );
      if (
        !descriptor || descriptor.name !== body.name ||
        descriptor.transport !== body.transport ||
        JSON.stringify(descriptor.input) !== JSON.stringify(body.input)
      ) {
        throw new Error("Unadmitted Node descriptor");
      }
      if (key === "prepare") {
        operation.prepared = true;
        data = { prepared: true };
      } else {
        state.nodeEffects++;
        data = { ready: true, result: "published" };
      }
      operation.runs[key] = { identity, result: data };
    }
  }
  if (path.endsWith("/complete")) {
    if (body.status === "succeeded") {
      if (
        operation.id === 17 &&
        (!parent.completed?.includes("record") ||
          operations["44"]?.status !== "succeeded")
      ) throw new Error("Bare parent completion");
      if (operation.id !== 17 && !operation.runs?.["caddy-publish"]) {
        throw new Error("Bare Node completion");
      }
    }
    operation.status = String(body.status);
    operation.result = body.result;
    operation.error_code = body.error_code;
    state.completions.push(operation.id);
  }
  await save();
  return { status: 200, body: { data } };
}
