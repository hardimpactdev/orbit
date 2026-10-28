import { assertEquals } from "jsr:@std/assert@1.0.19";
import { createModelTestContext } from "jsr:@swamp-club/swamp-testing@0.20261016.58";
import {
  GatewayClient,
  type Response,
} from "../../extensions/behaviors/automatic-deploy/gateway.ts";
import {
  type Check,
  type Commit,
  DecisionSchema,
  type Gateway,
  type Observation,
  type Operation,
  PolicyError,
  type Row,
  StateSchema,
  tick,
  trigger,
} from "../../extensions/behaviors/automatic-deploy/policy.ts";

export const [A, B, C, D, SIDE] = ["a", "b", "c", "d", "e"].map((c) =>
  c.repeat(40)
);
export const start = "2026-10-27T20:00:00.000Z";
export class FakeGateway implements Gateway {
  observation: Observation = {
    enabled: true,
    production: true,
    active: true,
    gate: "Required checks",
    repository: "acme/shop",
    gateReadable: true,
    resumedAfter: 1,
    blocked: [],
    history: [],
    holder: null,
    historyVersion: "f".repeat(64),
  };
  commits: Commit[] = [];
  branchHead = B;
  walkHead = B;
  runs = new Map<string, Check[]>();
  operations = new Map<number, Operation>();
  starts: { instance: number; commit: string; key: string }[] = [];
  reads: string[] = [];
  requests: string[] = [];
  now = start;
  gateResponse: Response | null = null;
  gateFailureAt: "walk" | "checks" = "walk";
  admissionError: Error | null = null;
  refusal: { status: number; code: string; message?: string } | null = null;
  private client = new GatewayClient((method, path, body, key) =>
    Promise.resolve().then(() => {
      this.requests.push(`${method} ${path}`);
      if (method === "GET") {
        return {
          status: 200,
          body: {
            data: {
              attempts: 0,
              started_at: null,
              finished_at: this.now,
              ...this.operations.get(Number(path.split("/").at(-1))),
              target_type: "instance",
              target_id: 7,
            },
          },
        };
      }
      if (this.admissionError) throw this.admissionError;
      if (this.refusal) {
        return {
          status: this.refusal.status,
          body: {
            error: {
              code: this.refusal.code,
              message: this.refusal.message ?? "Refused",
              details:
                ["operation.target_busy", "operation.dispatch_failed"].includes(
                    this.refusal.code,
                  )
                  ? { operation_id: 900 }
                  : {},
            },
          },
        };
      }
      assertEquals(path, `/api/v1/instances/7/deploy/automatic`);
      const commit = (body as { commit: string }).commit;
      assertEquals(body, { commit });
      assertEquals(typeof key, "string");
      const existing = this.starts.findIndex((s) => s.key === key);
      if (existing >= 0) {
        return {
          status: 202,
          body: {
            data: {
              attempts: 0,
              started_at: null,
              ...this.operations.get(existing + 100),
              target_type: "instance",
              target_id: 7,
            },
          },
        };
      }
      this.starts.push({ instance: 7, commit, key: key! });
      const operation: Operation = {
        id: this.starts.length + 99,
        kind: "instance.deploy",
        status: "queued",
        input: { commit },
        triggered_by: trigger,
        result: null,
        error_code: null,
      };
      this.operations.set(operation.id, operation);
      this.observation.holder = operation;
      return {
        status: 202,
        body: {
          data: {
            attempts: 0,
            started_at: null,
            ...operation,
            target_type: "instance",
            target_id: 7,
          },
        },
      };
    })
  );
  constructor() {
    this.row({ commit: A });
    this.chain([A, B]);
  }
  history(_instance: number) {
    return Promise.resolve(structuredClone(this.observation.history));
  }
  observe(_instance: number) {
    this.reads.push("observe");
    return Promise.resolve(structuredClone(this.observation));
  }
  head(_instance: number) {
    this.reads.push("head");
    return Promise.resolve(this.branchHead);
  }
  walk(_instance: number, _deployed: string) {
    this.reads.push("walk");
    if (this.gateResponse && this.gateFailureAt === "walk") {
      // Exercise the actual Gateway GET error decoder at the observation port.
      return new GatewayClient(() => Promise.resolve(this.gateResponse!))
        .operation(900)
        .then(() => ({ head: this.walkHead, commits: this.commits }));
    }
    return Promise.resolve({ head: this.walkHead, commits: this.commits });
  }
  checks(_instance: number, commit: string, _name: string) {
    this.reads.push(`checks:${commit}`);
    if (this.gateResponse && this.gateFailureAt === "checks") {
      return new GatewayClient(() => Promise.resolve(this.gateResponse!))
        .operation(900)
        .then(() => this.runs.get(commit) ?? []);
    }
    return Promise.resolve(this.runs.get(commit) ?? []);
  }
  deploy(instance: number, commit: string, key: string) {
    return this.client.deploy(instance, commit, key);
  }
  receipt(_instance: number, _commit: string, _key: string) {
    this.reads.push("receipt");
    return Promise.resolve(null);
  }
  resolve(
    _instance: number,
    _commit: string,
    _key: string,
  ): Promise<Operation> {
    return Promise.reject(
      new PolicyError(
        "automatic.outcome_unknown",
        "Resolve unavailable in this fixture.",
      ),
    );
  }
  operation(id: number) {
    return this.client.operation(id);
  }
  row(input: Partial<Row> = {}) {
    const row: Row = {
      id: (this.observation.history.at(-1)?.id ?? 0) + 1,
      kind: "deploy",
      status: "succeeded",
      commit: A,
      triggered_by: "laptop",
      failed_step: null,
      error_code: null,
      started_at: this.now,
      finished_at: this.now,
      ...input,
    };
    this.observation.history.push(row);
    return row;
  }
  failure(
    commit = B,
    failed_step = "preparation",
    error_code = "source.unavailable",
  ) {
    return this.row({
      commit,
      triggered_by: trigger,
      status: "failed",
      failed_step,
      error_code,
    });
  }
  chain(shas: string[]) {
    this.commits = shas.map((sha, i) => ({
      sha,
      parents: i ? [shas[i - 1]] : [],
      descendsFromDeployed: i > 0,
    }));
    this.branchHead = this.walkHead = shas.at(-1)!;
    for (const sha of shas) this.check(sha, "success");
  }
  check(sha: string, conclusion: string | null, status = "completed") {
    this.runs.set(sha, [{
      name: "Required checks",
      head_sha: sha,
      app: "github-actions",
      status,
      conclusion,
    }]);
  }
  finish(
    status: "failed" | "succeeded" = "succeeded",
    failed_step = "preparation",
    error_code = "source.unavailable",
  ) {
    const op = this.observation.holder!;
    const row = this.row({
      commit: op.input.commit!,
      triggered_by: trigger,
      status,
      failed_step: status === "failed" ? failed_step : null,
      error_code: status === "failed" ? error_code : null,
    });
    op.status = status;
    op.result = { deployment_id: row.id };
    this.observation.holder = null;
  }
  hold(kind = "instance.deploy", own = false) {
    this.observation.holder = {
      id: 900,
      kind,
      status: "queued",
      input: { commit: B },
      triggered_by: own ? trigger : "laptop",
      result: null,
      error_code: null,
    };
  }
  resume() {
    this.observation.resumedAfter = this.observation.history.at(-1)?.id ?? 0;
  }
}
export class PolicyFixture {
  gateway = new FakeGateway();
  stored = new Map<string, Record<string, unknown>>();
  decisions: Record<string, unknown>[] = [];
  stateWrites = 0;
  get state() {
    return StateSchema.parse(this.stored.get("state-7") ?? {});
  }
  get decision() {
    return this.decisions.at(-1)!;
  }
  get alerts() {
    return this.state.notifications;
  }
  advance(minutes: number) {
    this.gateway.now = new Date(Date.parse(this.gateway.now) + minutes * 60_000)
      .toISOString();
  }
  async tick() {
    const { context } = createModelTestContext({
      globalArgs: {
        gatewayUrl: "https://gateway.example.test",
        caFile: "/unused",
      },
    });
    const write = context.writeResource.bind(context);
    const ctx = Object.assign(context, {
      readResource: (name: string) =>
        Promise.resolve(this.stored.get(name) ?? null),
      writeResource: async (
        spec: "state" | "decision",
        name: string,
        data: Record<string, unknown>,
      ) => {
        (spec === "decision" ? DecisionSchema : StateSchema)
          .parse(data);
        const handle = await write(spec, name, data);
        this.stored.set(name, structuredClone(data));
        if (spec === "decision") this.decisions.push(structuredClone(data));
        else this.stateWrites++;
        return handle;
      },
    });
    try {
      const decision = await tick(
        7,
        this.state,
        this.gateway,
        async (state) => {
          if (
            JSON.stringify(state) !== JSON.stringify(this.stored.get("state-7"))
          ) await ctx.writeResource("state", "state-7", state);
        },
        this.gateway.now,
      );
      if (decision) {
        await ctx.writeResource("decision", "decision-7", {
          ready: decision.state.result !== "failed",
          error_code: decision.state.error_code,
          gateway_gaps: [],
          projection: decision.projection,
          alerts: decision.alerts,
          activity: decision.activity,
        });
      }
    } catch (error) {
      if (
        !(error instanceof PolicyError) || this.decision?.ready !== false ||
        this.state.result !== "failed"
      ) {
        throw error;
      }
    }
    return this.state.result;
  }
}
