import { z } from "npm:zod@4.4.3";
import { DockerEngine } from "../_lib/docker_engine.ts";
import { type SystemCommand, systemCommand } from "./system.ts";

const controls = (value: string) =>
  [...value].some((character) =>
    character.charCodeAt(0) < 32 || character.charCodeAt(0) === 127
  );
const Id = z.number().int().positive();
const Digest = z.string().regex(/^[a-f0-9]{64}$/);
const Text = z.string().max(65_000).refine((value) => !value.includes("\0"));
const Path = z.string().startsWith("/").max(4096).refine((value) =>
  !controls(value) &&
  !value.split("/").some((part) => part === "." || part === "..")
);
const Artifact = z.object({
  process_id: Id,
  runtime: z.enum(["systemd", "docker"]),
  name: z.string().max(255),
}).strict();
const Control = Artifact.extend({
  operation: z.enum(["start", "stop", "restart"]),
  on_demand: z.boolean(),
});
const Stage = z.object({
  process_id: Id,
  unit: z.string().max(255),
  content: Text,
  environment: Text,
}).strict();
const Activate = z.object({
  process_id: Id,
  unit: z.string().max(255),
  change: z.enum(["create", "replace", "reload"]),
  desired: z.enum(["running", "stopped"]),
  on_demand: z.boolean(),
}).strict();
const Remove = Artifact.extend({
  anonymous_volumes: z.boolean().optional(),
  vite: z.object({ path: Path, instance_id: Id }).strict().nullable(),
});
const Container = z.object({
  process_id: Id,
  container: z.string().max(255),
  desired: z.enum(["running", "stopped"]),
  spec: Digest,
  image: z.string().max(4096).regex(/^[A-Za-z0-9][A-Za-z0-9._/:@-]*$/),
  command: z.array(Text).min(1).max(4096),
  restart: z.enum(["no", "on-failure", "always", "unless-stopped"]),
  working_directory: Path.nullable(),
  ports: z.array(z.string().max(100)).max(4096),
  mounts: z.array(
    z.object({
      source: z.string().min(1).max(4096).refine((s) =>
        !/[,=]/.test(s) && !controls(s)
      ),
      target: Path.refine((s) => !/[,=]/.test(s)),
      read_only: z.boolean(),
    }).strict(),
  ).max(4096),
  environment: z.record(
    z.string().regex(/^[A-Za-z_][A-Za-z0-9_]*$/),
    Text.refine((s) => !/[\r\n]/.test(s)),
  ),
  expected_spec: Digest.nullable().optional(),
  expected_running: z.boolean().nullable().optional(),
  health: z.object({
    url: z.url().refine((s) => /^https?:\/\//.test(s) && !controls(s)),
    attempts: z.number().int().min(1).max(90),
    interval_seconds: z.number().int().min(0).max(2),
  }).strict().nullable().optional(),
}).strict().refine((input) => !input.health || input.desired === "running");

const Units = "/etc/systemd/system";
const Candidates = "/etc/orbit/systemd-candidates";
const Environments = "/etc/orbit/processes";
const Inspect = '{{ index .Config.Labels "orbit.managed" }}{{ printf "\\n" }}' +
  '{{ index .Config.Labels "orbit.container.kind" }}{{ printf "\\n" }}' +
  '{{ index .Config.Labels "orbit.process.id" }}{{ printf "\\n" }}' +
  '{{ index .Config.Labels "orbit.process.spec" }}{{ printf "\\n" }}{{ .State.Running }}';

export type ProcessSystemCommand =
  | "process.stage-unit"
  | "process.activate-unit"
  | "process.converge-container"
  | "process.control"
  | "process.remove"
  | "process.check-release"
  | "process.status";

class Refused extends Error {
  constructor(readonly code: string) {
    super(code);
  }
}
function refuse(code = "process.invalid"): never {
  throw new Refused(code);
}
function name(runtime: string, id: number, value: string) {
  if (
    !new RegExp(
      `^orbit-process-${id}-[a-z0-9](?:[a-z0-9-]*[a-z0-9])?` +
        (runtime === "systemd" ? "\\.service$" : "$"),
    ).test(value)
  ) refuse();
}
function marked(content: string, id: number) {
  return content.split(/\r?\n/).includes(`X-Orbit-Process-ID=${id}`);
}
async function digest(content: string) {
  return Array.from(
    new Uint8Array(
      await crypto.subtle.digest("SHA-256", new TextEncoder().encode(content)),
    ),
    (byte) => byte.toString(16).padStart(2, "0"),
  ).join("");
}
type Meta = {
  type: number;
  uid: number;
  gid: number;
  mode: number;
  links: number;
};
type OwnedContainer = { spec: string; running: boolean };
type ContainerInput = z.infer<typeof Container>;
export type BeforeContainerReplacement = () => Promise<Record<string, unknown>>;

function publishedPorts(values: string[]) {
  const ports: Record<string, { HostIp: string; HostPort: string }[]> = {};
  for (const value of values) {
    const match = /^(.*?)(?:\/(tcp|udp|sctp))?$/.exec(value);
    if (!match) refuse();
    const [, binding, protocol] = match;
    let host = "";
    let remainder = binding;
    if (binding.startsWith("[")) {
      const address = /^\[([a-fA-F0-9:]+)\]:(.*)$/.exec(binding);
      if (!address) refuse();
      host = address[1];
      remainder = address[2];
    }
    const parts = remainder.split(":");
    if (parts.length === 3 && !host) {
      host = parts.shift()!;
      if (
        !/^(?:\d{1,3}\.){3}\d{1,3}$/.test(host) ||
        host.split(".").some((part) => Number(part) > 255)
      ) refuse();
    }
    if (parts.length > 2 || parts.length < 1) refuse();
    const containerPort = parts.pop()!;
    const hostPort = parts.pop() ?? "";
    if (
      !/^\d+$/.test(containerPort) || Number(containerPort) < 1 ||
      Number(containerPort) > 65535 ||
      (hostPort &&
        (!/^\d+$/.test(hostPort) || Number(hostPort) < 1 ||
          Number(hostPort) > 65535))
    ) refuse();
    const key = `${containerPort}/${protocol ?? "tcp"}`;
    (ports[key] ??= []).push({ HostIp: host, HostPort: hostPort });
  }
  return ports;
}

/** Fixed system actions only. The enclosing native model lock owns serialization. */
class ProcessSystem {
  private engine: DockerEngine;

  constructor(
    private command: SystemCommand,
    private signal?: AbortSignal,
    private sleep = (ms: number) =>
      new Promise<void>((resolve) => setTimeout(resolve, ms)),
    private beforeContainerReplacement?: BeforeContainerReplacement,
  ) {
    this.engine = new DockerEngine(async (input, signal) => {
      const reply = await this.command(
        input.command,
        input.args,
        input.stdin,
        signal,
        input.timeoutMs,
      );
      return { success: reply.code === 0, stdout: reply.stdout };
    });
  }

  private run(
    program: string,
    args: readonly string[],
    stdin?: string,
    timeout = 300_000,
  ) {
    if (program === "/usr/bin/docker") {
      return this.engine.call(args, this.signal, timeout).then((reply) => ({
        code: reply.success ? 0 : 1,
        stdout: reply.stdout,
      }));
    }
    return this.command(
      "/usr/bin/sudo",
      ["-n", "--", program, ...args],
      stdin,
      this.signal,
      timeout,
    );
  }
  private async must(
    program: string,
    args: readonly string[],
    code: string,
    stdin?: string,
    timeout?: number,
  ) {
    const reply = await this.run(program, args, stdin, timeout);
    if (reply.code !== 0) refuse(code);
    return reply.stdout;
  }
  private async test(flag: string, path: string) {
    const reply = await this.run("/usr/bin/test", [flag, path]);
    if (reply.code !== 0 && reply.code !== 1) refuse("process.converge_failed");
    return reply.code === 0;
  }
  private async meta(path: string): Promise<Meta | null> {
    if (!await this.test("-e", path) && !await this.test("-L", path)) {
      return null;
    }
    const value = await this.must("/usr/bin/stat", [
      "--printf",
      "%f:%u:%g:%a:%h",
      "--",
      path,
    ], "process.converge_failed");
    const fields = value.split(":");
    if (
      fields.length !== 5 || fields.some((part) => !/^[a-f0-9]+$/.test(part))
    ) refuse();
    const [type, uid, gid, mode, links] = fields;
    return {
      type: parseInt(type, 16) & 0xf000,
      uid: Number(uid),
      gid: Number(gid),
      mode: parseInt(mode, 8),
      links: Number(links),
    };
  }
  private owned(meta: Meta, type: number, mode?: number) {
    if (
      meta.type !== type || meta.uid !== 0 || meta.gid !== 0 ||
      (type === 0x8000 && meta.links !== 1) ||
      (mode !== undefined ? meta.mode !== mode : (meta.mode & 0o022) !== 0)
    ) refuse();
  }
  private async directory(path: string, privateMode = true) {
    const current = await this.meta(path);
    if (current) {
      this.owned(current, 0x4000);
      if (privateMode && current.mode !== 0o700) {
        await this.must(
          "/usr/bin/chmod",
          ["0700", "--", path],
          "process.converge_failed",
        );
      }
    } else {
      const parent = path.slice(0, path.lastIndexOf("/"));
      const owner = await this.meta(parent);
      if (!owner) refuse();
      this.owned(owner, 0x4000);
      await this.must("/usr/bin/mkdir", [
        "--mode",
        privateMode ? "0700" : "0755",
        "--",
        path,
      ], "process.converge_failed");
    }
  }
  private async read(path: string) {
    return await this.must(
      "/usr/bin/cat",
      ["--", path],
      "process.converge_failed",
    );
  }
  private async removeFile(path: string) {
    if (await this.meta(path)) {
      await this.must("/usr/bin/rm", ["--", path], "process.remove_failed");
    }
  }
  private async write(path: string, content: string, mode: string) {
    const previous = await this.meta(path);
    if (previous) this.owned(previous, 0x8000);
    const directory = path.slice(0, path.lastIndexOf("/"));
    const temporary = (await this.must("/usr/bin/mktemp", [
      "--tmpdir=" + directory,
      ".orbit-process-XXXXXXXXXX",
    ], "process.converge_failed")).trim();
    if (
      !temporary.startsWith(directory + "/.orbit-process-") ||
      !/^[A-Za-z0-9]{10}$/.test(
        temporary.slice((directory + "/.orbit-process-").length),
      )
    ) refuse();
    try {
      const staged = await this.meta(temporary);
      if (!staged) refuse();
      this.owned(staged, 0x8000, 0o600);
      await this.must(
        "/usr/bin/dd",
        ["of=" + temporary, "status=none"],
        "process.converge_failed",
        content,
      );
      await this.must(
        "/usr/bin/chmod",
        [mode, "--", temporary],
        "process.converge_failed",
      );
      await this.must(
        "/usr/bin/sync",
        ["-f", temporary],
        "process.converge_failed",
      );
      await this.rename(temporary, path);
      await this.must(
        "/usr/bin/sync",
        ["-f", directory],
        "process.converge_failed",
      );
    } finally {
      await this.run("/usr/bin/rm", ["--force", "--", temporary]);
    }
  }
  private async rename(from: string, to: string) {
    await this.must("/usr/bin/mv", [
      "--force",
      "--no-target-directory",
      "--",
      from,
      to,
    ], "process.converge_failed");
  }
  private async ownedUnit(
    id: number,
    unit: string,
    directory = Units,
  ): Promise<string | null> {
    const path = `${directory}/${unit}`;
    const meta = await this.meta(path);
    if (!meta) return null;
    this.owned(meta, 0x8000);
    const content = await this.read(path);
    if (!marked(content, id)) refuse("process.name_collision");
    return content;
  }
  private async container(
    id: number,
    artifact: string,
  ): Promise<OwnedContainer | null> {
    const present = await this.run("/usr/bin/docker", [
      "container",
      "ls",
      "--all",
      "--filter",
      `name=^/${artifact}$`,
      "--format",
      "{{.Names}}",
    ]);
    if (present.code !== 0) refuse("process.converge_failed");
    if (present.stdout.trim() === "") return null;
    if (present.stdout.trim() !== artifact) refuse("process.name_collision");
    const text = await this.must("/usr/bin/docker", [
      "container",
      "inspect",
      "--format",
      Inspect,
      artifact,
    ], "process.status_failed");
    const [managed, kind, owner, spec, running, ...extra] = text.trim().split(
      "\n",
    );
    if (managed !== "true" || kind !== "process" || owner !== String(id)) {
      refuse("process.name_collision");
    }
    if (
      extra.length || !/^[a-f0-9]{64}$/.test(spec) ||
      !["true", "false"].includes(running)
    ) refuse("process.status_failed");
    return { spec, running: running === "true" };
  }
  async status(input: z.infer<typeof Artifact>) {
    name(input.runtime, input.process_id, input.name);
    if (input.runtime === "systemd") {
      if (await this.ownedUnit(input.process_id, input.name) === null) {
        return { state: "absent" };
      }
      const result = await this.run("/usr/bin/systemctl", [
        "is-active",
        input.name,
      ]);
      const state = result.stdout.trim();
      if (
        ![
          "active",
          "reloading",
          "inactive",
          "failed",
          "activating",
          "deactivating",
          "maintenance",
        ].includes(state)
      ) refuse("process.status_failed");
      return { state };
    }
    if (!await this.container(input.process_id, input.name)) {
      return { state: "absent" };
    }
    const state = (await this.must("/usr/bin/docker", [
      "container",
      "inspect",
      "--format",
      "{{.State.Status}}",
      input.name,
    ], "process.status_failed")).trim();
    if (!/^[a-z][a-z_-]{0,63}$/.test(state)) refuse("process.status_failed");
    return { state };
  }
  async control(input: z.infer<typeof Control>) {
    name(input.runtime, input.process_id, input.name);
    const present = input.runtime === "systemd"
      ? await this.ownedUnit(input.process_id, input.name)
      : await this.container(input.process_id, input.name);
    if (!present) refuse("process.not_found");
    const error = `process.${input.operation}_failed`;
    if (input.runtime === "docker") {
      await this.must("/usr/bin/docker", [
        "container",
        input.operation,
        input.name,
      ], error);
    } else if (input.operation === "stop") {
      await this.must(
        "/usr/bin/systemctl",
        ["disable", "--now", input.name],
        error,
      );
    } else if (input.operation === "start") {
      await this.must(
        "/usr/bin/systemctl",
        input.on_demand
          ? ["start", input.name]
          : ["enable", "--now", input.name],
        error,
      );
    } else {
      if (!input.on_demand) {
        await this.must("/usr/bin/systemctl", ["enable", input.name], error);
      }
      await this.must("/usr/bin/systemctl", ["restart", input.name], error);
    }
    return {};
  }

  private async installEnvironment(id: number, unit: string, content: string) {
    const hash = await digest(content);
    if (
      !content.startsWith(`# Orbit Process ${id}\n`) ||
      !unit.split(/\r?\n/).includes(
        `EnvironmentFile=${Environments}/${id}/${hash}.env`,
      )
    ) refuse();
    await this.directory("/etc/orbit", false);
    await this.directory(Environments);
    await this.directory(`${Environments}/${id}`);
    const path = `${Environments}/${id}/${hash}.env`;
    const current = await this.meta(path);
    if (current) {
      this.owned(current, 0x8000, 0o600);
      if (await this.read(path) !== content) refuse();
    } else {
      const pending = `${Environments}/${id}/.environment.pending`;
      const previous = await this.meta(pending);
      if (previous) this.owned(previous, 0x8000, 0o600);
      await this.write(pending, content, "0600");
      await this.rename(pending, path);
      await this.must(
        "/usr/bin/sync",
        ["-f", `${Environments}/${id}`],
        "process.converge_failed",
      );
    }
  }
  async stage(input: z.infer<typeof Stage>) {
    name("systemd", input.process_id, input.unit);
    const current = await this.ownedUnit(input.process_id, input.unit);
    if (!marked(input.content, input.process_id)) refuse();
    await this.installEnvironment(
      input.process_id,
      input.content,
      input.environment,
    );
    if (current === input.content) {
      const observed = await this.status({
        process_id: input.process_id,
        runtime: "systemd",
        name: input.unit,
      });
      return { staged: false, previous: true, state: observed.state };
    }
    await this.directory(Candidates);
    const candidate = `${Candidates}/${input.unit}`;
    const existing = await this.ownedUnit(
      input.process_id,
      input.unit,
      Candidates,
    );
    if (existing !== null && !marked(existing, input.process_id)) {
      refuse("process.name_collision");
    }
    await this.write(candidate, input.content, "0644");
    const verified = await this.run("/usr/bin/systemd-analyze", [
      "verify",
      candidate,
    ]);
    if (verified.code !== 0) {
      await this.removeFile(candidate);
      refuse("process.candidate_invalid");
    }
    return { staged: true, previous: current !== null, state: null };
  }
  private async reload(code: string) {
    await this.must("/usr/bin/systemctl", ["daemon-reload"], code);
  }
  private async restoreUnit(input: z.infer<typeof Activate>) {
    if (input.change === "replace") {
      await this.rename(
        `${Candidates}/.${input.unit}.backup`,
        `${Units}/${input.unit}`,
      );
    } else await this.removeFile(`${Units}/${input.unit}`);
    await this.reload("process.rollback_failed");
  }
  async activate(input: z.infer<typeof Activate>) {
    name("systemd", input.process_id, input.unit);
    const current = await this.ownedUnit(input.process_id, input.unit);
    const backup = `${Candidates}/.${input.unit}.backup`;
    if (input.change === "reload") {
      if (current === null) refuse("process.not_found");
      await this.reload("process.activation_failed");
    } else {
      const candidate = await this.ownedUnit(
        input.process_id,
        input.unit,
        Candidates,
      );
      if (
        candidate === null ||
        (input.change === "replace") !== (current !== null)
      ) refuse("process.recovery_required");
      if (input.change === "replace") {
        const old = await this.meta(backup);
        if (old) {
          this.owned(old, 0x8000, 0o600);
          if (!marked(await this.read(backup), input.process_id)) {
            refuse("process.name_collision");
          }
        }
        // Old units may still contain inline secrets. A backup is always private.
        await this.write(backup, current!, "0600");
      }
      await this.rename(
        `${Candidates}/${input.unit}`,
        `${Units}/${input.unit}`,
      );
      const reloaded = await this.run("/usr/bin/systemctl", ["daemon-reload"]);
      if (reloaded.code !== 0) {
        await this.restoreUnit(input);
        refuse("process.activation_failed");
      }
    }
    let activated;
    if (input.desired === "stopped") {
      activated = await this.run("/usr/bin/systemctl", [
        "disable",
        "--now",
        input.unit,
      ]);
    } else {
      activated = input.on_demand
        ? { code: 0, stdout: "" }
        : await this.run("/usr/bin/systemctl", ["enable", input.unit]);
      if (activated.code === 0) {
        activated = await this.run("/usr/bin/systemctl", [
          "restart",
          input.unit,
        ]);
      }
    }
    if (activated.code !== 0) {
      if (input.change !== "reload") {
        await this.must(
          "/usr/bin/systemctl",
          ["disable", "--now", input.unit],
          "process.recovery_required",
        );
        await this.restoreUnit(input);
      }
      if (input.change === "replace") {
        const args = input.desired === "stopped"
          ? ["disable", "--now", input.unit]
          : input.on_demand
          ? ["start", input.unit]
          : ["enable", "--now", input.unit];
        await this.must("/usr/bin/systemctl", args, "process.rollback_failed");
      }
      refuse(
        input.desired === "running"
          ? "process.start_failed"
          : "process.stop_failed",
      );
    }
    let backup_left = false;
    if (input.change === "replace") {
      try {
        await this.removeFile(backup);
      } catch {
        backup_left = true;
      }
    }
    return { backup_left };
  }

  private async environmentRemoval(id: number) {
    const root = await this.meta(Environments);
    if (!root) return [];
    this.owned(root, 0x4000, 0o700);
    const owner = `${Environments}/${id}`;
    const meta = await this.meta(owner);
    if (!meta) return [];
    this.owned(meta, 0x4000, 0o700);
    const entries = await this.must("/usr/bin/find", [
      owner,
      "-mindepth",
      "1",
      "-maxdepth",
      "1",
      "-printf",
      "%f\\0",
    ], "process.remove_failed");
    const paths: string[] = [];
    for (const entry of entries.split("\0").filter(Boolean)) {
      const temporary = /^\.orbit-process-[A-Za-z0-9]{10}$/.test(entry);
      if (
        entry !== ".environment.pending" && !temporary &&
        !/^[a-f0-9]{64}\.env$/.test(entry)
      ) refuse();
      const path = `${owner}/${entry}`;
      const file = await this.meta(path);
      if (!file) refuse();
      this.owned(file, 0x8000, 0o600);
      if (entry !== ".environment.pending" && !temporary) {
        const content = await this.read(path);
        if (
          !content.startsWith(`# Orbit Process ${id}\n`) ||
          entry !== `${await digest(content)}.env`
        ) refuse();
      }
      paths.push(path);
    }
    return paths;
  }
  async remove(input: z.infer<typeof Remove>) {
    name(input.runtime, input.process_id, input.name);
    if (input.runtime === "docker") {
      if (input.vite !== null) refuse();
      const present: string[] = [];
      for (
        const suffix of [
          "-candidate",
          "",
          "-rollback-running",
          "-rollback-stopped",
        ]
      ) {
        const artifact = input.name + suffix;
        if (await this.container(input.process_id, artifact)) {
          present.push(artifact);
        }
      }
      for (const artifact of present) {
        await this.docker(
          [
            "container",
            "rm",
            "--force",
            ...(input.anonymous_volumes ? ["--volumes"] : []),
            artifact,
          ],
          "process.remove_failed",
        );
      }
      return {};
    }
    const environments = await this.environmentRemoval(input.process_id);
    const unit = await this.ownedUnit(input.process_id, input.name);
    const candidate = await this.ownedUnit(
      input.process_id,
      input.name,
      Candidates,
    );
    const backupPath = `${Candidates}/.${input.name}.backup`;
    const backup = await this.meta(backupPath);
    if (backup) {
      this.owned(backup, 0x8000, 0o600);
      if (!marked(await this.read(backupPath), input.process_id)) {
        refuse("process.name_collision");
      }
    }
    const vitePaths: string[] = [];
    if (input.vite) {
      if (
        input.vite.path !==
          `/etc/orbit/vite/app-instance-${input.vite.instance_id}.env`
      ) refuse();
      for (const path of [input.vite.path, `${input.vite.path}.pending`]) {
        const file = await this.meta(path);
        if (file) {
          this.owned(file, 0x8000);
          if (
            !(await this.read(path)).split("\n").includes(
              `# Orbit Instance ${input.vite.instance_id}`,
            )
          ) refuse("process.vite_cleanup_failed");
          vitePaths.push(path);
        }
      }
    }
    // All owned artifacts are validated before the first stop or deletion.
    if (unit !== null) {
      await this.must(
        "/usr/bin/systemctl",
        ["disable", "--now", input.name],
        "process.remove_failed",
      );
      await this.removeFile(`${Units}/${input.name}`);
      await this.reload("process.remove_failed");
      await this.run("/usr/bin/systemctl", ["reset-failed", input.name]);
    }
    if (candidate !== null) {
      await this.removeFile(`${Candidates}/${input.name}`);
    }
    if (backup) await this.removeFile(backupPath);
    for (const path of [...environments, ...vitePaths]) {
      await this.removeFile(path);
    }
    if (await this.meta(`${Environments}/${input.process_id}`)) {
      await this.must("/usr/bin/rmdir", [
        "--",
        `${Environments}/${input.process_id}`,
      ], "process.remove_failed");
    }
    return {};
  }

  private docker(args: readonly string[], code = "process.converge_failed") {
    return this.must("/usr/bin/docker", args, code, undefined, 900_000);
  }
  private async ready(input: ContainerInput) {
    if (!input.health) return;
    for (let i = 0; i < input.health.attempts; i++) {
      const reply = await this.run(
        "/usr/bin/curl",
        [
          "-q",
          "--config",
          "-",
          "--fail",
          "--silent",
          "--max-time",
          "3",
          "--output",
          "/dev/null",
        ],
        `url = ${JSON.stringify(input.health.url)}\n`,
        5000,
      );
      if (reply.code === 0) return;
      if (i + 1 < input.health.attempts) {
        await this.sleep(input.health.interval_seconds * 1000);
      }
    }
    refuse("process.health_failed");
  }
  private async desired(input: ContainerInput, state: OwnedContainer) {
    const running = input.desired === "running";
    if (state.running !== running) {
      await this.docker([
        "container",
        running ? "start" : "stop",
        input.container,
      ], running ? "process.start_failed" : "process.stop_failed");
    }
  }
  private async create(input: ContainerInput, artifact: string) {
    const ports = publishedPorts(input.ports);
    const body = {
      Image: input.image,
      Cmd: input.command,
      Env: Object.entries(input.environment).map(([key, value]) =>
        `${key}=${value}`
      ),
      ...(input.working_directory === null
        ? {}
        : { WorkingDir: input.working_directory }),
      Labels: {
        "orbit.managed": "true",
        "orbit.container.kind": "process",
        "orbit.process.id": String(input.process_id),
        "orbit.process.spec": input.spec,
      },
      ExposedPorts: Object.fromEntries(
        Object.keys(ports).map((key) => [key, {}]),
      ),
      HostConfig: {
        RestartPolicy: { Name: input.restart, MaximumRetryCount: 0 },
        PortBindings: ports,
        Mounts: input.mounts.map((mount) => ({
          Type: mount.source.startsWith("/") ? "bind" : "volume",
          Source: mount.source,
          Target: mount.target,
          ReadOnly: mount.read_only,
        })),
      },
    };
    const image = await this.run("/usr/bin/docker", [
      "image",
      "inspect",
      "--format",
      "{{.Id}}",
      input.image,
    ]);
    if (image.code !== 0) {
      await this.docker(["image", "pull", "--quiet", input.image]);
    }
    // Docker's private command and environment are JSON on protected stdin.
    const reply = await this.engine.create(artifact, body, this.signal);
    if (!reply.success) refuse("process.converge_failed");
    const created = z.object({ Id: z.string().regex(/^[a-f0-9]{64}$/) })
      .safeParse(JSON.parse(reply.stdout));
    if (!created.success) refuse("process.candidate_invalid");
  }
  private async restoreDocker(input: ContainerInput, rollback: string) {
    const running = rollback.endsWith("-rollback-running");
    await this.docker(
      ["container", running ? "start" : "stop", rollback],
      "process.recovery_required",
    );
    await this.docker(
      ["container", "rename", rollback, input.container],
      "process.recovery_required",
    );
  }
  private async cleanupCandidate(input: ContainerInput) {
    const candidate = `${input.container}-candidate`;
    if (await this.container(input.process_id, candidate)) {
      await this.docker(
        ["container", "rm", "--force", candidate],
        "process.recovery_required",
      );
    }
  }
  private async rollbackDocker(input: ContainerInput, rollback: string) {
    const candidate = `${input.container}-candidate`;
    await this.docker(
      ["container", "rename", input.container, candidate],
      "process.recovery_required",
    );
    await this.docker(
      ["container", "stop", candidate],
      "process.recovery_required",
    );
    await this.restoreDocker(input, rollback);
    await this.cleanupCandidate(input);
  }
  async converge(input: ContainerInput) {
    name("docker", input.process_id, input.container);
    publishedPorts(input.ports);
    const canonicalName = input.container;
    const candidateName = `${canonicalName}-candidate`;
    let canonical = await this.container(input.process_id, canonicalName);
    const runningName = `${canonicalName}-rollback-running`;
    const stoppedName = `${canonicalName}-rollback-stopped`;
    const running = await this.container(input.process_id, runningName);
    const stopped = await this.container(input.process_id, stoppedName);
    let candidate = await this.container(input.process_id, candidateName);
    if (running && stopped) refuse("process.recovery_required");
    const previous = running ?? stopped;
    let readyVerified = false;
    if (
      input.expected_spec &&
      ((previous && previous.spec !== input.expected_spec) ||
        (canonical && canonical.spec !== input.expected_spec &&
          canonical.spec !== input.spec) ||
        (!previous && canonical && input.expected_running !== null &&
          input.expected_running !== undefined &&
          canonical.running !== input.expected_running) ||
        (!canonical && !previous))
    ) refuse("process.spec_changed");
    if (previous) {
      const rollback = running ? runningName : stoppedName;
      if (!canonical) {
        if (candidate?.running) {
          await this.docker(
            ["container", "stop", candidateName],
            "process.recovery_required",
          );
        }
        await this.restoreDocker(input, rollback);
        canonical = { ...previous, running: !!running };
      } else {
        if (canonical.spec !== input.spec || candidate) {
          refuse("process.recovery_required");
        }
        if (input.desired === "running" && !canonical.running) {
          await this.rollbackDocker(input, rollback);
          canonical = { ...previous, running: !!running };
        } else {
          await this.desired(input, canonical);
          try {
            await this.ready(input);
          } catch (error) {
            await this.rollbackDocker(input, rollback);
            throw error;
          }
          await this.docker(["container", "rm", "--force", rollback]);
          readyVerified = true;
          canonical = { ...canonical, running: input.desired === "running" };
        }
      }
      candidate = await this.container(input.process_id, candidateName);
    }
    if (canonical?.spec === input.spec) {
      await this.cleanupCandidate(input);
      await this.desired(input, canonical);
      if (!readyVerified) await this.ready(input);
      return {};
    }
    if (!canonical && candidate?.spec === input.spec) {
      await this.docker(["container", "rename", candidateName, canonicalName]);
      await this.desired(input, candidate);
      await this.ready(input);
      return {};
    }
    await this.cleanupCandidate(input);
    const prepared = await this.beforeContainerReplacement?.();
    if (prepared?.error_code !== undefined) {
      return {
        error_code: z.string().regex(/^[a-z0-9_.-]{1,100}$/).parse(
          prepared.error_code,
        ),
      };
    }
    await this.create(input, canonical ? candidateName : canonicalName);
    const created = await this.container(
      input.process_id,
      canonical ? candidateName : canonicalName,
    );
    if (created?.spec !== input.spec) {
      if (canonical) await this.cleanupCandidate(input);
      refuse("process.candidate_invalid");
    }
    if (!canonical) {
      await this.desired(input, created);
      await this.ready(input);
      return {};
    }
    const rollback = canonical.running ? runningName : stoppedName;
    const staged = await this.run("/usr/bin/docker", [
      "container",
      "rename",
      canonicalName,
      rollback,
    ]);
    if (staged.code !== 0) {
      await this.cleanupCandidate(input);
      refuse("process.activation_failed");
    }
    if (canonical.running) {
      const stop = await this.run("/usr/bin/docker", [
        "container",
        "stop",
        rollback,
      ]);
      if (stop.code !== 0) {
        await this.restoreDocker(input, rollback);
        await this.cleanupCandidate(input);
        refuse("process.activation_failed");
      }
    }
    const published = await this.run("/usr/bin/docker", [
      "container",
      "rename",
      candidateName,
      canonicalName,
    ]);
    if (published.code !== 0) {
      await this.restoreDocker(input, rollback);
      await this.cleanupCandidate(input);
      refuse("process.activation_failed");
    }
    try {
      await this.desired(input, created);
      await this.ready(input);
      await this.docker(
        ["container", "rm", "--force", rollback],
        "process.activation_failed",
      );
    } catch (error) {
      await this.rollbackDocker(input, rollback);
      throw error;
    }
    return {};
  }
  async checkRelease(input: { directory: string }) {
    return { available: await this.test("-d", input.directory) };
  }
}

/** Internal typed dispatcher; it accepts no executable, shell or command source. */
export function processSystem(
  command: SystemCommand = systemCommand,
  sleep?: (ms: number) => Promise<void>,
) {
  return async (
    name: ProcessSystemCommand,
    input: Record<string, unknown>,
    signal?: AbortSignal,
    beforeContainerReplacement?: BeforeContainerReplacement,
  ): Promise<Record<string, unknown>> => {
    try {
      const system = new ProcessSystem(
        command,
        signal,
        sleep,
        beforeContainerReplacement,
      );
      switch (name) {
        case "process.stage-unit":
          return await system.stage(Stage.parse(input));
        case "process.activate-unit":
          return await system.activate(Activate.parse(input));
        case "process.converge-container":
          return await system.converge(Container.parse(input));
        case "process.control":
          return await system.control(Control.parse(input));
        case "process.remove":
          return await system.remove(Remove.parse(input));
        case "process.status":
          return await system.status(Artifact.parse(input));
        case "process.check-release":
          return await system.checkRelease(
            z.object({ directory: Path }).strict().parse(input),
          );
        default:
          return { error_code: "process.invalid" };
      }
    } catch (error) {
      if (error instanceof Refused) return { error_code: error.code };
      if (error instanceof z.ZodError) return { error_code: "process.invalid" };
      // A command interruption can follow a partial effect. The DAG owns failure/recovery.
      throw new Error("Process system action did not complete");
    }
  };
}
