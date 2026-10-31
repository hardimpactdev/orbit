import { z } from "npm:zod@4.4.3";

const User = z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/);
const Version = z.string().regex(/^[0-9]+\.[0-9]+$/);
const Path = z.string().startsWith("/").max(4096).refine((value) =>
  !/[\0\r\n]/.test(value) &&
  value.split("/").slice(1).every((part) =>
    part !== "" && part !== "." && part !== ".."
  )
);
const Relative = z.string().refine((value) =>
  value === "" ||
  value.split("/").every((part) =>
    /^[A-Za-z0-9_.-]+$/.test(part) && part !== "." && part !== ".."
  )
);
const ObjectInput = z.record(z.string(), z.unknown());
export type LocalReply = { success: boolean; code: number; stdout: string };
export type LocalOptions = {
  user?: string;
  input?: string;
  timeoutMs?: number;
};
export type LocalStat = {
  kind: "file" | "directory" | "link" | "socket" | "other";
  uid: number;
  gid: number;
  mode: number;
};
export interface RouteLocalHost {
  run(
    binary: string,
    args: string[],
    options?: LocalOptions,
  ): Promise<LocalReply>;
  stat(path: string): Promise<LocalStat | null>;
  read(path: string): Promise<string | null>;
  write(path: string, value: string, user: string, mode: number): Promise<void>;
  realPath(path: string): Promise<string | null>;
  download(url: string, sha256: string): Promise<string>;
}
const encoder = new TextEncoder();

/** Standard local programs only. Private stdin and stderr never become native output. */
export function localRouteHost(): RouteLocalHost {
  async function run(
    binary: string,
    args: string[],
    options: LocalOptions = {},
  ): Promise<LocalReply> {
    const child = new Deno.Command("/usr/bin/sudo", {
      args: [
        "-n",
        ...(options.user ? ["-u", User.parse(options.user)] : []),
        "--",
        binary,
        ...args,
      ],
      stdin: options.input === undefined ? "null" : "piped",
      stdout: "piped",
      stderr: "null",
      signal: AbortSignal.timeout(options.timeoutMs ?? 120000),
    }).spawn();
    try {
      if (options.input !== undefined) {
        const writer = child.stdin.getWriter();
        await writer.write(encoder.encode(options.input));
        await writer.close();
      }
      let size = 0;
      const chunks: Uint8Array[] = [];
      for await (const chunk of child.stdout) {
        size += chunk.length;
        if (size > 16 * 1024 * 1024) {
          throw new Error("route.local_output_exceeded");
        }
        chunks.push(chunk);
      }
      const status = await child.status;
      const bytes = new Uint8Array(size);
      let offset = 0;
      for (const chunk of chunks) {
        bytes.set(chunk, offset);
        offset += chunk.length;
      }
      return {
        success: status.success,
        code: status.code,
        stdout: new TextDecoder().decode(bytes),
      };
    } catch {
      try {
        child.kill("SIGTERM");
      } catch { /* The process already exited. */ }
      await child.status.catch(() => {});
      throw new Error("route.local_command_failed");
    }
  }
  async function required(
    binary: string,
    args: string[],
    options?: LocalOptions,
  ) {
    const result = await run(binary, args, options);
    if (!result.success) throw new Error("route.local_command_failed");
    return result;
  }
  const host: RouteLocalHost = {
    run,
    async stat(path) {
      const result = await run("/usr/bin/stat", [
        "--printf=%F:%u:%g:%a",
        "--",
        Path.parse(path),
      ]);
      if (!result.success) {
        const missing = await run("/usr/bin/test", ["!", "-e", path]);
        const link = await run("/usr/bin/test", ["-L", path]);
        if (missing.success && !link.success) return null;
        throw new Error("route.local_path_invalid");
      }
      const [kind, uid, gid, mode] = result.stdout.split(":");
      return {
        kind: kind === "regular file"
          ? "file"
          : kind === "directory"
          ? "directory"
          : kind === "symbolic link"
          ? "link"
          : kind === "socket"
          ? "socket"
          : "other",
        uid: Number(uid),
        gid: Number(gid),
        mode: parseInt(mode, 8),
      };
    },
    async read(path) {
      const stat = await host.stat(path);
      if (!stat) return null;
      if (stat.kind !== "file") throw new Error("route.local_path_invalid");
      return (await required("/usr/bin/cat", ["--", path])).stdout;
    },
    async write(path, value, user, mode) {
      Path.parse(path);
      if (user !== "root") User.parse(user);
      const temporary = await Deno.makeTempFile({
        prefix: "orbit-route-private-",
      });
      const candidate = path + ".orbit-" + crypto.randomUUID();
      try {
        await Deno.chmod(temporary, 0o600);
        await Deno.writeTextFile(temporary, value);
        await required("/usr/bin/install", [
          "-m",
          mode.toString(8),
          "-o",
          user,
          "-g",
          user,
          "-T",
          "--",
          temporary,
          candidate,
        ]);
        await required("/usr/bin/mv", ["-fT", "--", candidate, path]);
      } finally {
        await Deno.remove(temporary).catch(() => {});
        await run("/usr/bin/rm", ["-f", "--", candidate]);
      }
    },
    async realPath(path) {
      const result = await run("/usr/bin/readlink", [
        "-e",
        "--",
        Path.parse(path),
      ]);
      return result.success ? result.stdout.trim() : null;
    },
    async download(url, sha256) {
      const parsed = z.string().url().parse(url);
      if (new URL(parsed).protocol !== "https:") {
        throw new Error("php.build_input_invalid");
      }
      z.string().regex(/^[a-f0-9]{64}$/).parse(sha256);
      const response = await fetch(parsed, {
        redirect: "error",
        signal: AbortSignal.timeout(120000),
      });
      if (!response.ok || !response.body) {
        throw new Error("php.build_download_failed");
      }
      const file = await Deno.makeTempFile({
        prefix: "orbit-route-php-",
        suffix: ".deb",
      });
      try {
        const output = await Deno.open(file, { write: true });
        let count = 0;
        try {
          for await (const chunk of response.body) {
            count += chunk.length;
            if (count > 268435456) throw new Error("php.build_download_failed");
            await output.write(chunk);
          }
        } finally {
          output.close();
        }
        const digest = Array.from(
          new Uint8Array(
            await crypto.subtle.digest("SHA-256", await Deno.readFile(file)),
          ),
          (b) => b.toString(16).padStart(2, "0"),
        ).join("");
        if (digest !== sha256) throw new Error("php.build_download_failed");
        return file;
      } catch (error) {
        await Deno.remove(file).catch(() => {});
        throw error;
      }
    },
  };
  return host;
}

async function required(
  host: RouteLocalHost,
  binary: string,
  args: string[],
  options?: LocalOptions,
) {
  const result = await host.run(binary, args, options);
  if (!result.success) throw new Error("route.local_command_failed");
  return result.stdout;
}
async function account(host: RouteLocalHost, user: string) {
  User.parse(user);
  const row = (await required(host, "/usr/bin/getent", ["passwd", user])).trim()
    .split(":");
  if (
    row.length !== 7 || row[0] !== user || !/^\d+$/.test(row[2]) ||
    Number(row[2]) === 0
  ) throw new Error("route.local_owner_invalid");
  return { uid: Number(row[2]), gid: Number(row[3]), home: row[5] };
}
async function directory(host: RouteLocalHost, path: string, uid?: number) {
  Path.parse(path);
  const stat = await host.stat(path);
  if (
    !stat || stat.kind !== "directory" ||
    (uid !== undefined && stat.uid !== uid) ||
    await host.realPath(path) !== path
  ) throw new Error("route.local_path_invalid");
  return stat;
}
async function regular(host: RouteLocalHost, path: string, uid?: number) {
  const stat = await host.stat(path);
  if (
    stat && (stat.kind !== "file" || (uid !== undefined && stat.uid !== uid))
  ) throw new Error("route.local_path_invalid");
  return stat;
}
function parent(path: string) {
  return path.slice(0, path.lastIndexOf("/")) || "/";
}
async function mkdir(
  host: RouteLocalHost,
  path: string,
  user: string,
  mode: number,
) {
  await required(host, "/usr/bin/install", [
    "-d",
    "-m",
    mode.toString(8),
    "-o",
    user,
    "-g",
    user,
    "--",
    path,
  ]);
}
function lines(text: string, key: string) {
  return [...text.matchAll(new RegExp("^" + key + "=[^\\r\\n]*", "gm"))];
}
function plain(text: string, key: string) {
  const line = lines(text, key)[0]?.[0];
  const value = line?.slice(key.length + 1).trim() ?? "";
  return /^(['"]).*\1$/.test(value) ? value.slice(1, -1) : value;
}
function put(text: string, key: string, line: string) {
  const found = lines(text, key)[0];
  return found
    ? text.slice(0, found.index) + line +
      text.slice(found.index! + found[0].length)
    : text + (text && !text.endsWith("\n") ? "\n" : "") + line + "\n";
}

/** Parse Laravel's literal cache without evaluating application PHP. */
export function replaceCachedUrl(source: string, url: string): string {
  const tokens = [
    ...source.matchAll(
      /'(?:\\.|[^'\\])*'|"(?:\\.|[^"\\])*"|\/\*[^]*?\*\/|\/\/[^\n]*|\#[^\n]*|=>|[()[\],]|[A-Za-z_][A-Za-z0-9_]*|\S/g,
    ),
  ].filter((match) => !/^\/\*|^\/\/|^#/.test(match[0]));
  const stack: string[][] = [];
  let pending: string | undefined;
  const found: RegExpMatchArray[] = [];
  for (let i = 0; i < tokens.length; i++) {
    const token = tokens[i][0];
    if (token === "(" || token === "[") {
      stack.push([...(stack.at(-1) ?? []), ...(pending ? [pending] : [])]);
      pending = undefined;
    } else if (token === ")" || token === "]") {
      if (!stack.length) throw new Error("instance.laravel_cache_invalid");
      stack.pop();
      pending = undefined;
    } else if (token === ",") pending = undefined;
    else if (/^['"]/.test(token) && tokens[i + 1]?.[0] === "=>") {
      const key = token.slice(1, -1);
      if (
        stack.length === 2 && stack.at(-1)?.length === 1 &&
        stack.at(-1)?.[0] === "app" && key === "url"
      ) {
        const next = tokens[i + 2];
        if (!next || !/^['"]/.test(next[0])) {
          throw new Error("instance.laravel_cache_invalid");
        }
        found.push(next);
      }
      pending = key;
    }
  }
  if (stack.length || found.length !== 1) {
    throw new Error("instance.laravel_cache_invalid");
  }
  const match = found[0];
  return source.slice(0, match.index) + "'" +
    url.replaceAll("\\", "\\\\").replaceAll("'", "\\'") + "'" +
    source.slice(match.index! + match[0].length);
}
const UrlInput = z.object({
  url: z.string().url().regex(/^https:\/\//),
  app_key: z.string().startsWith("APP_KEY="),
  app_name: z.string().startsWith("APP_NAME="),
  app_name_stored: z.boolean(),
});
function updatedUrl(
  original: string,
  input: z.infer<typeof UrlInput>,
  created: boolean,
  keyStored = false,
) {
  if (lines(original, "APP_URL").length > 1) {
    throw new Error("instance.laravel_url_invalid");
  }
  let updated = put(original, "APP_URL", "APP_URL=" + input.url);
  const keys = lines(updated, "APP_KEY");
  if (
    keys.length < 2 &&
    ((created && (!plain(updated, "APP_KEY") || keyStored)) ||
      (keys.length === 1 && !plain(updated, "APP_KEY")))
  ) updated = put(updated, "APP_KEY", input.app_key);
  const names = lines(updated, "APP_NAME");
  if (
    created && names.length < 2 &&
    (input.app_name_stored || !plain(updated, "APP_NAME") ||
      plain(updated, "APP_NAME") === "Laravel")
  ) updated = put(updated, "APP_NAME", input.app_name);
  return updated;
}

export function routeWorkloadLocal(host: RouteLocalHost = localRouteHost()) {
  async function writeEnvironment(raw: unknown) {
    const input = z.object({
      mode: z.enum(["write", "merge"]),
      base: Path,
      user: User,
      maximum: z.number().int().positive().max(4194304),
      require_home: z.boolean(),
      file: z.enum([".env", ".env.testing"]),
      managed_keys: z.array(z.string().regex(/^[A-Za-z_][A-Za-z0-9_]*$/)),
      content: z.string(),
    }).parse(raw);
    const owner = await account(host, input.user);
    await directory(host, input.base, owner.uid);
    if (input.require_home && owner.home !== input.base) {
      throw new Error("instance.environment_input_invalid");
    }
    if (encoder.encode(input.content).length > input.maximum) {
      throw new Error("instance.environment_input_invalid");
    }
    const path = input.base + "/" + input.file;
    await regular(host, path, owner.uid);
    if (!input.require_home) {
      const repo = await host.run("/usr/bin/git", [
        "-c",
        "core.hooksPath=/dev/null",
        "-c",
        "core.fsmonitor=false",
        "-C",
        input.base,
        "rev-parse",
        "--is-inside-work-tree",
      ], { user: input.user });
      if (repo.success) {
        const tracked = await host.run("/usr/bin/git", [
          "-C",
          input.base,
          "ls-files",
          "--error-unmatch",
          "--",
          input.file,
        ], { user: input.user });
        if (tracked.success || tracked.code !== 1) {
          throw new Error("instance.environment_tracked");
        }
      }
    }
    const previous = await host.read(path) ?? "";
    let content = input.content;
    if (input.mode === "merge") {
      const managed = new Set(input.managed_keys);
      const kept = previous.split(/\r?\n/).filter((line) => {
        const match = line.match(
          /^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=/,
        );
        return !match || !managed.has(match[1]);
      }).join("\n");
      content = kept.replace(/\n*$/, "") + (kept ? "\n" : "") + input.content;
    }
    if (encoder.encode(content).length > input.maximum) {
      throw new Error("instance.environment_input_invalid");
    }
    if (content === previous) return { ready: true };
    await host.write(path, content, input.user, 0o600);
    return { ready: true };
  }
  async function writeDevelopmentUrl(raw: unknown) {
    const input = UrlInput.extend({
      directory: Path,
      owner: User,
      app_key_stored: z.boolean(),
      require_artisan: z.boolean(),
    }).parse(raw);
    const owner = await account(host, input.owner);
    await directory(host, input.directory, owner.uid);
    const artisan = await host.stat(input.directory + "/artisan");
    if (input.require_artisan && artisan?.kind !== "file") {
      return { ready: true };
    }
    const environment = input.directory + "/.env";
    const stat = await regular(host, environment, owner.uid);
    const template = input.directory + "/.env.example";
    await regular(host, template, owner.uid);
    const original = await host.read(environment) ??
      await host.read(template) ?? "";
    const updated = updatedUrl(original, input, !stat, input.app_key_stored);
    const cache = input.directory + "/bootstrap/cache/config.php";
    for (const suffix of ["bootstrap", "bootstrap/cache"]) {
      const path = input.directory + "/" + suffix;
      if (await host.stat(path)) await directory(host, path, owner.uid);
    }
    const cached = await regular(host, cache, owner.uid);
    const oldCache = cached ? await host.read(cache) : null;
    const newCache = oldCache === null
      ? null
      : replaceCachedUrl(oldCache, input.url);
    if (updated !== original || !stat) {
      await host.write(environment, updated, input.owner, stat?.mode ?? 0o600);
    }
    if (newCache !== null && newCache !== oldCache) {
      await host.write(cache, newCache, input.owner, cached!.mode);
    }
    return { ready: true };
  }
  async function selectedRelease(user: string, home: string) {
    const owner = await account(host, user);
    if (owner.home !== home) {
      throw new Error("instance.production_home_invalid");
    }
    await directory(host, home, owner.uid);
    const link = await host.stat(home + "/current");
    if (!link) return null;
    if (link.kind !== "link") {
      throw new Error("instance.production_home_invalid");
    }
    const release = await host.realPath(home + "/current");
    if (!release || parent(release) !== home + "/releases") {
      throw new Error("instance.production_home_invalid");
    }
    await directory(host, release, owner.uid);
    return { owner, release };
  }
  async function writeDirectoryUrl(raw: unknown) {
    const input = UrlInput.extend({
      user: User,
      home: Path,
      directory: Relative,
    }).parse(raw);
    const selected = await selectedRelease(input.user, input.home);
    if (!selected) return { ready: true };
    const { owner, release } = selected;
    const application = release +
      (input.directory ? "/" + input.directory : "");
    await directory(host, application, owner.uid);
    if ((await host.stat(application + "/artisan"))?.kind !== "file") {
      return { ready: true };
    }
    let stable = input.home + "/env";
    for (const segment of ["", ...input.directory.split("/").filter(Boolean)]) {
      if (segment) stable += "/" + segment;
      if (!await host.stat(stable)) {
        await mkdir(host, stable, input.user, 0o700);
      }
      await directory(host, stable, owner.uid);
    }
    const environment = stable + "/.env";
    const stat = await regular(host, environment, owner.uid);
    const template = application + "/.env.example";
    await regular(host, template, owner.uid);
    const original = await host.read(environment) ??
      await host.read(template) ?? "";
    const updated = updatedUrl(original, input, !stat);
    if (updated !== original || !stat) {
      await host.write(
        environment,
        updated,
        input.user,
        stat ? (stat.mode & 0o770) : 0o600,
      );
    }
    return { ready: true };
  }
  async function grantSourceAccess(raw: unknown) {
    const input = z.object({
      user: User,
      sites: z.array(
        z.object({
          checkout: Path,
          relative_root: Relative,
          application: Path,
        }),
      ),
    }).parse(raw);
    const owner = await account(host, input.user);
    const saved: { path: string; acl: string }[] = [];
    const rootSaved: { path: string; acl: string }[] = [];
    try {
      for (const site of input.sites) {
        await directory(host, site.checkout, owner.uid);
        const root = site.checkout +
          (site.relative_root ? "/" + site.relative_root : "");
        await directory(host, root, owner.uid);
        await directory(host, site.application, owner.uid);
        saved.push({
          path: site.checkout,
          acl: await required(host, "/usr/bin/getfacl", [
            "-Rp",
            "-P",
            "--",
            site.checkout,
          ], { user: input.user }),
        });
        await required(host, "/usr/bin/find", [
          "-P",
          site.checkout,
          "-type",
          "d",
          "-exec",
          "/usr/bin/setfacl",
          "-m",
          "d:u:caddy:---",
          "--",
          "{}",
          "+",
        ], { user: input.user });
        await required(host, "/usr/bin/setfacl", [
          "-P",
          "-R",
          "-n",
          "-m",
          "u:caddy:---",
          "--",
          site.checkout,
        ], { user: input.user });
        const links = (await required(host, "/usr/bin/find", [
          "-P",
          root,
          "-type",
          "l",
          "-print",
        ], { user: input.user })).trim().split("\n").filter(Boolean);
        const storage = await host.realPath(
          site.application + "/storage/app/public",
        );
        for (const link of links) {
          if (
            link !== root + "/storage" || !storage ||
            await host.realPath(link) !== storage
          ) throw new Error("instance.caddy_access_invalid");
        }
        for (
          let ancestor = parent(root);
          ancestor !== "/";
          ancestor = parent(ancestor)
        ) {
          const stat = await host.stat(ancestor);
          if (!stat || stat.kind !== "directory") {
            throw new Error("instance.caddy_access_invalid");
          }
          const asUser = stat.uid === owner.uid
            ? input.user
            : stat.uid === 0
            ? undefined
            : null;
          if (asUser === null) throw new Error("instance.caddy_access_invalid");
          if (
            stat.uid === 0 && !rootSaved.some((row) => row.path === ancestor)
          ) {
            rootSaved.push({
              path: ancestor,
              acl: await required(host, "/usr/bin/getfacl", [
                "-p",
                "--",
                ancestor,
              ]),
            });
          }
          await required(host, "/usr/bin/setfacl", [
            "-n",
            "-m",
            "u:caddy:x",
            "--",
            ancestor,
          ], { user: asUser });
        }
        for (const served of [root, ...(storage ? [storage] : [])]) {
          await required(host, "/usr/bin/find", [
            "-P",
            served,
            "-type",
            "d",
            "-exec",
            "/usr/bin/setfacl",
            "-m",
            "d:u:caddy:r-x",
            "--",
            "{}",
            "+",
          ], { user: input.user });
          await required(host, "/usr/bin/setfacl", [
            "-P",
            "-R",
            "-n",
            "-m",
            "u:caddy:r-X",
            "--",
            served,
          ], { user: input.user });
        }
        if (await host.stat(site.checkout + "/.git")) {
          await required(host, "/usr/bin/setfacl", [
            "-P",
            "-R",
            "-n",
            "-m",
            "u:caddy:---",
            "--",
            site.checkout + "/.git",
          ], { user: input.user });
        }
      }
    } catch (error) {
      for (const row of saved.reverse()) {
        await required(host, "/usr/bin/setfacl", ["--restore=-"], {
          user: input.user,
          input: row.acl,
        });
      }
      for (const row of rootSaved.reverse()) {
        await required(host, "/usr/bin/setfacl", ["--restore=-"], {
          input: row.acl,
        });
      }
      throw error;
    }
    return { ready: true };
  }
  async function prepareWebRoots(raw: unknown) {
    const input = z.object({
      user: User,
      home: Path,
      served: z.array(z.object({ web_root: Relative, directory: Relative })),
    }).parse(raw);
    const selected = await selectedRelease(input.user, input.home);
    if (!selected) return { ready: true };
    for (const served of input.served) {
      const application = selected.release +
        (served.directory ? "/" + served.directory : "");
      await directory(host, application, selected.owner.uid);
      const root = selected.release + "/" + served.web_root;
      await directory(host, root, selected.owner.uid);
      const target = "../".repeat(
        served.directory ? 3 + served.directory.split("/").length - 1 : 2,
      ) + "env/" + (served.directory ? served.directory + "/" : "") + ".env";
      const environment = application + "/.env";
      const stat = await host.stat(environment);
      if (stat) {
        if (
          stat.kind !== "link" ||
          (await required(host, "/usr/bin/readlink", ["--", environment]))
              .trim() !== target
        ) throw new Error("instance.production_home_invalid");
      } else {await required(host, "/usr/bin/ln", [
          "-s",
          "--",
          target,
          environment,
        ], { user: input.user });}
      await grantSourceAccess({
        user: input.user,
        sites: [{
          checkout: selected.release,
          relative_root: served.web_root,
          application,
        }],
      });
    }
    return { ready: true };
  }
  async function installPackages(raw: unknown) {
    const Pin = z.object({
      name: z.string().regex(/^[a-z0-9][a-z0-9+.-]+$/),
      version: z.string().regex(/^[A-Za-z0-9.+:~_-]+$/),
      architecture: z.literal("amd64"),
      url: z.string().url(),
      sha256: z.string().regex(/^[a-f0-9]{64}$/),
    });
    const input = z.object({
      versions: z.record(
        Version,
        z.object({
          packages: z.array(Pin).min(1),
          extensions: z.array(z.string()),
        }),
      ),
      mirror: z.string().default(""),
      extras: z.literal("keep"),
      fpm: z.enum(["none", "shared"]),
      pcov: z.boolean(),
      opentelemetry: z.boolean(),
      release: z.object({ id: z.string(), codenames: z.array(z.string()) })
        .nullable(),
    }).parse(raw);
    const release = await host.read("/etc/os-release");
    if (input.release) {
      const fields = Object.fromEntries(
        (release ?? "").split("\n").filter((line) => /^[A-Z_]+=/.test(line))
          .map((line) => {
            const index = line.indexOf("=");
            return [
              line.slice(0, index),
              line.slice(index + 1).replace(/^"|"$/g, ""),
            ];
          }),
      );
      if (
        fields.ID !== input.release.id ||
        !input.release.codenames.includes(fields.VERSION_CODENAME)
      ) throw new Error("php.build_platform_unsupported");
    }
    if (
      (await required(host, "/usr/bin/dpkg", ["--print-architecture"]))
        .trim() !== "amd64"
    ) throw new Error("php.build_platform_unsupported");
    const pins = new Map<string, z.infer<typeof Pin>>();
    for (const build of Object.values(input.versions)) {
      for (const pin of build.packages) {
        const prior = pins.get(pin.name);
        if (prior && JSON.stringify(prior) !== JSON.stringify(pin)) {
          throw new Error("php.build_input_invalid");
        }
        pins.set(pin.name, pin);
      }
    }
    const installed = await required(host, "/usr/bin/dpkg-query", [
      "-W",
      "-f=${Package}\t${Status}\t${Version}\n",
    ]);
    const versions = new Map(
      installed.trim().split("\n").map((line) => {
        const [name, status, version] = line.split("\t");
        return [name, status === "install ok installed" ? version : ""];
      }),
    );
    const pending = [...pins.values()].filter((pin) =>
      versions.get(pin.name) !== pin.version
    );
    if (!pending.length) return { ready: true };
    const files: string[] = [];
    try {
      for (const pin of pending) {
        const url = input.mirror
          ? input.mirror.replace(/\/$/, "") + "/" + pin.name + "_" +
            pin.sha256 + ".deb"
          : pin.url;
        const file = await host.download(url, pin.sha256);
        files.push(file);
        const fields = await required(host, "/usr/bin/dpkg-deb", [
          "--field",
          file,
          "Package",
          "Version",
          "Architecture",
        ]);
        const values = Object.fromEntries(
          fields.trim().split("\n").map((line) => line.split(": ")),
        );
        if (
          values.Package !== pin.name || values.Version !== pin.version ||
          values.Architecture !== pin.architecture
        ) throw new Error("php.build_download_failed");
      }
      await required(host, "/usr/bin/dpkg", [
        "--force-confold",
        "--install",
        "--",
        ...files,
      ], { timeoutMs: 600000 });
      for (const pin of pending) {
        const actual = (await required(host, "/usr/bin/dpkg-query", [
          "-W",
          "-f=${Version}",
          pin.name,
        ])).trim();
        if (actual !== pin.version) throw new Error("php.build_install_failed");
      }
      for (const version of Object.keys(input.versions)) {
        await required(host, "/usr/bin/php" + version, ["-v"]);
        if (input.fpm === "shared") {
          await required(host, "/usr/sbin/php-fpm" + version, ["-t"]);
        }
      }
    } finally {
      for (const file of files) {
        await required(host, "/usr/bin/rm", ["-f", "--", file]);
      }
    }
    return { ready: true };
  }
  async function configureRuntime(raw: unknown) {
    const input = z.object({
      version: Version,
      runtime_ini: z.string().max(8192),
      release: z.object({ id: z.string(), codenames: z.array(z.string()) }),
    }).parse(raw);
    const settings = new Map<string, string>();
    for (
      const line of input.runtime_ini.split("\n").map((line) => line.trim())
        .filter((line) => line && !line.startsWith(";"))
    ) {
      const match = line.match(/^([A-Za-z_.]+)\s*=\s*(\S+)$/);
      if (
        !match || settings.has(match[1]) ||
        ![
          "post_max_size",
          "upload_max_filesize",
          "opcache.enable",
          "opcache.memory_consumption",
          "opcache.interned_strings_buffer",
          "opcache.max_accelerated_files",
          "opcache.jit",
          "opcache.jit_buffer_size",
        ].includes(match[1])
      ) throw new Error("php.runtime_input_invalid");
      settings.set(match[1], match[2]);
    }
    if (settings.size !== 8) throw new Error("php.runtime_input_invalid");
    const binary = "/usr/sbin/php-fpm" + input.version;
    if (!await host.stat(binary)) return { ready: true };
    const base = "/etc/php/" + input.version;
    const available = base + "/mods-available/orbit-runtime.ini",
      link = base + "/fpm/conf.d/99-orbit-runtime.ini",
      configuration = base + "/fpm/php-fpm.conf";
    const previous = await host.read(available);
    const shared = await host.read(configuration);
    if (shared === null) throw new Error("php.runtime_configuration_failed");
    const linkState = await host.stat(link);
    if (linkState && linkState.kind !== "link") {
      throw new Error("php.runtime_configuration_failed");
    }
    const changed = previous !== input.runtime_ini ||
      await host.realPath(link) !== available;
    await host.write(available, input.runtime_ini, "root", 0o644);
    if (await host.realPath(link) !== available) {
      if (linkState) await required(host, "/usr/bin/rm", ["--", link]);
      await required(host, "/usr/bin/ln", [
        "-s",
        "--",
        "../../mods-available/orbit-runtime.ini",
        link,
      ]);
    }
    let global = true;
    let emitted = false;
    const configLines: string[] = [];
    for (const line of shared.split("\n")) {
      if (/^\s*\[/.test(line)) global = line.trim() === "[global]";
      if (line.trim() === "[global]") {
        configLines.push(line, "process_control_timeout = 30s");
        emitted = true;
      } else if (!(global && /^\s*process_control_timeout\s*=/.test(line))) {
        configLines.push(line);
      }
    }
    if (!emitted) {
      configLines.unshift("[global]", "process_control_timeout = 30s");
    }
    try {
      await host.write(configuration, configLines.join("\n"), "root", 0o644);
      await required(host, binary, ["-t"]);
      const info = await required(host, binary, ["-i"]);
      if (
        !info.includes(link) ||
        [...settings].some(([key, value]) =>
          !info.split("\n").some((line) =>
            line.trim() === `${key} => ${value} => ${value}`
          )
        )
      ) throw new Error("php.runtime_configuration_failed");
      if (
        changed &&
        (await host.run("/usr/bin/systemctl", [
          "is-active",
          "--quiet",
          "php" + input.version + "-fpm.service",
        ])).success
      ) {
        await required(host, "/usr/bin/systemctl", [
          "reload",
          "php" + input.version + "-fpm.service",
        ]);
      }
    } catch (error) {
      await host.write(configuration, shared, "root", 0o644);
      if (previous !== null) {
        await host.write(available, previous, "root", 0o644);
      } else await required(host, "/usr/bin/rm", ["-f", "--", available]);
      throw error;
    }
    return { ready: true };
  }
  async function convergeProductionRuntime(raw: unknown) {
    const input = z.object({
      mode: z.literal("converge"),
      user: User,
      home: Path,
      version: Version,
      marker: z.string().min(1),
      application: Path,
      initial_application: Path,
      configuration: z.object({
        main: z.string(),
        pool: z.string(),
        local_defaults: z.string(),
        master_ini: z.string(),
        unit: z.string(),
        initial_pool: z.string(),
      }),
    }).parse(raw);
    const owner = await account(host, input.user);
    if (input.home !== owner.home || input.home !== "/home/" + input.user) {
      throw new Error("php.runtime_invalid");
    }
    await directory(host, input.home, owner.uid);
    const root = "/etc/orbit/php-fpm/" + input.user,
      generated = root + "/generated",
      local = root + "/local.conf",
      marker = root + "/orbit.identity",
      unit = "/etc/systemd/system/orbit-" + input.user + "-php" +
        input.version + "-fpm.service",
      service = "orbit-" + input.user + "-php" + input.version + "-fpm.service";
    const existing = await host.stat(root);
    if (existing && (existing.kind !== "directory" || existing.uid !== 0)) {
      throw new Error("php.runtime_invalid");
    }
    const identity = await host.read(marker);
    if (identity !== null && identity !== input.marker) {
      throw new Error("php.runtime_invalid");
    }
    if (
      identity === null &&
      (await host.stat(generated) || await host.stat(local) ||
        await host.stat(unit))
    ) throw new Error("php.runtime_invalid");
    await mkdir(host, root, "root", 0o755);
    if (identity === null) {
      await host.write(marker, input.marker, "root", 0o644);
    }
    await regular(host, marker, 0);
    await regular(host, local, 0);
    if (!await host.stat(local)) {
      await host.write(
        local,
        input.configuration.local_defaults,
        "root",
        0o644,
      );
    }
    const localText = await host.read(local) ?? "";
    for (
      const line of localText.split("\n").map((line) => line.trim()).filter((
        line,
      ) => line && !/^[;#]/.test(line))
    ) {
      if (line.startsWith("[")) {
        if (line !== `[orbit-${input.user}]`) {
          throw new Error("php.runtime_invalid");
        }
      } else if (
        /^(?:include|pid|user|group|listen(?:\.[a-z]+)?|chdir|chroot|env\[(?:home|user)\])\s*=/i
          .test(line)
      ) throw new Error("php.runtime_invalid");
    }
    const selected = await selectedRelease(input.user, input.home);
    const application = selected
      ? input.application
      : input.initial_application;
    const resolved = await host.realPath(application);
    const expected = selected
      ? selected.release +
        input.application.slice((input.home + "/current").length)
      : input.initial_application;
    if (!resolved || resolved !== expected) {
      throw new Error("php.runtime_invalid");
    }
    await directory(host, resolved, owner.uid);
    const pool =
      (selected ? input.configuration.pool : input.configuration.initial_pool) +
      "\n; Orbit application release: " + resolved + "\n";
    for (
      const line of pool.split("\n").filter((line) =>
        line.startsWith("chdir = ")
      )
    ) {
      const path = line.slice(8);
      const actual = await host.realPath(path);
      if (!actual || !actual.startsWith(input.home + "/releases/")) {
        throw new Error("php.runtime_invalid");
      }
    }
    const work = root + "/.native-validation";
    await mkdir(host, work, "root", 0o700);
    const localDigest = Array.from(
      new Uint8Array(
        await crypto.subtle.digest("SHA-256", encoder.encode(localText)),
      ),
      (b) => b.toString(16).padStart(2, "0"),
    ).join("");
    const desired: { [key: string]: string } = {
      "php-fpm.conf": input.configuration.main,
      "pool.conf": pool,
      "master.ini": input.configuration.master_ini,
      "local.sha256": localDigest + "\n",
    };
    const validation = input.configuration.main.replaceAll(
      generated + "/pool.conf",
      work + "/pool.conf",
    ).replaceAll(local, work + "/local.conf");
    for (
      const [name, content] of Object.entries({
        ...desired,
        "php-fpm.validate.conf": validation,
        "local.conf": localText,
      })
    ) await host.write(work + "/" + name, content, "root", 0o644);
    const before = new Map<string, string | null>();
    for (const name of Object.keys(desired)) {
      await regular(host, generated + "/" + name, 0);
      before.set(
        generated + "/" + name,
        await host.read(generated + "/" + name),
      );
    }
    await regular(host, unit, 0);
    before.set(unit, await host.read(unit));
    const active =
      (await host.run("/usr/bin/systemctl", ["is-active", "--quiet", service]))
        .success;
    try {
      await required(host, "/usr/bin/env", [
        "PHP_INI_SCAN_DIR=/etc/php/" + input.version + "/fpm/conf.d:" + work,
        "/usr/sbin/php-fpm" + input.version,
        "-y",
        work + "/php-fpm.validate.conf",
        "-t",
      ]);
      await mkdir(host, generated, "root", 0o755);
      for (const [name, content] of Object.entries(desired)) {
        await host.write(generated + "/" + name, content, "root", 0o644);
      }
      await host.write(unit, input.configuration.unit, "root", 0o644);
      await required(host, "/usr/bin/systemctl", ["daemon-reload"]);
      await required(host, "/usr/bin/systemctl", ["enable", service]);
      await required(host, "/usr/bin/systemctl", [
        active ? "restart" : "start",
        service,
      ]);
      await required(host, "/usr/bin/systemctl", [
        "is-active",
        "--quiet",
        service,
      ]);
      const pid = (await required(host, "/usr/bin/systemctl", [
        "show",
        "--property=MainPID",
        "--value",
        service,
      ])).trim();
      if (
        !/^[1-9][0-9]+$/.test(pid) ||
        await host.realPath("/proc/" + pid + "/exe") !==
          "/usr/sbin/php-fpm" + input.version
      ) throw new Error("php.runtime_invalid");
      const caddy =
        (await required(host, "/usr/bin/getent", ["group", "caddy"])).trim()
          .split(":")[2];
      for (
        const line of pool.split("\n").filter((line) =>
          line.startsWith("listen = ")
        )
      ) {
        const socket = await host.stat(line.slice(9));
        if (
          socket?.kind !== "socket" || socket.uid !== owner.uid ||
          socket.gid !== Number(caddy) || socket.mode !== 0o660
        ) throw new Error("php.runtime_invalid");
      }
    } catch (error) {
      await host.run("/usr/bin/systemctl", ["stop", service]);
      for (const [path, content] of before) {
        if (content === null) {
          await required(host, "/usr/bin/rm", ["-f", "--", path]);
        } else await host.write(path, content, "root", 0o644);
      }
      await required(host, "/usr/bin/systemctl", ["daemon-reload"]);
      if (active) {
        await required(host, "/usr/bin/systemctl", ["start", service]);
      }
      throw error;
    } finally {
      await required(host, "/usr/bin/rm", ["-rf", "--", work]);
    }
    return { ready: true };
  }
  async function prepareRuntime(raw: unknown) {
    const input = z.object({
      production: z.boolean(),
      runtime: ObjectInput.nullable().optional(),
      profile: ObjectInput.nullable().optional(),
      packages: ObjectInput.nullable().optional(),
    }).parse(raw);
    if (!input.production) {
      if (input.packages) {
        const packages = z.object({
          install: ObjectInput,
          configure: z.array(ObjectInput),
        }).parse(input.packages);
        await installPackages(packages.install);
        for (const profile of packages.configure) {
          await configureRuntime(profile);
        }
      }
      return { ready: true };
    }
    if (input.runtime === null || input.runtime === undefined) {
      return { ready: true };
    }
    await installPackages(input.packages);
    await configureRuntime(input.profile);
    return await convergeProductionRuntime(input.runtime);
  }
  async function synchronizeEnvironment(raw: unknown) {
    const writes = z.array(z.object({
      instance_id: z.number().int().positive(),
      name: z.enum([
        "instance.write-environment",
        "instance.write-directory-url",
        "instance.write-development-url",
      ]),
      input: ObjectInput,
    })).parse(raw);
    for (const write of writes) {
      if (write.name === "instance.write-environment") {
        await writeEnvironment(write.input);
      } else if (write.name === "instance.write-directory-url") {
        await writeDirectoryUrl(write.input);
      } else await writeDevelopmentUrl(write.input);
    }
    return { ready: true };
  }
  return {
    grantSourceAccess,
    prepareRuntime,
    prepareWebRoots,
    synchronizeEnvironment,
    writeEnvironment,
    writeDevelopmentUrl,
    writeDirectoryUrl,
    installPackages,
    configureRuntime,
    convergeProductionRuntime,
  };
}
