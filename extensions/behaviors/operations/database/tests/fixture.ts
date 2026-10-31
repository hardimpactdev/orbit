import { createModelTestContext } from "jsr:@swamp-club/swamp-testing@0.20261016.58";
import { database } from "../mod.ts";
import { type Context, Operation } from "../../../operation-runner/mod.ts";
import type { Http } from "../../../automatic-deploy/gateway.ts";
import { type Child, type Descriptor } from "../contract.ts";
import type { z } from "npm:zod@4.4.3";

export class Fixture {
  operation = Operation.parse({
    id: 7,
    node_id: 2,
    kind: "database.create",
    status: "running",
    cancel_requested: false,
    input: { intent_id: 9, connection_id: 11, driver: "pgsql" },
    step_results: {},
  });
  descriptors: z.infer<typeof Descriptor>[] = [{
    key: "database-apply",
    step: "database-apply",
    transport: "agent",
    name: "database.server-admin",
    input: {
      operation: "execute",
      container: "orbit-process-1-dev",
      password: { $secret: "database.intent", id: 9 },
      sql: { $secret: "database.intent", id: 9 },
      password_variable: "PGPASSWORD",
      client: ["psql"],
    },
    required: true,
    cleanup: false,
    resumable: true,
  }];
  children: z.infer<typeof Child>[] = [];
  childRows: Record<string, unknown>[] = [];
  loseChild = false;
  childStatus = "succeeded";
  omitChildrenContext = false;
  revealCleanupAfterFailure = false;
  receiptState: "succeeded" | "failed" | "running" | "unknown" | null = null;
  calls: { path: string; body: Record<string, unknown> }[] = [];
  receipts = new Map<string, Record<string, unknown>>();
  resources = new Map<string, Record<string, unknown>>();
  invoke?: (body: Record<string, unknown>) => Promise<Record<string, unknown>>;
  runs = 0;
  completions = 0;
  loseRun = false;
  loseStep = false;
  loseCompletion = false;
  leaseLost = false;
  refusal: string | null = null;
  seedBusy = false;
  nullClaim = false;
  finished: Record<string, unknown> | null = null;
  http: Http = async (_method, path, raw) => {
    await Promise.resolve();
    const body = raw as Record<string, unknown>;
    this.calls.push({ path, body });
    if (this.leaseLost) {
      return { status: 409, body: { error: { code: "operation.lease_lost" } } };
    }
    let data: unknown = {
      ...this.operation,
      execution_context: {
        descriptors: this.descriptors,
        ...(this.omitChildrenContext ? {} : {
          children: this.children.filter((child) =>
            !this.revealCleanupAfterFailure || child.phase !== "after" ||
            [...this.receipts.values()].some((result) => result.error_code)
          ),
        }),
      },
    };
    if (path.endsWith("/claim") && this.nullClaim) data = null;
    if (path === "/api/v1/operations/7") {
      data = {
        ...this.operation,
        result: this.finished?.result ?? {},
        error_code: this.finished?.error_code ?? null,
      };
    }
    if (path.includes("/receipts/")) {
      const key = decodeURIComponent(path.split("/").at(-1)!);
      const result = this.receipts.get(key);
      data = {
        effect_key: `op-7.${key}`,
        state: this.receiptState ??
          (result ? (result.error_code ? "failed" : "succeeded") : "unknown"),
        result: result ?? null,
      };
    }
    if (path.endsWith("/children")) {
      if (_method === "POST") {
        let child = this.childRows.find((row) => row.key === body.key);
        if (!child) {
          child = {
            id: 20 + this.childRows.length,
            key: body.key,
            kind: this.children.find((entry) => entry.key === body.key)!.kind,
            node_id: this.children.find((entry) =>
              entry.key === body.key
            )!.node_id,
            status: this.childStatus,
            error_code: null,
            result: {},
          };
          this.childRows.push(child);
        }
        data = child;
        if (this.loseChild) {
          this.loseChild = false;
          throw new Error("Lost child admission reply");
        }
      } else data = this.childRows;
    }
    if (path.endsWith("/run") && this.seedBusy) {
      return { status: 409, body: { error: { code: "sqlite.seed_busy" } } };
    }
    if (path.endsWith("/run")) {
      const key = String(body.key);
      if (!this.receipts.has(key)) {
        this.runs++;
        this.receipts.set(
          key,
          this.invoke
            ? await this.invoke(body)
            : this.refusal
            ? { error_code: this.refusal }
            : {
              backend: this.operation.kind.endsWith("destroy")
                ? "absent"
                : "active",
            },
        );
      }
      data = this.receipts.get(key);
      if (this.loseRun) {
        this.loseRun = false;
        throw new Error("Lost run reply");
      }
    }
    if (path.endsWith("/step")) {
      const saved = this.operation.steps.find((step) =>
        step.name === body.name
      );
      if (saved) saved.status = String(body.status);
      else {this.operation.steps.push({
          name: String(body.name),
          status: String(body.status),
        });}
      if (body.status === "succeeded") {
        this.operation.step_results[String(body.name)] = body.result;
      }
      if (this.loseStep && body.status === "succeeded") {
        this.loseStep = false;
        throw new Error("Lost step reply");
      }
    }
    if (path.endsWith("/complete")) {
      this.completions++;
      this.finished = body;
      this.operation.status = String(body.status);
      if (this.loseCompletion) {
        this.loseCompletion = false;
        throw new Error("Lost completion reply");
      }
    }
    return { status: 200, body: { data } };
  };
  context(): Context {
    const { context } = createModelTestContext({
      globalArgs: {
        gatewayUrl: "https://gateway.example.test",
        caFile: "/unused",
      },
    });
    const write = context.writeResource.bind(context);
    return Object.assign(context, {
      globalArgs: {
        gatewayUrl: "https://gateway.example.test",
        caFile: "/unused",
      },
      readResource: (name: string) =>
        Promise.resolve(this.resources.get(name) ?? null),
      writeResource: async (
        spec: string,
        name: string,
        value: Record<string, unknown>,
      ) => {
        const handle = await write(spec, name, value);
        this.resources.set(name, structuredClone(value));
        return handle;
      },
    });
  }
  execute(worker = "run-1") {
    return database(() => this.http).methods.execute.execute({
      kind: this.operation.kind,
      worker,
    }, this.context());
  }
}
