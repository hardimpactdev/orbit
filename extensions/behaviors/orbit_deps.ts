// @nckrtl/orbit-deps — read-only dependency scan and upgrade planner for the
// Orbit monorepo. `scan` records an inventory; `plan` compares it with
// Packagist and the npm registry and records a typed upgrade plan.
import { z } from "npm:zod@4";
import { buildInventory, fetchGatewayInventory, git, type SubProjectInventory } from "./_lib/inventory.ts";
import {
  type Ecosystem,
  gt,
  isMajorJump,
  isStable,
  maxSatisfying,
  normaliseVersion,
  suggestedConstraint,
  toRange,
} from "./_lib/versions.ts";

const GlobalArgsSchema = z.object({
  gatewayUrl: z.string().describe("Gateway API origin, e.g. https://gateway.orbit"),
  caCertPath: z.string().describe("PEM file of the Orbit root CA"),
  instanceId: z.number().int().describe("Orbit development Instance whose inventory to read"),
  checkoutDir: z.string().describe("Scratch clone of the Orbit repository (read-only use)"),
  branch: z.string().default("main"),
});
type GlobalArgs = z.infer<typeof GlobalArgsSchema>;

const DirectDepSchema = z.object({
  name: z.string(),
  registryName: z.string(),
  constraint: z.string(),
  scope: z.enum(["regular", "development"]),
  locked: z.string().nullable(),
});

const InventorySchema = z.object({
  commit: z.string(),
  branch: z.string(),
  instanceId: z.number(),
  scannedAt: z.string(),
  gatewayError: z.string().nullable(),
  subProjects: z.array(z.object({
    path: z.string(),
    ecosystems: z.array(z.object({
      ecosystem: z.enum(["composer", "npm"]),
      manager: z.enum(["composer", "bun", "npm"]),
      source: z.enum(["gateway", "checkout"]),
      reason: z.string().nullable(),
      observedAt: z.string().nullable(),
      direct: z.array(DirectDepSchema),
    })),
  })),
});

const PackageResultSchema = z.object({
  subProject: z.string(),
  ecosystem: z.enum(["composer", "npm"]),
  name: z.string(),
  scope: z.enum(["regular", "development"]),
  constraint: z.string(),
  locked: z.string().nullable(),
  maxInConstraint: z.string().nullable(),
  latest: z.string().nullable(),
  status: z.enum(["current", "bump", "constraint-change", "bump+constraint-change", "skipped", "unknown"]),
  major: z.boolean(),
  note: z.string().nullable(),
});

const SubtaskSchema = z.object({
  id: z.string(),
  kind: z.enum(["within-constraints", "constraint-change"]),
  subProject: z.string(),
  ecosystems: z.array(z.enum(["composer", "npm"])),
  title: z.string(),
  major: z.boolean(),
  packages: z.array(z.object({
    name: z.string(),
    ecosystem: z.enum(["composer", "npm"]),
    from: z.string().nullable(),
    to: z.string(),
    constraint: z.string(),
    newConstraint: z.string().nullable(),
  })),
});

const UpgradePlanSchema = z.object({
  commit: z.string(),
  generatedAt: z.string(),
  majorsExist: z.boolean(),
  counts: z.object({
    subProjects: z.number(),
    packages: z.number(),
    bumps: z.number(),
    constraintChanges: z.number(),
    majors: z.number(),
    skipped: z.number(),
    unknown: z.number(),
    subtasks: z.number(),
  }),
  sources: z.array(z.object({ subProject: z.string(), ecosystem: z.string(), source: z.string(), reason: z.string().nullable() })),
  subtasks: z.array(SubtaskSchema),
  packages: z.array(PackageResultSchema),
});

type PackageResult = z.infer<typeof PackageResultSchema>;
type Subtask = z.infer<typeof SubtaskSchema>;

interface RegistryInfo {
  versions: string[]; // stable, normalised
  latest: string | null;
}

async function fetchRegistry(ecosystem: Ecosystem, name: string): Promise<RegistryInfo> {
  if (ecosystem === "composer") {
    const res = await fetch(`https://repo.packagist.org/p2/${name}.json`);
    if (!res.ok) throw new Error(`Packagist ${res.status}`);
    const body = await res.json();
    const versions = ((body.packages?.[name] ?? []) as { version: string }[])
      .map((v) => normaliseVersion(v.version))
      .filter((v): v is string => v !== null && isStable(v));
    const latest = versions.reduce<string | null>((a, v) => (a === null || gt(v, a) ? v : a), null);
    return { versions, latest };
  }
  const res = await fetch(`https://registry.npmjs.org/${name.replace("/", "%2F")}`, {
    headers: { Accept: "application/vnd.npm.install-v1+json" },
  });
  if (!res.ok) throw new Error(`npm registry ${res.status}`);
  const body = await res.json();
  const versions = Object.keys(body.versions ?? {}).filter((v) => isStable(v));
  const tagged = body["dist-tags"]?.latest as string | undefined;
  const latest = tagged && isStable(tagged)
    ? tagged
    : versions.reduce<string | null>((a, v) => (a === null || gt(v, a) ? v : a), null);
  return { versions, latest };
}

async function pool<T, R>(items: T[], limit: number, fn: (item: T) => Promise<R>): Promise<R[]> {
  const out: R[] = new Array(items.length);
  let next = 0;
  await Promise.all(Array.from({ length: Math.min(limit, items.length) }, async () => {
    while (next < items.length) {
      const i = next++;
      out[i] = await fn(items[i]);
    }
  }));
  return out;
}

function slug(s: string): string {
  return s.replace(/^\.$/, "root").replace(/[^a-zA-Z0-9]+/g, "-").replace(/^-|-$/g, "").toLowerCase();
}

export const behavior = {
  type: "@nckrtl/orbit-deps",
  version: "2026.10.22.1",
  globalArguments: GlobalArgsSchema,
  resources: {
    inventory: {
      description: "Direct dependencies per Orbit sub-project, with where each was read from",
      schema: InventorySchema,
      lifetime: "infinite",
      garbageCollection: 20,
    },
    upgradePlan: {
      description: "Typed upgrade plan: within-constraint bumps and constraint changes as subtasks",
      schema: UpgradePlanSchema,
      lifetime: "infinite",
      garbageCollection: 20,
    },
  },
  methods: {
    scan: {
      description: "Read the Gateway dependency inventory; fall back to the scratch checkout where it is stale",
      arguments: z.object({}),
      execute: async (
        _args: Record<string, never>,
        context: {
          globalArgs: GlobalArgs;
          writeResource: (spec: string, name: string, data: Record<string, unknown>) => Promise<{ name: string }>;
          logger: { info: (msg: string, props?: Record<string, unknown>) => void; warning: (msg: string, props?: Record<string, unknown>) => void };
        },
      ) => {
        const g = context.globalArgs;
        await git(g.checkoutDir, ["fetch", "--quiet", "origin", g.branch]);
        const commit = (await git(g.checkoutDir, ["rev-parse", `origin/${g.branch}`])).trim();

        let gateway = null;
        let gatewayError: string | null = null;
        try {
          gateway = await fetchGatewayInventory(g.gatewayUrl, g.caCertPath, g.instanceId);
        } catch (e) {
          gatewayError = (e as Error).message;
          context.logger.warning("Gateway inventory unavailable: {error}", { error: gatewayError });
        }

        const subProjects: SubProjectInventory[] = await buildInventory(g.checkoutDir, commit, gateway, gatewayError);
        for (const sp of subProjects) {
          for (const e of sp.ecosystems) {
            context.logger.info("{path} {eco}: {n} direct from {source}{why}", {
              path: sp.path,
              eco: e.ecosystem,
              n: e.direct.length,
              source: e.source,
              why: e.reason ? ` (${e.reason})` : "",
            });
          }
        }
        const handle = await context.writeResource("inventory", "inventory", {
          commit,
          branch: g.branch,
          instanceId: g.instanceId,
          scannedAt: new Date().toISOString(),
          gatewayError,
          subProjects,
        });
        return { dataHandles: [handle] };
      },
    },

    plan: {
      description: "dependency-planner: compare the inventory with Packagist and npm and record an upgrade plan",
      arguments: z.object({}),
      execute: async (
        _args: Record<string, never>,
        context: {
          readResource: (name: string) => Promise<Record<string, unknown> | null>;
          writeResource: (spec: string, name: string, data: Record<string, unknown>) => Promise<{ name: string }>;
          logger: { info: (msg: string, props?: Record<string, unknown>) => void };
        },
      ) => {
        const inv = (await context.readResource("inventory")) as z.infer<typeof InventorySchema> | null;
        if (!inv) throw new Error("No inventory recorded yet; run scan first");

        // One registry lookup per (ecosystem, package).
        const keys = new Map<string, { ecosystem: Ecosystem; name: string }>();
        for (const sp of inv.subProjects) {
          for (const e of sp.ecosystems) {
            for (const d of e.direct) keys.set(`${e.ecosystem}:${d.registryName}`, { ecosystem: e.ecosystem, name: d.registryName });
          }
        }
        const entries = [...keys.entries()];
        const fetched = await pool(entries, 12, async ([key, k]) => {
          try {
            return [key, await fetchRegistry(k.ecosystem, k.name)] as const;
          } catch (e) {
            return [key, (e as Error).message] as const;
          }
        });
        const registry = new Map<string, RegistryInfo | string>(fetched);

        const packages: PackageResult[] = [];
        for (const sp of inv.subProjects) {
          for (const e of sp.ecosystems) {
            for (const d of e.direct) {
              const base = {
                subProject: sp.path,
                ecosystem: e.ecosystem,
                name: d.name,
                scope: d.scope,
                constraint: d.constraint,
                locked: d.locked ? normaliseVersion(d.locked) : null,
                maxInConstraint: null,
                latest: null,
                major: false,
                note: null,
              };
              const range = toRange(e.ecosystem, d.constraint);
              if (range === null) {
                packages.push({ ...base, status: "skipped", note: `constraint ${d.constraint} is not a registry range` });
                continue;
              }
              const info = registry.get(`${e.ecosystem}:${d.registryName}`)!;
              if (typeof info === "string") {
                packages.push({ ...base, status: "unknown", note: info });
                continue;
              }
              const max = maxSatisfying(info.versions, range);
              const latest = info.latest;
              const bump = max !== null && base.locked !== null && gt(max, base.locked);
              const change = latest !== null && (max === null || gt(latest, max));
              const major = change && isMajorJump(max ?? base.locked ?? "0.0.0", latest!);
              packages.push({
                ...base,
                maxInConstraint: max,
                latest,
                major,
                status: bump && change ? "bump+constraint-change" : bump ? "bump" : change ? "constraint-change" : "current",
              });
            }
          }
        }

        const subtasks: Subtask[] = [];
        for (const sp of inv.subProjects) {
          const mine = packages.filter((p) => p.subProject === sp.path);
          const bumps = mine.filter((p) => p.status === "bump" || p.status === "bump+constraint-change");
          if (bumps.length > 0) {
            subtasks.push({
              id: `bump-${slug(sp.path)}`,
              kind: "within-constraints",
              subProject: sp.path,
              ecosystems: [...new Set(bumps.map((p) => p.ecosystem))],
              title: `Update ${sp.path} within constraints (${bumps.length} direct)`,
              major: false,
              packages: bumps.map((p) => ({
                name: p.name,
                ecosystem: p.ecosystem,
                from: p.locked,
                to: p.maxInConstraint!,
                constraint: p.constraint,
                newConstraint: null,
              })),
            });
          }
          for (const p of mine.filter((p) => p.status.endsWith("constraint-change"))) {
            subtasks.push({
              id: `change-${slug(sp.path)}-${slug(p.name)}`,
              kind: "constraint-change",
              subProject: sp.path,
              ecosystems: [p.ecosystem],
              title: `${p.major ? "Major: " : ""}${p.name} ${p.constraint} → ${p.latest} in ${sp.path}`,
              major: p.major,
              packages: [{
                name: p.name,
                ecosystem: p.ecosystem,
                from: p.locked,
                to: p.latest!,
                constraint: p.constraint,
                newConstraint: suggestedConstraint(p.latest!),
              }],
            });
          }
        }

        const count = (f: (p: PackageResult) => boolean) => packages.filter(f).length;
        const plan = {
          commit: inv.commit,
          generatedAt: new Date().toISOString(),
          majorsExist: subtasks.some((s) => s.major),
          counts: {
            subProjects: inv.subProjects.length,
            packages: packages.length,
            bumps: count((p) => p.status.startsWith("bump")),
            constraintChanges: count((p) => p.status.endsWith("constraint-change")),
            majors: count((p) => p.major),
            skipped: count((p) => p.status === "skipped"),
            unknown: count((p) => p.status === "unknown"),
            subtasks: subtasks.length,
          },
          sources: inv.subProjects.flatMap((sp) =>
            sp.ecosystems.map((e) => ({ subProject: sp.path, ecosystem: e.ecosystem, source: e.source, reason: e.reason }))
          ),
          subtasks,
          packages,
        };
        context.logger.info("Plan at {commit}: {subtasks} subtasks, {majors} majors", {
          commit: inv.commit.slice(0, 9),
          subtasks: subtasks.length,
          majors: plan.counts.majors,
        });
        const handle = await context.writeResource("upgradePlan", "upgrade-plan", plan);
        return { dataHandles: [handle] };
      },
    },
  },
};
