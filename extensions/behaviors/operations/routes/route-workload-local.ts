import {
  type NodeCommand,
  run as runNodeCommand,
} from "../_lib/node-commands.ts";
import { z } from "npm:zod@4.4.3";
import { installPhpBuild, PhpBuildInput } from "../php/build_local.ts";
import {
  configurePhpRuntime,
  PhpRuntimeProfileInput,
} from "../php/profile_local.ts";
import { convergeProductionRuntime as applyProductionRuntime } from "../php/runtime_local.ts";
import type { LocalPhpRunner } from "../php/local.ts";

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

// Download and checksum on the logical Node. Only the temporary filename returns.
const downloadProgram = String
  .raw`import hashlib, json, os, signal, sys, tempfile, time, urllib.request
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs): raise ValueError()
def interrupted(*args): raise InterruptedError()
signal.signal(signal.SIGTERM, interrupted)
path = None
complete = False
try:
    args = json.load(sys.stdin)
    opener = urllib.request.build_opener(NoRedirect)
    deadline = time.monotonic() + 120
    with opener.open(args['url'], timeout=120) as response:
        if not 200 <= response.status < 300: raise ValueError()
        if int(response.headers.get('Content-Length', '0')) > 268435456: raise ValueError()
        fd, path = tempfile.mkstemp(prefix='orbit-route-php-', suffix='.deb', dir='/tmp')
        digest = hashlib.sha256()
        count = 0
        with os.fdopen(fd, 'wb') as output:
            while True:
                if time.monotonic() >= deadline: raise TimeoutError()
                chunk = response.read(1048576)
                if not chunk: break
                count += len(chunk)
                if count > 268435456: raise ValueError()
                digest.update(chunk)
                output.write(chunk)
        if digest.hexdigest() != args['sha256']: raise ValueError()
    print(path)
    complete = True
except BaseException:
    sys.exit(1)
finally:
    if path and not complete:
        try: os.unlink(path)
        except FileNotFoundError: pass
`;

/** Standard local programs only. Private stdin and stderr never become native output. */
export function localRouteHost(
  invoke?: RouteLocalHost["run"],
  command: NodeCommand = runNodeCommand,
): RouteLocalHost {
  const run = invoke ?? runDefault;
  async function runDefault(
    binary: string,
    args: string[],
    options: LocalOptions = {},
  ): Promise<LocalReply> {
    try {
      const output = await command(
        [binary, ...args],
        options.input === undefined ? undefined : encoder.encode(options.input),
        options.user ? User.parse(options.user) : "root",
        {
          deadlineMs: options.timeoutMs ?? 120000,
          maxOutputBytes: 16 * 1024 * 1024,
        },
      );
      if (output.stdout.byteLength > 16 * 1024 * 1024) throw new Error();
      return {
        success: output.success,
        code: output.code,
        stdout: new TextDecoder().decode(output.stdout),
      };
    } catch {
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
      const candidate = path + ".orbit-" + crypto.randomUUID();
      try {
        await required("/usr/bin/install", [
          "-m",
          mode.toString(8),
          "-o",
          user,
          "-g",
          user,
          "-T",
          "--",
          "/dev/stdin",
          candidate,
        ], { input: value });
        await required("/usr/bin/mv", ["-fT", "--", candidate, path]);
      } finally {
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
      try {
        const result = await run("/usr/bin/python3", [
          "-I",
          "-c",
          downloadProgram,
        ], {
          input: JSON.stringify({ url: parsed, sha256 }),
          timeoutMs: 120000,
        });
        if (!result.success) throw new Error();
        const file = result.stdout.trim();
        if (!/^\/tmp\/orbit-route-php-[A-Za-z0-9_-]+\.deb$/.test(file)) {
          throw new Error();
        }
        return file;
      } catch {
        throw new Error("php.build_download_failed");
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

export function routeWorkloadLocal(
  host: RouteLocalHost = localRouteHost(),
  phpRunner?: LocalPhpRunner,
) {
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
  async function installPackages(raw: unknown, signal?: AbortSignal) {
    await installPhpBuild(PhpBuildInput.parse(raw), signal, phpRunner);
    return { ready: true };
  }
  async function configureRuntime(raw: unknown, signal?: AbortSignal) {
    await configurePhpRuntime(
      PhpRuntimeProfileInput.parse(raw),
      signal,
      phpRunner,
    );
    return { ready: true };
  }
  async function convergeProductionRuntime(raw: unknown, signal?: AbortSignal) {
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
    await applyProductionRuntime(input, signal, phpRunner);
    return { ready: true };
  }
  async function prepareRuntime(raw: unknown, signal?: AbortSignal) {
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
        await installPackages(packages.install, signal);
        for (const profile of packages.configure) {
          await configureRuntime(profile, signal);
        }
      }
      return { ready: true };
    }
    if (input.runtime === null || input.runtime === undefined) {
      return { ready: true };
    }
    await installPackages(input.packages, signal);
    await configureRuntime(input.profile, signal);
    return await convergeProductionRuntime(input.runtime, signal);
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
