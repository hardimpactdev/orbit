import { changesFor } from "./changes.ts";
import { audit, direct, versions } from "./composer.ts";
import { decode, optionalText, type Place, readJson } from "./io.ts";
import { type Change, show } from "./summary.ts";

export async function composerRows(
  root: string,
  place: Place,
  base: string,
  signal?: AbortSignal,
): Promise<Change[]> {
  const manifest = await readJson(`${place.dir}/composer.json`);
  const baseManifestText = await show(
    root,
    place,
    base,
    "composer.json",
    signal,
  );
  const baseManifest = baseManifestText === null
    ? manifest
    : decode(baseManifestText, "composer.json");
  const beforeDirect = direct(baseManifest);
  const afterDirect = direct(manifest);
  const baseLock = await show(root, place, base, "composer.lock", signal);
  const before = versions(baseLock);
  const after = versions(await optionalText(`${place.dir}/composer.lock`));
  return await changesFor(
    place,
    "composer",
    beforeDirect,
    afterDirect,
    { versions: before, instances: before },
    { versions: after, instances: after },
    async () => {
      const current = await audit(place.dir, signal);
      const temp = await Deno.makeTempDir({
        prefix: "composer-maintenance-base-",
      });
      try {
        let previous = {} as Awaited<ReturnType<typeof audit>>;
        if (baseLock !== null) {
          await Deno.writeTextFile(
            `${temp}/composer.json`,
            baseManifestText ?? JSON.stringify(manifest),
          );
          await Deno.writeTextFile(`${temp}/composer.lock`, baseLock);
          previous = await audit(temp, signal);
        }
        return { previous, current };
      } finally {
        await Deno.remove(temp, { recursive: true });
      }
    },
  );
}
