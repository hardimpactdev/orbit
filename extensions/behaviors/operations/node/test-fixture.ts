import { createModelTestContext } from "jsr:@swamp-club/swamp-testing@0.20261016.58";
import type { Http } from "../../automatic-deploy/gateway.ts";
import { type Context, Operation } from "../../operation-runner/mod.ts";
import { node } from "./mod.ts";

export class Fixture {
  operation = Operation.parse({
    id: 7,
    node_id: 2,
    kind: "node.converge",
    status: "running",
    cancel_requested: false,
    input: {
      force_artifacts: false,
      first_visit: false,
      _footprint: {
        agent_pin: "a".repeat(64),
        artifacts: [],
        payloads: {},
        children: {},
      },
    },
    step_results: {},
  });
  calls: { path: string; body: Record<string, unknown> }[] = [];
  receipts = new Map<string, Record<string, unknown>>();
  resources = new Map<string, Record<string, unknown>>();
  replies = new Map<string, Record<string, unknown>>();
  children = new Map<
    number,
    {
      status: string;
      result: Record<string, unknown> | null;
      error_code: string | null;
    }
  >();
  childAdmissions = 0;
  childIntent: Record<string, unknown> | null = null;
  lostRun: string | null = null;
  lostChild = false;
  lostCompletion = false;
  leaseLost = false;
  receiptState: "running" | "unknown" | null = null;
  get snapshot() {
    return this.operation.input._footprint as {
      artifacts: {
        name: string;
        digest: string;
        required: boolean;
        repair: boolean;
      }[];
      payloads: Record<string, Record<string, unknown>>;
      children: Record<
        string,
        { kind: string; node_id: number; input: Record<string, unknown> }
      >;
    };
  }
  artifact(name: string, required = true, repair = false) {
    this.snapshot.artifacts.push({
      name,
      digest: `digest-${name}`,
      required,
      repair,
    });
  }
  child(artifact: string, kind: string, input: Record<string, unknown>) {
    this.artifact(artifact);
    this.snapshot.children[artifact] = { kind, node_id: 2, input };
    if (artifact === "private-dns") {
      this.snapshot.payloads["private-dns-listener"] = {
        release_id: "rendered",
      };
      this.replies.set("footprint-private-dns-listener", {
        installed: true,
        changed: true,
      });
    }
  }
  http: Http = async (_method, path, raw) => {
    await Promise.resolve();
    const body = (raw ?? {}) as Record<string, unknown>;
    this.calls.push({ path, body });
    if (this.leaseLost) {
      return { status: 409, body: { error: { code: "operation.lease_lost" } } };
    }
    let data: unknown = path.startsWith("/api/v1/operations/")
      ? { ...this.operation, result: null, error_code: null }
      : this.operation;
    if (path.endsWith("/children") && _method === "POST") {
      this.childAdmissions++;
      const spec =
        this.snapshot.children[String(body.key).replace(/^footprint-/, "")];
      if (
        !spec ||
        (Object.keys(body).length !== 2 || typeof body.worker !== "string")
      ) {
        throw new Error("Child must use stored intent");
      }
      this.childIntent = { key: body.key, ...spec };
      if (this.lostChild) {
        this.lostChild = false;
        throw new Error("Lost child reply");
      }
      data = {
        id: 20,
        ...spec,
        status: "queued",
        input: spec.input,
        cancel_requested: false,
        step_results: {},
      };
    } else if (path.endsWith("/children")) {
      data = this.childIntent
        ? [{
          id: 20,
          key: this.childIntent.key,
          kind: this.childIntent.kind,
          node_id: this.childIntent.node_id,
          ...(this.children.get(20) ??
            { status: "succeeded", result: {}, error_code: null }),
        }]
        : [];
    } else if (path.includes("/receipts/")) {
      const result =
        this.receipts.get(decodeURIComponent(path.split("/").at(-1)!)) ?? null;
      data = {
        effect_key: `op-7.${decodeURIComponent(path.split("/").at(-1)!)}`,
        exit_code: null,
        finished_at: null,
        state: this.receiptState ??
          (result ? result.error_code ? "failed" : "succeeded" : "unknown"),
        result: this.receiptState ? null : result,
      };
    } else if (path.endsWith("/run")) {
      const key = String(body.key);
      if (!this.receipts.has(key)) {
        this.receipts.set(
          key,
          this.replies.get(key) ?? (
            key === "prepare-footprint"
              ? { prepared: true }
              : key === "footprint-result"
              ? {
                footprint_proven: true,
                artifacts: this.snapshot.artifacts.map((a) => ({
                  name: a.name,
                  status: a.required ? "applied" : "unchanged",
                  reason: null,
                })),
                skipped: [],
                digest: "digest",
                changed: this.snapshot.artifacts.some((a) => a.required),
              }
              : { changed: false }
          ),
        );
      }
      if (key === "prepare-agent-configuration") {
        this.snapshot.payloads.agent.binary_changed =
          this.receipts.get("footprint-agent-install")!.changed;
      }
      data = this.receipts.get(key);
      if (this.lostRun === key) {
        this.lostRun = null;
        throw new Error("Lost run reply");
      }
    } else if (path.endsWith("/step")) {
      if (body.status === "succeeded") {
        this.operation.step_results[String(body.name)] = body.result;
      }
    } else if (path.endsWith("/wait")) {
      data = {
        ...this.operation,
        status: "waiting",
        worker: null,
        lease_seconds: 0,
      };
    } else if (path.endsWith("/complete") && this.lostCompletion) {
      this.lostCompletion = false;
      throw new Error("Lost completion reply");
    }
    this.operation.execution_context = {
      descriptors: this.operation.kind === "node.converge"
        ? [{
          key: "footprint-agent",
          step: "footprint-agent",
          transport: "agent",
          name: "agent.configure",
          input: this.snapshot.payloads.agent ?? {},
          required: false,
          resumable: false,
        }]
        : [],
    };
    return { status: 200, body: { data } };
  };
  context(): Context {
    const globals = {
      gatewayUrl: "https://gateway.example.test",
      caFile: "/unused",
    };
    const { context } = createModelTestContext({ globalArgs: globals });
    const write = context.writeResource.bind(context);
    return Object.assign(context, {
      globalArgs: globals,
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
  execute(worker = "worker-1") {
    return node(() => this.http).methods.execute.execute({
      kind: this.operation.kind,
      worker,
    }, this.context());
  }
  runs() {
    return this.calls.filter((c) => c.path.endsWith("/run")).map((c) =>
      c.body.name
    );
  }
  completion() {
    return this.calls.findLast((c) => c.path.endsWith("/complete"))?.body;
  }
}
