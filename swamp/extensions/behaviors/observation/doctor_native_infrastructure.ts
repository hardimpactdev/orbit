import type { Row } from "./doctor_contract.ts";

type Local = (argv: string[]) => Promise<string>;
type Metadata = { owner: number; kind: string; mode: number; size: number };
type Pool = { name: string; directory: string | null };
type Configuration = { pools: Pool[]; prefix: string };
const poolName =
  /^orbit-(?:instance|workspace|app-instance)-[1-9][0-9]*(?:-[0-9a-f]{8})?$/;
const version = /^\d+\.\d+$/;
const name = /^[A-Za-z0-9][A-Za-z0-9._+@:-]{0,254}$/;
const encodedBytes = (text: string) => new TextEncoder().encode(text).length;
function fail(code: string): never {
  throw new Error(code);
}

/** Fixed Node-local reads. A selector outside this module is left to its owning reader. */
export async function readInfrastructure(
  selector: string,
  input: Row,
  local: Local,
): Promise<Row | undefined> {
  switch (selector) {
    case "doctor.inspect-role-runtime":
      return await roleRuntime(input, local);
    case "caddy.read-live":
      return { content: await text(local, "/etc/caddy/Caddyfile", 4194304) };
    case "php.inspect-shared-pools":
      return await phpPools(input, local);
    case "wireguard.inspect-hub":
      return await hub(local);
    case "wireguard.inspect-peer-dns":
      return await peerDns(input, local);
    case "wireguard.query-dns":
      return await queryDns(input, local);
    default:
      return undefined;
  }
}

async function metadata(
  local: Local,
  path: string,
  follow = true,
): Promise<Metadata | null> {
  let result: string;
  try {
    result = await local([
      "sudo",
      "-n",
      "env",
      "LC_ALL=C",
      "stat",
      ...(follow ? ["-L"] : []),
      "--printf=%u\\t%F\\t%a\\t%s",
      "--",
      path,
    ]);
  } catch {
    return null;
  }
  const fields = result.split("\t");
  if (
    fields.length !== 4 || !/^\d+$/.test(fields[0]) ||
    !/^[0-7]+$/.test(fields[2]) || !/^\d+$/.test(fields[3])
  ) fail("doctor.metadata_unverifiable");
  return {
    owner: Number(fields[0]),
    kind: fields[1],
    mode: parseInt(fields[2], 8),
    size: Number(fields[3]),
  };
}
async function text(
  local: Local,
  path: string,
  limit: number,
): Promise<string> {
  const info = await metadata(local, path);
  if (
    !info || info.kind !== "regular file" && info.kind !== "regular empty file"
  ) return "";
  if (info.size > limit) fail("doctor.file_read_bound");
  const value = await local([
    "sudo",
    "-n",
    "head",
    "-c",
    String(limit + 1),
    "--",
    path,
  ]);
  if (encodedBytes(value) > limit) fail("doctor.file_read_bound");
  return value;
}
async function active(local: Local, service: string): Promise<boolean> {
  try {
    await local(["systemctl", "is-active", "--quiet", service]);
    return true;
  } catch {
    return false;
  }
}

function names(value: unknown): string[] {
  if (
    !Array.isArray(value) || value.length > 256 ||
    !value.every((item) => typeof item === "string" && name.test(item))
  ) fail("doctor.role_input_invalid");
  return value as string[];
}
async function installed(local: Local, packageName: string): Promise<boolean> {
  try {
    return (await local([
      "dpkg-query",
      "-W",
      "-f=${Status}",
      "--",
      packageName,
    ])).trim() === "install ok installed";
  } catch {
    return false;
  }
}
async function dockerCe(local: Local): Promise<boolean> {
  for (const packageName of ["docker-ce", "docker-ce-cli", "containerd.io"]) {
    if (!await installed(local, packageName)) return false;
  }
  const binary = await metadata(local, "/usr/bin/docker");
  return binary !== null && binary.kind === "regular file" &&
    (binary.mode & 0o111) !== 0 && await active(local, "docker");
}
async function roleRuntime(
  input: Row,
  local: Local,
): Promise<Row> {
  const packages = names(input.packages), services = names(input.services);
  let packagesPresent = true, servicesActive = true;
  for (const packageName of packages) {
    packagesPresent = (packageName === "docker.io" && await dockerCe(local) ||
      await installed(local, packageName)) && packagesPresent;
  }
  for (const service of services) {
    servicesActive = await active(local, service) && servicesActive;
  }
  let caddyVersion: string | null = null;
  if (packages.includes("caddy")) {
    try {
      caddyVersion =
        (await local(["caddy", "version"])).split(/\r?\n/, 1)[0].trim().split(
          /\s+/,
          1,
        )[0] || null;
    } catch {
      caddyVersion = null;
    }
  }
  return {
    packages: packagesPresent,
    services: servicesActive,
    caddy_version: caddyVersion,
  };
}

function parseConfiguration(value: unknown): Configuration {
  if (
    typeof value !== "string" || encodedBytes(value) > 1048576 ||
    value.includes("\0")
  ) fail("php.shared_pool_invalid");
  const result: Configuration = { pools: [], prefix: "" };
  const names = new Set<string>();
  for (const line of value.split(/(?<=\n)/)) {
    const trimmed = line.trim();
    if (trimmed.startsWith("[")) {
      const section = trimmed.match(/^\[(.*)\]$/)?.[1];
      if (!section || !poolName.test(section) || names.has(section)) {
        fail("php.shared_pool_invalid");
      }
      names.add(section);
      result.pools.push({ name: section, directory: null });
    }
    const pool = result.pools.at(-1);
    if (pool) {
      const at = trimmed.indexOf("=");
      if (at !== -1 && trimmed.slice(0, at).trim() === "chdir") {
        const directory = trimmed.slice(at + 1).trim();
        if (
          !directory.startsWith("/") ||
          Array.from(directory).some((character) =>
            character.charCodeAt(0) < 32 || character.charCodeAt(0) === 127
          ) ||
          pool.directory !== null
        ) fail("php.shared_pool_invalid");
        pool.directory = directory;
      }
    } else if (!trimmed || trimmed.startsWith(";") || trimmed.startsWith("#")) {
      result.prefix += line;
    } else fail("php.shared_pool_invalid");
  }
  return result;
}
async function owned(
  local: Local,
  path: string,
  directory: boolean,
): Promise<boolean> {
  const info = await metadata(local, path, false);
  if (!info) return false;
  if (
    info.owner !== 0 ||
    (directory
      ? info.kind !== "directory"
      : !["regular file", "regular empty file"].includes(info.kind))
  ) fail("php.shared_pool_unsafe");
  return true;
}
async function phpPools(input: Row, local: Local): Promise<Row> {
  if (
    !input.configurations || typeof input.configurations !== "object" ||
    Array.isArray(input.configurations)
  ) fail("php.shared_pool_invalid");
  const desired: [string, Configuration][] = [];
  const names = new Set<string>();
  for (
    const [key, value] of Object.entries(input.configurations).sort((
      [a],
      [b],
    ) => a.localeCompare(b))
  ) {
    if (!version.test(key)) fail("php.shared_pool_invalid");
    const configuration = parseConfiguration(value);
    for (const pool of configuration.pools) {
      if (pool.directory === null || names.has(pool.name)) {
        fail("php.shared_pool_invalid");
      }
      names.add(pool.name);
    }
    desired.push([key, configuration]);
  }
  if (!await owned(local, "/etc", true)) fail("php.shared_pool_unsafe");
  const actual: [string, Configuration][] = [];
  if (await owned(local, "/etc/php", true)) {
    const listing = await local([
      "sudo",
      "-n",
      "find",
      "/etc/php",
      "-mindepth",
      "1",
      "-maxdepth",
      "1",
      "-printf",
      "%f\\n",
    ]);
    for (
      const key of listing.trim().split("\n").filter((entry) =>
        version.test(entry)
      ).sort()
    ) {
      if (!await owned(local, `/etc/php/${key}`, true)) {
        fail("php.shared_pool_unsafe");
      }
      if (
        !await owned(local, `/etc/php/${key}/fpm`, true) ||
        !await owned(local, `/etc/php/${key}/fpm/pool.d`, true)
      ) continue;
      const path = `/etc/php/${key}/fpm/pool.d/orbit-scopes.conf`;
      if (await owned(local, path, false)) {
        actual.push([
          key,
          parseConfiguration(await text(local, path, 1048576)),
        ]);
      }
    }
  }
  const missing: Row[] = [], versions: string[] = [];
  const exists = new Map<string, boolean>();
  const present = async (path: string) => {
    if (!exists.has(path)) {
      exists.set(path, (await metadata(local, path))?.kind === "directory");
    }
    return exists.get(path)!;
  };
  for (const [key, configuration] of actual) {
    for (const pool of configuration.pools) {
      if (pool.directory !== null && !await present(pool.directory)) {
        missing.push({
          pool: pool.name,
          version: key,
          directory: pool.directory,
          installed: true,
        });
      }
    }
  }
  for (const [key, configuration] of desired) {
    let kept = 0;
    for (const pool of configuration.pools) {
      if (await present(pool.directory!)) kept++;
      else if (!missing.some((entry) => entry.pool === pool.name)) {
        missing.push({
          pool: pool.name,
          version: key,
          directory: pool.directory,
          installed: false,
        });
      }
    }
    if (
      kept > 0 ||
      configuration.pools.length === 0 && configuration.prefix !== ""
    ) versions.push(key);
  }
  return { versions, missing_pools: missing };
}

async function digest(local: Local, path: string): Promise<string | null> {
  const info = await metadata(local, path);
  if (!info || !["regular file", "regular empty file"].includes(info.kind)) {
    return null;
  }
  const sum = await local(["sudo", "-n", "sha256sum", "--", path]);
  const hash = sum.match(/^([a-f0-9]{64})\s/)?.[1];
  if (!hash) fail("wireguard.hub_unreadable");
  return hash;
}
async function hub(local: Local): Promise<Row> {
  return {
    active: await active(local, "wg-quick@orbit"),
    config_sha256: await digest(local, "/etc/wireguard/orbit.conf"),
    dns_records_sha256: await digest(
      local,
      "/etc/dnsmasq.d/orbit-records.conf",
    ),
  };
}

/** Only public DNS fields leave the root shell; the private key is never printed. */
const peerSnapshot = `set -eu
for file in /etc/wireguard/orbit.dns-link /etc/wireguard/orbit.conf; do
  value=''
  if [ -f "$file" ]; then
    if [ "$file" = /etc/wireguard/orbit.conf ]; then
      value=$(sed -n '/^PostUp = resolvectl/p' "$file")
    else
      value=$(head -c 1048577 -- "$file")
    fi
  fi
  printf '%s' "$value" | base64 -w0
  printf '\\n'
done
for setting in dns domain default-route; do
  value=$(resolvectl "$setting" orbit)
  printf '%s' "$value" | base64 -w0
  printf '\\n'
done
value=$(resolvectl dns)
printf '%s' "$value" | base64 -w0
printf '\\n'
`;

async function peerDns(input: Row, local: Local): Promise<Row> {
  if (
    typeof input.server !== "string" || !/^[A-Fa-f0-9:.]+$/.test(input.server)
  ) fail("wireguard.peer_dns_unreadable");
  const encoded = await local([
    "sudo",
    "-n",
    "flock",
    "--shared",
    "--wait",
    "5",
    "/run/lock/orbit-wireguard-peer.lock",
    "sh",
    "-c",
    peerSnapshot,
  ]);
  const fields = encoded.replace(/\n$/, "").split("\n");
  if (fields.length !== 6) fail("wireguard.peer_dns_unreadable");
  const values = fields.map((field) => {
    if (field.length > 1398104 || !/^[A-Za-z0-9+/]*={0,2}$/.test(field)) {
      fail("wireguard.peer_dns_unreadable");
    }
    try {
      const bytes = Uint8Array.from(atob(field), (char) => char.charCodeAt(0));
      if (bytes.length > 1048576) fail("wireguard.peer_dns_unreadable");
      return new TextDecoder("utf-8", { fatal: true }).decode(bytes).replace(
        /\n+$/,
        "",
      );
    } catch {
      return fail("wireguard.peer_dns_unreadable");
    }
  });
  const [state, hooks, dns, domains, default_route, listing] = values;
  return {
    state,
    hooks,
    dns,
    domains,
    default_route,
    uplink: usableUplink(listing, input.server, state.split("\n")[1] ?? ""),
  };
}
export function usableUplink(
  listing: string,
  privateServer: string,
  previous: string,
): boolean {
  return listing.split("\n").some((line) => {
    if (
      /^Link [0-9]+ \(orbit\):/.test(line) ||
      !/^(Global|Link [0-9]+ \([^)]+\)): ?/.test(line)
    ) return false;
    return line.slice(line.indexOf(":") + 1).trim().split(/\s+/).some(
      (value) => {
        const server = value.split(/[%#]/, 1)[0];
        if (
          [privateServer, previous, "::1", "::", "0.0.0.0"].includes(server)
        ) return false;
        const octets = server.split(".");
        if (
          octets.length === 4 && octets.every((octet) => /^\d+$/.test(octet))
        ) {
          return octets.every((octet) => Number(octet) <= 255) &&
            Number(octets[0]) !== 127;
        }
        return server.includes(":") && /^[A-Fa-f0-9:.]+$/.test(server);
      },
    );
  });
}
async function queryDns(input: Row, local: Local): Promise<Row> {
  if (
    !Array.isArray(input.names) || !input.names.length ||
    input.names.length > 8 ||
    !input.names.every((value) =>
      typeof value === "string" && value.length <= 253 &&
      /^[A-Za-z0-9.-]+$/.test(value)
    )
  ) fail("wireguard.dns_name_invalid");
  const answers: (string | null)[] = [];
  for (const hostname of input.names) {
    try {
      answers.push(
        (await local([
          "resolvectl",
          "query",
          "--cache=no",
          "--type=A",
          hostname,
        ])).replace(/[\r\n]+$/, ""),
      );
    } catch {
      answers.push(null);
    }
  }
  return { answers };
}
