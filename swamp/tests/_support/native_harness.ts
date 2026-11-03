/**
 * Shared local native workflow fixture. Each test gets a disposable Swamp repo,
 * runs the pinned runtime against it with no inherited serve connection, and
 * reads runs and step outcomes from native history.
 */
import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { parse, stringify } from "jsr:@std/yaml@1.0.12";

export const ROOT = new URL("../../", import.meta.url).pathname.replace(/\/$/, "");

type Workflow = {
  jobs: { labels?: unknown; steps: Record<string, unknown>[] }[];
  [key: string]: unknown;
};
export type Run = { id: string; status: string; [key: string]: unknown };
export type History = {
  inputs: Record<string, unknown>;
  jobs: { name: string; steps: { name: string; status: string; outputs?: Record<string, unknown> }[] }[];
  [key: string]: unknown;
};

const pinned = (() => {
  const source = Deno.readTextFileSync(`${ROOT}/extensions/behaviors/node-worker/config.ts`);
  const pin = source.match(/export const Swamp = \{\s*version: "([\w.-]+)",\s*sha256: "([a-f0-9]{64})",\s*bytes: (\d+)/);
  if (!pin) throw new Error("Missing canonical runtime pin");
  return { version: pin[1], sha256: pin[2], bytes: Number(pin[3]) };
})();

const cacheRoot = () =>
  Deno.env.get("SWAMP_RUNTIME_CACHE") ??
    `${Deno.env.get("XDG_CACHE_HOME") ?? `${Deno.env.get("HOME")}/.cache`}/orbit-shape-runtime`;

async function sha256(path: string) {
  const digest = await crypto.subtle.digest("SHA-256", await Deno.readFile(path));
  return Array.from(new Uint8Array(digest), (byte) => byte.toString(16).padStart(2, "0")).join("");
}

async function validRuntime(path: string) {
  try {
    return (await Deno.stat(path)).size === pinned.bytes && await sha256(path) === pinned.sha256;
  } catch {
    return false;
  }
}

/** SWAMP_BIN, else the checksum-verified pinned runtime from the shared CI cache. */
export async function swampBinary(): Promise<string> {
  const chosen = Deno.env.get("SWAMP_BIN");
  if (chosen) return chosen;
  const binary = `${cacheRoot()}/swamp-${pinned.version}`;
  if (await validRuntime(binary)) return binary;
  await Deno.mkdir(cacheRoot(), { recursive: true });
  const candidate = `${binary}.${crypto.randomUUID()}.download`;
  try {
    const response = await fetch(
      `https://github.com/swamp-club/swamp/releases/download/v${pinned.version}/swamp-linux-x86_64`,
    );
    if (!response.ok) throw new Error(`Runtime download failed: ${response.status}`);
    await Deno.writeFile(candidate, response.body!, { mode: 0o755 });
    if (!await validRuntime(candidate)) throw new Error("Published runtime checksum mismatch");
    await Deno.rename(candidate, binary);
  } finally {
    await Deno.remove(candidate).catch(() => {});
  }
  return binary;
}

/** One step of a tracked workflow, by step name. */
export function trackedStep(workflow: string, name: string) {
  const value = parse(Deno.readTextFileSync(`${ROOT}/workflows/workflow-${workflow}.yaml`)) as Workflow;
  const step = value.jobs.flatMap((job) => job.steps).find((step) => step.name === name);
  if (!step) throw new Error(`${workflow} has no step ${name}`);
  return step as { name: string; task: { inputs: Record<string, unknown> } };
}

const isolatedEnv = (extra: Record<string, string>) => {
  const env = Deno.env.toObject();
  for (
    const key of [
      "SWAMP_SERVER_URL",
      "SWAMP_SERVE_URL",
      "SWAMP_SERVER_TOKEN",
      "SWAMP_SERVER_TOKEN_FILE",
      "SWAMP_REPO_DIR",
      "SWAMP_CA_CERT",
      "SWAMP_EXTENSIONS_DIR",
    ]
  ) delete env[key];
  return { ...env, SWAMP_NO_UPDATE_CHECK: "1", SWAMP_NO_TELEMETRY: "1", ...extra };
};

const decoder = new TextDecoder();

const upstream = JSON.parse(Deno.readTextFileSync(`${ROOT}/extensions/models/upstream_extensions.json`)) as Record<
  string,
  { version: string }
>;

/** Where a pinned registry extension is cached; CI fills it from the credentialed pull job. */
const pulledDirectory = (name: string) => `${cacheRoot()}/pulled/${name.replace("/", "__")}@${upstream[name].version}`;

/** Pull a pinned registry extension once per cache; repos then copy the pulled tree. */
export async function pullExtension(name: string) {
  const directory = pulledDirectory(name);
  try {
    await Deno.stat(`${directory}/extensions/models/upstream_extensions.json`);
    return directory;
  } catch {
    // Pull below.
  }
  await Deno.mkdir(`${cacheRoot()}/pulled`, { recursive: true });
  // Pull beside the cache entry, so the final rename never crosses a filesystem.
  const scratch = await Deno.makeTempDir({ dir: `${cacheRoot()}/pulled`, prefix: ".pull-" });
  await Deno.copyFile(`${ROOT}/.swamp.yaml`, `${scratch}/.swamp.yaml`);
  const result = await new Deno.Command(await swampBinary(), {
    args: ["extension", "pull", `${name}@${upstream[name].version}`, "--json"],
    cwd: scratch,
    env: isolatedEnv({}),
    clearEnv: true,
    stdout: "piped",
    stderr: "piped",
  }).output();
  if (!result.success) {
    await Deno.remove(scratch, { recursive: true });
    throw new Error(`extension pull ${name} failed: ${decoder.decode(result.stdout)}${decoder.decode(result.stderr)}`);
  }
  await Deno.rename(scratch, directory).catch(async () => await Deno.remove(scratch, { recursive: true }));
  return directory;
}

async function copyTree(from: string, to: string, skip: (name: string) => boolean = () => false) {
  await Deno.mkdir(to, { recursive: true });
  for await (const entry of Deno.readDir(from)) {
    if (skip(entry.name)) continue;
    const source = `${from}/${entry.name}`;
    const target = `${to}/${entry.name}`;
    if (entry.isDirectory) await copyTree(source, target, skip);
    else if (entry.isSymlink) await Deno.symlink(await Deno.readLink(source), target);
    else await Deno.copyFile(source, target);
  }
}

async function* files(directory: string): AsyncGenerator<string> {
  for await (const entry of Deno.readDir(directory)) {
    const path = `${directory}/${entry.name}`;
    if (entry.isDirectory) yield* files(path);
    else if (entry.isFile) yield path;
  }
}

function contains(haystack: Uint8Array, needle: Uint8Array) {
  outer: for (let index = 0; index + needle.length <= haystack.length; index++) {
    for (let offset = 0; offset < needle.length; offset++) {
      if (haystack[index + offset] !== needle[offset]) continue outer;
    }
    return true;
  }
  return false;
}

const SignerBehaviors = ["orbit-certificate-signing-http", '${{ "certificate-sign" }}'];

const isTestFile = (name: string) => /_test\.(ts|py)$|_fixture\.ts$/.test(name);

export class NativeRepo {
  /** Extra environment for the native process, such as a PATH with fake tools. */
  env: Record<string, string> = {};
  /** Private values that must never reach native stdout, stderr or any scratch file. */
  secrets: string[] = [];
  /** Keep the actual certificate signing command instead of the fixed fixture. */
  realCertificateSigning = false;
  timeoutMs = 60_000;

  constructor(readonly dir: string) {}

  copyBehaviors(...families: string[]) {
    return Promise.all(families.map((family) =>
      // Tests and their fixtures stay out: their literal fixture values would trip the private-bytes scan.
      copyTree(`${ROOT}/extensions/behaviors/${family}`, `${this.dir}/extensions/behaviors/${family}`, isTestFile)
    ));
  }

  /** Install a pinned registry extension, such as `@swamp/ssh`, at its upstream_extensions.json version. */
  async pull(name: string) {
    const source = await pullExtension(name);
    await copyTree(`${source}/.swamp/pulled-extensions/${name}`, `${this.dir}/.swamp/pulled-extensions/${name}`);
    const path = `${this.dir}/extensions/models/upstream_extensions.json`;
    const current = await Deno.readTextFile(path).then(JSON.parse, () => ({}));
    await Deno.mkdir(`${this.dir}/extensions/models`, { recursive: true });
    await Deno.writeTextFile(path, JSON.stringify({ ...current, [name]: upstream[name] }));
  }

  /** Copy a tracked workflow into the repo without placement labels, optionally edited. */
  async workflow(name: string, edit: (workflow: Workflow) => void = () => {}) {
    const workflow = parse(await Deno.readTextFile(`${ROOT}/workflows/workflow-${name}.yaml`)) as Workflow;
    for (const job of workflow.jobs) {
      delete job.labels;
      for (const step of job.steps) delete step.labels;
    }
    edit(workflow);
    await Deno.writeTextFile(`${this.dir}/workflows/workflow-${name}.yaml`, stringify(workflow));
    return workflow;
  }

  /** Write an executable fake tool in TypeScript and return its path. */
  async fakeBinary(name: string, source: string) {
    const path = `${this.dir}/${name}`;
    await Deno.writeTextFile(path, `#!/usr/bin/env -S ${Deno.execPath()} run -A --quiet --no-config --no-lock\n${source}`, {
      mode: 0o755,
    });
    return path;
  }

  async cli(args: string[], options: { success?: boolean; input?: string } = {}) {
    if (!this.realCertificateSigning) await this.useFixtureSigner();
    const child = new Deno.Command(await swampBinary(), {
      args: [...args, "--json"],
      cwd: this.dir,
      env: isolatedEnv(this.env),
      clearEnv: true,
      stdin: options.input === undefined ? "null" : "piped",
      stdout: "piped",
      stderr: "piped",
      signal: AbortSignal.timeout(this.timeoutMs),
    }).spawn();
    if (options.input !== undefined) {
      const writer = child.stdin.getWriter();
      await writer.write(new TextEncoder().encode(options.input));
      await writer.close();
    }
    const result = await child.output();
    const stdout = decoder.decode(result.stdout);
    const stderr = decoder.decode(result.stderr);
    await this.assertPrivateAbsent(stdout, stderr);
    assertEquals(result.success, options.success ?? true, stdout + stderr);
    return { stdout, stderr, code: result.code };
  }

  /** Start a CLI command without waiting for it, such as a run that a test cancels. */
  async spawn(args: string[]) {
    if (!this.realCertificateSigning) await this.useFixtureSigner();
    return new Deno.Command(await swampBinary(), {
      args: [...args, "--json"],
      cwd: this.dir,
      env: isolatedEnv(this.env),
      clearEnv: true,
      stdout: "piped",
      stderr: "piped",
    }).spawn();
  }

  /** Run a workflow and return its run record, history and step statuses by name. */
  async runWorkflow(name: string, inputs: unknown, options: { success?: boolean } = {}) {
    const run = JSON.parse(
      (await this.cli(["workflow", "run", name, "--input", JSON.stringify(inputs)], options)).stdout,
    ) as Run;
    return { run, ...await this.history(run.id) };
  }

  /** Native history of a run id, or of a workflow name's newest run, with step statuses by name. */
  async history(runOrWorkflow: string) {
    const history = JSON.parse((await this.cli(["workflow", "history", "get", runOrWorkflow])).stdout) as History;
    const steps: Record<string, string> = {};
    for (const job of history.jobs) for (const step of job.steps) steps[step.name] = step.status;
    return { history, steps };
  }

  /** Replace a workflow with a one-step `command/shell` stub that accepts any input and runs `command`. */
  async stubWorkflow(name: string, command: string) {
    await Deno.writeTextFile(
      `${this.dir}/workflows/workflow-${name}.yaml`,
      stringify({
        id: crypto.randomUUID(),
        name,
        inputs: { type: "object", additionalProperties: true },
        jobs: [{
          name: "fixture",
          steps: [{
            name: "result",
            task: {
              type: "behavior",
              behaviorType: "command/shell",
              behaviorName: '${{ "fixture-"+run.id }}',
              methodName: "execute",
              inputs: { run: command },
            },
          }],
        }],
      }),
    );
  }

  /** Scan outputs and every scratch file, including native history, logs and bundles. */
  async assertPrivateAbsent(...outputs: string[]) {
    const needles = this.secrets.map((value) => new TextEncoder().encode(value));
    if (needles.length === 0) return;
    for (const output of outputs) {
      assert(!this.secrets.some((value) => output.includes(value)), "Private bytes in native stdout/stderr");
    }
    const overlap = Math.max(...needles.map((needle) => needle.length)) - 1;
    for await (const path of files(this.dir)) {
      using file = await Deno.open(path);
      let tail = new Uint8Array();
      const block = new Uint8Array(1024 * 1024);
      for (let read; (read = await file.read(block)) !== null;) {
        const data = new Uint8Array(tail.length + read);
        data.set(tail);
        data.set(block.subarray(0, read), tail.length);
        assert(
          !needles.some((needle) => contains(data, needle)),
          `Private bytes in scratch file: ${path.slice(this.dir.length + 1)}`,
        );
        tail = overlap ? data.slice(-overlap) : new Uint8Array();
      }
    }
  }

  /** Family tests use the fixed fixture signer; only the HTTPS test runs the real command. */
  private async useFixtureSigner() {
    for await (const entry of Deno.readDir(`${this.dir}/workflows`)) {
      if (!entry.name.endsWith(".yaml")) continue;
      const path = `${this.dir}/workflows/${entry.name}`;
      const workflow = parse(await Deno.readTextFile(path)) as Workflow;
      let changed = false;
      for (const job of workflow.jobs ?? []) {
        for (const step of job.steps ?? []) {
          const task = (step.task ?? {}) as { behaviorName?: string; inputs?: Record<string, unknown> };
          if (SignerBehaviors.includes(task.behaviorName ?? "")) {
            task.inputs = {
              ...task.inputs,
              run: '${{ model["orbit-certificate-signing-http"].input.globalArguments.run }}',
            };
            changed = true;
          }
        }
      }
      if (changed) await Deno.writeTextFile(path, stringify(workflow));
    }
  }

  async [Symbol.asyncDispose]() {
    await Deno.remove(this.dir, { recursive: true });
  }
}

/** A disposable native repo: `await using repo = await nativeRepo();` */
export async function nativeRepo() {
  const dir = await Deno.makeTempDir({ prefix: "orbit-native-", dir: Deno.env.get("TMPDIR") });
  await Deno.copyFile(`${ROOT}/.swamp.yaml`, `${dir}/.swamp.yaml`);
  await Deno.mkdir(`${dir}/workflows`);
  await Deno.mkdir(`${dir}/tests/_support`, { recursive: true });
  await Deno.copyFile(`${ROOT}/tests/_support/native_direct.ts`, `${dir}/tests/_support/native_direct.ts`);
  await Deno.mkdir(`${dir}/models/@nckrtl/certificates`, { recursive: true });
  const model = parse(
    await Deno.readTextFile(`${ROOT}/models/@nckrtl/certificates/orbit-certificate-signing-http.yaml`),
  ) as { globalArguments: Record<string, unknown> };
  model.globalArguments.run = `printf '%s\\n' '{"certificates":[]}'`;
  await Deno.writeTextFile(`${dir}/models/@nckrtl/certificates/fixture-http.yaml`, stringify(model));
  return new NativeRepo(dir);
}

/** CI's native tests job pulls every pinned extension once, then prints the runtime path for SWAMP_BIN. */
if (import.meta.main) {
  for (const name of Object.keys(upstream)) console.log(await pullExtension(name));
  console.log(await swampBinary());
}
