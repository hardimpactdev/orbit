import { localCommand, localFilesystem } from "../_lib/local-worker.ts";
import { z } from "npm:zod@4.4.3";

export type CacheArtifact = {
  manager: "composer" | "npm";
  package: string;
  version: string;
  url: string;
  integrity: string | null;
  key: string;
};
export type CacheFill = {
  artifacts: CacheArtifact[];
  max_bytes: number;
  github_token: string | null;
  deadline_ms: number;
};
type Run = (
  program: string,
  args: string[],
  signal?: AbortSignal,
) => Promise<string>;
const root = "/var/lib/orbit/package-cache";
const marker = "orbit-package-cache-v1\n";
const hex = (bytes: Uint8Array) =>
  [...bytes].map((b) => b.toString(16).padStart(2, "0")).join("");
async function textHash(value: string, algorithm: string) {
  return hex(
    new Uint8Array(
      await crypto.subtle.digest(algorithm, new TextEncoder().encode(value)),
    ),
  );
}
const github = (url: string) =>
  /^https:\/\/(api|codeload)\.github\.com\//.test(url);
function allowed(url: string, redirect = false) {
  return /^https:\/\/registry\.npmjs\.org\/(?:@[a-z0-9][a-z0-9_.-]*\/)?[a-z0-9][a-z0-9_.-]*\/-\/[a-z0-9][a-z0-9_.-]*-[0-9]+\.[0-9]+\.[0-9]+(?:[-+][a-zA-Z0-9_.-]+)?\.tgz$/
    .test(url) ||
    (redirect
      ? /^https:\/\/api\.github\.com\/repositories\/[1-9][0-9]*\/zipball\/[a-f0-9]{40}$/
      : /^https:\/\/api\.github\.com\/repos\/[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+\/zipball\/[a-f0-9]{40}$/)
      .test(url) ||
    /^https:\/\/codeload\.github\.com\/[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+\/(?:legacy\.zip|zip)\/[a-f0-9]{40}$/
      .test(url);
}
export async function cacheIntegrity(
  a: CacheArtifact,
): Promise<{ algorithm: string; wanted: string } | null> {
  if (!allowed(a.url)) throw new Error("Invalid public archive URL");
  if (a.manager === "composer") {
    if (
      !/^composer\/[a-z0-9][a-z0-9_.-]*\/[a-z0-9][a-z0-9_.-]*\/[a-f0-9]{40}\.zip$/
        .test(a.key) ||
      a.key !== `composer/${a.package}/${await textHash(a.url, "SHA-1")}.zip`
    ) throw new Error("Invalid Composer cache key");
    if (a.integrity === null) return null;
    if (!/^sha1-[a-f0-9]{40}$/.test(a.integrity)) {
      throw new Error("Invalid Composer integrity");
    }
    return { algorithm: "sha1", wanted: a.integrity.slice(5) };
  }
  if (
    !/^(?:@[a-z0-9][a-z0-9_.-]*\/)?[a-z0-9][a-z0-9_.-]*$/.test(a.package) ||
    !/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][a-zA-Z0-9_.-]+)?$/.test(a.version) ||
    a.url !==
      `https://registry.npmjs.org/${a.package}/-/${
        a.package.split("/").at(-1)
      }-${a.version}.tgz`
  ) throw new Error("Invalid npm archive identity");
  const match = /^(sha1|sha256|sha512)-([A-Za-z0-9+/]+={0,2})$/.exec(
    a.integrity ?? "",
  );
  if (!match) throw new Error("Invalid npm integrity");
  const bytes = Uint8Array.from(atob(match[2]), (c) => c.charCodeAt(0));
  if (
    bytes.length !== { sha1: 20, sha256: 32, sha512: 64 }[match[1]] ||
    btoa(String.fromCharCode(...bytes)) !== match[2] ||
    a.key !== `npm/${await textHash(`${a.url} ${a.integrity}`, "SHA-256")}.tgz`
  ) throw new Error("Invalid npm integrity");
  return { algorithm: match[1], wanted: hex(bytes) };
}
const command: Run = async (program, args, signal) => {
  const result = await localCommand(program, {
    args,
    stdin: "null",
    stderr: "null",
    stdout: "piped",
    signal,
  }).output();
  if (!result.success) throw new Error("Cache filesystem command failed");
  return new TextDecoder().decode(result.stdout);
};
export type CacheHostOptions = {
  root?: string;
  owner?: number;
  run?: Run;
  fetch?: typeof fetch;
  now?: () => number;
};

/** Local worker owns downloads and sudo file writes; tokens never enter argv or files. */
export async function fillCacheHost(
  input: CacheFill,
  signal?: AbortSignal,
  options: CacheHostOptions = {},
) {
  const base = options.root ?? root, owner = options.owner ?? 0;
  const invoke = options.run ?? command,
    fetcher = options.fetch ?? fetch,
    now = options.now ?? Date.now;
  const deadline = now() + input.deadline_ms;
  const active = () => {
    signal?.throwIfAborted();
    if (now() >= deadline) {
      throw new Error("Package cache fill deadline expired");
    }
  };
  const run: Run = async (program, args, external = signal) => {
    active();
    return await invoke(
      program,
      args,
      AbortSignal.any([
        ...(external ? [external] : []),
        AbortSignal.timeout(Math.max(1, deadline - now())),
      ]),
    );
  };
  const sudo = async (...args: string[]) => {
    active();
    return await run("sudo", ["-n", ...args], signal);
  };
  const safe = async (path: string, directory: boolean) => {
    const stat = await Deno.lstat(path);
    if (
      stat.isSymlink || (directory ? !stat.isDirectory : !stat.isFile) ||
      stat.uid !== owner || ((stat.mode ?? 0) & 0o022) !== 0
    ) throw new Error("Cache path has unsafe ownership or permissions");
    return stat;
  };
  const hash = async (file: string, algorithm: string) => {
    active();
    const value = await run(`${algorithm}sum`, ["--", file], signal);
    const digest = value.split(/\s/)[0];
    if (!/^[a-f0-9]+$/.test(digest)) {
      throw new Error("Cache archive read failed");
    }
    return digest;
  };
  const removeDownload = async (path: string) => {
    try {
      await localFilesystem().remove(path);
    } catch (error) {
      if (!(error instanceof Deno.errors.NotFound)) throw error;
    }
  };

  const scratch = await localFilesystem().makeTempDir({
    prefix: "orbit-cache-fill-",
  });
  const atomic = async (destination: string, content: string) => {
    const source = `${scratch}/${crypto.randomUUID()}`;
    const staging = `${destination}.tmp-${crypto.randomUUID()}`;

    await localFilesystem().writeTextFile(source, content, { mode: 0o600 });
    try {
      await sudo("install", "-m", "0644", "--", source, staging);
      await sudo("mv", "-fT", "--", staging, destination);
    } finally {
      await run("sudo", ["-n", "rm", "-f", "--", staging]);
    }
  };
  try {
    active();
    try {
      await safe(base, true);
    } catch (error) {
      if (!(error instanceof Deno.errors.NotFound)) throw error;
      await sudo("install", "-d", "-m", "0755", "--", base);
      await safe(base, true);
    }
    let owned = false;
    for await (const entry of Deno.readDir(base)) {
      if (entry.name === ".orbit-owned") owned = true;
    }
    if (!owned) {
      for await (const _entry of Deno.readDir(base)) {
        throw new Error("Refusing an unowned cache directory");
      }
      await atomic(`${base}/.orbit-owned`, marker);
    }
    await safe(`${base}/.orbit-owned`, false);
    if (await Deno.readTextFile(`${base}/.orbit-owned`) !== marker) {
      throw new Error("Cache ownership marker is invalid");
    }
    let inventory: Record<string, string> = {};
    try {
      await safe(`${base}/.inventory.json`, false);
      inventory = z.record(z.string(), z.string().regex(/^[a-f0-9]{64}$/))
        .parse(JSON.parse(await Deno.readTextFile(`${base}/.inventory.json`)));
    } catch (error) {
      if (!(error instanceof Deno.errors.NotFound)) throw error;
    }
    const walk = async (directory: string): Promise<number> => {
      let size = 0;
      for await (const entry of Deno.readDir(directory)) {
        const path = `${directory}/${entry.name}`,
          stat = await Deno.lstat(path);
        if (stat.isSymlink || stat.uid !== owner) {
          throw new Error("Unsafe cache entry");
        }
        size += stat.isDirectory ? await walk(path) : stat.size;
      }
      return size;
    };
    let used = await walk(base);
    const hashes: Record<string, string> = {},
      failures: Record<string, string> = {};
    for (const artifact of input.artifacts) {
      active();
      let temporary: string | undefined;
      try {
        const integrity = await cacheIntegrity(artifact),
          target = `${base}/${artifact.key}`;
        try {
          await safe(target, false);
          const digest = await hash(target, "sha256");
          if (inventory[artifact.key] === digest) {
            if (
              integrity &&
              await hash(target, integrity.algorithm) !== integrity.wanted
            ) throw new Error("Archive checksum mismatch");
            hashes[artifact.key] = `sha256-${digest}`;
            continue;
          }
          await sudo("install", "-d", "-m", "0755", "--", `${base}/quarantine`);
          await sudo(
            "mv",
            "--",
            target,
            `${base}/quarantine/${crypto.randomUUID()}`,
          );
        } catch (error) {
          if (!(error instanceof Deno.errors.NotFound)) throw error;
        }
        temporary = `${scratch}/${crypto.randomUUID()}`;
        let url = artifact.url, size = 0;
        for (let redirects = 0;; redirects++) {
          active();
          if (redirects === 10) throw new Error("Too many archive redirects");
          if (github(url) && !input.github_token) {
            throw new Error("GitHub installation token unavailable");
          }
          const response = await fetcher(url, {
            headers: {
              "User-Agent": "Orbit-package-cache",
              ...(github(url)
                ? { Authorization: `Bearer ${input.github_token}` }
                : {}),
            },
            redirect: "manual",
            signal: AbortSignal.any([
              ...(signal ? [signal] : []),
              AbortSignal.timeout(
                Math.max(1, Math.min(60000, deadline - now())),
              ),
            ]),
          });
          if (response.status >= 300 && response.status < 400) {
            const next = new URL(response.headers.get("location") ?? "", url)
              .toString();
            await response.body?.cancel();
            if (
              !allowed(next, true) || github(url) !== github(next) ||
              (github(url) && url.split("/").at(-1) !== next.split("/").at(-1))
            ) throw new Error("Rejected archive redirect");
            url = next;
            continue;
          }
          if (!response.ok) {
            await response.body?.cancel();
            throw new Error(`HTTP ${response.status}`);
          }
          const file = await localFilesystem().open(temporary, {
            write: true,
            createNew: true,
            mode: 0o600,
          });
          try {
            if (response.body) {
              for await (const bytes of response.body) {
                active();
                size += bytes.length;
                if (size > 512 * 1024 * 1024) {
                  throw new Error("Archive exceeds size limit");
                }
                if (used + size > input.max_bytes) {
                  throw new Error("Cache full");
                }
                let offset = 0;
                while (offset < bytes.length) {
                  offset += await file.write(bytes.subarray(offset));
                }
              }
            }
            await file.sync();
          } finally {
            file.close();
          }
          break;
        }
        if (
          integrity &&
          await hash(temporary, integrity.algorithm) !== integrity.wanted
        ) throw new Error("Archive checksum mismatch");
        const digest = await hash(temporary, "sha256");
        const parts = artifact.key.split("/");
        let directory = base;
        for (const component of parts.slice(0, -1)) {
          directory += `/${component}`;
          try {
            await safe(directory, true);
          } catch (error) {
            if (!(error instanceof Deno.errors.NotFound)) throw error;
            await sudo("install", "-d", "-m", "0755", "--", directory);
          }
        }
        const staging = `${target}.tmp-${crypto.randomUUID()}`;
        try {
          await sudo("install", "-m", "0444", "--", temporary, staging);
          await sudo("mv", "-fT", "--", staging, target);
        } finally {
          await run("sudo", ["-n", "rm", "-f", "--", staging]);
        }
        inventory[artifact.key] = digest;
        await atomic(`${base}/.inventory.json`, JSON.stringify(inventory));
        used += size;
        hashes[artifact.key] = `sha256-${digest}`;
      } catch (error) {
        active();
        const known =
          /^(Archive checksum mismatch|Cache full|Archive exceeds size limit|Invalid [A-Za-z ]+|GitHub installation token unavailable|Rejected archive redirect|Too many archive redirects|HTTP [0-9]{3})$/
            .test(error instanceof Error ? error.message : "");
        failures[artifact.key] = known
          ? (error as Error).message
          : "FillRefused";
      } finally {
        if (temporary) await removeDownload(temporary);
      }
    }
    return { hashes, failures };
  } finally {
    await localFilesystem().remove(scratch, { recursive: true });
  }
}
