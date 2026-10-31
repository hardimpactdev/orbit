import { z } from "npm:zod@4.4.3";
import { parseRecipe, rewriteSnapshot, same } from "./contents.ts";
import { requirePolicy } from "./policy.ts";

const Sury = "https://packages.sury.org/php/";
const Zabbly = "https://pkgs.zabbly.com/incus/lts-7.0/";
const SuryKey =
  "b486fd5488185c4c46467960fa69c53d5085fec492cf76b9eaf3db33561c9d7c";
const ZabblyKey =
  "f1adb44fdf82083f983a392b5b406d34e473dce3d66a51de3118cdc1f51d3f87";
const Digest = z.string().regex(/^[a-f0-9]{64}$/);
const Version = z.string().regex(/^[0-9][A-Za-z0-9.+:~_-]{0,200}$/);
export async function sha256(bytes: Uint8Array<ArrayBuffer>) {
  return Array.from(
    new Uint8Array(await crypto.subtle.digest("SHA-256", bytes)),
    (byte) => byte.toString(16).padStart(2, "0"),
  ).join("");
}
async function download(url: string, maximum = 64 * 1024 * 1024) {
  const response = await fetch(url, {
    redirect: "error",
    signal: AbortSignal.timeout(60000),
  });
  requirePolicy(response.ok);
  let size = 0;
  const chunks: Uint8Array[] = [];
  for await (const chunk of response.body ?? []) {
    size += chunk.length;
    requirePolicy(size <= maximum);
    chunks.push(chunk);
  }
  const bytes = new Uint8Array(size);
  let offset = 0;
  for (const chunk of chunks) {
    bytes.set(chunk, offset);
    offset += chunk.length;
  }
  return bytes;
}
async function command(
  name: "/usr/bin/gpgv" | "/usr/bin/gpg" | "/usr/bin/sha256sum",
  args: string[],
  input?: Uint8Array,
) {
  const child = new Deno.Command(name, {
    args,
    stdin: input ? "piped" : "null",
    stdout: "piped",
    stderr: "null",
    signal: AbortSignal.timeout(30000),
  }).spawn();
  try {
    if (input) {
      const writer = child.stdin.getWriter();
      await writer.write(input);
      await writer.close();
    }
    const output = await child.output();
    requirePolicy(output.success && output.stdout.length <= 2 * 1024 * 1024);
    return output.stdout;
  } finally {
    try {
      child.kill("SIGTERM");
    } catch { /* Already exited. */ }
    await child.status.catch(() => {});
  }
}
async function verifiedRelease(
  url: string,
  directory: string,
  keyring: string,
) {
  const path = directory + "/InRelease";
  await Deno.writeFile(path, await download(url, 2 * 1024 * 1024), {
    mode: 0o600,
  });
  return new TextDecoder().decode(
    await command("/usr/bin/gpgv", [
      "--homedir",
      directory,
      "--keyring",
      keyring,
      "--output",
      "-",
      path,
    ]),
  );
}
export function releaseHashes(release: string) {
  const hashes: Record<string, { sha256: string; size: number }> = {};
  let section = false;
  for (const line of release.split("\n")) {
    if (!line.startsWith(" ")) {
      section = line.trim() === "SHA256:";
      continue;
    }
    if (!section) continue;
    const match = /^ ([a-f0-9]{64})\s+(\d+) (\S+)$/.exec(line);
    requirePolicy(match && !Object.hasOwn(hashes, match[3]));
    hashes[match[3]] = { sha256: match[1], size: Number(match[2]) };
  }
  return hashes;
}
export function* stanzas(text: string): Generator<Record<string, string>> {
  const separator = /\n\s*\n/g;
  let start = 0;
  for (;;) {
    const next = separator.exec(text);
    const block = text.slice(start, next?.index ?? text.length);
    const fields: Record<string, string> = {};
    for (const line of block.split("\n")) {
      if (!line || line.startsWith(" ") || !line.includes(":")) continue;
      const colon = line.indexOf(":"), key = line.slice(0, colon);
      requirePolicy(!Object.hasOwn(fields, key));
      fields[key] = line.slice(colon + 1).trim();
    }
    if (fields.Package) yield fields;
    if (!next) return;
    start = separator.lastIndex;
  }
}
function comparePart(left: string, right: string): number {
  const order = (value: string) =>
    value === "~"
      ? -1
      : !value
      ? 0
      : /^[A-Za-z]$/.test(value)
      ? value.charCodeAt(0)
      : value.charCodeAt(0) + 256;
  while (left || right) {
    const lt = /^[^0-9]*/.exec(left)![0], rt = /^[^0-9]*/.exec(right)![0];
    for (let index = 0; index < Math.max(lt.length, rt.length); index++) {
      const a = order(lt[index] ?? ""), b = order(rt[index] ?? "");
      if (a !== b) return a < b ? -1 : 1;
    }
    left = left.slice(lt.length);
    right = right.slice(rt.length);
    const ln = /^[0-9]*/.exec(left)![0], rn = /^[0-9]*/.exec(right)![0];
    const a = BigInt(ln || "0"), b = BigInt(rn || "0");
    if (a !== b) return a < b ? -1 : 1;
    left = left.slice(ln.length);
    right = right.slice(rn.length);
  }
  return 0;
}
export function compareVersions(left: string, right: string) {
  const split = (version: string): [bigint, string, string] => {
    Version.parse(version);
    const colon = version.indexOf(":");
    const epoch = colon === -1 ? 0n : BigInt(version.slice(0, colon));
    const rest = colon === -1 ? version : version.slice(colon + 1),
      dash = rest.lastIndexOf("-");
    return [
      epoch,
      dash === -1 ? rest : rest.slice(0, dash),
      dash === -1 ? "0" : rest.slice(dash + 1),
    ];
  };
  const a = split(left), b = split(right);
  return a[0] !== b[0]
    ? a[0] < b[0] ? -1 : 1
    : comparePart(a[1], b[1]) || comparePart(a[2], b[2]);
}
async function index(
  root: string,
  names: string[],
  hashes: ReturnType<typeof releaseHashes>,
) {
  const name = names.find((name) => Object.hasOwn(hashes, name));
  requirePolicy(name);
  const bytes = await download(root + name), expected = hashes[name];
  requirePolicy(
    bytes.length === expected.size && await sha256(bytes) === expected.sha256,
  );
  if (name.endsWith(".gz")) {
    return await new Response(
      new Blob([bytes]).stream().pipeThrough(new DecompressionStream("gzip")),
    ).text();
  }
  return new TextDecoder("utf-8", { fatal: true }).decode(bytes);
}
export async function snapshotVersions(content: string, next: string) {
  const recipe = parseRecipe(content), versions: Record<string, string> = {};
  const packages = new Set(
    Object.entries(recipe.tools).filter(([, tool]) => tool.source === "ubuntu")
      .map(([name]) => name),
  );
  const directory = await Deno.makeTempDir({ prefix: "orbit-ubuntu-index-" });
  try {
    for (
      const pocket of ["resolute", "resolute-updates", "resolute-security"]
    ) {
      const root = "https://snapshot.ubuntu.com/ubuntu/" + next + "/dists/" +
        pocket + "/";
      const release = await verifiedRelease(
        root + "InRelease",
        directory,
        "/usr/share/keyrings/ubuntu-archive-keyring.gpg",
      );
      requirePolicy(
        /^Codename: resolute$/m.test(release) &&
          new RegExp("^Suite: " + pocket + "$", "m").test(release) &&
          /^Architectures: .*\bamd64\b/m.test(release),
      );
      const hashes = releaseHashes(release);
      for (const component of ["main", "universe"]) {
        const text = await index(root, [
          component + "/binary-amd64/Packages.gz",
        ], hashes);
        for (const fields of stanzas(text)) {
          if (
            packages.has(fields.Package) && fields.Version &&
            (!versions[fields.Package] ||
              compareVersions(fields.Version, versions[fields.Package]) > 0)
          ) versions[fields.Package] = fields.Version;
        }
      }
    }
    requirePolicy([...packages].every((name) => versions[name]));
    return versions;
  } finally {
    await Deno.remove(directory, { recursive: true });
  }
}
export async function bumpSnapshot(content: string, next: string) {
  return rewriteSnapshot(content, next, await snapshotVersions(content, next));
}

export async function artifactIndexes(content: string, verifyFiles = false) {
  const recipe = parseRecipe(content);
  const raw = z.record(z.string(), z.unknown()).parse(JSON.parse(content));
  const tools = z.record(z.string(), z.record(z.string(), z.unknown())).parse(
    raw.tools,
  );
  const phpNames = [
    ...new Set([
      ...recipe.php.shared,
      ...Object.values(recipe.php.versions).flatMap((version) =>
        version.packages
      ),
    ]),
  ];
  const directory = await Deno.makeTempDir({ prefix: "orbit-artifact-index-" });
  try {
    const suryKey = await download(Sury + "apt.gpg", 1024 * 1024);
    requirePolicy(await sha256(suryKey) === SuryKey);
    await Deno.writeFile(directory + "/sury.gpg", suryKey, { mode: 0o600 });
    const suryRelease = await verifiedRelease(
      Sury + "dists/resolute/InRelease",
      directory,
      directory + "/sury.gpg",
    );
    const sury = Array.from(stanzas(
      await index(Sury + "dists/resolute/", [
        "main/binary-amd64/Packages.gz",
        "main/binary-amd64/Packages",
      ], releaseHashes(suryRelease)),
    ));
    for (const name of phpNames) {
      const rows = sury.filter((row) =>
        row.Package === name && ["amd64", "all"].includes(row.Architecture)
      );
      requirePolicy(rows.length > 0);
      rows.sort((a, b) => compareVersions(b.Version, a.Version));
      const newest = rows[0];
      Digest.parse(newest.SHA256);
      requirePolicy(
        /^pool\/[A-Za-z0-9_./+:%~-]+$/.test(newest.Filename) &&
          !newest.Filename.split("/").includes(".."),
      );
      tools[name] = {
        ...tools[name],
        version: newest.Version,
        artifacts: {
          amd64: { url: Sury + newest.Filename, sha256: newest.SHA256 },
        },
      };
    }
    for (const [version, selected] of Object.entries(recipe.php.versions)) {
      const common = tools["php" + version + "-common"].version;
      requirePolicy(
        selected.packages.every((name) =>
          typeof tools[name].version === "string" &&
          (!(tools[name].version as string).startsWith(version + ".") ||
            tools[name].version === common)
        ),
      );
    }
    const zabblyKey = await download(
      "https://pkgs.zabbly.com/key.asc",
      1024 * 1024,
    );
    requirePolicy(await sha256(zabblyKey) === ZabblyKey);
    await Deno.writeFile(
      directory + "/zabbly.gpg",
      await command("/usr/bin/gpg", [
        "--batch",
        "--no-options",
        "--homedir",
        directory,
        "--dearmor",
      ], zabblyKey),
      { mode: 0o600 },
    );
    const zabblyRelease = await verifiedRelease(
      Zabbly + "dists/resolute/InRelease",
      directory,
      directory + "/zabbly.gpg",
    );
    const rows = Array.from(stanzas(
      await index(
        Zabbly + "dists/resolute/",
        ["main/binary-amd64/Packages"],
        releaseHashes(zabblyRelease),
      ),
    ));
    const selected: Record<string, Record<string, string>> = {};
    for (const name of ["incus", "incus-base", "incus-client"]) {
      const candidates = rows.filter((row) =>
        row.Package === name && row.Architecture === "amd64" &&
        /^1:7\.0\.[0-9]+-ubuntu26\.04-[0-9]+$/.test(row.Version)
      );
      requirePolicy(candidates.length > 0);
      candidates.sort((a, b) => compareVersions(b.Version, a.Version));
      selected[name] = candidates[0];
    }
    requirePolicy(
      new Set(Object.values(selected).map((row) => row.Version)).size === 1,
    );
    for (const [name, row] of Object.entries(selected)) {
      const filename = "pool/main/i/incus/" + name + "_" +
        row.Version.split(":")[1] + "_amd64.deb";
      requirePolicy(row.Filename === filename);
      Digest.parse(row.SHA256);
      const native = row.Version.split(":")[1],
        upstream = native.split("-")[0];
      const artifact = {
        url: Zabbly + filename,
        sha256: row.SHA256,
        version: row.Version,
      };
      const previous = z.record(z.string(), z.unknown()).safeParse(
        tools[name]?.artifacts,
      );
      if (
        verifyFiles &&
        !same(previous.success ? previous.data.amd64 : null, artifact)
      ) {
        await verifyDebianFile(
          artifact.url,
          artifact.sha256,
          directory + "/" + name + ".deb",
        );
      }
      tools[name] = {
        source: "artifact",
        version: upstream,
        artifacts: { amd64: artifact },
      };
    }
    raw.tools = tools;
    const updated = JSON.stringify(raw, null, 4) + "\n";
    parseRecipe(updated);
    return updated;
  } finally {
    await Deno.remove(directory, { recursive: true });
  }
}

async function verifyDebianFile(url: string, expected: string, path: string) {
  const response = await fetch(url, {
    redirect: "error",
    signal: AbortSignal.timeout(60000),
  });
  requirePolicy(response.ok && response.body);
  const file = await Deno.open(path, {
    createNew: true,
    write: true,
    mode: 0o600,
  });
  let size = 0;
  await response.body.pipeThrough(
    new TransformStream<Uint8Array, Uint8Array>({
      transform(chunk, controller) {
        size += chunk.length;
        requirePolicy(size <= 512 * 1024 * 1024);
        controller.enqueue(chunk);
      },
    }),
  ).pipeTo(file.writable);
  const output = new TextDecoder().decode(
    await command("/usr/bin/sha256sum", [path]),
  );
  requirePolicy(output.startsWith(Digest.parse(expected) + "  "));
}
