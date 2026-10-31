import { taskInputs, todoError } from "./task_contract.ts";
import { behavior } from "../../extensions/behaviors/orbit_gateway.ts";
import { behavior as projectBehavior } from "../../extensions/behaviors/projects/nckrtl-com/mod.ts";
import { type Findings } from "../../extensions/behaviors/_lib/project_schedule.ts";
import {
  policyIdentity,
  sources,
} from "../../extensions/behaviors/_lib/dependency_results.ts";
import {
  type Index,
  indexName,
  repositoryIdentity,
} from "../../extensions/behaviors/_lib/dependency_results_schema.ts";
export const original = JSON.parse(
  await Deno.readTextFile(
    new URL("../fixtures/project-extensions/nckrtl-com.json", import.meta.url),
  ),
);
export const reports = JSON.parse(
  await Deno.readTextFile(
    new URL("../fixtures/project-extensions/detection.json", import.meta.url),
  ),
);

export function fixture(token?: Record<string, unknown>) {
  const data = new Map<string, unknown>();
  const groups = new Map<number, Record<string, unknown>>();
  let posts = 0;
  let starts = 0;
  let loseStart = false;
  let mismatch = false;
  let createStatus = 201, createAttempts = 0;
  const server = Deno.serve(
    { hostname: "127.0.0.1", port: 0, onListen() {} },
    async (req) => {
      const url = new URL(req.url);
      const json = (value: unknown, status = 200) =>
        Response.json({ data: value }, { status });
      if (url.pathname === "/api/v1/projects/20/dependency-read-token") {
        return json(token ?? null, token ? 200 : 403);
      }
      if (url.pathname === "/api/v1/projects/20") {
        return json({
          id: 20,
          repository_url: original.repository,
          task_check: mismatch ? "different" : original.check,
          default_branch: original.defaultBranch,
        });
      }
      if (
        url.pathname === "/api/v1/task-groups" && req.method === "GET"
      ) return json([...groups.values()]);
      if (url.pathname === "/api/v1/task-groups" && req.method === "POST") {
        createAttempts++;
        if (createStatus !== 201) {
          return Response.json({ error: { code: "validation.failed" } }, {
            status: createStatus,
          });
        }
        const body = await req.json();
        if (!taskInputs.safeParse(body.tasks).success) {
          return Response.json({ error: { code: "validation.failed" } }, {
            status: 422,
          });
        }
        const id = ++posts;
        const group = {
          ...body,
          id,
          taskable_id: null,
          vm_power: null,
          tasks: body.tasks.map((t: unknown) => ({
            ...(t as object),
            id: id * 10,
            status: "todo",
          })),
        };
        groups.set(id, group);
        return json(group, 201);
      }
      const id = Number(url.pathname.split("/").at(-1));
      if (groups.has(id) && req.method === "PATCH") {
        const body = await req.json();
        const group = groups.get(id)!;
        const error = body.status === "todo" ? todoError(group.tasks) : null;
        if (error) {
          return Response.json({ error: { code: error } }, { status: 422 });
        }
        Object.assign(group, body);
      }
      return groups.has(id) ? json(groups.get(id)) : json(null, 404);
    },
  );
  const ctx = {
    globalArgs: behavior.globalArguments.parse({
      // Loopback HTTP uses no TLS, but curl still requires the --cacert path to exist.
      caFile: "/dev/null",
      gatewayUrl: `http://127.0.0.1:${server.addr.port}`,
      projectExtensions: [{
        projectId: 20,
        type: "@nckrtl/project-nckrtl-com",
      }],
    }),
    definition: { name: "orbit-gateway" },
    queryData: (predicate: string, select?: string) => {
      const model = JSON.parse(predicate.match(/modelName == ("[^"]*")/)![1]);
      const name = predicate.match(/name == ("[^"]*")/);
      const prefix = predicate.match(/name.startsWith\(("[^"]*")\)/);
      const values = [...data].filter(([key]) =>
        key.startsWith(`${model}/`) &&
        (name
          ? key === `${model}/${JSON.parse(name[1])}`
          : prefix
          ? key.slice(model.length + 1).startsWith(JSON.parse(prefix[1]))
          : true)
      ).map(([, value]) => structuredClone(value));
      return Promise.resolve(
        select === "attributes.runs"
          ? values.map((v) => (v as { runs: unknown }).runs)
          : select === "attributes"
          ? values
          : values.map((attributes) => ({ attributes })),
      );
    },
    writeResource: (
      _spec: string,
      name: string,
      value: Record<string, unknown>,
    ) => {
      data.set(`orbit-gateway/${name}`, structuredClone(value));
      return Promise.resolve({ name });
    },
    logger: { info() {} },
    vaultService: {
      get: () =>
        Promise.reject(new Error("must not read secrets in scheduler")),
    },
    invokeModel: async (
      args: {
        definition?: string;
        modelType?: string;
        name?: string;
        method: string;
        arguments?: Record<string, unknown>;
      },
    ) => {
      if (args.modelType === projectBehavior.type) {
        await projectBehavior.methods.configuration.execute({}, {
          readResource: (name) =>
            Promise.resolve(
              data.get(`${args.name}/${name}`) as Record<string, unknown> ??
                null,
            ),
          writeResource: (_spec, name, value) => {
            data.set(`${args.name}/${name}`, value);
            return Promise.resolve({ name });
          },
        });
        return { ok: true };
      }
      if (args.modelType === "@swamp/git" && args.method === "remote_ref") {
        data.set(`${args.name}/remote-ref-refs-heads-main`, {
          sha: "a".repeat(40),
          ref: "refs/heads/main",
        });
        return { ok: true };
      }
      if (args.method === "start") {
        starts++;
        data.set(`${args.definition}/state-${args.arguments!.workItem}`, {
          workItem: args.arguments!.workItem,
          stageId: "provision",
          status: "active",
        });
        if (loseStart) {
          loseStart = false;
          return { ok: false, error: { message: "lost start reply" } };
        }
      }
      if (
        args.definition === "dependency-package" && args.method === "status" &&
        !args.arguments?.workItem
      ) {
        data.set("dependency-package/status-_factory", {
          runs: [...data].filter(([key]) =>
            key.startsWith("dependency-package/state-")
          ).map(([, value]) => value),
        });
      }
      return { ok: true };
    },
  };
  return {
    ctx,
    data,
    groups,
    close: () => server.shutdown(),
    posts: () => posts,
    createAttempts: () => createAttempts,
    createStatus: (status: number) => {
      createStatus = status;
    },
    starts: () => starts,
    loseStart: () => {
      loseStart = true;
    },
    mismatch: () => {
      mismatch = true;
    },
  };
}

/** A coherent, current producer result at the scheduler boundary; producer internals have their own tests. */
export async function seedServicePlan(
  f: ReturnType<typeof fixture>,
  findings: Findings,
) {
  const now = new Date().toISOString(),
    ref = "refs/heads/main",
    sourceCommit = "a".repeat(40);
  const value = {
    ...findings,
    sourceCommit,
    sourceRef: ref,
    policyIdentity: await policyIdentity(original),
  };
  const attempt = {
    run_id: findings.source,
    started_at: now,
    finished_at: now,
    status: "succeeded" as const,
    error_code: null,
  };
  const publication = crypto.randomUUID();
  const index: Index = {
    schema_version: 1,
    publication_id: publication,
    project_id: 20,
    repository: repositoryIdentity(original.repository),
    source_ref: ref,
    source_commit: sourceCommit,
    generated_at: now,
    last_attempt: attempt,
    sources: sources(original).map((identity) => ({
      ...identity,
      last_attempt: attempt,
      report: {
        ...identity,
        modelIdOrName: "orbit-gateway",
        dataName: `dependencies-v1-report-${publication}-${"0".repeat(64)}`,
        version: 1,
        source_commit: sourceCommit,
        observed_at: now,
        publication_id: publication,
      },
    })),
    batch_pr: {
      status: "absent",
      checked_at: now,
      last_success_at: now,
      error_code: null,
      value: null,
    },
  };
  f.data.set(`orbit-gateway/${await indexName(20, ref)}`, index);
  f.data.set("orbit-gateway/findings-20", value);
  return value;
}
