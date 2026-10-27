import {
  assertChangedReleaseAge,
  assertFreshTarget,
  batchPlaces,
  BatchPlanSchema,
  type Candidate,
  compare,
  key,
  planBatch,
  scratch,
  type UpdateTarget,
} from "../../_lib/maintenance/batch.ts";
import {
  audit,
  type CommonArgs,
  direct,
  MaintenanceFailure,
  optionalText,
  places,
  readJson,
  run,
  versions,
  writeJson,
} from "./io.ts";

export async function releases(
  c: Pick<Candidate, "package">,
  signal?: AbortSignal,
) {
  const response = await fetch(
    `https://repo.packagist.org/p2/${c.package}.json`,
    { signal },
  );
  if (!response.ok) {
    throw new MaintenanceFailure(
      "No verified Packagist release metadata for target",
    );
  }
  const data = await response.json();
  const rows = data.packages?.[c.package];
  if (!Array.isArray(rows)) {
    throw new MaintenanceFailure("Invalid Packagist metadata");
  }
  return rows.map((r: { version: string; time: string }) => ({
    version: r.version?.replace(/^v/, ""),
    publishedAt: r.time,
  }));
}
function select(
  manifest: Record<string, unknown>,
  targets: UpdateTarget[],
  choices?: UpdateTarget[][],
) {
  const result = structuredClone(manifest);
  for (const t of targets) {
    const sections = ["require", "require-dev"].filter((s) =>
      t.package in ((result[s] ?? {}) as object)
    );
    if (!sections.length) continue; // Constrain transitive updates through Composer, never add a new root requirement.
    const range = sections.length === 1
      ? (result[sections[0]] as Record<string, unknown>)[t.package]
      : null;
    if (
      typeof range !== "string" ||
      /(?:npm:|workspace:|git|https?:|file:|link:|\bas\b)/.test(range)
    ) {
      throw new MaintenanceFailure(
        `No direct registry target for ${t.package}`,
        64,
      );
    }
    (result[sections[0]] as Record<string, unknown>)[t.package] =
      choices?.find((o) => key(o[0]) === key(t))?.map((t) => t.version).join(
        " || ",
      ) ?? t.version;
  }
  return result;
}
async function apply(
  root: string,
  args: CommonArgs,
  targets: UpdateTarget[],
  signal?: AbortSignal,
  planning = false,
  choices?: UpdateTarget[][],
) {
  const p = await places({ ...args, cwd: root });
  const selected: UpdateTarget[] = [];
  for (const place of batchPlaces(targets, p.places, "composer")) {
    const before = versions(await optionalText(`${place.dir}/composer.lock`));
    const manifest = await readJson(`${place.dir}/composer.json`);
    await writeJson(
      `${place.dir}/composer.json`,
      select(manifest, place.targets, choices),
    );
    const cmd = [
      "update",
      ...place.targets.map((t) => t.package),
      ...place.targets.flatMap((
        t,
      ) => [
        "--with",
        `${t.package}:${
          choices?.find((o) => key(o[0]) === key(t))?.map((t) => t.version)
            .join(" || ") ?? t.version
        }`,
      ]),
      "--with-dependencies",
      "--no-interaction",
      ...(planning ? ["--no-install", "--no-scripts", "--no-plugins"] : []),
    ];
    await run(place.dir, cmd, signal);
    const locked = versions(await optionalText(`${place.dir}/composer.lock`));
    for (const t of place.targets) {
      if (
        choices
          ? !choices.find((o) => key(o[0]) === key(t))?.some((o) =>
            o.version === locked[t.package]
          )
          : locked[t.package] !== t.version
      ) {
        throw new MaintenanceFailure(
          `Resolver did not lock ${t.package} at ${t.version}`,
        );
      }
    }
    await assertChangedReleaseAge(
      before,
      locked,
      args.policy[place.path]?.minimumReleaseAgeDays ?? 0,
      (name) => releases({ package: name }, signal),
    );
    const resolved = place.targets.filter((t) =>
      compare(locked[t.package], before[t.package] ?? "0.0.0") > 0
    ).map((t) => ({ ...t, version: locked[t.package] }));
    selected.push(...resolved);
    const findings = await audit(place.dir, signal);
    for (
      const t of (planning ? resolved : place.targets).filter((t) =>
        t.purpose === "security"
      )
    ) {
      if (findings[t.package]?.length) {
        throw new MaintenanceFailure(
          `Selected advisory fix remains affected: ${t.package}`,
        );
      }
    }
  }
  return selected;
}
export async function plan(
  args: CommonArgs,
  candidates: Candidate[],
  signal?: AbortSignal,
) {
  const project = await places(args),
    manifests = new Map<string, Record<string, unknown>>();
  for (const p of project.places) {
    manifests.set(p.path, await readJson(`${p.dir}/composer.json`));
  }
  const valid: Candidate[] = [];
  for (const c of candidates) {
    const place = project.places.find((p) => p.path === c.path);
    if (!place) throw new MaintenanceFailure("Unconfigured detection path");
    const locked = versions(await optionalText(`${place.dir}/composer.lock`));
    valid.push({ ...c, current: locked[c.package] });
  }
  if (!valid.length) {
    return BatchPlanSchema.parse({ schema: 1, targets: [], blocked: [] });
  }
  const temp = await scratch(project.root, signal);
  const files = new Map<string, string | null>();
  for (const p of project.places) {
    for (const name of ["composer.json", "composer.lock"]) {
      const f = `${p.path}/${name}`;
      files.set(f, await optionalText(`${project.root}/${f}`));
    }
  }
  try {
    const result = await planBatch(valid, args.policy, async (c) => {
      const range = direct(manifests.get(c.path)!)[c.package];
      if (/(?:git|https?:|file:|\bas\b)/.test(range)) {
        throw new Error("Unsupported non-registry dependency constraint");
      }
      return await releases(c, signal);
    }, async (choices) => {
      for (const [f, text] of files) {
        if (text !== null) {
          await Deno.writeTextFile(`${temp.root}/${f}`, text);
        }
      }
      try {
        const targets = await apply(
          temp.root,
          args,
          choices.map((o) => o[0]),
          signal,
          true,
          choices,
        );
        return { targets };
      } catch (e) {
        return {
          targets: [],
          error: e instanceof Error ? e.message : String(e),
        };
      }
    });
    return result;
  } finally {
    await temp.close();
  }
}
export async function batch(
  args: CommonArgs,
  targets: UpdateTarget[],
  signal?: AbortSignal,
) {
  // Revalidate release dates immediately before changing the real checkout. No age bypass.
  for (const t of targets) {
    const release = (await releases(t, signal)).find((r) =>
      r.version === t.version
    );
    const time = Date.parse(release?.publishedAt ?? "");
    if (
      !Number.isFinite(time) ||
      Date.now() - time <
        (args.policy[t.path]?.minimumReleaseAgeDays ?? 0) * 86400000
    ) {
      throw new MaintenanceFailure(
        `Target does not satisfy release-age policy: ${t.package}`,
      );
    }
  }
  const project = await places(args);
  for (const place of batchPlaces(targets, project.places, "composer")) {
    const current = versions(await optionalText(`${place.dir}/composer.lock`)),
      findings = await audit(place.dir, signal);
    select(await readJson(`${place.dir}/composer.json`), place.targets);
    for (const t of place.targets) {
      assertFreshTarget(t, current[t.package], findings[t.package] ?? []);
    }
  }
  await apply(args.cwd, args, targets, signal);
}
