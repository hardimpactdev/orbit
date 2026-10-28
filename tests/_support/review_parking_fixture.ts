import { assert } from "jsr:@std/assert@1.0.19";
import {
  type Admission,
  AdmissionSchema,
} from "../../extensions/behaviors/_lib/project_admission.ts";
import type { Http } from "../../extensions/behaviors/_lib/gateway_stage.ts";

export const success = JSON.parse(
  await Deno.readTextFile(
    new URL("../fixtures/review-parking/succeeded.json", import.meta.url),
  ),
);
export const refusal = JSON.parse(
  await Deno.readTextFile(
    new URL("../fixtures/review-parking/refused.json", import.meta.url),
  ),
);
export const operation = success.body.data;
export const group = () => ({
  id: operation.target_id,
  project_id: 20,
  taskable_id: operation.result.instance_id,
  orchestration: "external",
  status: "running",
  pr_url: "https://github.com/acme/park/pull/1",
  watched_pr_state: "open",
  vm_power: "running",
  tasks: [{ status: "completed" }],
});
export const admission = (): Admission => ({
  key: "batch-20",
  projectId: 20,
  mode: "package",
  workItem: `tg-${operation.target_id}`,
  released: false,
  admittedAt: "2026-10-28T00:00:00Z",
});
export function fixture() {
  let saved = admission(), now = 1000;
  const g = group();
  const calls: string[] = [];
  const http: Http = (method, path, _body, key) => {
    calls.push(`${method} ${path}${key ? ` ${key}` : ""}`);
    if (path === `/api/v1/task-groups/${g.id}/park`) {
      return Promise.resolve({
        status: 202,
        body: {
          data: { ...operation, status: "queued", poll_after_seconds: 1 },
        },
      });
    }
    if (path.startsWith("/api/v1/operations/")) {
      g.status = "waiting_for_review";
      g.vm_power = "stopped";
      return Promise.resolve({ status: success.status, body: success.body });
    }
    return Promise.resolve({ status: 200, body: { data: g } });
  };
  const deps = {
    http,
    now: () => now,
    save: (a: Admission) => {
      saved = AdmissionSchema.parse(structuredClone(a));
      return Promise.resolve();
    },
  };
  return {
    g,
    calls,
    deps,
    read: () => saved,
    tick: () => {
      now += 60_000;
    },
  };
}

/** Probe reservation itself, rather than the driver's earlier group-list reads. */
export function reservationLockFixture() {
  let observed = false;
  return {
    observed: () => observed,
    observe: async (dir: string) => {
      if (observed) return;
      const status = await new Deno.Command("swamp", {
        args: ["datastore", "lock", "status", "--json"],
        cwd: dir,
        stdout: "piped",
        stderr: "piped",
      }).output();
      assert(status.success);
      assert(
        new TextDecoder().decode(status.stdout).includes(
          "orbit-gateway/f5fa45d6-6307-48b6-a094-9aae8f79febc",
        ),
        "reservation must run under the real gateway behavior lock",
      );
      observed = true;
    },
    install: async (dir: string) => {
      const module = `${dir}/extensions/behaviors/_lib/project_admission.ts`;
      const source = await Deno.readTextFile(module);
      const original =
        'const response = await deps.http("GET", "/api/v1/task-groups");';
      assert(source.includes(original));
      await Deno.writeTextFile(
        module,
        source.replace(
          original,
          'await deps.http("GET", "/fixture/reservation-lock");\n  ' + original,
        ),
      );
    },
  };
}

export function evidenceHttp(
  f: ReturnType<typeof fixture>,
  invalid: string,
): Http {
  return async (...args) => {
    if (!args[1].startsWith("/api/v1/operations/")) {
      return await f.deps.http(...args);
    }
    f.g.status = "waiting_for_review";
    f.g.vm_power = invalid === "running guest" ? "running" : "stopped";
    if (invalid === "child") f.g.tasks[0].status = "running";
    if (invalid === "assistance") {
      Object.assign(f.g, { assistance_requested: true });
    }
    return {
      status: 200,
      body: {
        data: {
          ...operation,
          ...(invalid === "foreign" ? { target_id: 999 } : {}),
          status: ["queued", "failed", "cancelled"].includes(invalid)
            ? invalid
            : "succeeded",
        },
      },
    };
  };
}

export function capacityFixture(
  admissions: Admission[],
  groups: Record<string, unknown>[],
) {
  return {
    admissions,
    deps: {
      admissions,
      existing: [],
      http: () => Promise.resolve({ status: 200, body: { data: groups } }),
      save: (a: Admission) => {
        const index = admissions.findIndex((r) => r.key === a.key);
        if (index < 0) admissions.push(a);
        else admissions[index] = a;
        return Promise.resolve();
      },
    },
  };
}

export function reconciliationFixture(
  f: ReturnType<typeof fixture>,
  unavailable: boolean,
) {
  const issues: (string | null)[] = [];
  return {
    issues,
    deps: {
      admissions: [f.read()],
      receipts: [],
      save: f.deps.save,
      saveReceipt: () =>
        Promise.reject(new Error("no schedule receipt to change")),
      assistance: (_a: Admission, reason: string | null) => {
        issues.push(reason);
        return Promise.resolve();
      },
      http: () =>
        Promise.resolve(
          unavailable
            ? { status: 503, body: null }
            : { status: 200, body: { data: f.g } },
        ),
    },
  };
}

/** Each independent fake run owns a Project; group 74 exercises the legacy configuration. */
export function independentProjects(
  fake: ReturnType<typeof import("./fake_gateway.ts").startFakeGateway>,
  project:
    import("../../extensions/behaviors/_lib/dependency_projects.ts").DependencyProject,
) {
  return [...fake.groups.values()].filter((g) => g.id !== 74).map((g) => {
    g.project_id = 1000 + g.id;
    g.scenario.projectId = g.project_id;
    fake.projects.set(g.project_id, {
      ...fake.projects.get(20)!,
      id: g.project_id,
    });
    return { ...project, projectId: g.project_id };
  });
}
