import { z } from "npm:zod@4.4.3";
import { dirname, join, normalize } from "node:path";
import { configuredProject, Snapshot } from "./metadata.ts";
import { inventory } from "../../_lib/dependency_inventory.ts";
import { sources } from "../../_lib/dependency_results.ts";
import { repositoryIdentity, sourceKey } from "../../_lib/dependency_results_schema.ts";
import { batchReceipt, FindingsSchema } from "../../_lib/project_schedule.ts";
import { sha256 } from "../../_lib/repository_analysis.ts";
import {
  changedSources,
  composerFacts,
  declared,
  fresh,
  manifests,
  ManifestsSchema,
  npmFacts,
  type RegistryFacts,
  RegistryFactsSchema,
  registryPackages,
  type SourcePackages,
  SourcePackagesSchema,
  TreeEntrySchema,
  updates,
} from "../../_lib/maintenance/inspect.ts";

type Context = {
  queryData(predicate: string): Promise<unknown[]>;
  writeResource(spec: string, name: string, value: unknown): Promise<unknown>;
  vaultService: { get(vault: string, key: string, consumer: string): Promise<string> };
};
const Public = z.record(z.string(), z.unknown());
const Commit = z.string().regex(/^[a-f0-9]{40}$/);
// The vault and key names of the admitted read token; the step resolves the value, so it is never an input.
const Token = z.object({ vault: z.literal("orbit-control-inputs"), key: z.string().regex(/^project-dependency-read-[1-9][0-9]*$/) }).strict();
type TokenRef = z.infer<typeof Token>;
const reveal = (ctx: Context, token: TokenRef) => ctx.vaultService.get(token.vault, token.key, "project-maintenance-inspect");

/** One GitHub App read through the REST API with the admitted read-only token; no clone, no Git config. */
async function github(token: string, repository: string, path: string): Promise<unknown> {
  const response = await fetch(`https://api.github.com/repos/${repository.replace(/^github\.com\//, "")}/${path}`, {
    headers: { authorization: `Bearer ${token}`, accept: "application/vnd.github+json", "x-github-api-version": "2022-11-28" },
  });
  if (!response.ok) throw new Error(`project.scan_failed: GitHub ${response.status}`);
  return await response.json();
}

const factName = async (ecosystem: string, name: string) => `registry-${ecosystem}-${(await sha256(name)).slice(0, 40)}`;

async function storedFacts(ctx: Context): Promise<Map<string, RegistryFacts>> {
  const facts = new Map<string, RegistryFacts>();
  // Each Project's run writes facts under its own instance; the newest answer for a package wins.
  for (const row of await ctx.queryData(`modelType == "@nckrtl/project-maintenance-inspect" && name.startsWith("registry-")`)) {
    const parsed = RegistryFactsSchema.safeParse((row as { attributes: unknown }).attributes);
    const key = parsed.success ? `${parsed.data.ecosystem}:${parsed.data.name}` : "";
    if (parsed.success && (facts.get(key)?.fetchedAt ?? "") < parsed.data.fetchedAt) facts.set(key, parsed.data);
  }
  return facts;
}

/** Bounded concurrency for registry reads, so one inspection never floods Packagist or npm. */
async function each<T>(items: T[], limit: number, work: (item: T) => Promise<void>) {
  const queue = [...items];
  await Promise.all(Array.from({ length: Math.min(limit, queue.length) }, async () => {
    for (let item = queue.shift(); item !== undefined; item = queue.shift()) await work(item);
  }));
}

export const behavior = {
  type: "@nckrtl/project-maintenance-inspect",
  version: "2026.11.03.1",
  resources: {
    public: { schema: Public, lifetime: "infinite", garbageCollection: 1 },
    facts: { schema: RegistryFactsSchema, lifetime: "infinite", garbageCollection: 1 },
  },
  methods: {
    manifests: {
      description: "Read the default branch head and the blob SHAs of its manifests and lockfiles in one Git tree",
      arguments: z.object({ project: Snapshot, previous: ManifestsSchema, token: Token }).strict(),
      async execute(args: { project: z.infer<typeof Snapshot>; previous: z.infer<typeof ManifestsSchema>; token: TokenRef }, ctx: Context) {
        const project = await configuredProject(args.project, ctx);
        const repository = repositoryIdentity(project.repository);
        const token = await reveal(ctx, args.token);
        const branch = z.object({ commit: z.object({ sha: Commit }) })
          .parse(await github(token, repository, `branches/${encodeURIComponent(project.defaultBranch)}`));
        const tree = z.object({ truncated: z.boolean(), tree: z.array(z.object({ path: z.string(), type: z.string(), sha: z.string() })) })
          .parse(await github(token, repository, `git/trees/${branch.commit.sha}?recursive=1`));
        if (tree.truncated) throw new Error("project.scan_failed: the Git tree is too large to read in one request");
        const current = manifests(project, tree.tree.filter((e) => e.type === "blob").map((e) => TreeEntrySchema.parse({ path: e.path, sha: e.sha })));
        return { dataHandles: [await ctx.writeResource("public", "manifests", {
          commit: branch.commit.sha, manifests: current, changed: changedSources(current, args.previous),
        })] };
      },
    },
    packages: {
      description: "Read the changed sources' files by blob SHA and list their installed packages; keep unchanged sources",
      arguments: z.object({
        project: Snapshot,
        manifests: ManifestsSchema,
        changed: z.array(z.string()),
        previous: z.array(SourcePackagesSchema),
        token: Token,
      }).strict(),
      async execute(args: { project: z.infer<typeof Snapshot>; manifests: z.infer<typeof ManifestsSchema>; changed: string[]; previous: SourcePackages[]; token: TokenRef }, ctx: Context) {
        const project = await configuredProject(args.project, ctx);
        const repository = repositoryIdentity(project.repository);
        const token = await reveal(ctx, args.token);
        const result: SourcePackages[] = [];
        for (const source of sources(project)) {
          const keyed = sourceKey(source);
          const kept = args.previous.find((row) => sourceKey(row.source) === keyed);
          if (!args.changed.includes(keyed) && kept !== undefined) {
            result.push(kept);
            continue;
          }
          const root = await Deno.makeTempDir({ prefix: "orbit-inspect-" });
          try {
            for (const [path, sha] of Object.entries(args.manifests[keyed] ?? {})) {
              const target = normalize(join(root, path));
              if (!target.startsWith(`${root}/`)) throw new Error("project.scan_failed: manifest path escapes the source");
              const blob = z.object({ encoding: z.literal("base64"), content: z.string() })
                .parse(await github(token, repository, `git/blobs/${sha}`));
              await Deno.mkdir(dirname(target), { recursive: true });
              await Deno.writeFile(target, Uint8Array.from(atob(blob.content.replace(/\s/g, "")), (c) => c.charCodeAt(0)));
            }
            const manifest = JSON.parse(await Deno.readTextFile(join(root, source.path, source.ecosystem === "composer" ? "composer.json" : "package.json")));
            result.push(SourcePackagesSchema.parse({ source, packages: await inventory(root, source), declared: declared(source, manifest) }));
          } finally {
            await Deno.remove(root, { recursive: true });
          }
        }
        return { dataHandles: [await ctx.writeResource("public", "packages", { sources: result })] };
      },
    },
    registries: {
      description: "Refresh the latest releases and advisories of every listed package older than a day, cached across Projects",
      arguments: z.object({ sources: z.array(SourcePackagesSchema) }).strict(),
      async execute(args: { sources: SourcePackages[] }, ctx: Context) {
        const facts = await storedFacts(ctx), now = new Date();
        const stale = registryPackages(args.sources).filter((p) => !fresh(facts.get(`${p.ecosystem}:${p.name}`), now.getTime()));
        const handles: unknown[] = [];
        await each(stale, 8, async (p) => {
          const value = p.ecosystem === "composer" ? await composerFacts(p.name, now) : await npmFacts(p.name, now);
          handles.push(await ctx.writeResource("facts", await factName(p.ecosystem, p.name), value));
        });
        handles.push(await ctx.writeResource("public", "registries", { checked_at: now.toISOString(), refreshed: stale.length }));
        return { dataHandles: handles };
      },
    },
    updates: {
      description: "Find the eligible majors and advisory fixes under the Project policy, as one package group request",
      arguments: z.object({ project: Snapshot, commit: Commit, sources: z.array(SourcePackagesSchema) }).strict(),
      async execute(args: { project: z.infer<typeof Snapshot>; commit: string; sources: SourcePackages[] }, ctx: Context) {
        const project = await configuredProject(args.project, ctx);
        const { targets, blocked } = updates(project, args.sources, await storedFacts(ctx), Date.now());
        const findings = FindingsSchema.parse({ schema: 1, key: `${project.projectId}-${args.commit}`, projectId: project.projectId,
          source: args.commit, sourceCommit: args.commit, reports: [], plans: [], targets, blocked: [] });
        const requests = targets.length === 0 ? [] : [await (async () => {
          const receipt = await batchReceipt(project, findings);
          return { action: "create_group", project_id: project.projectId, key: receipt.key, input: receipt.request,
            maintenance: { mode: "package", project, targets, source_commit: args.commit } };
        })()];
        return { dataHandles: [await ctx.writeResource("public", "updates", { targets, blocked, requests })] };
      },
    },
  },
};
