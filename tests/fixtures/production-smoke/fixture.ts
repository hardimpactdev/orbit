import { recordQuery } from "../worker_query.ts";
import { createModelTestContext } from "jsr:@swamp-club/swamp-testing@0.20261016.58";
import {
  type Context,
  productionSmoke,
} from "../../../extensions/behaviors/production-smoke/mod.ts";
import { type Http } from "../../../extensions/behaviors/automatic-deploy/gateway.ts";
export const config = {
  projectId: 20,
  repository: "git@github.com:nckrtl/website.git",
  check: "composer check",
  defaultBranch: "main",
  worker: { composerPaths: ["."], jsPaths: [], policy: {} },
  productionSmoke: {
    instance: 7,
    baseUrl: "https://nckrtl.com",
    workflow: ".github/workflows/production-smoke.yml",
    ref: "main",
    timeoutSeconds: 600,
  },
};
export const selected = {
  deployment_id: 9,
  commit: "a".repeat(40),
  release: "release-9",
};
export const observation = {
  instance_id: 7,
  project_id: 20,
  repository_url: config.repository,
  production: true,
  base_urls: ["https://nckrtl.com"],
  selected,
  retained_releases: [selected.release],
};
export function operation(input: unknown) {
  return {
    id: 42,
    kind: "instance.smoke",
    target_type: "instance_smoke",
    target_id: 7,
    input,
    status: "succeeded",
    error_code: null as string | null,
  };
}
export function passed(input: unknown) {
  return {
    ...(input as object),
    state: "passed",
    run_id: 123,
    run_attempt: 1,
    workflow_sha: "b".repeat(40),
    run_url: "https://github.com/nckrtl/website/actions/runs/123",
    conclusion: "success",
    tests: 2,
    failures: 0,
    errors: 0,
    skipped: 0,
    output: "2 passed",
    artifacts: [],
  };
}
export class Fixture {
  project = structuredClone(config) as
    & Omit<typeof config, "productionSmoke">
    & { productionSmoke?: typeof config.productionSmoke };
  observed = structuredClone(observation);
  requests: { method: string; path: string; body: unknown; key?: string }[] =
    [];
  now = Date.parse("2026-10-28T12:00:00.000Z");
  lost = false;
  lostBefore = false;
  refusal: { status: number; code: string } | null = null;
  admitted = new Map<string, ReturnType<typeof operation>>();
  outage = false;
  onReceipt: (() => void) | null = null;
  receipt: {
    request_key: string;
    found: boolean;
    operation: ReturnType<typeof operation> | null;
    smoke: ReturnType<typeof passed> | null;
  } = { request_key: "smoke.7.9", found: false, operation: null, smoke: null };
  stored = new Map<string, Record<string, unknown>>();
  http: Http = (method, path, body, key) =>
    Promise.resolve().then(() => {
      this.requests.push({ method, path, body, key });
      if (this.outage) throw new Error("fixture read outage");
      if (path.endsWith("/context")) {
        return { status: 200, body: { data: structuredClone(this.observed) } };
      }
      if (method === "POST") {
        if (this.lostBefore) throw new Error("fixture lost before admission");
        if (this.refusal) {
          return {
            status: this.refusal.status,
            body: {
              error: {
                code: this.refusal.code,
                message: "Fixture admission refusal.",
              },
            },
          };
        }
        if (!this.admitted.has(key!)) this.admitted.set(key!, operation(body));
        this.receipt = {
          request_key: key!,
          found: true,
          operation: this.admitted.get(key!)!,
          smoke: null,
        };
        if (this.lost) throw new Error("fixture lost response");
        return { status: 202, body: { data: this.receipt.operation } };
      }
      this.onReceipt?.();
      return { status: 200, body: { data: structuredClone(this.receipt) } };
    });
  context(): Context {
    const { context } = createModelTestContext();
    return {
      ...context,
      globalArgs: {
        projectId: 20,
        projectType: "@nckrtl/project-native-smoke",
        gatewayUrl: "https://gateway.orbit",
        caFile: "/unused",
      },
      invokeModel: () => Promise.resolve({ ok: true }),
      queryData: recordQuery(() =>
        Promise.resolve([{ ...this.project, schema: 3 }])
      ),
      readResource: (name: string) =>
        Promise.resolve(this.stored.get(name) ?? null),
      writeResource: async (
        spec: string,
        name: string,
        value: Record<string, unknown>,
      ) => {
        this.stored.set(name, structuredClone(value));
        return await context.writeResource(spec, name, value);
      },
    };
  }
  async tick(mode: "observe" | "baseline" = "observe") {
    await productionSmoke(() => this.http, () => this.now).methods.tick.execute(
      { mode },
      this.context(),
    );
    return this.stored.get("state")!;
  }
  pass() {
    this.receipt.smoke = passed(this.receipt.operation!.input);
  }
  get posts() {
    return this.requests.filter((r) => r.method === "POST");
  }
}
