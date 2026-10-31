import { recordQuery } from "../worker_query.ts";
import { assertEquals } from "jsr:@std/assert@1.0.19";
import type { Http } from "../../../extensions/behaviors/automatic-deploy/gateway.ts";
const file = "native-merge.json";
export const fixtureHttp: Http = async (_method, path, raw) => {
  const state = JSON.parse(await Deno.readTextFile(file));
  const input = raw as Record<string, unknown>;
  let data = state.operation;
  if (path.endsWith("/claimable")) {
    return {
      status: 200,
      body: {
        data: {
          kinds: [{
            kind: "tasks.dependency_merge",
            claimable: 1,
            oldest_at: null,
          }],
        },
      },
    };
  }
  if (path.endsWith("/claim")) state.operation.worker = input.worker;
  if (path.endsWith("/run")) {
    if (
      input.transport !== "gateway" ||
      input.name !== "github.dependency-squash"
    ) throw new Error("Unbound merge effect");
    assertEquals(input.input, state.operation.input);
    state.runs++;
    if (!state.receipt) {
      state.writes++;
      state.receipt = {
        merged: true,
        head_sha: "a".repeat(40),
        merge_sha: "b".repeat(40),
      };
    }
    data = state.receipt;
    if (state.loseRun) {
      state.loseRun = false;
      await Deno.writeTextFile(file, JSON.stringify(state));
      throw new Error("Lost merge reply");
    }
  }
  if (path.endsWith("/step") && input.status === "succeeded") {
    state.operation.step_results.squash = input.result;
  }
  if (path.endsWith("/complete")) {
    if (state.loseComplete) {
      state.loseComplete = false;
      await Deno.writeTextFile(file, JSON.stringify(state));
      throw new Error("Lost complete reply");
    }
    state.operation.status = input.status;
    state.completions.push(input);
  }
  await Deno.writeTextFile(file, JSON.stringify(state));
  return { status: 200, body: { data } };
};

export const fixtureConfiguration = {
  invokeModel: (_args: unknown) => Promise.resolve({ ok: true }),
  queryData: recordQuery((_predicate: string, _select?: string) =>
    Promise.resolve([{
      projectId: 20,
      enabled: true,
      repository: "acme/widgets",
      defaultBranch: "main",
      smokeWorkflow: ".github/workflows/production-smoke.yml",
    }])
  ),
};
