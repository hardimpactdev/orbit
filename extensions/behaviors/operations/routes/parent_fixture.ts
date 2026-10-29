import { assertEquals } from "jsr:@std/assert@1.0.19";
import { createModelTestContext } from "jsr:@swamp-club/swamp-testing@0.20261016.58";
import { type Context } from "../../operation-runner/mod.ts";
import type { Http } from "../../automatic-deploy/gateway.ts";
import { routes } from "./mod.ts";

export class ParentFixture {
  public = false;
  production = false;
  webRoot = false;
  remoteRouter = false;
  mode: string | null = null;
  childPhases: { stage: string; key: string }[] | null = null;
  loseComplete = false;
  immediate = false;
  operation = {
    id: 17,
    kind: "route.analytics_publish",
    node_id: 2,
    input: { route_id: 8 },
    status: "running",
    cancel_requested: false,
    execution_context: {
      descriptors: [],
      result: {} as Record<string, unknown>,
    },
    steps: [] as { name: string; status: string }[],
    step_results: {} as Record<string, unknown>,
  };
  children = new Map<
    string,
    {
      id: number;
      key: string;
      kind: string;
      node_id: number;
      status: string;
      error_code: string | null;
      result: Record<string, unknown>;
    }
  >();
  calls: { path: string; body: Record<string, unknown> }[] = [];
  resources = new Map<string, unknown>();
  keys = [
    "host-certificate.node-2-route-8-router",
    "host-caddy.node-2",
    "dns-published.node-17",
  ];
  get childKeys() {
    return [
      ...this.keys,
      ...(this.public
        ? [
          "public-edge-verified.ingress",
          "public-activated.node-3",
          "ingress-firewall.ingress",
        ]
        : []),
    ];
  }
  http: Http = (method, path, raw) =>
    Promise.resolve().then(() => {
      const body = (raw ?? {}) as Record<string, unknown>;
      this.calls.push({ path, body });
      if (path === "/api/v1/operations/17") {
        return {
          status: 200,
          body: { data: { ...this.operation, result: null, error_code: null } },
        };
      }
      if (method === "GET") {
        return { status: 200, body: { data: [...this.children.values()] } };
      }
      let data: unknown = this.operation;
      if (path.endsWith("/run")) {
        const input = body.input as Record<string, unknown>;
        data = input.action === "prepare"
          ? {
            prepared: true,
            mode: this.mode ?? this.operation.kind,
            production: this.production,
            public: this.public,
            remote_router: this.remoteRouter,
            web_root: this.webRoot,
            targeted: false,
            child_phases: this.childPhases ?? this.childKeys.map((key) => ({
              stage: key.split(".")[0],
              key,
            })),
          }
          : input.action === "open"
          ? { opened: true }
          : { ready: true, stage: input.stage };
      }
      if (path.endsWith("/children")) {
        const key = String(body.key);
        assertEquals(Object.keys(body).sort(), ["key", "worker"]);
        if (!this.children.has(key)) {
          this.children.set(key, {
            id: 40 + this.children.size,
            key,
            kind: key.startsWith("host-certificate")
              ? "certificate.converge"
              : "route.caddy_converge",
            node_id: key.includes("ingress") || key.includes("node-3") ? 3 : 2,
            status: this.immediate ? "succeeded" : "queued",
            error_code: null,
            result: { ready: true },
          });
        }
        data = {
          ...this.children.get(key),
          input: {},
          cancel_requested: false,
          step_results: {},
        };
      }
      if (path.endsWith("/step")) {
        const name = String(body.name),
          status = String(body.status),
          step = this.operation.steps.find((step) => step.name === name);
        if (step) step.status = status;
        else this.operation.steps.push({ name, status });
        if (status === "succeeded") {
          this.operation.step_results[name] = body.result;
        }
      }
      if (path.endsWith("/wait")) {
        data = { ...this.operation, status: "waiting" };
      }
      if (path.endsWith("/complete") && this.loseComplete) {
        this.loseComplete = false;
        throw new Error("lost parent completion reply");
      }
      return { status: path.endsWith("/children") ? 202 : 200, body: { data } };
    });
  async execute() {
    const { context } = createModelTestContext({
      globalArgs: {
        gatewayUrl: "https://gateway.example.test",
        caFile: "/unused",
      },
    });
    const ctx = Object.assign(context, {
      globalArgs: {
        gatewayUrl: "https://gateway.example.test",
        caFile: "/unused",
      },
      readResource: (name: string) =>
        Promise.resolve(this.resources.get(name) ?? null),
      writeResource: (_spec: string, name: string, value: unknown) => {
        this.resources.set(name, value);
        return Promise.resolve({ name });
      },
    }) as Context;
    return await routes(() => this.http).methods.execute.execute(
      { kind: this.operation.kind, worker: "worker" },
      ctx,
    );
  }
  settle() {
    for (const child of this.children.values()) child.status = "succeeded";
  }
}
