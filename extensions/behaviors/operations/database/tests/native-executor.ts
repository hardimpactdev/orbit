import type { Http } from "../../../automatic-deploy/gateway.ts";
export const fixtureHttp: Http = async (_method, path, raw) => {
  const state = JSON.parse(await Deno.readTextFile("native-database.json"));
  const body = raw as Record<string, unknown>;
  let data: unknown = {
    ...state.operation,
    execution_context: { descriptors: state.descriptors },
  };
  let lost = false;
  if (path.endsWith("/claim")) {
    data = body.kind === state.operation.kind &&
        state.operation.status !== "succeeded"
      ? data
      : null;
  }
  if (path.includes("/receipts/")) {
    const key = decodeURIComponent(path.split("/").at(-1)!);
    data = {
      effect_key: `op-${state.operation.id}.${key}`,
      state: state.receipts[key] ? "succeeded" : "unknown",
      result: state.receipts[key] ?? null,
    };
    state.receiptReads = (state.receiptReads ?? 0) + 1;
  }
  if (path.endsWith("/run")) {
    const key = String(body.key);
    if (!state.receipts[key]) {
      state.runs++;
      state.receipts[key] = { backend: "active" };
    }
    data = state.receipts[key];
    if (state.loseRunReply) {
      state.loseRunReply = false;
      lost = true;
    }
  }
  if (path.endsWith("/step")) {
    const step = state.operation.steps.find((step: { name: string }) =>
      step.name === body.name
    );
    if (step) step.status = body.status;
    else state.operation.steps.push({ name: body.name, status: body.status });
    if (body.status === "succeeded") {
      state.operation.step_results[String(body.name)] = body.result;
    }
  }
  if (path.endsWith("/complete")) {
    if (!state.completion) {
      state.completion = body;
      state.completions++;
    }
    state.operation.status = body.status;
  }
  await Deno.writeTextFile("native-database.json", JSON.stringify(state));
  if (lost) throw new Error("Lost Node reply");
  return { status: 200, body: { data } };
};
