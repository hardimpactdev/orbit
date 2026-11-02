import { serviceToolPolicy } from "../../_lib/maintenance/tool_policy.ts";
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
  targetRange,
  UnwritableTarget,
  type UpdateTarget,
  writableReleases,
} from "../../_lib/maintenance/batch.ts";
import {
  command,
  type CommandResult,
  type CommonArgs,
  decode,
  MaintenanceFailure,
  optionalText,
  type Place,
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
/** npm reports deterministic misses on either JSON stdout or its diagnostic stream. */
function registryFailure(result: CommandResult, message: string): never {
  let code: unknown;
  try {
    code = JSON.parse(result.stdout)?.error?.code;
  } catch { /* npm may report only stderr. */ }
  if (
    code === "E404" || (code == null &&
      /\bnpm (?:ERR!|error)\s+code\s+E404\b|\bis not in (?:this|the(?: npm)?) registry\b/i
        .test(`${result.stdout}\n${result.stderr}`))
  ) {
    throw new UnwritableTarget(`${message}: package not found in registry`);
  }
  throw new MaintenanceFailure(message);
}
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
    registryFailure(result, "Cannot read registry target metadata");
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
      ) ?? targetRange(range, t);
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
  if (serviceToolPolicy()) {
    const result = await command(dir, "expo-catalog", [version], signal);
    if (result.code !== 0) {
      registryFailure(result, "Cannot read the Expo SDK dependency catalog");
    }
    return decode(result.stdout, "Expo SDK catalog") as Record<string, string>;
  }
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
      registryFailure(result, "Cannot read the Expo SDK dependency catalog");
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
/** One registry snapshot per plan/batch, isolated by configured path and never shared across runs. */
function metadata(signal?: AbortSignal) {
  const catalogs = new Map<string, Promise<Record<string, string>>>();
  const packages = new Map<string, ReturnType<typeof releases>>();
  const memo = <T>(
    cache: Map<string, Promise<T>>,
    key: string,
    read: () => Promise<T>,
  ) => {
    if (!cache.has(key)) cache.set(key, read());
    return cache.get(key)!;
  };
  return {
    sdk: (version: string, place: Place) =>
      memo(
        catalogs,
        `${place.path}\0${version}`,
        () => sdk(version, place.dir, signal),
      ),
    releases: (c: Pick<Candidate, "package">, place: Place) =>
      memo(
        packages,
        `${place.path}\0${c.package}`,
        () => releases(c, place.dir, signal),
      ),
  };
}
type Metadata = ReturnType<typeof metadata>;
async function align(
  args: CommonArgs,
  targets: UpdateTarget[],
  registry: Metadata,
) {
  const result = [...targets];
  for (const t of targets.filter((t) => t.package === "expo")) {
    const js = await workspace({ path: t.path, dir: `${args.cwd}/${t.path}` }),
      catalog = await registry.sdk(t.version, js);
    for (const [name, range] of Object.entries(catalog)) {
      if (!(name in js.direct) || !coupled(name)) continue;
      const selected = result.find((r) =>
        r.path === t.path && r.package === name
      );
      if (selected) {
        if (!satisfies(selected.version, range)) {
          throw new UnwritableTarget(
            `Selected ${name} conflicts with Expo SDK ${t.version}`,
          );
        }
        continue;
      }
      const available = eligible(
        await registry.releases({ package: name }, js),
        undefined,
        args.policy[t.path]?.minimumReleaseAgeDays ?? 0,
      ).filter((r) => satisfies(r.version, range));
      if (!available.length) {
        throw new UnwritableTarget(
          `No release-age eligible ${name} aligns with Expo SDK ${t.version}`,
        );
      }
      const aligned: UpdateTarget = {
        path: t.path,
        manager: "js",
        package: name,
        version: available[0].version,
        purpose: "major",
        advisories: [],
        advisoryUrls: [],
      };
      targetRange(js.direct[name], aligned);
      result.push(aligned);
    }
  }
  return result;
}
async function apply(
  root: string,
  args: CommonArgs,
  targets: UpdateTarget[],
  registry: Metadata,
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
      const catalog = await registry.sdk(
        chosen.find((t) => t.package === "expo")!.version,
        place,
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
    const declarations = planning ? js.manifest : select(js.manifest, chosen);
    await writeJson(
      `${place.dir}/package.json`,
      select(js.manifest, chosen, choices ?? chosen.map((t) => [t])),
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
    if (!planning) {
      if (JSON.stringify(declarations) !== JSON.stringify(installed.manifest)) {
        const solved = await versions(installed);
        await writeJson(`${place.dir}/package.json`, declarations);
        await run(js, js.manager, [
          "install",
          js.manager === "npm" ? "--package-lock-only" : "--lockfile-only",
          "--ignore-scripts",
        ], signal);
        const synchronized = await versions(await workspace(place));
        if (
          Object.keys(solved).length !== Object.keys(synchronized).length ||
          Object.entries(solved).some(([name, version]) =>
            synchronized[name] !== version
          )
        ) {
          throw new MaintenanceFailure(
            "Lockfile synchronization changed resolved versions",
          );
        }
      }
    }
    const after = await versions(installed);
    await assertChangedReleaseAge(
      before,
      after,
      args.policy[place.path]?.minimumReleaseAgeDays ?? 0,
      (name) =>
        registry.releases(
          {
            package: installed.manager === "bun"
              ? descriptor((locked[name] as unknown[])[0] as string)[0]
              : (locked[name] as { name?: string })?.name ?? registryName(name),
          },
          place,
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
  const p = await places(args, "package.json"), registry = metadata(signal);
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
          throw new UnwritableTarget(
            "No direct registry target; transitive/aliased finding remains recorded",
          );
        }
        return await writableReleases(
          c,
          args.policy,
          await registry.releases(c, place),
          async (version) => {
            const target: UpdateTarget = {
              path: c.path,
              manager: c.manager,
              package: c.package,
              version,
              purpose: c.reasons.includes("security") ? "security" : "major",
              advisories: c.advisories.map((a) => a.id),
              advisoryUrls: c.advisories.map((a) => a.url!),
            };
            targetRange(js.direct[c.package], target);
            if (js.expo && c.package === "expo") {
              await align(args, [target], registry);
            }
          },
        );
      }, async (choices) => {
        for (const [f, text] of files) {
          if (text !== null) {
            await Deno.writeTextFile(`${temp.root}/${f}`, text);
          }
        }
        let expanded: UpdateTarget[];
        try {
          expanded = await align(args, choices.map((o) => o[0]), registry);
        } catch (error) {
          if (!(error instanceof UnwritableTarget)) throw error;
          return { targets: [], error: error.message };
        }
        try {
          const matrix = expanded.map((t) =>
            choices.find((o) => key(o[0]) === key(t)) ?? [t]
          );
          const targets = await apply(
            temp.root,
            args,
            expanded,
            registry,
            signal,
            true,
            matrix,
          );
          return { targets };
        } catch (e) {
          signal?.throwIfAborted();
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
  const p = await places(args, "package.json"), registry = metadata(signal);
  for (const t of targets) {
    const place = p.places.find((p) => p.path === t.path);
    if (!place) throw new MaintenanceFailure("Unknown batch path");
    const r = (await registry.releases(t, place)).find((r) =>
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
  await apply(args.cwd, args, targets, registry, signal);
}
