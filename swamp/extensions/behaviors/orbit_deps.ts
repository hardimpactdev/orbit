// @nckrtl/orbit-deps — read-only dependency scan and upgrade planner for the
// Orbit monorepo. `scan` records an inventory; `plan` compares it with
// Packagist and the npm registry and records a typed upgrade plan.
import { z } from "npm:zod@4";
import semver from "npm:semver@7.7.2";
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

// A missing/partial registry document is not evidence that no release exists.
// Keep diagnostic rows, but fail the method and the existing workflow closed.
const PackagistResponse = z.object({ packages: z.record(z.string(), z.array(z.object({ version: z.string().min(1) }))) });
const NpmResponse = z.object({ name: z.string(), versions: z.record(z.string(), z.object({ version: z.string() })), "dist-tags": z.object({ latest: z.string().optional() }).optional() });

function composerRegistryVersion(raw: string): string | null {
  if (raw.startsWith("dev-") || raw.endsWith("-dev")) return null;
  // normaliseVersion also handles old lockfile shorthand. Registry evidence
  // must not coerce arbitrary text containing digits into a successful release.
  if (!/^v?\d+(?:\.\d+){0,3}(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/i.test(raw) ||
    (!/^v?\d+\.\d+\.\d+/i.test(raw) && /[-+]/.test(raw))) throw new Error("Unsupported registry version");
  const parts = raw.replace(/^v/i, "").split(/(?=[-+])/)[0].split(".");
  const suffix = raw.replace(/^v/i, "").slice(parts.join(".").length);
  // Preserve the earlier numeric shorthand/four-part Composer policy, but
  // validate prereleases strictly instead of dropping an invalid suffix.
  const value = semver.valid([...parts.slice(0, 3), ...Array(Math.max(0, 3 - parts.length)).fill("0")].join(".") + suffix);
  if (value === null) throw new Error("Invalid registry version");
  return isStable(value) ? value : null;
}

function npmRegistryVersion(raw: string): string {
  // Validate the whole npm version before any stable filtering. Composer's
  // shorthand, fourth numeric component and dev branches do not apply here.
  const value = semver.valid(raw);
  if (value === null) throw new Error("Invalid npm registry version");
  return value;
}

async function fetchRegistry(ecosystem: Ecosystem, name: string): Promise<RegistryInfo> {
  const json = async (response: Response): Promise<unknown> => {
    try { return await response.json(); } catch { throw new Error("Registry returned invalid JSON"); }
  };
  if (ecosystem === "composer") {
    const res = await fetch(`https://repo.packagist.org/p2/${name}.json`);
    if (!res.ok) throw new Error(`Packagist ${res.status}`);
    const parsed = PackagistResponse.safeParse(await json(res));
    if (!parsed.success) throw new Error("Packagist returned malformed package metadata");
    const rows = parsed.data.packages[name];
    if (!rows?.length) throw new Error("Packagist omitted the requested package releases");
    const versions = rows.map((v) => composerRegistryVersion(v.version)).filter((v): v is string => v !== null);
    if (!versions.length) throw new Error("Packagist supplied no supported stable releases");
    const latest = versions.reduce<string | null>((a, v) => (a === null || gt(v, a) ? v : a), null);
    return { versions, latest };
  }
  const res = await fetch(`https://registry.npmjs.org/${name.replace("/", "%2F")}`, {
    headers: { Accept: "application/vnd.npm.install-v1+json" },
  });
  if (!res.ok) throw new Error(`npm registry ${res.status}`);
  const parsed = NpmResponse.safeParse(await json(res));
  if (!parsed.success || parsed.data.name !== name) throw new Error("npm returned malformed or foreign package metadata");
  const body = parsed.data;
  const entries = Object.entries(body.versions);
  if (!entries.length) throw new Error("npm returned incomplete release metadata");
  const releases = entries.map(([version, value]) => {
    const parsedVersion = npmRegistryVersion(version);
    npmRegistryVersion(value.version);
    if (value.version !== version) throw new Error("npm returned inconsistent release metadata");
    return parsedVersion;
  });
  const tagged = body["dist-tags"]?.latest;
  const taggedVersion = tagged === undefined ? null : npmRegistryVersion(tagged);
  if (tagged !== undefined && !Object.hasOwn(body.versions, tagged)) throw new Error("npm latest tag names a missing release");
  const versions = releases.filter(isStable);
  if (!versions.length) throw new Error("npm supplied no supported stable releases");
  const stableTag = taggedVersion !== null && isStable(taggedVersion) ? taggedVersion : null;
  const latest = stableTag !== null
    ? stableTag
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
  version: "2026.10.30.1",
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
            for (const d of e.direct) {
              // Unsupported constraints remain explicit skips, not registry
              // lookups whose failures could be ignored by that later branch.
              if (toRange(e.ecosystem, d.constraint) !== null) keys.set(`${e.ecosystem}:${d.registryName}`, { ecosystem: e.ecosystem, name: d.registryName });
            }
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
        if (plan.counts.unknown > 0) throw new Error(`Registry evidence unavailable for ${plan.counts.unknown} package(s); upgrade-plan is diagnostic only`);
        return { dataHandles: [handle] };
      },
    },
  },
};
