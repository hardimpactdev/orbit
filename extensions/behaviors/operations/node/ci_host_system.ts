import { localSystemCommand, type SystemTransport } from "./system_command.ts";

const project = "orbit-ci";
const network = "orbitci0";
const acl = "orbit-ci-egress";
const pool = "default";
const projectConfig = {
  "features.images": "true",
  "features.profiles": "true",
  "features.storage.volumes": "true",
  "features.storage.buckets": "true",
  "features.networks": "false",
  restricted: "true",
  "restricted.networks.access": network,
  "restricted.containers.nesting": "block",
  "restricted.containers.privilege": "unprivileged",
  "restricted.virtual-machines.lowlevel": "block",
  "restricted.containers.lowlevel": "allow",
  "limits.virtual-machines": "0",
};
const networkConfig = {
  "ipv4.nat": "true",
  "ipv6.address": "none",
  "security.acls": acl,
  "security.acls.default.egress.action": "reject",
  "security.acls.default.ingress.action": "reject",
};
const profile = {
  description: "Orbit CI shard runner",
  config: {
    "boot.autostart": "true",
    "limits.cpu": "6",
    "limits.memory": "12GiB",
    "limits.processes": "8192",
    "security.nesting": "false",
    "security.privileged": "false",
  },
  devices: {
    eth0: { name: "eth0", network, type: "nic" },
    root: { path: "/", pool, type: "disk" },
  },
};
const egress = [
  {
    action: "reject",
    destination:
      "0.0.0.0/8,10.0.0.0/8,100.64.0.0/10,127.0.0.0/8,169.254.0.0/16,172.16.0.0/12,192.0.0.0/24,192.168.0.0/16,198.18.0.0/15,224.0.0.0/3",
    state: "enabled",
  },
  { action: "reject", destination: "::/0", state: "enabled" },
  { action: "allow", destination: "0.0.0.0/0", state: "enabled" },
];
const failure = { error_code: "ci_runner.host_failed" } as const;
class CiHostFailure extends Error {}
type ObjectValue = Record<string, unknown>;
const object = (value: unknown): ObjectValue =>
  value !== null && typeof value === "object" && !Array.isArray(value)
    ? value as ObjectValue
    : {};
const includes = (actual: unknown, expected: Record<string, string>) =>
  Object.entries(expected).every(([key, value]) =>
    object(actual)[key] === value
  );
const equal = (left: unknown, right: unknown): boolean => {
  if (Array.isArray(left) || Array.isArray(right)) {
    return Array.isArray(left) && Array.isArray(right) &&
      left.length === right.length && left.every((value, i) =>
        equal(value, right[i])
      );
  }
  if (
    left !== null && right !== null && typeof left === "object" &&
    typeof right === "object"
  ) {
    const keys = Object.keys(left);
    return keys.length === Object.keys(right).length &&
      keys.every((key) =>
        Object.hasOwn(right, key) &&
        equal(object(left)[key], object(right)[key])
      );
  }
  return left === right;
};
const defaultPath = (collection: string, name?: string) =>
  `/1.0/${collection}${name === undefined ? "" : `/${name}`}${
    ["networks", "network-acls"].includes(collection) ? "?project=default" : ""
  }`;
const settings = (config: Record<string, string>) =>
  Object.entries(config).map(([key, value]) => `${key}=${value}`);

/** Reserved CI host resources only. Runner containers, images, caches and the shared pool survive. */
export async function convergeCiHost(
  action: "converge" | "remove" | "purge",
  transport: SystemTransport = localSystemCommand,
  signal?: AbortSignal,
): Promise<
  | { outcome: "converged" | "retained" | "removed" | "occupied" }
  | typeof failure
> {
  signal?.throwIfAborted();
  if (!["converge", "remove", "purge"].includes(action)) {
    throw new Error("Unknown CI host action");
  }
  const deadline = performance.now() + 180000;
  const incus = async (args: string[], document?: unknown) => {
    signal?.throwIfAborted();
    const remaining = deadline - performance.now();
    if (remaining <= 0) throw new CiHostFailure();
    const result = await transport({
      command: "/usr/bin/sudo",
      args: [
        "-n",
        "/usr/bin/incus",
        ...(args[0] === "query" ? [] : ["--project", "default"]),
        ...args,
      ],
      ...(document === undefined ? {} : { stdin: JSON.stringify(document) }),
      timeoutMs: Math.min(30000, Math.ceil(remaining)),
    }, signal);
    if (
      !result.success || new TextEncoder().encode(result.stdout).length > 65536
    ) throw new CiHostFailure();
    return result.stdout;
  };
  const query = async (path: string): Promise<unknown> => {
    const stdout = await incus(["query", path]);
    try {
      return JSON.parse(stdout);
    } catch {
      throw new CiHostFailure();
    }
  };
  const list = async (path: string): Promise<string[]> => {
    const result = await query(path);
    if (
      !Array.isArray(result) ||
      result.some((value) => typeof value !== "string")
    ) throw new CiHostFailure();
    return result;
  };
  const exists = async (collection: string, name: string) =>
    (await list(defaultPath(collection))).some((path) =>
      path.split("?")[0] === `/1.0/${collection}/${name}`
    );
  const matches = async () => {
    for (
      const [collection, name] of [
        ["projects", project],
        ["networks", network],
        ["network-acls", acl],
        ["storage-pools", pool],
      ]
    ) {
      if (!await exists(collection, name)) return false;
    }
    const currentProject = object(
      await query(defaultPath("projects", project)),
    );
    const currentNetwork = object(
      await query(defaultPath("networks", network)),
    );
    const currentAcl = object(await query(defaultPath("network-acls", acl)));
    const currentProfile = object(
      await query(`/1.0/profiles/default?project=${project}`),
    );
    const rules = Array.isArray(currentAcl.egress)
      ? currentAcl.egress.map((rule) =>
        Object.fromEntries(
          Object.entries(object(rule)).filter(([, value]) => value !== ""),
        )
      )
      : [];
    const address = object(currentNetwork.config)["ipv4.address"];
    return includes(currentProject.config, projectConfig) &&
      currentNetwork.type === "bridge" && currentNetwork.managed === true &&
      typeof address === "string" && !["", "none", "auto"].includes(address) &&
      includes(currentNetwork.config, networkConfig) && equal(rules, egress) &&
      equal(currentAcl.ingress, []) &&
      equal(currentProfile.config, profile.config) &&
      equal(currentProfile.devices, profile.devices);
  };
  try {
    if (action !== "converge") {
      if (await exists("projects", project)) {
        if ((await list(`/1.0/instances?project=${project}`)).length > 0) {
          return { outcome: "occupied" };
        }
        if (action === "purge") await incus(["project", "delete", project]);
      }
      if (action === "remove") return { outcome: "retained" };
      if (await exists("networks", network)) {
        await incus(["network", "delete", network]);
      }
      if (await exists("network-acls", acl)) {
        await incus(["network", "acl", "delete", acl]);
      }
      return { outcome: "removed" };
    }
    if (!await exists("storage-pools", pool)) {
      await incus(["storage", "create", pool, "dir"]);
    }
    if (!await exists("projects", project)) {
      await incus([
        "project",
        "create",
        project,
        ...Object.entries(projectConfig)
          .filter(([key]) => key.startsWith("features.")).flatMap((
            [key, value],
          ) => ["-c", `${key}=${value}`]),
      ]);
    }
    if (
      object(
        object(await query(defaultPath("projects", project))).config,
      )["features.networks"] !== "false"
    ) throw new CiHostFailure();
    await incus(["project", "set", project, ...settings(projectConfig)]);
    if (!await exists("network-acls", acl)) {
      await incus(["network", "acl", "create", acl]);
    }
    await incus(["network", "acl", "edit", acl], {
      description: "Orbit CI runner boundary",
      egress,
      ingress: [],
      config: {},
    });
    if (!await exists("networks", network)) {
      await incus([
        "network",
        "create",
        network,
        "--type=bridge",
        "ipv4.address=auto",
        "ipv4.nat=true",
        "ipv6.address=none",
      ]);
    }
    const currentNetwork = object(
      await query(defaultPath("networks", network)),
    );
    if (currentNetwork.type !== "bridge" || currentNetwork.managed !== true) {
      throw new CiHostFailure();
    }
    await incus(["network", "set", network, ...settings(networkConfig)]);
    await incus(["profile", "edit", "default", "--project", project], profile);
    if (!await matches()) throw new CiHostFailure();
    return { outcome: "converged" };
  } catch (error) {
    if (error instanceof CiHostFailure) return failure;
    throw error;
  }
}
