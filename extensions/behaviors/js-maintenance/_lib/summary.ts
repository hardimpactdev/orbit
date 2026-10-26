import { changesFor, type LockView } from "../../_lib/maintenance/changes.ts";
import { composerRows } from "../../_lib/maintenance/composer_summary.ts";
import {
  decode,
  MaintenanceFailure,
  optionalText,
  type Place,
} from "../../_lib/maintenance/io.ts";
import {
  type Change,
  resolveBase,
  show,
  summaryResult,
} from "../../_lib/maintenance/summary.ts";
import { audit, type Findings } from "./reports.ts";
import {
  descriptor,
  direct,
  lockDecode,
  lockName,
  nodeName,
  prepare,
  topName,
  type Workspace,
  workspace,
} from "./workspace.ts";
function view(text: string | null, js: Workspace): LockView {
  const versions: Record<string, string> = {},
    instances: Record<string, string> = {};
  if (text === null) return { versions, instances };
  const data = lockDecode(text, js.manager);
  const packages = data.packages as Record<string, unknown>;
  // Workspace validation and the same parser used by audit validate the lock shape.
  if (!packages || typeof packages !== "object" || Array.isArray(packages)) {
    throw new MaintenanceFailure(`${lockName(js)} has no packages field`);
  }
  for (const [key, value] of Object.entries(packages)) {
    if (js.manager === "bun") {
      if (!Array.isArray(value) || typeof value[0] !== "string") {
        throw new MaintenanceFailure("Invalid bun.lock package entry");
      }
      const [name, version] = descriptor(value[0]);
      instances[key] = `${name}@${version.replace(/^v(?=\d)/, "")}`;
      if (topName(key)) versions[key] = version.replace(/^v(?=\d)/, "");
    } else {
      const at = key.lastIndexOf("node_modules/");
      const name = at < 0 ? null : topName(key.slice(at + 13));
      if (!name) continue;
      const item = value as { version?: unknown };
      if (!item || typeof item.version !== "string" || !item.version) continue;
      const version = item.version.replace(/^v(?=\d)/, "");
      instances[key] = `${name}@${version}`;
      if (nodeName(key)) versions[name] = version;
    }
  }
  return { versions, instances };
}
async function rowsFor(
  root: string,
  place: Place,
  base: string,
  signal?: AbortSignal,
) {
  const js = await workspace(place);
  const manifest = await show(root, place, base, "package.json", signal);
  const baseManifest = manifest === null
    ? js.manifest
    : decode(manifest, "package.json");
  const file = lockName(js);
  const baseLock = await show(root, place, base, file, signal);
  return await changesFor(
    place,
    direct(baseManifest),
    js.direct,
    view(baseLock, js),
    view(await optionalText(`${place.dir}/${file}`), js),
    async () => {
      await prepare(js, signal);
      const current = await audit(js, signal);
      const temp = await Deno.makeTempDir({ prefix: "js-maintenance-base-" });
      try {
        let previous: Findings = {};
        if (baseLock !== null) {
          await Deno.writeTextFile(
            `${temp}/package.json`,
            manifest ?? JSON.stringify(js.manifest),
          );
          await Deno.writeTextFile(`${temp}/${file}`, baseLock);
          const before = await workspace({ path: place.path, dir: temp });
          if (before.vitePlus) {
            await Deno.mkdir(`${temp}/node_modules/.bin`, { recursive: true });
            await Deno.symlink(
              `${js.dir}/node_modules/.bin/vp`,
              `${temp}/node_modules/.bin/vp`,
            );
          }
          previous = await audit(before, signal);
        }
        return { previous, current };
      } finally {
        await Deno.remove(temp, { recursive: true });
      }
    },
  );
}
/** Include Composer in the same paths when present; the format is shared with Composer maintenance. */
export async function summarize(
  root: string,
  places: Place[],
  base?: string,
  signal?: AbortSignal,
) {
  const resolved = await resolveBase(root, base, signal),
    changes: Change[] = [];
  for (const place of places) {
    const rows: Change[] = [];
    if (await optionalText(`${place.dir}/composer.json`) !== null) {
      rows.push(...await composerRows(root, place, resolved, signal));
    }
    if (await optionalText(`${place.dir}/package.json`) !== null) {
      rows.push(...await rowsFor(root, place, resolved, signal));
    }
    changes.push(
      ...rows.sort((a, b) =>
        a.package < b.package ? -1 : a.package > b.package ? 1 : 0
      ),
    );
  }
  return summaryResult(changes);
}
