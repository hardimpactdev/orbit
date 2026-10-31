import { z } from "npm:zod@4.4.3";
import { parse as yaml } from "jsr:@std/yaml@1.0.12";
// @deno-types="npm:@types/semver@7.7.1"
import { satisfies } from "npm:semver@7.7.2";
import {
  invokeLocalVp,
  type VpActionName,
  type VpInvoke,
} from "./provisioning_vp_local.ts";
import {
  type Provisioning,
  ProvisioningRefusal,
} from "./provisioning_source.ts";

const ObjectValue = z.record(z.string(), z.unknown());
const exact = z.string().regex(/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/);
const Environment = z.object({
  store: z.string().startsWith("/"),
  vp_version: exact,
  node_version: exact,
  package_manager: z.enum(["npm", "pnpm"]),
  package_manager_version: exact,
  package_manager_hash: z.string().regex(/^sha(?:224|256|384|512)\.[a-f0-9]+$/i)
    .nullable(),
}).strict();
const field = (value: unknown, ...path: string[]): unknown =>
  path.reduce(
    (parent: unknown, key) =>
      parent !== null && typeof parent === "object" && !Array.isArray(parent)
        ? (parent as Record<string, unknown>)[key]
        : undefined,
    value,
  );
const record = (value: unknown): Record<string, unknown> =>
  ObjectValue.safeParse(value).data ?? {};
const lockedVersion = (value: unknown) =>
  exact.parse(
    z.string().parse(value).replace(/\(.*\)$/, "").replace(
      /^(?:(?:npm:)?vite-plus@|workspace:)/,
      "",
    ),
  );

/** Resolve an exact immutable runtime locally from the Project's existing manifests. */
export function provisioningVpRequirements(raw: unknown, store: string) {
  const configuration = ObjectValue.parse(raw),
    pkg = record(configuration.package);
  const dependencies = {
    ...record(pkg.dependencies),
    ...record(pkg.devDependencies),
  };
  const names = Object.keys(dependencies).filter((name) =>
    name === "vite-plus" ||
    (typeof dependencies[name] === "string" &&
      String(dependencies[name]).startsWith("npm:vite-plus@"))
  );
  if (names.length === 0) return null;
  const versions: string[] = [];
  let nodeEngine: unknown, lockedManager: unknown, family: "pnpm" | "npm";
  const pnpm = field(configuration, "locks", "pnpm-lock.yaml"),
    npm = field(configuration, "locks", "package-lock.json");
  if (typeof pnpm === "string") {
    family = "pnpm";
    const lock = yaml(pnpm, { schema: "json" });
    lockedManager = field(lock, "packageManager");
    for (const name of names) {
      for (const section of ["dependencies", "devDependencies"]) {
        const entry = field(lock, "importers", ".", section, name) ??
          field(lock, section, name);
        let value = typeof entry === "string" ? entry : field(entry, "version");
        if (typeof value === "string" && value.startsWith("link:")) {
          value = field(configuration, "workspaces", value);
        }
        if (typeof value === "string") {
          const version = lockedVersion(value);
          versions.push(version);
          nodeEngine = field(
            lock,
            "packages",
            `vite-plus@${version}`,
            "engines",
            "node",
          );
        }
      }
    }
  } else if (typeof npm === "string") {
    family = "npm";
    const lock = JSON.parse(npm);
    lockedManager = typeof field(lock, "npmVersion") === "string"
      ? `npm@${field(lock, "npmVersion")}`
      : field(lock, "packageManager");
    for (const name of names) {
      let entry = field(lock, "packages", `node_modules/${name}`) ??
        field(lock, "dependencies", name);
      if (field(entry, "link") === true) {
        entry = field(
          lock,
          "packages",
          z.string().parse(field(entry, "resolved")),
        );
      }
      const version = field(entry, "version");
      if (typeof version === "string") {
        versions.push(lockedVersion(version));
        nodeEngine = field(entry, "engines", "node");
      }
    }
  } else throw new Error("Missing immutable Vite+ lock");
  const selected = [...new Set(versions)];
  if (selected.length !== 1) throw new Error("Ambiguous Vite+ lock");
  const node = exact.parse(
    z.string().parse(
      configuration[".node-version"] ?? configuration[".nvmrc"] ??
        field(pkg, "engines", "node"),
    ).trim().replace(/^v/, ""),
  );
  const managerEngine = field(pkg, "engines", family);
  const selector = z.string().parse(
    pkg.packageManager ?? lockedManager ??
      (typeof managerEngine === "string" ? `${family}@${managerEngine}` : ""),
  );
  const manager =
    /^(npm|pnpm)@(.+?)(?:\+(sha(?:224|256|384|512)\.[a-f0-9]+))?$/i.exec(
      selector,
    );
  if (!manager || manager[1] !== family) {
    throw new Error("Package manager does not match lock");
  }
  for (const engine of [nodeEngine, field(pkg, "engines", "node")]) {
    if (
      engine !== undefined &&
      (typeof engine !== "string" || !satisfies(node, engine))
    ) throw new Error("Node version conflicts with engines");
  }
  if (
    managerEngine !== undefined &&
    (typeof managerEngine !== "string" || !satisfies(manager[2], managerEngine))
  ) throw new Error("Package manager conflicts with engines");
  return Environment.parse({
    store,
    vp_version: selected[0],
    node_version: node,
    package_manager: family,
    package_manager_version: manager[2],
    package_manager_hash: manager[3] ?? null,
  });
}

/** Manifests and npmrc never leave this native method; only the selected runtime is public. */
export async function prepareProvisioningVp(
  plan: Provisioning,
  invoke: VpInvoke = invokeLocalVp,
  signal?: AbortSignal,
) {
  const call = async (
    name: VpActionName,
    input: Record<string, unknown>,
  ) => {
    const value = await invoke({
      name,
      input,
    }, signal);
    if (value.error_code !== undefined) {
      throw new ProvisioningRefusal(z.string().parse(value.error_code), "vp");
    }
    return value;
  };
  const configuration = await call("tool.read-vp-project", {
    path: plan.application,
    user: plan.user,
  });
  let unresolved: ReturnType<typeof provisioningVpRequirements>;
  try {
    unresolved = provisioningVpRequirements(configuration, "/unresolved");
  } catch {
    throw new ProvisioningRefusal("deployment.vp_requirements_invalid", "vp");
  }
  if (unresolved === null) return null;
  const { binary } = z.object({ binary: z.string().regex(/^\/.+\/bin\/vp$/) })
    .parse(await call("tool.inspect-vp-scope", {}));
  const environment = {
    ...unresolved,
    store: binary.slice(0, -"/bin/vp".length),
  };
  const { version } = z.object({ version: z.string() }).parse(
    await call("tool.vp-version", {}),
  );
  if (version !== environment.vp_version) {
    throw new ProvisioningRefusal("deployment.vp_store_failed", "vp");
  }
  const { vp_version, ...input } = environment;
  const inspect = async () =>
    z.object({ installed: z.array(z.boolean()).length(1) }).parse(
      await call("tool.inspect-vp-store", { environments: [input] }),
    ).installed[0];
  if (!await inspect()) {
    // The Tools owner supplies installation as a same-root lower action.
    throw new ProvisioningRefusal("deployment.vp_store_failed", "vp");
  }
  return { ...input, vp_version };
}
