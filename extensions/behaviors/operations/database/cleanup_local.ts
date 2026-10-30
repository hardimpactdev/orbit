import { cleanupPayload } from "./cleanup_contract.ts";
import { cleanupSeed } from "./cleanup_seed.ts";

/** The configured Node user owns clone exports; seed state cleanup runs with sudo. */
export async function cleanupAsOwner(
  raw: unknown,
): Promise<Record<string, unknown>> {
  const { input, agent_sha256 } = cleanupPayload(raw);
  if (input.variant !== "clone") {
    return await cleanupSeed(agent_sha256, input);
  }
  try {
    let directory: Deno.FileInfo;
    try {
      directory = await Deno.lstat(input.directory);
    } catch (error) {
      if (error instanceof Deno.errors.NotFound) return { backend: "absent" };
      throw error;
    }
    const uid = Deno.uid();
    if (
      uid === 0 || !directory.isDirectory || directory.isSymlink ||
      directory.uid !== uid || directory.mode === null ||
      (directory.mode & 0o7777) !== 0o700
    ) {
      return { error_code: "database.cleanup_owner_unavailable" };
    }
    const snapshot = input.directory + "/snapshot.sqlite";
    try {
      const file = await Deno.lstat(snapshot);
      if (!file.isFile || file.isSymlink || file.uid !== uid) {
        return { error_code: "database.sqlite_cleanup_refused" };
      }
      await Deno.remove(snapshot);
    } catch (error) {
      if (!(error instanceof Deno.errors.NotFound)) throw error;
    }
    await Deno.remove(input.directory);
    return { backend: "absent" };
  } catch {
    return { error_code: "database.sqlite_cleanup_failed" };
  }
}
