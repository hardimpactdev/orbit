import { localCommand, localFilesystem } from "../_lib/local-worker.ts";
/** Node-local deployment effects. Git credentials live only in child-process environment. */
export type GitRead = { github_token: string | null };
export type LocalRelease = { name: string; commit: string };
export type DevelopmentHome = {
  home: string;
  repository: string;
  instance: number;
  user: string;
};
export type ProductionHome = DevelopmentHome & {
  relative_root: string;
  application_suffix: string;
  served: { web_root: string; directory: string }[];
};
export type SourceSelection = {
  branch: string;
  commit: string | null;
  protocols: string[];
  git_read: GitRead;
};
export interface DeploySourceLocal {
  inspectDevelopment(
    home: DevelopmentHome & { branch: string },
  ): Promise<{ commit: string }>;
  resolveDevelopment(
    home: DevelopmentHome,
    source: SourceSelection,
  ): Promise<{ commit: string }>;
  checkoutDevelopment(
    home: DevelopmentHome,
    target: { branch: string; commit: string },
  ): Promise<{ commit: string }>;
  prepareRelease(
    home: ProductionHome,
    source: SourceSelection & { name: string; shared_paths: string[] },
  ): Promise<LocalRelease>;
  inspectRelease(
    home: ProductionHome,
    name?: string,
  ): Promise<{ release: LocalRelease | null }>;
  activateRelease(
    home: ProductionHome,
    release: LocalRelease,
  ): Promise<LocalRelease>;
  pruneReleases(
    home: ProductionHome,
    input: { limit: number; selected: string; previous: string | null },
  ): Promise<{ removed: string[]; in_use: string[] }>;
}
export class LocalDeployError extends Error {
  constructor(readonly code: string) {
    super(code);
  }
}
const require = (ok: unknown, code = "deployment.identity_changed") => {
  if (!ok) throw new LocalDeployError(code);
};
const nameValid = (name: string) =>
  /^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/.test(name);
const commitValid = (commit: string) =>
  /^(?:[a-f0-9]{40}|[a-f0-9]{64})$/.test(commit);
const pathValid = (path: string) =>
  path.length > 0 &&
  path.split("/").every((part) =>
    /^[A-Za-z0-9._-]+$/.test(part) && part !== "." && part !== ".."
  );
const join = (base: string, suffix: string) => `${base}/${suffix}`;
const decoder = new TextDecoder();
type File = {
  type: string;
  uid: number;
  gid: number;
  dev: number;
  ino: number;
  mode: number;
};
export type LocalOptions = {
  deadline: number;
  signal?: AbortSignal;
  /** Alternate roots are only for real filesystem fixture tests. */
  homeRoot?: string;
  stateRoot?: string;
  procRoot?: string;
  rootUid?: number;
  /** Fixture tests run as their own account, without sudo. */
  unprivileged?: boolean;
};

export function localDeploySource(options: LocalOptions): DeploySourceLocal {
  const homeRoot = options.homeRoot ?? "/home",
    stateRoot = options.stateRoot ?? "/var/lib/orbit/app-instance-sources";
  const procRoot = options.procRoot ?? "/proc", rootUid = options.rootUid ?? 0;
  async function command(
    program: string,
    args: string[],
    user?: string,
    root = false,
    env: Record<string, string> = {},
    input?: string,
  ): Promise<{ success: boolean; output: string }> {
    const remaining = options.deadline - Date.now();
    require(remaining > 0, "deployment.deadline_exceeded");
    if (options.signal?.aborted) {
      throw new LocalDeployError("operation.cancelled");
    }
    require(/^[a-z][a-z0-9-]*$/.test(program));
    let executable = `/usr/bin/${program}`, argv = args;
    if (!options.unprivileged && (root || user !== undefined)) {
      executable = "/usr/bin/sudo";
      argv = [
        "-n",
        ...(user ? ["-H", "-u", user] : []),
        ...(Object.keys(env).length
          ? [`--preserve-env=${Object.keys(env).join(",")}`]
          : []),
        "--",
        `/usr/bin/${program}`,
        ...args,
      ];
    }
    const stop = new AbortController();
    const timer = setTimeout(() => stop.abort(), Math.min(remaining, 900_000));
    const abort = () => stop.abort();
    options.signal?.addEventListener("abort", abort, { once: true });
    try {
      const child = localCommand(executable, {
        args: argv,
        env: {
          PATH: "/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin",
          LANG: "C.UTF-8",
          ...env,
        },
        clearEnv: true,
        stdin: input === undefined ? "null" : "piped",
        stdout: "piped",
        stderr: "null",
        signal: stop.signal,
      }).spawn();
      if (input !== undefined) {
        const writer = child.stdin.getWriter();
        await writer.write(new TextEncoder().encode(input));
        await writer.close();
      }
      // Filesystem inventories are private local data, never workflow resources. Refuse
      // oversized output before buffering it rather than letting an unbounded tree
      // or Git response exhaust the worker.
      const chunks: Uint8Array[] = [];
      let size = 0;
      try {
        for await (const chunk of child.stdout) {
          size += chunk.length;
          require(size <= 16 * 1024 * 1024, "deployment.local_output_limit");
          chunks.push(chunk);
        }
      } catch (error) {
        try {
          child.kill("SIGKILL");
        } catch { /* Already stopped. */ }
        await child.status;
        throw error;
      }
      const bytes = new Uint8Array(size);
      let offset = 0;
      for (const chunk of chunks) {
        bytes.set(chunk, offset);
        offset += chunk.length;
      }
      return {
        success: (await child.status).success,
        output: decoder.decode(bytes).replace(/\n+$/, ""),
      };
    } finally {
      clearTimeout(timer);
      options.signal?.removeEventListener("abort", abort);
    }
  }
  async function run(
    program: string,
    args: string[],
    user?: string,
    root = false,
    env: Record<string, string> = {},
    input?: string,
  ): Promise<string> {
    const result = await command(program, args, user, root, env, input);
    require(result.success, "deployment.local_command_failed");
    return result.output;
  }
  async function stat(path: string): Promise<File | null> {
    const result = await command(
      "stat",
      ["--printf=%f %u %g %d %i", "--", path],
      undefined,
      true,
    );
    if (!result.success) return null;
    const [mode, uid, gid, dev, ino] = result.output.split(" ");
    const bits = Number.parseInt(mode, 16), typeBits = bits & 0xf000;
    return {
      type: typeBits === 0x4000
        ? "d"
        : typeBits === 0xa000
        ? "l"
        : typeBits === 0x8000
        ? "f"
        : "?",
      uid: +uid,
      gid: +gid,
      dev: +dev,
      ino: +ino,
      mode: bits & 0xfff,
    };
  }
  async function real(path: string): Promise<string | null> {
    const result = await command(
      "realpath",
      ["--canonicalize-existing", "--", path],
      undefined,
      true,
    );
    return result.success ? result.output : null;
  }
  async function canonicalDirectory(path: string) {
    require((await stat(path))?.type === "d" && await real(path) === path);
  }
  async function entries(path: string): Promise<string[]> {
    const output = await run(
      "find",
      [path, "-mindepth", "1", "-maxdepth", "1", "-printf", "%f\\0"],
      undefined,
      true,
    );
    return output.split("\0").filter(Boolean);
  }
  async function tree(
    path: string,
  ): Promise<{ path: string; type: string; uid: number; gid: number }[]> {
    const output = await run(
      "find",
      [path, "-xdev", "-printf", "%y\\0%U\\0%G\\0%p\\0"],
      undefined,
      true,
    );
    const parts = output.split("\0"), found = [];
    for (let i = 0; i + 3 < parts.length; i += 4) {
      found.push({
        type: parts[i],
        uid: +parts[i + 1],
        gid: +parts[i + 2],
        path: parts[i + 3],
      });
    }
    return found;
  }
  async function account(user: string) {
    require(/^[a-z_][a-z0-9_-]{0,63}$/.test(user) && user !== "root");
    const uid = +(await run("id", ["-u", "--", user])),
      gid = +(await run("id", ["-g", "--", user]));
    require(Number.isSafeInteger(uid) && Number.isSafeInteger(gid) && uid > 0);
    return { uid, gid };
  }
  const gitSettings = [
    "core.hooksPath=/dev/null",
    "core.fsmonitor=false",
    "core.alternateRefsCommand=true",
    "core.sshCommand=ssh",
    "core.askPass=",
    "core.gitProxy=",
    "credential.helper=",
    "uploadpack.packObjectsHook=",
    "protocol.ext.allow=never",
  ];
  async function git(
    user: string,
    directory: string | null,
    args: string[],
    read: GitRead = { github_token: null },
    protocols?: string[],
  ) {
    require(
      read.github_token === null ||
        /^[A-Za-z0-9_.-]{1,4096}$/.test(read.github_token),
      "deployment.git_credential_invalid",
    );
    const env: Record<string, string> = {
      GIT_OPTIONAL_LOCKS: "0",
      GIT_TERMINAL_PROMPT: "0",
      GIT_CONFIG_NOSYSTEM: "1",
      GIT_CONFIG_GLOBAL: "/dev/null",
      GIT_CONFIG_COUNT: "0",
    };
    const config = [...gitSettings];
    if (protocols || read.github_token) {
      config.push("protocol.allow=never", "http.followRedirects=false");
      for (
        const protocol of [
          "https",
          "http",
          "ssh",
          "git",
          "file",
          "ext",
          "ftp",
          "ftps",
        ]
      ) {
        config.push(
          `protocol.${protocol}.allow=${
            (read.github_token
                ? protocol === "https"
                : protocol !== "ext" && protocols?.includes(protocol))
              ? "always"
              : "never"
          }`,
        );
      }
    }
    if (read.github_token) {
      const encoded = btoa(`x-access-token:${read.github_token}`);
      const privateConfig = [
        [
          "http.https://github.com/.extraheader",
          `Authorization: Basic ${encoded}`,
        ],
        ["url.https://github.com/.insteadOf", "git@github.com:"],
        ["url.https://github.com/.insteadOf", "ssh://git@github.com/"],
      ];
      env.GIT_CONFIG_COUNT = String(privateConfig.length);
      privateConfig.forEach(([key, value], i) => {
        env[`GIT_CONFIG_KEY_${i}`] = key;
        env[`GIT_CONFIG_VALUE_${i}`] = value;
      });
    }
    return await command(
      "git",
      [
        ...config.flatMap((setting) => ["-c", setting]),
        "--literal-pathspecs",
        ...(directory ? ["-C", directory] : []),
        ...args,
      ],
      user,
      false,
      env,
    );
  }
  async function gitRead(
    home: DevelopmentHome,
    args: string[],
    read?: GitRead,
    protocols?: string[],
  ) {
    const result = await git(home.user, home.home, args, read, protocols);
    require(result.success, "deployment.source_failed");
    return result.output;
  }
  async function branchCheck(home: DevelopmentHome, branch: string) {
    require(
      !branch.startsWith("-") &&
        (await git(home.user, home.home, [
          "check-ref-format",
          "--branch",
          branch,
        ])).success,
      "deployment.branch_invalid",
    );
  }
  async function firstParent(
    home: DevelopmentHome,
    head: string,
    commit: string,
  ) {
    require(commitValid(commit), "deployment.commit_not_on_branch");
    const distance = await gitRead(home, [
      "rev-list",
      "--first-parent",
      "--count",
      `${commit}..${head}`,
    ]);
    require(
      /^\d+$/.test(distance) &&
        await gitRead(home, [
            "rev-parse",
            "--verify",
            `${head}~${distance}`,
          ]) === commit,
      "deployment.commit_not_on_branch",
    );
  }
  async function developmentGuard(home: DevelopmentHome) {
    const user = await account(home.user), self = +(await run("id", ["-u"]));
    require(user.uid === self, "deployment.identity_changed");
    await canonicalDirectory(home.home);
    const directory = await stat(join(home.home, ".git"));
    require(
      directory?.type === "d" && directory.uid === user.uid &&
        (await stat(home.home))?.uid === user.uid,
    );
    require(
      (await gitRead(home, [
        "config",
        "--null",
        "--get",
        "remote.origin.url",
      ])) === `${home.repository}\0`,
    );
    require(await gitRead(home, ["rev-parse", "--git-common-dir"]) === ".git");
  }
  async function layout(home: ProductionHome) {
    await account(home.user);
    require(
      home.home === join(homeRoot, home.user) && pathValid(home.relative_root),
    );
    require(
      home.application_suffix === "" ||
        home.application_suffix.startsWith("/") &&
          pathValid(home.application_suffix.slice(1)),
    );
    require(
      home.served.every((served) =>
        pathValid(served.web_root) &&
        (served.directory === "" || pathValid(served.directory))
      ),
    );
    const state = join(stateRoot, String(home.instance)),
      marker = join(state, "release-layout");
    const stateInfo = await stat(state), markerInfo = await stat(marker);
    require(
      stateInfo?.type === "d" && stateInfo.uid === rootUid &&
        stateInfo.gid === rootUid && stateInfo.mode === 0o700,
    );
    require(
      markerInfo?.type === "f" && markerInfo.uid === rootUid &&
        markerInfo.gid === rootUid && markerInfo.mode === 0o600,
    );
    require(
      await run("cat", ["--", marker], undefined, true) ===
        `${home.repository}\0${home.user}\0${home.home}\0initial\0`,
    );
    await canonicalDirectory(home.home);
    await canonicalDirectory(join(home.home, "releases"));
  }
  function releasePath(home: ProductionHome, name: string) {
    require(nameValid(name));
    return join(join(home.home, "releases"), name);
  }
  function application(home: ProductionHome, release: string) {
    return `${release}${home.application_suffix}`;
  }
  async function selected(home: ProductionHome): Promise<string | null> {
    const current = join(home.home, "current"), info = await stat(current);
    if (info === null) return null;
    require(info.type === "l");
    const actual = await real(current), prefix = `${home.home}/releases/`;
    require(actual?.startsWith(prefix));
    const name = actual!.slice(prefix.length);
    require(nameValid(name));
    return name;
  }
  async function publicStorage(
    home: ProductionHome,
    release: string,
    web: string,
  ) {
    require(web.endsWith("/public"));
    const application = web.slice(0, -7),
      relative = application.slice(release.length + 1);
    const expected = relative
      ? join(home.home, `${relative}/storage/app/public`)
      : join(home.home, "storage/app/public");
    const actual = await real(join(web, "storage"));
    require(
      actual === join(application, "storage/app/public") || actual === expected,
    );
    require(
      (await stat(actual!))?.type === "d" &&
        (await tree(actual!)).every((file) => file.type !== "l"),
    );
    return actual!;
  }
  async function webRoot(
    home: ProductionHome,
    release: string,
    relative: string,
  ) {
    const web = join(release, relative);
    await canonicalDirectory(web);
    for (const file of await tree(web)) {
      if (file.type === "l") {
        require(file.path === join(web, "storage"));
        await publicStorage(home, release, web);
      }
    }
    return web;
  }
  async function inspect(
    home: ProductionHome,
    name: string,
  ): Promise<LocalRelease> {
    const release = releasePath(home, name), user = await account(home.user);
    await canonicalDirectory(release);
    require((await stat(join(release, ".git")))?.type === "d");
    const checkout = { ...home, home: release };
    require(
      await gitRead(checkout, [
        "config",
        "--null",
        "--get",
        "remote.origin.url",
      ]) === `${home.repository}\0`,
    );
    const environment = join(application(home, release), ".env");
    require(
      (await stat(environment))?.type === "l" &&
        await real(environment) === join(home.home, ".env"),
    );
    await webRoot(home, release, home.relative_root);
    require(
      (await tree(release)).every((file) =>
        file.uid === user.uid && file.gid === user.gid
      ),
    );
    const commit = await gitRead(checkout, ["rev-parse", "--verify", "HEAD"]);
    require(commitValid(commit));
    return { name, commit };
  }
  async function noLinks(base: string, relative: string) {
    require(pathValid(relative));
    let path = base;
    for (const part of relative.split("/")) {
      path = join(path, part);
      require((await stat(path))?.type !== "l");
    }
  }
  async function linkServed(home: ProductionHome, release: string) {
    for (const served of home.served) {
      const app = served.directory ? join(release, served.directory) : release;
      await canonicalDirectory(app);
      const target = `${
        "../".repeat(
          served.directory
            ? 3 + (served.directory.match(/\//g)?.length ?? 0)
            : 2,
        )
      }env/${served.directory ? `${served.directory}/` : ""}.env`;
      const env = join(app, ".env"), existing = await stat(env);
      if (existing?.type === "l") {
        require(await run("readlink", ["--", env], undefined, true) === target);
      } else {
        require(existing === null);
        await run("ln", ["-s", "--", target, env], home.user);
      }
    }
  }
  async function grant(home: ProductionHome, release: string, web: string) {
    let path = web.slice(0, web.lastIndexOf("/"));
    while (path.startsWith(`${release}/`)) {
      await canonicalDirectory(path);
      await run("setfacl", ["-m", "u:caddy:--x", "--", path], undefined, true);
      path = path.slice(0, path.lastIndexOf("/"));
    }
    await run("setfacl", ["-m", "u:caddy:--x", "--", release], undefined, true);
    await run(
      "setfacl",
      ["-P", "-R", "-m", "u:caddy:r-X", "--", web],
      undefined,
      true,
    );
    for (
      const directory of (await tree(web)).filter((entry) => entry.type === "d")
    ) {
      await run(
        "setfacl",
        ["-m", "d:u:caddy:r-x", "--", directory.path],
        undefined,
        true,
      );
    }
    if ((await stat(join(web, "storage")))?.type === "l") {
      const target = await publicStorage(home, release, web);
      path = target.slice(0, target.lastIndexOf("/"));
      while (path.startsWith(home.home)) {
        await run(
          "setfacl",
          ["-m", "u:caddy:--x", "--", path],
          undefined,
          true,
        );
        if (path === home.home) break;
        path = path.slice(0, path.lastIndexOf("/"));
      }
      await run(
        "setfacl",
        ["-P", "-R", "-m", "u:caddy:r-X", "--", target],
        undefined,
        true,
      );
      for (
        const directory of (await tree(target)).filter((entry) =>
          entry.type === "d"
        )
      ) {
        await run(
          "setfacl",
          ["-m", "d:u:caddy:r-x", "--", directory.path],
          undefined,
          true,
        );
      }
    }
  }
  return {
    async inspectDevelopment(home) {
      await developmentGuard(home);
      await branchCheck(home, home.branch);
      const branch = await git(home.user, home.home, [
        "symbolic-ref",
        "--quiet",
        "--short",
        "HEAD",
      ]);
      require(
        branch.success && branch.output === home.branch,
        "deployment.branch_changed",
      );
      const commit = await gitRead(home, ["rev-parse", "--verify", "HEAD"]);
      require(commitValid(commit));
      return { commit };
    },
    async resolveDevelopment(home, source) {
      await developmentGuard(home);
      await branchCheck(home, source.branch);
      const tracking = `refs/remotes/origin/${source.branch}`;
      const result = await git(
        home.user,
        home.home,
        [
          "fetch",
          "--quiet",
          "--no-tags",
          "--",
          home.repository,
          `+refs/heads/${source.branch}:${tracking}`,
        ],
        source.git_read,
        source.protocols,
      );
      require(result.success, "deployment.source_failed");
      const commit = source.commit ??
        await gitRead(home, ["rev-parse", "--verify", `${tracking}^{commit}`]);
      if (source.commit !== null) {
        await firstParent(home, tracking, source.commit);
      }
      require(commitValid(commit));
      return { commit };
    },
    async checkoutDevelopment(home, target) {
      await developmentGuard(home);
      await branchCheck(home, target.branch);
      require(commitValid(target.commit));
      const directory = await localFilesystem().open(home.home, { read: true });
      try {
        try {
          require(await directory.tryLock(true), "instance.lifecycle_busy");
        } catch (error) {
          if (error instanceof LocalDeployError) throw error;
          throw new LocalDeployError("instance.lifecycle_busy");
        }
        const opened = await directory.stat(),
          named = await Deno.lstat(home.home);
        require(
          opened.dev === named.dev && opened.ino === named.ino &&
            !named.isSymlink,
        );
        require(
          await gitRead(home, [
            "status",
            "--porcelain",
            "--untracked-files=no",
          ]) === "",
          "deployment.checkout_dirty",
        );
        const local = `refs/heads/${target.branch}`;
        if (
          (await git(home.user, home.home, [
            "rev-parse",
            "--verify",
            "--quiet",
            `${local}^{commit}`,
          ])).success
        ) {
          require(
            (await git(home.user, home.home, [
              "merge-base",
              "--is-ancestor",
              local,
              target.commit,
            ])).success,
            "deployment.branch_diverged",
          );
        }
        const untracked =
          (await gitRead(home, ["ls-files", "-z", "--others", "--directory"]))
            .split("\0").filter(Boolean);
        for (const raw of untracked) {
          const entry = raw.replace(/\/$/, ""), slash = entry.lastIndexOf("/");
          if (slash < 0) continue;
          require(!entry.startsWith("/") && !entry.split("/").includes(".."));
          const parent = entry.slice(0, slash);
          if (
            await gitRead(home, [
              "ls-tree",
              "-d",
              target.commit,
              "--",
              parent,
            ]) !== ""
          ) continue;
          const path = join(home.home, entry), info = await stat(path);
          if (info?.type === "d") {
            for (
              const directory of (await tree(path)).filter((item) =>
                item.type === "d"
              )
            ) await run("chmod", ["u+w", "--", directory.path], home.user);
            await run("rm", ["-rf", "--", path], home.user);
          } else if (info) await run("rm", ["--", path], home.user);
        }
        await gitRead(home, [
          "checkout",
          "--quiet",
          "--no-track",
          "-B",
          target.branch,
          target.commit,
        ]);
        require(
          await gitRead(home, ["symbolic-ref", "--short", "HEAD"]) ===
            target.branch,
        );
        const commit = await gitRead(home, ["rev-parse", "--verify", "HEAD"]);
        require(commit === target.commit);
        return { commit };
      } finally {
        directory.close();
      }
    },
    async prepareRelease(home, source) {
      await layout(home);
      require(nameValid(source.name));
      await branchCheck(home, source.branch);
      require((await stat(join(home.home, ".env")))?.type === "f");
      const release = releasePath(home, source.name);
      require(await stat(release) === null);
      if (source.commit !== null) require(commitValid(source.commit));
      require(
        (await git(
          home.user,
          null,
          [
            "clone",
            "--no-checkout",
            "--origin",
            "origin",
            "--",
            home.repository,
            release,
          ],
          source.git_read,
          source.protocols,
        )).success,
        "deployment.source_failed",
      );
      const checkout = { ...home, home: release };
      require(
        (await git(
          home.user,
          release,
          ["fetch", "--prune", "--", "origin"],
          source.git_read,
          source.protocols,
        )).success,
        "deployment.source_failed",
      );
      const tracking = `refs/remotes/origin/${source.branch}`;
      await gitRead(checkout, ["show-ref", "--verify", "--quiet", tracking]);
      if (source.commit !== null) {
        await firstParent(checkout, tracking, source.commit);
      }
      await gitRead(checkout, [
        "checkout",
        "--detach",
        source.commit ?? tracking,
      ]);
      const app = application(home, release);
      await canonicalDirectory(app);
      const environment = join(app, ".env"),
        target = `${
          "../".repeat(2 + (home.application_suffix.match(/\//g)?.length ?? 0))
        }.env`;
      require(await stat(environment) === null);
      await run("ln", ["-s", "--", target, environment], home.user);
      require(await real(environment) === join(home.home, ".env"));
      for (const path of source.shared_paths.filter(Boolean)) {
        require(
          pathValid(path) && home.relative_root !== path &&
            !home.relative_root.startsWith(`${path}/`),
        );
        await noLinks(release, path);
        await noLinks(home.home, path);
        const from = join(release, path),
          to = join(home.home, path),
          original = await stat(from);
        require(original === null || original.type === "d");
        await run("mkdir", ["-p", "--", to], home.user);
        await canonicalDirectory(to);
        if ((await entries(to)).length === 0 && original?.type === "d") {
          require((await tree(from)).every((entry) => entry.type !== "l"));
          const seed = await run(
            "mktemp",
            ["-d", `${to}.seed.XXXXXXXX`],
            home.user,
          );
          try {
            await run("cp", ["-a", "--", `${from}/.`, `${seed}/`], home.user);
            await run("mv", ["-T", "--", seed, to], home.user);
          } catch (error) {
            await command("rm", ["-rf", "--", seed], home.user);
            throw error;
          }
        }
        await run(
          "mkdir",
          ["-p", "--", from.slice(0, from.lastIndexOf("/"))],
          home.user,
        );
        await run("rm", ["-rf", "--", from], home.user);
        await run("ln", ["-s", "--", to, from], home.user);
      }
      const storage = join(app, "storage");
      if (
        (await stat(storage))?.type === "l" &&
        (await stat(join(app, "public")))?.type === "d"
      ) {
        const relative = app.slice(release.length).replace(/^\//, ""),
          target = join(
            home.home,
            `${relative ? `${relative}/` : ""}storage/app/public`,
          ),
          actual = await real(join(storage, "app/public"));
        require(actual === null || actual === target);
        await run("mkdir", ["-p", "--", target], home.user);
        if (await stat(join(app, "public/storage")) === null) {
          await run("ln", [
            "-s",
            "--",
            "../storage/app/public",
            join(app, "public/storage"),
          ], home.user);
        }
      }
      await linkServed(home, release);
      return await inspect(home, source.name);
    },
    async inspectRelease(home, name) {
      await layout(home);
      const selectedName = name ?? await selected(home);
      return {
        release: selectedName === null
          ? null
          : await inspect(home, selectedName),
      };
    },
    async activateRelease(home, release) {
      await layout(home);
      const checked = await inspect(home, release.name);
      require(checked.commit === release.commit);
      const path = releasePath(home, release.name);
      await grant(home, path, await webRoot(home, path, home.relative_root));
      await linkServed(home, path);
      for (const served of home.served) {
        await grant(home, path, await webRoot(home, path, served.web_root));
      }
      const temporary = join(home.home, `.current.${crypto.randomUUID()}.tmp`);
      try {
        await run(
          "ln",
          ["-s", "--", `releases/${release.name}`, temporary],
          home.user,
        );
        await run(
          "mv",
          ["-Tf", "--", temporary, join(home.home, "current")],
          home.user,
        );
      } catch (error) {
        await command("rm", ["-f", "--", temporary], home.user);
        throw error;
      }
      return checked;
    },
    async pruneReleases(home, input) {
      await layout(home);
      require(input.limit === 3 && await selected(home) === input.selected);
      const releases = join(home.home, "releases"), names: string[] = [];
      for (const name of (await entries(releases)).filter(nameValid).sort()) {
        try {
          await inspect(home, name);
          names.push(name);
        } catch { /* Invalid folders are never prune candidates. */ }
      }
      require(names.includes(input.selected));
      const user = await account(home.user), busy = new Set<string>();
      for (
        const pid of (await entries(procRoot)).filter((name) =>
          /^\d+$/.test(name)
        )
      ) {
        const process = join(procRoot, pid), info = await stat(process);
        if (!info || info.uid !== user.uid) continue;
        for (const link of ["cwd", "root", "exe"]) {
          const result = await command(
            "readlink",
            ["--", join(process, link)],
            undefined,
            true,
          );
          if (result.success) {
            if (result.output.startsWith(`${releases}/`)) {
              busy.add(result.output.slice(releases.length + 1).split("/")[0]);
            }
          } else {
            const status = await command(
              "cat",
              ["--", join(process, "status")],
              undefined,
              true,
            );
            require(
              await stat(process) === null ||
                /^State:\s+Z/m.test(status.output),
              "deployment.cleanup_unconfirmed",
            );
          }
        }
      }
      const newest = [
        ...names.filter((name) => name !== "initial").reverse(),
        ...names.filter((name) => name === "initial"),
      ];
      const keep = new Set(
        [input.selected, input.previous, ...newest].filter((
          name,
        ): name is string => name !== null && names.includes(name)).filter((
          name,
          index,
          all,
        ) => all.indexOf(name) === index).slice(0, 3),
      );
      const removed = [], in_use = [];
      for (const name of names.filter((name) => !keep.has(name))) {
        if (busy.has(name)) {
          in_use.push(name);
          continue;
        }
        await run("mv", [
          "-T",
          "--",
          join(releases, name),
          join(releases, `.pruned-${name}`),
        ], home.user);
        removed.push(name);
      }
      for (const name of await entries(releases)) {
        if (
          !name.startsWith(".pruned-") || !nameValid(name.slice(8)) ||
          busy.has(name.slice(8))
        ) continue;
        const path = join(releases, name);
        if ((await stat(path))?.type !== "d") continue;
        for (
          const directory of (await tree(path)).filter((entry) =>
            entry.type === "d"
          )
        ) await command("chmod", ["u+w", "--", directory.path], home.user);
        await command(
          "rm",
          ["-rf", "--one-file-system", "--", path],
          home.user,
        );
      }
      return { removed, in_use };
    },
  };
}

/** Read-only checkout verification for the parent; never invokes an agent action. */
export async function inspectDevelopment(
  home: DevelopmentHome & { branch: string },
  options: LocalOptions,
): Promise<{ commit: string }> {
  return await localDeploySource(options).inspectDevelopment(home);
}
