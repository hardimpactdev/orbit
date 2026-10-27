import { z } from "npm:zod@4";
import {
  command,
  type CommonArgs,
  MaintenanceFailure,
  type Place,
} from "./io.ts";

export const UpdateTargetSchema = z.strictObject({
  path: z.string().min(1),
  manager: z.enum(["composer", "js"]),
  package: z.string().regex(
    /^(?:@[a-z0-9][a-z0-9_.-]*\/)?[a-z0-9][a-z0-9_.-]*(?:\/[a-z0-9][a-z0-9_.-]*)?$/,
  ),
  version: z.string().regex(/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/),
  purpose: z.enum(["major", "security"]),
  advisories: z.array(z.string().min(1)),
  advisoryUrls: z.array(z.string().url().startsWith("https://")),
}).superRefine((v, c) => {
  if (
    (v.purpose === "security") !== (v.advisories.length > 0) ||
    v.advisories.length !== v.advisoryUrls.length
  ) {
    c.addIssue({
      code: "custom",
      message: "security needs current advisory IDs and source URLs",
    });
  }
  if (
    v.manager === "composer" &&
    !/^[a-z0-9][a-z0-9_.-]*\/[a-z0-9][a-z0-9_.-]*$/.test(v.package)
  ) c.addIssue({ code: "custom", message: "invalid Composer package" });
  if (
    v.manager === "js" && v.package.includes("/") && !v.package.startsWith("@")
  ) c.addIssue({ code: "custom", message: "invalid JS package" });
});
export type UpdateTarget = z.infer<typeof UpdateTargetSchema>;
export const TargetsSchema = z.array(UpdateTargetSchema).min(1).refine(
  (t) => new Set(t.map(key)).size === t.length,
  "one target per package/path/ecosystem",
);
export const BlockedSchema = z.strictObject({
  path: z.string(),
  manager: z.enum(["composer", "js"]),
  package: z.string(),
  reason: z.string().min(1),
});
export const BatchPlanSchema = z.strictObject({
  schema: z.literal(1),
  targets: z.array(UpdateTargetSchema),
  blocked: z.array(BlockedSchema),
});
export type BatchPlan = z.infer<typeof BatchPlanSchema>;
export type Candidate = {
  path: string;
  manager: "composer" | "js";
  package: string;
  current?: string;
  latest?: string;
  reasons: string[];
  advisories: { id: string; url: string | null }[];
};
export function key(t: { path: string; manager: string; package: string }) {
  return `${t.manager}:${t.path}:${t.package}`;
}
export function compare(a: string, b: string) {
  const x = a.replace(/^v/, "").split(".").map(Number),
    y = b.replace(/^v/, "").split(".").map(Number);
  for (let i = 0; i < 3; i++) if (x[i] !== y[i]) return x[i] - y[i];
  return 0;
}
export function eligible(
  releases: { version: string; publishedAt: string }[],
  current: string | undefined,
  days: number,
  now = Date.now(),
) {
  return releases.filter((r) =>
    /^\d+\.\d+\.\d+$/.test(r.version) &&
    Number.isFinite(Date.parse(r.publishedAt)) &&
    now - Date.parse(r.publishedAt) >= days * 86400000 &&
    (!current || compare(r.version, current) > 0)
  ).sort((a, b) => compare(b.version, a.version));
}

/** Pure selection; a successful solver/audit covers the entire selected set. */
export async function planBatch(
  candidates: Candidate[],
  policy: CommonArgs["policy"],
  releases: (
    c: Candidate,
  ) => Promise<{ version: string; publishedAt: string }[]>,
  solve: (
    choices: UpdateTarget[][],
  ) => Promise<{ targets: UpdateTarget[]; error?: string }>,
): Promise<BatchPlan> {
  const blocked: BatchPlan["blocked"] = [],
    options: { candidate: Candidate; targets: UpdateTarget[] }[] = [];
  for (
    const c of [...candidates].sort((a, b) =>
      Number(b.reasons.includes("security")) -
        Number(a.reasons.includes("security")) || key(a).localeCompare(key(b))
    )
  ) {
    try {
      if (
        c.reasons.includes("security") &&
        (!c.advisories.length ||
          c.advisories.some((a) => !a.url?.startsWith("https://")))
      ) throw new Error("Advisory has no verified source URL");
      const rows = eligible(
        await releases(c),
        c.current,
        policy[c.path]?.minimumReleaseAgeDays ?? 0,
      ).filter((r) =>
        c.reasons.includes("security") || !c.current ||
        Number(r.version.split(".")[0]) >
          Number(c.current.replace(/^v/, "").split(".")[0])
      );
      const targets = rows.map((r) =>
        UpdateTargetSchema.parse({
          path: c.path,
          manager: c.manager,
          package: c.package,
          version: r.version,
          purpose: c.reasons.includes("security") ? "security" : "major",
          advisories: c.advisories.map((a) => a.id),
          advisoryUrls: c.advisories.map((a) => a.url),
        })
      );
      if (!targets.length) {
        throw new Error("No stable target satisfies the release-age policy");
      }
      options.push({ candidate: c, targets });
    } catch (e) {
      blocked.push({
        path: c.path,
        manager: c.manager,
        package: c.package,
        reason: e instanceof Error ? e.message : String(e),
      });
    }
  }
  if (!options.length) return { schema: 1, targets: [], blocked };
  // Ask the native resolver to choose versions jointly, including coupled upgrades.
  let choices = options.map((o) => o.targets);
  let result = await solve(choices);
  const reasons = new Map<string, string>();
  if (result.error) {
    // Existing locked versions are optional only while isolating unsatisfied requests.
    choices = options.map((o) =>
      o.candidate.current && /^\d+\.\d+\.\d+$/.test(o.candidate.current)
        ? [...o.targets, { ...o.targets[0], version: o.candidate.current }]
        : o.targets
    );
    const relaxed = await solve(choices);
    if (relaxed.error) {
      for (const o of options) {
        blocked.push({
          path: o.candidate.path,
          manager: o.candidate.manager,
          package: o.candidate.package,
          reason: relaxed.error,
        });
      }
      return { schema: 1, targets: [], blocked };
    }
    result = relaxed;
    // Require each eligible upgrade, security first, while the solver may adjust
    // all other version choices. Never freeze a previous package at its latest.
    for (let i = 0; i < options.length; i++) {
      const trial = choices.map((versions, j) =>
        i === j ? options[j].targets : versions
      );
      const attempt = await solve(trial);
      if (!attempt.error) {
        choices = trial;
        result = attempt;
      } else reasons.set(key(options[i].candidate), attempt.error);
    }
  }
  for (const o of options) {
    const selected = result.targets.find((t) => key(t) === key(o.candidate));
    if (!selected) {
      blocked.push({
        path: o.candidate.path,
        manager: o.candidate.manager,
        package: o.candidate.package,
        reason: reasons.get(key(o.candidate)) ??
          "No target satisfies project constraints and advisory verification",
      });
    } else if (
      o.candidate.reasons.includes("major") && o.candidate.current &&
      Number(selected.version.split(".")[0]) <=
        Number(o.candidate.current.replace(/^v/, "").split(".")[0])
    ) {
      blocked.push({
        path: o.candidate.path,
        manager: o.candidate.manager,
        package: o.candidate.package,
        reason:
          "Security fix selected; no jointly compatible eligible major target",
      });
    }
  }
  return { schema: 1, targets: result.targets, blocked };
}

export function assertFreshTarget(
  target: UpdateTarget,
  current: string | undefined,
  advisories: { id: string }[],
) {
  if (!current || !/^\d+\.\d+\.\d+$/.test(current)) {
    throw new MaintenanceFailure(
      `Cannot verify current locked version of ${target.package}`,
    );
  }
  if (compare(current, target.version) > 0) {
    throw new MaintenanceFailure(
      `Stale batch would downgrade ${target.package} from ${current} to ${target.version}`,
      64,
    );
  }
  if (
    target.purpose === "security" && current !== target.version &&
    target.advisories.some((id) => !advisories.some((a) => a.id === id))
  ) {
    throw new MaintenanceFailure(
      `Stale advisory evidence for ${target.package}; detect again`,
      64,
    );
  }
}

export async function assertChangedReleaseAge(
  before: Record<string, string>,
  after: Record<string, string>,
  days: number,
  metadata: (
    name: string,
  ) => Promise<{ version: string; publishedAt: string }[]>,
) {
  for (const [name, version] of Object.entries(after)) {
    if (before[name] === version) continue;
    const released = (await metadata(name)).find((r) => r.version === version),
      time = Date.parse(released?.publishedAt ?? "");
    if (!Number.isFinite(time) || Date.now() - time < days * 86400000) {
      throw new MaintenanceFailure(
        `Resolved ${name}@${version} does not satisfy release-age policy`,
      );
    }
  }
}

/** Copy only tracked files; never dependency trees, credentials or the source Git directory. */
export async function scratch(
  root: string,
  signal?: AbortSignal,
): Promise<{ root: string; close: () => Promise<void> }> {
  const temp = await Deno.makeTempDir({ prefix: "maintenance-plan-" });
  try {
    const result = await command(root, "git", ["ls-files", "-z"], signal);
    if (result.code !== 0) {
      throw new MaintenanceFailure(
        "Batch planning requires a tracked checkout",
      );
    }
    for (const file of result.stdout.split("\0").filter(Boolean)) {
      if (file.split("/").includes("..") || file.startsWith("/")) {
        throw new MaintenanceFailure("Invalid tracked path");
      }
      const stat = await Deno.lstat(`${root}/${file}`);
      if (!stat.isFile) {
        throw new MaintenanceFailure(
          `Batch planning cannot copy tracked non-file ${file}`,
        );
      }
      const to = `${temp}/${file}`;
      await Deno.mkdir(to.slice(0, to.lastIndexOf("/")), { recursive: true });
      await Deno.copyFile(`${root}/${file}`, to);
    }
    return { root: temp, close: () => Deno.remove(temp, { recursive: true }) };
  } catch (e) {
    await Deno.remove(temp, { recursive: true });
    throw e;
  }
}
export function batchPlaces(
  targets: UpdateTarget[],
  places: Place[],
  manager: "composer" | "js",
) {
  TargetsSchema.parse(targets);
  for (const t of targets) {
    if (t.manager !== manager || !places.some((p) => p.path === t.path)) {
      throw new MaintenanceFailure(
        "Batch target has an unconfigured path or ecosystem",
        64,
      );
    }
  }
  return places.map((p) => ({
    ...p,
    targets: targets.filter((t) => t.path === p.path),
  })).filter((p) => p.targets.length);
}
