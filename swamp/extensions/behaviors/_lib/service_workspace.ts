import { PackageSandboxes } from "./package_sandbox.ts";
import { dirname, relative, resolve } from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";
import { command, object } from "./maintenance/io.ts";
import { descriptor, lockDecode } from "../js-maintenance/_lib/workspace.ts";
import { type ToolPolicy } from "./maintenance/tool_policy.ts";
// @deno-types="npm:@types/semver@7.7.1"
import { validRange } from "npm:semver@7.7.2";

const manifests = new Set([
  "composer.json",
  "composer.lock",
  "package.json",
  "package-lock.json",
  "bun.lock",
  "pnpm-lock.yaml",
]);
const npmHosts = new Set(["registry.npmjs.org"]);
const composerHosts = new Set([
  "repo.packagist.org",
  "packagist.org",
  "api.github.com",
  "github.com",
  "codeload.github.com",
]);
function publicUrl(raw: string, hosts: Set<string>) {
  const u = new URL(raw);
  if (
    u.protocol !== "https:" || u.username || u.password || u.port ||
    !hosts.has(u.hostname)
  ) throw new Error("unsupported_dependency_endpoint");
}
function dependencyValue(raw: string, file: string, root: string) {
  if (/^catalog:(?:[a-zA-Z0-9_.-]+)?$/.test(raw)) return;
  if (raw.startsWith("npm:")) {
    const alias = /^npm:(?:@[a-z0-9_.-]+\/)?[a-z0-9_.-]+@(.+)$/.exec(raw);
    if (!alias || !registrySelector(alias[1])) {
      throw new Error("unsafe_npm_alias");
    }
    return;
  }
  if (/^(file:|link:|workspace:|\.\.?\/)/.test(raw)) {
    if (raw.startsWith("workspace:")) {
      if (/^workspace:(?:[~^*]|[~^]?\d[\d.*x~^| <>=+-]*)$/.test(raw)) return;
      return dependencyValue(`file:${raw.slice(10)}`, file, root);
    }
    const local = raw.replace(/^(file:|link:)/, "");
    // Reject alternate path/URL spellings before any tool can reinterpret them.
    if (
      !local || /[\\%?#:|]/.test(local) || local.startsWith("/") ||
      local.split("/").includes("~") ||
      [...local].some((c) => c.charCodeAt(0) < 32)
    ) {
      throw new Error("local_dependency_escape");
    }
    const target = fileURLToPath(
        new URL(`file:${local}`, `${pathToFileURL(dirname(file)).href}/`),
      ),
      rel = relative(root, target);
    if (rel === ".." || rel.startsWith("../") || rel.startsWith("/")) {
      throw new Error("local_dependency_escape");
    }
    // Local package directories contain manifests only. Never import a host file/tarball.
    if (!Deno.statSync(`${target}/package.json`).isFile) {
      throw new Error("local_package_manifest_missing");
    }
    return;
  }
  if (raw.startsWith("https://")) {
    // Public registry tarballs only; git packages may execute prepare while being resolved.
    publicUrl(raw, npmHosts);
    return;
  }
  if (registrySelector(raw)) return;
  throw new Error("unsupported_dependency_endpoint");
}
function registrySelector(raw: string) {
  // npm interprets a bare *.tgz / *.tar.gz as a host file, even without a slash.
  if (raw === "") return true; // npm treats an empty registry spec as *.
  return raw.trim() === raw && raw.split("||").every((alternative) => {
    const selector = alternative.trim();
    // Peer declarations can combine semver ranges and registry tags (e.g. insiders).
    return selector.length > 0 && (validRange(selector) !== null ||
      (/^[a-zA-Z_][a-zA-Z0-9._-]*$/.test(selector) &&
        !/\.(?:tgz|tar|tar\.gz)$/i.test(selector)));
  });
}
function inspect(
  value: unknown,
  visit: (s: string, key: string) => void,
  key = "",
) {
  if (typeof value === "string") visit(value, key);
  else if (value && typeof value === "object") {
    for (const [k, v] of Object.entries(value)) {
      inspect(v, visit, k);
    }
  }
}
export async function serviceWorkspace(
  clone: string,
  signal?: AbortSignal,
): Promise<{ root: string; policy: ToolPolicy; close(): Promise<void> }> {
  const root = await Deno.makeTempDir({ prefix: "dependency-tools-" });
  let toolHome: string | undefined;
  const sandboxes = new PackageSandboxes();
  const close = async () => {
    await sandboxes.close();
    await Deno.remove(root, { recursive: true });
    if (toolHome) await Deno.remove(toolHome, { recursive: true });
  };
  try {
    const listing = await command(
      clone,
      "git",
      ["ls-files", "--stage", "-z"],
      signal,
    );
    if (listing.code) throw new Error("tracked_manifests_unavailable");
    const entries = listing.stdout.split("\0").filter(Boolean).map((line) => {
      const tab = line.indexOf("\t");
      return { mode: line.slice(0, 6), file: line.slice(tab + 1) };
    });
    const files = entries.filter((e) =>
      manifests.has(e.file.split("/").at(-1)!)
    ).map((e) => {
      if (e.mode !== "100644" && e.mode !== "100755") {
        throw new Error("manifest_symlink_unsupported");
      }
      return e.file;
    });
    let size = 0;
    for (const f of files) {
      signal?.throwIfAborted();
      if (f.startsWith("/") || f.split("/").includes("..")) {
        throw new Error("unsafe_manifest_path");
      }
      const stat = await command(
        clone,
        "git",
        ["cat-file", "-s", `:${f}`],
        signal,
      );
      const bytes = Number(stat.stdout.trim());
      if (
        stat.code || !Number.isSafeInteger(bytes) || bytes < 0 ||
        bytes > 16 * 1024 * 1024 || (size += bytes) > 64 * 1024 * 1024
      ) throw new Error("unsafe_manifest_file");
      const blob = await command(clone, "git", ["show", `:${f}`], signal);
      if (blob.code) throw new Error("manifest_blob_unavailable");
      await Deno.mkdir(resolve(root, f, ".."), { recursive: true });
      await Deno.writeTextFile(`${root}/${f}`, blob.stdout);
    }
    for (const f of files) {
      const file = `${root}/${f}`;
      if (f.endsWith(".yaml")) continue; // pnpm is inventory-only and never passed to package tools.
      const raw = await Deno.readTextFile(file);
      if (f.endsWith("bun.lock")) {
        const lock = lockDecode(raw, "bun");
        for (const key of ["catalog", "catalogs"]) {
          inspect(lock[key], (s) => dependencyValue(s, file, root));
        }
        for (
          const entry of Object.values(object(lock.packages, "Bun packages"))
        ) {
          if (!Array.isArray(entry) || typeof entry[0] !== "string") {
            throw new Error("invalid_bun_resolution");
          }
          const [, resolution] = descriptor(entry[0]);
          dependencyValue(resolution, file, root);
          if (typeof entry[1] === "string" && entry[1]) {
            dependencyValue(entry[1], file, root);
          }
        }
        continue;
      }
      const data = object(JSON.parse(raw), f);
      if (f.endsWith("composer.json")) {
        const repos = data.repositories;
        if (repos !== undefined) {
          for (const repo of Object.values(objectOrArray(repos))) {
            if (repo === false) {
              continue;
            }
            const r = object(repo, "Composer repository");
            if (
              r.type !== "composer" || typeof r.url !== "string"
            ) throw new Error("unsupported_composer_repository");
            publicUrl(r.url, new Set(["repo.packagist.org", "packagist.org"]));
          }
        }
        const safe = Object.fromEntries(
          Object.entries(data).filter(([k]) =>
            [
              "name",
              "version",
              "require",
              "require-dev",
              "conflict",
              "replace",
              "provide",
              "minimum-stability",
              "prefer-stable",
            ].includes(k)
          ),
        );
        const config = data.config
          ? object(data.config, "Composer config")
          : null;
        if (config?.platform) safe.config = { platform: config.platform };
        inspect(safe, (s) => {
          if (/^(?:https?:|git|ssh:|file:|\/|\.\.?\/)/.test(s)) {
            throw new Error("unsupported_composer_constraint");
          }
        });
        // Approved repositories are validated separately above.
        safe.repositories = repos === undefined ? [] : Object.fromEntries(
          Object.entries(objectOrArray(repos)).map((
            [key, value],
          ) => [
            key,
            value === false ? false : {
              type: "composer",
              url: object(value, "Composer repository").url,
            },
          ]),
        );
        await Deno.writeTextFile(file, JSON.stringify(safe));
      } else if (f.endsWith("composer.lock")) {
        // Funding links describe a package; Composer never acquires code from them.
        for (const section of ["packages", "packages-dev"]) {
          if (data[section] === undefined) continue;
          if (!Array.isArray(data[section])) {
            throw new Error("invalid_composer_packages");
          }
          for (const packageRecord of data[section]) {
            delete object(packageRecord, "Composer package").funding;
          }
        }
        inspect(data, (s, k) => {
          if (k === "url") publicUrl(s, composerHosts);
        });
        await Deno.writeTextFile(file, JSON.stringify(data));
      } else if (f.endsWith("package.json")) {
        const safe = Object.fromEntries(
          Object.entries(data).filter(([k]) =>
            [
              "name",
              "version",
              "private",
              "packageManager",
              "workspaces",
              "dependencies",
              "devDependencies",
              "optionalDependencies",
              "peerDependencies",
              "peerDependenciesMeta",
              "engines",
              "os",
              "cpu",
              "overrides",
              "resolutions",
              "catalog",
              "catalogs",
            ].includes(k)
          ),
        );
        for (
          const k of [
            "dependencies",
            "devDependencies",
            "optionalDependencies",
            "peerDependencies",
            "resolutions",
            "catalog",
            "catalogs",
          ]
        ) inspect(safe[k], (s) => dependencyValue(s, file, root));
        inspect(safe.overrides, (s) => {
          if (/^\$(?:@[a-z0-9_.-]+\/)?[a-z0-9_.-]+$/.test(s)) {
            const name = s.slice(1);
            if (
              [
                "dependencies",
                "devDependencies",
                "optionalDependencies",
                "peerDependencies",
              ]
                .some((k) => Object.hasOwn(object(safe[k] ?? {}, k), name))
            ) return;
            throw new Error("unknown_override_reference");
          }
          dependencyValue(s, file, root);
        });
        const workspaces = safe.workspaces && !Array.isArray(safe.workspaces)
          ? object(safe.workspaces, "workspaces")
          : null;
        for (const k of ["catalog", "catalogs"]) {
          inspect(workspaces?.[k], (s) => dependencyValue(s, file, root));
        }
        inspect(workspaces ? workspaces.packages : safe.workspaces, (s) => {
          if (
            s.startsWith("/") || s.includes("\\") || s.split("/").includes("..")
          ) throw new Error("unsafe_workspace_path");
        });
        await Deno.writeTextFile(file, JSON.stringify(safe));
      } else {
        validateNpmLock(data, file, root);
      }
    }
    const home = await Deno.makeTempDir({ prefix: "dependency-tool-home-" });
    toolHome = home;
    const policy = {
      home,
      npmrc: `${home}/npmrc`,
      bunfig: `${home}/bunfig.toml`,
      sandboxes,
    };
    await Deno.writeTextFile(
      policy.npmrc,
      "registry=https://registry.npmjs.org/\ngit=git\nignore-scripts=true\n",
    );
    await Deno.writeTextFile(
      policy.bunfig,
      '[install]\nregistry = "https://registry.npmjs.org"\nignoreScripts = true\n',
    );
    // The shared resolver copies tracked inputs. Stage only the sanitized manifests/locks.
    await command(root, "git", ["init", "--quiet"], signal);
    await command(root, "git", ["add", "--", ...files], signal);
    return { root, policy, close };
  } catch (e) {
    await close();
    throw e;
  }
}
function objectOrArray(v: unknown): Record<string, unknown> | unknown[] {
  return Array.isArray(v) ? v : object(v, "Composer repositories");
}

function validateNpmLock(value: unknown, file: string, root: string) {
  const lock = object(value, "npm lock");
  for (
    const row of Object.values(object(lock.packages ?? {}, "npm packages"))
  ) {
    validateNpmEntry(object(row, "npm package"), file, root);
  }
  const legacy = (dependencies: unknown) => {
    for (
      const row of Object.values(object(dependencies ?? {}, "npm dependencies"))
    ) {
      const entry = object(row, "npm dependency");
      validateNpmEntry(entry, file, root);
      legacy(entry.dependencies);
    }
  };
  legacy(lock.dependencies);
}
function validateNpmEntry(
  row: Record<string, unknown>,
  file: string,
  root: string,
) {
  for (
    const key of [
      "dependencies",
      "devDependencies",
      "optionalDependencies",
      "peerDependencies",
      "requires",
    ]
  ) {
    if (row[key]) {
      for (
        const v of Object.values(
          object(row[key], "locked dependency constraints"),
        )
      ) if (typeof v === "string") dependencyValue(v, file, root);
    }
  }
  for (const key of ["resolved", "version"]) {
    const s = row[key];
    if (typeof s !== "string") continue;
    if (key === "resolved" && row.link === true) {
      dependencyValue(s.startsWith("file:") ? s : `file:${s}`, file, root);
    } else {
      dependencyValue(s, file, root);
    }
  }
}
