import { basename, relative, resolve } from "node:path";
import { z } from "npm:zod@4.4.3";

const Binary = "/usr/local/bin/orbit-agent";
const AgentResult = z.discriminatedUnion("ok", [
  z.object({ ok: z.literal(true), data: z.record(z.string(), z.unknown()) }),
  z.object({ ok: z.literal(false), error: z.object({ code: z.string() }) }),
]);
type Call = (
  input: Record<string, unknown>,
  signal?: AbortSignal,
) => Promise<Record<string, unknown>>;

/** Local fixed typed root action. No shell, caller environment, key or remote transport. */
export const packageCall: Call = async (input, signal) => {
  signal?.throwIfAborted();
  const binary = await Deno.readFile(Binary);
  const pin = [...new Uint8Array(await crypto.subtle.digest("SHA-256", binary))]
    .map((v) => v.toString(16).padStart(2, "0")).join("");
  const { files: manifests = {}, ...descriptor } = input;
  const body = new TextEncoder().encode(JSON.stringify(manifests));
  if (body.byteLength > 256 * 1024 * 1024) {
    throw new Error("package.input_invalid");
  }
  const payload = JSON.stringify({
    agent_sha256: pin,
    deadline_ms: 180000,
    stream_bytes: body.byteLength,
    input: descriptor,
  }) + "\n";
  const child = new Deno.Command("/usr/bin/sudo", {
    args: ["-n", Binary, "command", "package.session"],
    clearEnv: true,
    env: { PATH: "/usr/bin:/bin", LANG: "C.UTF-8" },
    stdin: "piped",
    stdout: "piped",
    stderr: "null",
  }).spawn();
  const abort = () => {
    try {
      child.kill("SIGKILL");
    } catch { /* Already ended. */ }
  };
  signal?.addEventListener("abort", abort, { once: true });
  try {
    if (signal?.aborted) abort();
    const writer = child.stdin.getWriter();
    await writer.write(new TextEncoder().encode(payload));
    await writer.write(body);
    await writer.close();
    const result = await child.output();
    if (!result.success) throw new Error("package.isolation_unavailable");
    const response = AgentResult.parse(
      JSON.parse(new TextDecoder().decode(result.stdout)),
    );
    if (!response.ok) throw new Error(response.error.code);
    return response.data;
  } finally {
    signal?.removeEventListener("abort", abort);
  }
};
const manifests = new Set([
  "package.json",
  "package-lock.json",
  "npm-shrinkwrap.json",
  "composer.json",
  "composer.lock",
  "bun.lock",
  "pnpm-workspace.yaml",
  "pnpm-lock.yaml",
]);
async function files(root: string) {
  const result: Record<string, string> = {};
  let bytes = 0;
  const walk = async (dir: string) => {
    for await (const item of Deno.readDir(dir)) {
      if (item.name === "node_modules" || item.name === ".git") continue;
      const path = `${dir}/${item.name}`;
      if (item.isSymlink) throw new Error("package.input_invalid");
      if (item.isDirectory) await walk(path);
      else if (item.isFile && manifests.has(item.name)) {
        const size = (await Deno.stat(path)).size;
        bytes += size;
        if (bytes > 64 * 1024 * 1024 || Object.keys(result).length >= 512) {
          throw new Error("package.input_invalid");
        }
        result[relative(root, path)] = await Deno.readTextFile(path);
      }
    }
  };
  await walk(root);
  return result;
}
const Executed = z.object({
  status: z.literal("executed"),
  code: z.number().int(),
  stdout: z.string(),
  stderr: z.string(),
  files: z.record(z.string(), z.string()),
});
const tools: Record<string, string> = {
  composer: "composer",
  npm: "npm",
  bun: "bun",
  "./node_modules/.bin/vp": "vite",
  node: "node",
  "expo-catalog": "expo_catalog",
};

/** One private persistent job per disposable input tree, with no shared package cache. */
export class PackageSandboxes {
  #sessions = new Map<string, string>();
  constructor(private call: Call = packageCall) {}
  async command(
    cwd: string,
    binary: string,
    args: string[],
    signal?: AbortSignal,
  ) {
    const tool = tools[binary];
    if (!tool) throw new Error("package.input_invalid");
    let root = await Deno.realPath(cwd);
    while (
      !/^\/(?:tmp|var\/tmp)\/(?:dependency-tools-|maintenance-plan-|js-maintenance-base-|composer-maintenance-base-)[^/]+$/
        .test(root)
    ) {
      const next = resolve(root, "..");
      if (next === root) throw new Error("package.input_invalid");
      root = next;
    }
    let job = this.#sessions.get(root);
    if (!job) {
      job = crypto.randomUUID().replaceAll("-", "");
      this.#sessions.set(root, job); // Retain identity even if the open reply is lost.
      const opened = await this.call({
        action: "open",
        job,
        files: await files(root),
      }, signal);
      if (opened.status !== "opened" || opened.job !== job) {
        throw new Error("package.outcome_unknown");
      }
    }
    const current = await files(root);
    const stop = () => {
      void this.call({ action: "cancel", job }).catch(() => {});
    };
    signal?.addEventListener("abort", stop, { once: true });
    try {
      signal?.throwIfAborted();
      const result = Executed.parse(
        await this.call({
          action: "run",
          job,
          tool,
          cwd: relative(root, cwd) || ".",
          args,
          files: current,
        }, signal),
      );
      for (const [name, value] of Object.entries(result.files)) {
        // Package output may replace only admitted manifests, never name a host file.
        const parent = resolve(root, name, "..");
        const admitted = Object.hasOwn(current, name) ||
          ["package.json", "composer.json"].some((manifest) =>
            Object.hasOwn(current, relative(root, `${parent}/${manifest}`))
          );
        if (!admitted || !manifests.has(basename(name))) {
          throw new Error("package.output_invalid");
        }
        const path = resolve(root, name);
        if (!path.startsWith(root + "/")) {
          throw new Error("package.output_invalid");
        }
        if (await Deno.realPath(parent) !== parent) {
          throw new Error("package.output_invalid");
        }
        try {
          const stat = await Deno.lstat(path);
          if (!stat.isFile || stat.isSymlink) {
            throw new Error("package.output_invalid");
          }
        } catch (error) {
          if (!(error instanceof Deno.errors.NotFound)) throw error;
        }
        await Deno.writeTextFile(path, value);
      }
      return {
        code: result.code,
        stdout: result.stdout,
        stderr: result.stderr,
      };
    } finally {
      signal?.removeEventListener("abort", stop);
    }
  }
  async close() {
    const failures: unknown[] = [];
    for (const [root, job] of this.#sessions) {
      try {
        await this.call({ action: "cancel", job });
        await this.call({ action: "close", job });
        this.#sessions.delete(root);
      } catch (error) {
        failures.push(error);
      }
    }
    if (failures.length) throw new Error("package.cleanup_failed");
  }
}
