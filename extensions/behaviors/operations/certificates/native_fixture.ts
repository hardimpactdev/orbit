/** Fake Gateway used only in a disposable native workflow repository. */
export async function fixtureHttp(method: string, path: string, raw?: unknown) {
  const state = JSON.parse(await Deno.readTextFile("native-certificates.json"));
  const body = raw as Record<string, unknown>;
  const save = () =>
    Deno.writeTextFile("native-certificates.json", JSON.stringify(state));
  let data: unknown;
  if (path.endsWith("/claimable")) {
    if (method !== "GET" || raw !== undefined) {
      throw new Error("Discovery must be a read");
    }
    return {
      status: 200,
      body: {
        data: {
          kinds: [{
            kind: state.operation.kind,
            claimable: state.operation.status === "queued" ? 1 : 0,
            oldest_at: "2026-10-29T00:00:00Z",
          }],
        },
      },
    };
  }
  if (path === `/api/v1/operations/${state.operation.id}`) {
    return {
      status: 200,
      body: {
        data: {
          ...state.operation,
          result: state.completions.at(-1)?.result ?? null,
          error_code: state.completions.at(-1)?.error_code ?? null,
        },
      },
    };
  }
  if (method === "GET") {
    const key = decodeURIComponent(path.split("/").at(-1)!);
    state.reads.push(key);
    data = {
      effect_key: `op-${state.operation.id}.${key}`,
      state: state.receipts[key] ? "succeeded" : "unknown",
      exit_code: 0,
      result: state.receipts[key] ?? null,
      finished_at: "2026-10-28T22:00:00Z",
    };
    await save();
    return { status: 200, body: { data } };
  }
  if (path.endsWith("/claim")) {
    if (
      body.kind !== state.operation.kind ||
      ["succeeded", "failed", "cancelled"].includes(state.operation.status)
    ) return { status: 200, body: { data: null } };
    state.operation.worker = body.worker;
    state.operation.status = "running";
  } else if (body.worker !== state.operation.worker) {
    return { status: 409, body: { error: { code: "operation.lease_lost" } } };
  }
  if (path.endsWith("/run")) {
    if ("node_id" in body || "effect_key" in body) {
      throw new Error("Caller target override");
    }
    const leaf = state.operation.input;
    const scope = { scope: leaf.scope, home: leaf.home };
    const specs: Record<
      string,
      { transport: string; name: string; input: unknown; result: unknown }
    > = {
      "certificate-request": {
        transport: "certificate-request",
        name: "certificate.request-leaf",
        input: leaf,
        result: {
          state: "request",
          request_base64: btoa(
            "-----BEGIN CERTIFICATE REQUEST-----\npublic CSR\n-----END CERTIFICATE REQUEST-----\n",
          ),
        },
      },
      "certificate-sign": {
        transport: "gateway",
        name: "certificate.sign-leaf",
        input: {
          domain: leaf.domain,
          request_base64: state.receipts["certificate-request"]?.request_base64,
        },
        result: { signed: true },
      },
      "certificate-publish": {
        transport: "agent",
        name: "certificate.publish-leaf",
        input: {
          ...leaf,
          certificate: { $secret: "certificate.leaf", id: state.operation.id },
          root_certificate: {
            $secret: "certificate.leaf",
            id: state.operation.id,
          },
        },
        result: {},
      },
      "certificate-inspect": {
        transport: "agent",
        name: "certificate.inspect-leaf",
        input: scope,
        result: { present: true },
      },
    };
    const key = String(body.key), spec = specs[key];
    if (
      !spec || body.name !== spec.name || body.transport !== spec.transport ||
      JSON.stringify(body.input) !== JSON.stringify(spec.input)
    ) throw new Error("Unadmitted certificate intent");
    if (!state.receipts[key]) {
      state.receipts[key] = spec.result;
      state.effects.push(key);
    }
    data = state.receipts[key];
    if (state.loseKey === key) {
      state.loseKey = null;
      await save();
      throw new Error("Lost certificate reply after effect");
    }
  }
  if (path.endsWith("/step")) {
    const step = state.operation.steps.find((s: { name: string }) =>
      s.name === body.name
    );
    if (step) step.status = body.status;
    else state.operation.steps.push({ name: body.name, status: body.status });
    if (body.status === "succeeded") {
      state.operation.step_results[String(body.name)] = body.result;
    }
  }
  if (path.endsWith("/complete")) {
    for (
      const key of [
        "certificate-request",
        "certificate-sign",
        "certificate-publish",
        "certificate-inspect",
      ]
    ) {
      if (!state.operation.step_results[key]) {
        throw new Error("Missing certificate proof");
      }
    }
    state.operation.status = body.status;
    if (!state.completions.length) state.completions.push(body);
    if (state.loseCompletion) {
      state.loseCompletion = false;
      await save();
      throw new Error("Lost completion reply");
    }
  }
  data ??= state.operation;
  await save();
  return { status: 200, body: { data } };
}
