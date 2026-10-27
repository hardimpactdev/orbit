// @deno-types="npm:@types/semver@7.7.1"
import { satisfies } from "npm:semver@7.7.2";
import {
  assertChangedReleaseAge,
  assertFreshTarget,
  batchPlaces,
  BatchPlanSchema,
  type Candidate,
  compare,
  eligible,
  key,
  planBatch,
  scratch,
  type UpdateTarget,
} from "../../_lib/maintenance/batch.ts";
import {
  command,
  type CommonArgs,
  decode,
  MaintenanceFailure,
  optionalText,
  places,
  writeJson,
} from "../../_lib/maintenance/io.ts";
import { audit } from "./reports.ts";
import {
  coupled,
  descriptor,
  lock,
  run,
  sections,
  workspace,
} from "./workspace.ts";
export async function releases(
  c: Pick<Candidate, "package">,
  dir: string,
  signal?: AbortSignal,
) {
  const result = await command(
    dir,
    "npm",
    ["view", c.package, "--json"],
    signal,
  );
  if (result.code !== 0) {
    throw new MaintenanceFailure("Cannot read registry target metadata");
  }
  const data = decode(result.stdout, "npm registry metadata");
  if (
    !Array.isArray(data.versions) || !data.time || typeof data.time !== "object"
  ) {
    throw new MaintenanceFailure(
      "Registry target has no verified release dates",
    );
  }
  return (data.versions as string[]).map((version) => ({
    version,
    publishedAt: (data.time as Record<string, string>)[version],
  }));
}
function select(
  manifest: Record<string, unknown>,
  targets: UpdateTarget[],
  choices?: UpdateTarget[][],
) {
  const result = structuredClone(manifest);
  for (const t of targets) {
    const found = sections.filter((s) =>
      t.package in ((result[s] ?? {}) as object)
    );
    const range = found.length === 1
      ? (result[found[0]] as Record<string, unknown>)[t.package]
      : null;
    if (
      typeof range !== "string" ||
      /(?:npm:|workspace:|git|https?:|file:|link:)/.test(range)
    ) {
      throw new MaintenanceFailure(
        `No direct registry target for ${t.package}`,
        64,
      );
    }
    (result[found[0]] as Record<string, unknown>)[t.package] =
      choices?.find((o) => key(o[0]) === key(t))?.map((t) => t.version).join(
        " || ",
      ) ?? t.version;
  }
  return result;
}
async function versions(js: Awaited<ReturnType<typeof workspace>>) {
  const packages = await lock(js), result: Record<string, string> = {};
  for (const [name, raw] of Object.entries(packages)) {
    if (js.manager === "npm") {
      const row = raw as { version?: string };
      if (name && row.version) result[name] = row.version;
    } else if (Array.isArray(raw) && typeof raw[0] === "string") {
      result[name] = descriptor(raw[0])[1];
    }
  }
  return result;
}
const installedVersion = (
  js: Awaited<ReturnType<typeof workspace>>,
  versions: Record<string, string>,
  name: string,
) => versions[js.manager === "npm" ? `node_modules/${name}` : name];
const registryName = (key: string) => key.split("node_modules/").at(-1)!;
/** Expo publishes its SDK dependency ranges; use that catalog for both planning and application. */
async function sdk(
  version: string,
  dir: string,
  signal?: AbortSignal,
): Promise<Record<string, string>> {
  const temp = await Deno.makeTempDir({ prefix: "maintenance-expo-" });
  try {
    const result = await command(dir, "npm", [
      "pack",
      `expo@${version}`,
      "--ignore-scripts",
      "--json",
      "--pack-destination",
      temp,
    ], signal);
    if (result.code !== 0) {
      throw new MaintenanceFailure(
        "Cannot read the Expo SDK dependency catalog",
      );
    }
    const rows = JSON.parse(result.stdout);
    const filename = rows[0]?.filename;
    if (
      typeof filename !== "string" ||
      !/^expo-[a-zA-Z0-9.-]+\.tgz$/.test(filename)
    ) throw new MaintenanceFailure("Invalid Expo SDK archive");
    const contents = await command(dir, "tar", [
      "-xzOf",
      `${temp}/${filename}`,
      "package/bundledNativeModules.json",
    ], signal);
    if (contents.code !== 0) {
      throw new MaintenanceFailure("Expo SDK has no dependency catalog");
    }
    return decode(contents.stdout, "Expo SDK catalog") as Record<
      string,
      string
    >;
  } finally {
    await Deno.remove(temp, { recursive: true });
  }
}
async function align(
  args: CommonArgs,
  targets: UpdateTarget[],
  signal?: AbortSignal,
) {
  const result = [...targets];
  for (const t of targets.filter((t) => t.package === "expo")) {
    const js = await workspace({ path: t.path, dir: `${args.cwd}/${t.path}` }),
      catalog = await sdk(t.version, js.dir, signal);
    for (const [name, range] of Object.entries(catalog)) {
      if (!(name in js.direct) || !coupled(name)) continue;
      const selected = result.find((r) =>
        r.path === t.path && r.package === name
      );
      if (selected) {
        if (!satisfies(selected.version, range)) {
          throw new MaintenanceFailure(
            `Selected ${name} conflicts with Expo SDK ${t.version}`,
          );
        }
        continue;
      }
      const available = eligible(
        await releases({ package: name }, js.dir, signal),
        undefined,
        args.policy[t.path]?.minimumReleaseAgeDays ?? 0,
      ).filter((r) => satisfies(r.version, range));
      if (!available.length) {
        throw new MaintenanceFailure(
          `No release-age eligible ${name} aligns with Expo SDK ${t.version}`,
        );
      }
      result.push({
        path: t.path,
        manager: "js",
        package: name,
        version: available[0].version,
        purpose: "major",
        advisories: [],
        advisoryUrls: [],
      });
    }
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
  const p = await places({ ...args, cwd: root }, "package.json");
  const selected: UpdateTarget[] = [];
  for (const place of batchPlaces(targets, p.places, "js")) {
    const js = await workspace(place);
    const chosen = place.targets, before = await versions(js);
    if (
      js.expo && !chosen.some((t) => t.package === "expo") &&
      chosen.some((t) => coupled(t.package))
    ) throw new MaintenanceFailure("Expo coupled target requires an SDK batch");
    if (js.expo && chosen.some((t) => t.package === "expo")) {
      const catalog = await sdk(
        chosen.find((t) => t.package === "expo")!.version,
        place.dir,
        signal,
      );
      for (const t of chosen) {
        if (
          t.package !== "expo" && coupled(t.package) && catalog[t.package] &&
          !satisfies(t.version, catalog[t.package])
        ) {
          throw new MaintenanceFailure(
            `Selected ${t.package} conflicts with the Expo SDK`,
          );
        }
      }
    }
    await writeJson(
      `${place.dir}/package.json`,
      select(js.manifest, chosen, choices),
    );
    await run(js, js.manager, [
      "install",
      ...(planning
        ? [
          js.manager === "npm" ? "--package-lock-only" : "--lockfile-only",
          "--ignore-scripts",
        ]
        : []),
    ], signal);
    const installed = await workspace(place), locked = await lock(installed);
    for (const t of chosen) {
      const row = locked[
        installed.manager === "npm" ? `node_modules/${t.package}` : t.package
      ];
      const version = installed.manager === "npm"
        ? (row as { version?: string })?.version
        : Array.isArray(row)
        ? String(row[0]).slice(t.package.length + 1)
        : null;
      if (
        choices
          ? !choices.find((o) => key(o[0]) === key(t))?.some((o) =>
            o.version === version
          )
          : version !== t.version
      ) {
        throw new MaintenanceFailure(
          `Resolver did not lock ${t.package} at ${t.version}`,
        );
      }
    }
    const after = await versions(installed);
    await assertChangedReleaseAge(
      before,
      after,
      args.policy[place.path]?.minimumReleaseAgeDays ?? 0,
      (name) =>
        releases(
          {
            package: installed.manager === "bun"
              ? descriptor((locked[name] as unknown[])[0] as string)[0]
              : (locked[name] as { name?: string })?.name ?? registryName(name),
          },
          place.dir,
          signal,
        ),
    );
    const resolved = chosen.filter((t) =>
      compare(
        installedVersion(installed, after, t.package)!,
        installedVersion(js, before, t.package) ?? "0.0.0",
      ) > 0
    ).map((t) => ({
      ...t,
      version: installedVersion(installed, after, t.package)!,
    }));
    selected.push(...resolved);
    const findings = await audit({
      ...installed,
      vitePlus: planning ? false : installed.vitePlus,
    }, signal);
    for (
      const t of (planning ? resolved : chosen).filter((t) =>
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
  const p = await places(args, "package.json");
  if (!candidates.length) {
    return { schema: 1 as const, targets: [], blocked: [] };
  }
  for (const c of candidates) {
    const place = p.places.find((p) => p.path === c.path);
    if (!place) throw new MaintenanceFailure("Unknown candidate path");
    const js = await workspace(place);
    c.current = installedVersion(js, await versions(js), c.package);
  }
  const temp = await scratch(p.root, signal),
    files = new Map<string, string | null>();
  for (const place of p.places) {
    for (const f of ["package.json", "package-lock.json", "bun.lock"]) {
      const name = `${place.path}/${f}`;
      files.set(name, await optionalText(`${p.root}/${name}`));
    }
  }
  try {
    return BatchPlanSchema.parse(
      await planBatch(candidates, args.policy, async (c) => {
        const place = p.places.find((p) => p.path === c.path);
        if (!place) throw new Error("Unknown detection path");
        const js = await workspace(place);
        if (
          !(c.package in js.direct) ||
          /(?:npm:|workspace:|git|https?:|file:|link:)/.test(
            js.direct[c.package],
          )
        ) {
          throw new Error(
            "No direct registry target; transitive/aliased finding remains recorded",
          );
        }
        return await releases(c, place.dir, signal);
      }, async (choices) => {
        for (const [f, text] of files) {
          if (text !== null) {
            await Deno.writeTextFile(`${temp.root}/${f}`, text);
          }
        }
        try {
          const expanded = await align(args, choices.map((o) => o[0]), signal);
          const matrix = expanded.map((t) =>
            choices.find((o) => key(o[0]) === key(t)) ?? [t]
          );
          const targets = await apply(
            temp.root,
            args,
            expanded,
            signal,
            true,
            matrix,
          );
          return { targets };
        } catch (e) {
          return {
            targets: [],
            error: e instanceof Error ? e.message : String(e),
          };
        }
      }),
    );
  } finally {
    await temp.close();
  }
}
export async function batch(
  args: CommonArgs,
  targets: UpdateTarget[],
  signal?: AbortSignal,
) {
  const p = await places(args, "package.json");
  for (const t of targets) {
    const place = p.places.find((p) => p.path === t.path);
    if (!place) throw new MaintenanceFailure("Unknown batch path");
    const r = (await releases(t, place.dir, signal)).find((r) =>
      r.version === t.version
    );
    const time = Date.parse(r?.publishedAt ?? "");
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
  for (const place of batchPlaces(targets, p.places, "js")) {
    const js = await workspace(place),
      current = await versions(js),
      findings = await audit({ ...js, vitePlus: false }, signal);
    select(js.manifest, place.targets);
    for (const t of place.targets) {
      assertFreshTarget(
        t,
        installedVersion(js, current, t.package),
        findings[t.package] ?? [],
      );
    }
  }
  await apply(args.cwd, args, targets, signal);
}
