import { behavior as composer } from "../composer-maintenance/mod.ts";
import { behavior as js } from "../js-maintenance/mod.ts";
import {
  assertProjectSettings,
  type DependencyProject,
} from "./dependency_projects.ts";
import { serviceWorkspace } from "./service_workspace.ts";
import { withServiceTools } from "./maintenance/tool_policy.ts";
import { inventory } from "./dependency_inventory.ts";
import {
  CommitSchema,
  type Index,
  indexName,
  IndexSchema,
  type Pr,
  PrSchema,
  ReportSchema,
  repositoryIdentity,
  type SourceIdentity,
  sourceKey,
} from "./dependency_results_schema.ts";
import {
  coalescePr,
  policyIdentity,
  refreshLifecycle,
  type ResultStore,
  scanProject,
  sources,
} from "./dependency_results.ts";
import { type Http } from "./gateway_stage.ts";
import { BatchPlanSchema, type Candidate } from "./maintenance/batch.ts";
import { command } from "./maintenance/io.ts";
import { type Context } from "./maintenance/context.ts";
import { type Findings } from "./project_schedule.ts";
import { aliases, lock, workspace } from "../js-maintenance/_lib/workspace.ts";
import { scanPnpm } from "../js-maintenance/_lib/locked.ts";
import { join } from "node:path";

export interface DependencyContext {
  definition: { name: string };
  signal?: AbortSignal;
  readResource?: (
    name: string,
    version?: number,
  ) => Promise<Record<string, unknown> | null>;
  queryData(predicate: string, select?: string): Promise<unknown[]>;
  writeResource(
    spec: string,
    name: string,
    data: Record<string, unknown>,
    overrides?: { tags: Record<string, string> },
  ): Promise<{ name: string; version?: number }>;
  runModel(
    args: {
      definition?: string;
      modelType?: string;
      name?: string;
      method: string;
      arguments?: Record<string, unknown>;
    },
  ): Promise<{ ok: boolean; resources?: { name: string; version?: number }[] }>;
}
const query = (ctx: DependencyContext, name: string) =>
  ctx.queryData(
    `modelName == ${JSON.stringify(ctx.definition.name)} && name == ${
      JSON.stringify(name)
    }`,
    "attributes",
  );
export function resultStore(ctx: DependencyContext): ResultStore {
  return {
    readIndex: async (name) => {
      const values = await query(ctx, name);
      if (values.length > 1) throw new Error("duplicate_dependency_index");
      return values.length ? IndexSchema.parse(values[0]) : null;
    },
    readReport: async (name, version) => {
      if (!ctx.readResource) {
        throw new Error("native_resource_reader_unavailable");
      }
      return ReportSchema.parse(await ctx.readResource(name, version));
    },
    writeIndex: async (name, value) => {
      await ctx.writeResource(
        "dependencyIndex",
        name,
        IndexSchema.parse(value),
        { tags: tags(value, value.source_ref, "index") },
      );
    },
    writeReport: async (name, value, ref) => {
      if ((await query(ctx, name)).length) {
        throw new Error("immutable_report_exists");
      }
      const handle = await ctx.writeResource(
        "dependencyReport",
        name,
        ReportSchema.parse(value),
        { tags: tags(value, ref, "report") },
      );
      if (
        !Number.isSafeInteger(handle.version) || Number(handle.version) <= 0
      ) throw new Error("native_report_version_unavailable");
      return handle.version!;
    },
  };
}
function tags(
  v: { project_id: number; repository: string },
  ref: string,
  kind: string,
) {
  return {
    contract: "orbit.dependencies.v1",
    kind,
    project_id: String(v.project_id),
    repository: v.repository,
    source_ref: ref,
  };
}
async function collect<T>(
  action: (ctx: Context) => Promise<unknown>,
  spec: string,
  signal?: AbortSignal,
): Promise<T> {
  let value: unknown;
  await action({
    signal,
    writeResource: (key, name, data) => {
      if (key === spec) value = data;
      return Promise.resolve({ name });
    },
  });
  if (!value) throw new Error("ecosystem_result_missing");
  return value as T;
}
function common(project: DependencyProject, root: string, paths: string[]) {
  return {
    cwd: root,
    paths,
    policy: Object.fromEntries(
      Object.entries(project.worker.policy).filter(([p]) => paths.includes(p)),
    ),
    exceptions: project.exceptions,
  };
}
async function detect(
  root: string,
  source: SourceIdentity,
  project: DependencyProject,
  signal?: AbortSignal,
) {
  const packages = await inventory(root, source);
  let identities: Record<string, string> = {};
  if (source.package_manager === "pnpm") {
    return {
      packages,
      candidates: await scanPnpm(
        `${root}/${source.path}`,
        source.path,
        packages,
        signal,
      ),
    };
  }
  if (source.ecosystem === "npm") {
    const actual = await workspace({
      path: source.path,
      dir: `${root}/${source.path}`,
    });
    if (actual.manager !== source.package_manager) {
      throw new Error("package_manager_changed");
    }
    identities = aliases(await lock(actual), actual.manager);
  }
  const behavior = source.ecosystem === "composer" ? composer : js;
  const result = await collect<{ candidates: Candidate[] }>(
    (ctx) =>
      behavior.methods.detect.execute(
        common(project, root, [source.path]),
        ctx,
      ),
    "detection",
    signal,
  );
  return { packages, candidates: result.candidates, identities };
}

/** Only short native writes take the gateway model lock; package work owns the scanner lock. */
export async function serviceDetection(
  project: DependencyProject,
  runId: string,
  ctx: DependencyContext,
  http: Http,
) {
  const signal = AbortSignal.any([
    ctx.signal ?? new AbortController().signal,
    AbortSignal.timeout(120 * 1000),
  ]);
  const store = resultStore(ctx);
  let tools: Awaited<ReturnType<typeof serviceWorkspace>> | null = null;
  let links: BatchLink[] = [];
  return await scanProject(project, runId, {
    store,
    now: () => new Date().toISOString(),
    acquire: async () => {
      await assertProjectSettings(project, http);
      const root = await Deno.makeTempDir({
        prefix: `dependency-source-${project.projectId}-`,
      });
      try {
        const result = await command(
          root,
          "git",
          [
            "-c",
            "core.hooksPath=/dev/null",
            "clone",
            "--no-checkout",
            "--depth",
            "1",
            "--branch",
            project.defaultBranch,
            "--",
            project.repository,
            root,
          ],
          signal,
          { GIT_TERMINAL_PROMPT: "0" },
        );
        if (result.code) throw new Error("source_acquisition_failed");
        const tree = await command(root, "git", ["read-tree", "HEAD"], signal);
        if (tree.code) throw new Error("source_tree_unavailable");
        tools = await serviceWorkspace(root, signal);
        const resultHead = await command(
          root,
          "git",
          ["rev-parse", "HEAD"],
          signal,
        );
        if (resultHead.code !== 0) throw new Error("source_head_unavailable");
        const commit = CommitSchema.parse(resultHead.stdout.trim());
        return {
          root: tools.root,
          commit,
          close: async () => {
            await tools!.close();
            await Deno.remove(root, { recursive: true });
          },
        };
      } catch (error) {
        if (tools) await tools.close();
        await Deno.remove(root, { recursive: true });
        throw error;
      }
    },
    detect: (root, source, project) =>
      withServiceTools(
        tools!.policy,
        () => detect(root, source, project, signal),
      ),
    plan: (root, project, candidates) =>
      withServiceTools(tools!.policy, async () => {
        const plans = [];
        for (
          const [manager, paths, behavior] of [
            ["composer", project.worker.composerPaths, composer],
            ["js", project.worker.jsPaths, js],
          ] as const
        ) {
          if (!paths.length) continue;
          const supported = paths.filter((p) =>
            project.worker.jsPackageManagers?.[p] !== "pnpm" ||
            manager === "composer"
          );
          const selected = candidates.filter((c) =>
            c.manager === manager && supported.includes(c.path)
          );
          const plan = supported.length
            ? BatchPlanSchema.parse(
              await collect(
                (ctx) =>
                  behavior.methods.plan.execute(
                    {
                      ...common(project, root, supported),
                      candidates: selected,
                    } as never,
                    ctx,
                  ),
                "plan",
                signal,
              ),
            )
            : { schema: 1 as const, targets: [], blocked: [] };
          plan.blocked.push(
            ...candidates.filter((c) =>
              c.manager === manager && !supported.includes(c.path)
            ).map((c) => ({
              path: c.path,
              manager: c.manager,
              package: c.package,
              reason: "The package manager has no verified update planner.",
            })),
          );
          plans.push(plan);
        }
        return plans;
      }),
    pr: async (p, previous) => {
      const e = await batchEvidence(p, previous, { ...ctx, signal }, http);
      links = e.links;
      return e.pr;
    },
    batchLinks: () => links,
    saveFindings: async (value) => {
      await ctx.writeResource(
        "findings",
        `findings-${project.projectId}`,
        value,
      );
    },
  });
}

export type BatchLink = {
  group_id: number;
  run_id: string;
  targets: {
    path: string;
    manager: string;
    package: string;
    version: string;
  }[];
};
/** Only successful authoritative group + GitHub lookups prove PR absence. */
export async function batchEvidence(
  project: DependencyProject,
  previous: Pr | null,
  ctx: DependencyContext,
  http: Http,
  github: (url: string) => Promise<Record<string, unknown>> = (url) =>
    githubPr(url, ctx.signal),
): Promise<{ pr: Pr; links: BatchLink[] }> {
  const checked = new Date().toISOString(),
    repository = repositoryIdentity(project.repository),
    links: BatchLink[] = [];
  try {
    ctx.signal?.throwIfAborted();
    const response = await http(
      "GET",
      `/api/v1/task-groups?project_id=${project.projectId}`,
    );
    if (response.status !== 200 || !Array.isArray(response.body?.data)) {
      throw new Error("groups_unavailable");
    }
    const targets = await ctx.queryData(
      `modelName == ${
        JSON.stringify(ctx.definition.name)
      } && name.startsWith("package-")`,
      "attributes",
    ) as { workItem: string; targets: BatchLink["targets"] }[];
    let value: Pr["value"] = null;
    for (const g of response.body.data as Record<string, unknown>[]) {
      ctx.signal?.throwIfAborted();
      if (g.project_id !== project.projectId) continue;
      const target = targets.find((t) => t.workItem === `tg-${g.id}`);
      if (!target) continue;
      const owners = await ctx.queryData(
        `modelName == ${
          JSON.stringify(`orbit-group-${target.workItem}`)
        } && name == "owner"`,
        "attributes",
      ) as { runId?: string }[];
      const publish = await ctx.queryData(
        `modelName == ${
          JSON.stringify(ctx.definition.name)
        } && name.startsWith(${
          JSON.stringify(`job-${target.workItem}.publish.c`)
        })`,
        "attributes",
      ) as {
        state: string;
        artifacts?: Record<string, Record<string, unknown>>;
      }[];
      const urls = [
        ...new Set([
          g.pr_url,
          g.watched_pr_url,
          ...publish.filter((p) => p.state === "succeeded").map((p) =>
            p.artifacts?.["pull-request"]?.url
          ),
        ].filter((v) => typeof v === "string")),
      ] as string[];
      if (
        urls.length > 1 || (publish.some((p) =>
          p.state === "succeeded"
        ) && !urls.length) ||
        (!urls.length &&
          (!Object.hasOwn(g, "pr_url") || !Object.hasOwn(g, "watched_pr_url")))
      ) throw new Error("pr_evidence_incomplete");
      const runId = owners[0]?.runId;
      if (
        !["completed", "cancelled", "failed"].includes(String(g.status)) &&
        runId
      ) {
        links.push({
          group_id: Number(g.id),
          run_id: runId,
          targets: target.targets,
        });
      }
      if (!urls.length) continue;
      if (
        !new RegExp(
          `^https://${
            repository.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")
          }/pull/[1-9][0-9]*$`,
        ).test(urls[0])
      ) throw new Error("pr_identity_mismatch");
      const pr = await github(urls[0]);
      if (
        pr.url !== urls[0] ||
        !["OPEN", "CLOSED", "MERGED"].includes(String(pr.state))
      ) throw new Error("pr_lookup_invalid");
      if (pr.state !== "OPEN") continue;
      if (!runId || value) throw new Error("batch_pr_ambiguous");
      value = {
        repository,
        number: Number(pr.number),
        url: urls[0],
        head_commit: CommitSchema.parse(pr.headRefOid),
        group_id: Number(g.id),
        run_id: runId,
      };
    }
    return {
      pr: PrSchema.parse({
        status: value ? "open" : "absent",
        checked_at: checked,
        last_success_at: checked,
        error_code: null,
        value,
      }),
      links,
    };
  } catch {
    return {
      pr: PrSchema.parse({
        status: "unknown",
        checked_at: checked,
        last_success_at: previous?.last_success_at ?? null,
        error_code: "batch_pr_lookup_failed",
        value: previous?.value ?? null,
      }),
      links: [],
    };
  }
}
export async function githubPr(
  url: string,
  signal?: AbortSignal,
  timeoutMs = 3000,
) {
  const bounded = AbortSignal.any([
    signal ?? new AbortController().signal,
    AbortSignal.timeout(timeoutMs),
  ]);
  bounded.throwIfAborted();
  // This runs outside withServiceTools: use the service account's gh config,
  // never the disposable package-tool HOME or a repository-controlled credential.
  const home = Deno.env.get("HOME");
  if (!home) throw new Error("github_credential_home_unavailable");
  const config = join(
    Deno.env.get("XDG_CONFIG_HOME") ?? join(home, ".config"),
    "gh",
  );
  const out = await command(
    Deno.cwd(),
    "gh",
    [
      "pr",
      "view",
      url,
      "--json",
      "number,url,headRefOid,state",
    ],
    bounded,
    { GH_CONFIG_DIR: config, GH_PROMPT_DISABLED: "1" },
  );
  if (out.code) throw new Error("github_lookup_failed");
  return JSON.parse(out.stdout);
}

export async function reconcileDependencyIndex(
  project: DependencyProject,
  index: Index,
  ctx: DependencyContext,
  http: Http,
) {
  const now = new Date().toISOString();
  if (
    index.last_attempt.status === "running" &&
    Date.now() - Date.parse(index.last_attempt.started_at) > 180000
  ) {
    const failed = {
      ...index.last_attempt,
      status: "failed" as const,
      finished_at: now,
      error_code: "scan_interrupted",
    };
    index = IndexSchema.parse({
      ...index,
      publication_id: crypto.randomUUID(),
      generated_at: now,
      last_attempt: failed,
      sources: index.sources.map((s) => ({ ...s, last_attempt: failed })),
    });
    await resultStore(ctx).writeIndex(
      await indexName(index.project_id, index.source_ref),
      index,
    );
  }
  const evidence = await batchEvidence(project, index.batch_pr, ctx, http);
  evidence.pr = coalescePr(index.batch_pr, evidence.pr);
  // Lookup uncertainty retains existing finding links as well as the last known PR value.
  if (evidence.pr.status === "unknown") {
    if (evidence.pr === index.batch_pr) return;
    const store = resultStore(ctx);
    await store.writeIndex(
      await indexName(index.project_id, index.source_ref),
      IndexSchema.parse({
        ...index,
        publication_id: crypto.randomUUID(),
        generated_at: new Date().toISOString(),
        batch_pr: evidence.pr,
      }),
    );
    return;
  }
  await refreshLifecycle(
    index,
    evidence.pr,
    evidence.links,
    resultStore(ctx),
    new Date().toISOString(),
  );
}

/** Revalidate both immutable head and all config/policy before the existing admission path. */
export async function assertCurrentPlan(
  project: DependencyProject,
  findings: Findings,
  ctx: DependencyContext,
) {
  if (
    !findings.sourceCommit ||
    findings.sourceRef !== `refs/heads/${project.defaultBranch}` ||
    findings.policyIdentity !== await policyIdentity(project)
  ) throw new Error("fresh_service_detection_required");
  const index = await resultStore(ctx).readIndex(
    await indexName(project.projectId, findings.sourceRef),
  );
  if (
    !index || index.last_attempt.status !== "succeeded" ||
    index.project_id !== project.projectId ||
    index.repository !== repositoryIdentity(project.repository) ||
    index.sources.map(sourceKey).sort().join("\n") !==
      sources(project).map(sourceKey).sort().join("\n") ||
    index.last_attempt.run_id !== findings.source ||
    index.source_commit !== findings.sourceCommit ||
    Date.now() - Date.parse(index.last_attempt.finished_at!) >= 26 * 3600000
  ) throw new Error("fresh_service_detection_required");
  const name = `dependency-source-${project.projectId}`;
  const result = await ctx.runModel({
    modelType: "@swamp/git",
    name,
    method: "remote_ref",
    arguments: { remote: project.repository, ref: findings.sourceRef },
  });
  if (!result.ok) throw new Error("source_head_unavailable");
  const heads = await ctx.queryData(
    `modelName == ${JSON.stringify(name)} && name == ${
      JSON.stringify(`remote-ref-${findings.sourceRef.replaceAll("/", "-")}`)
    }`,
    "attributes",
  ) as { sha?: string; ref?: string }[];
  if (
    heads.length !== 1 || heads[0].sha !== findings.sourceCommit ||
    heads[0].ref !== findings.sourceRef
  ) {
    if (
      heads.length === 1 && heads[0].ref === findings.sourceRef &&
      CommitSchema.safeParse(heads[0].sha).success
    ) {
      const now = new Date().toISOString(),
        attempt = {
          run_id: `head-check-${crypto.randomUUID()}`,
          started_at: now,
          finished_at: now,
          status: "failed" as const,
          error_code: "source_head_changed",
        };
      await resultStore(ctx).writeIndex(
        await indexName(project.projectId, findings.sourceRef),
        IndexSchema.parse({
          ...index,
          publication_id: crypto.randomUUID(),
          generated_at: now,
          source_commit: heads[0].sha,
          last_attempt: attempt,
          sources: index.sources.map((s) => ({ ...s, last_attempt: attempt })),
        }),
      );
    }
    throw new Error("source_head_changed_detect_again");
  }
}
