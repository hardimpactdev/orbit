import pin from "../family/agent/ssh-files.json" with { type: "json" };
import { catalogueDigest } from "../family/catalogue.ts";

/** Verify before bundling; use native `extension pull` to install the pin. */
export async function verifyAgentSsh(root = new URL("../", import.meta.url)) {
  const lock = JSON.parse(
    await Deno.readTextFile(
      new URL("family/agent/upstream_extensions.json", root),
    ),
  )[pin.package];
  if (lock?.version !== pin.version || lock?.checksum !== pin.archive_sha256) {
    throw new Error("SSH dependency release pin mismatch");
  }
  const base = new URL(".swamp/pulled-extensions/@swamp/ssh/", root);
  const actual: string[] = [];
  const walk = async (directory: URL, prefix: string) => {
    for await (const entry of Deno.readDir(directory)) {
      const name = prefix + entry.name;
      if (entry.isSymlink) throw new Error("SSH dependency contains a symlink");
      if (entry.isDirectory) {
        await walk(new URL(entry.name + "/", directory), name + "/");
      } else if (entry.isFile) actual.push(name);
    }
  };
  await walk(base, "");
  if (
    JSON.stringify(actual.sort()) !==
      JSON.stringify(Object.keys(pin.files_sha256).sort())
  ) throw new Error("SSH dependency file set mismatch");
  for (const [file, expected] of Object.entries(pin.files_sha256)) {
    // Published SSH files are UTF-8 source, manifest and documentation.
    if (
      await catalogueDigest(await Deno.readTextFile(new URL(file, base))) !==
        expected
    ) throw new Error("SSH dependency file digest mismatch");
  }
}
if (import.meta.main) {
  await verifyAgentSsh();
  console.log(`Verified ${pin.package}@${pin.version}`);
}
