import { z } from "npm:zod@4.4.3";
import { type SystemCommand, systemCommand } from "./system.ts";

import {
  Account,
  type Artifacts,
  Context,
  Id,
  legacyScheduleUnits,
  Path,
  paths,
  type PublicInput,
  PublicInstall,
  renderScheduleUnits,
} from "./contract.ts";
export { renderScheduleUnits } from "./contract.ts";
const Command = z.string().min(1).max(4096).regex(/^[^\0\r\n]+$/);
const Install = PublicInstall.extend({ command: Command.optional() });
const Remove = z.union([
  Install.extend({ command: Command, cascade: z.boolean() }),
  z.object({ id: Id, group: Account, cascade: z.boolean() }).strict(),
]);
export type ScheduleCommand =
  | "schedule.install"
  | "schedule.remove";

class Refused extends Error {
  constructor(readonly code: string) {
    super("Schedule system action refused");
  }
}
function refuse(code = "schedule.failed"): never {
  throw new Refused(code);
}
async function commandHash(command: string) {
  const bytes = await crypto.subtle.digest(
    "SHA-256",
    new TextEncoder().encode(command),
  );
  return Array.from(
    new Uint8Array(bytes),
    (b) => b.toString(16).padStart(2, "0"),
  ).join("");
}

/** Exact prior layouts are recognized only to replace them, never to execute them. */
function obsoleteArtifacts(
  input: z.infer<typeof Install> & { command: string },
) {
  const old = legacyScheduleUnits(PublicInstall.parse(publicInput(input)));
  return { ...old, script: old.script_prefix + input.command + "\n" };
}

type Metadata = {
  type: string;
  uid: number;
  gid: number;
  mode: number;
  links: number;
  size: number;
};
class Host {
  constructor(private run: SystemCommand, private signal?: AbortSignal) {}

  beginMutation() {
    this.signal?.throwIfAborted();
    this.signal = undefined;
  }

  system(program: string, args: string[], stdin?: string, timeout = 15_000) {
    return this.run(
      "/usr/bin/sudo",
      ["-n", "--", program, ...args],
      stdin,
      this.signal,
      timeout,
    );
  }
  async checked(program: string, args: string[], stdin?: string) {
    const result = await this.system(program, args, stdin);
    if (result.code !== 0) refuse();
    return result.stdout;
  }
  async metadata(path: string): Promise<Metadata | null> {
    const result = await this.system("/usr/bin/stat", [
      "--format=%F|%u|%g|%a|%h|%s",
      "--",
      path,
    ]);
    if (result.code !== 0) {
      const present = await this.system("/usr/bin/test", ["-e", path]);
      const link = await this.system("/usr/bin/test", ["-L", path]);
      if (present.code === 1 && link.code === 1) return null;
      refuse();
    }
    const fields = result.stdout.trim().split("|");
    if (
      fields.length !== 6 ||
      !fields.slice(1).every((v) => /^\d+$/.test(v))
    ) {
      refuse();
    }
    return {
      type: fields[0],
      uid: Number(fields[1]),
      gid: Number(fields[2]),
      mode: parseInt(fields[3], 8),
      links: Number(fields[4]),
      size: Number(fields[5]),
    };
  }
  async directory(path: string) {
    const info = await this.metadata(path);
    if (
      info &&
      (info.type !== "directory" || info.uid !== 0 || info.gid !== 0 ||
        (info.mode & 0o022) !== 0)
    ) refuse("schedule.artifact_conflict");
    return info !== null;
  }
  async inspectDirectories() {
    for (
      const path of [
        "/etc/orbit",
        "/etc/orbit/schedules",
        "/etc/orbit/schedule-candidates",
        "/etc/systemd/system",
      ]
    ) await this.directory(path);
  }
  async owned(
    path: string,
    gid: number | undefined,
    mode: number,
    marker: string,
  ) {
    const info = await this.metadata(path);
    if (info === null) return null;
    if (
      info.type !== "regular file" || info.uid !== 0 ||
      (gid !== undefined && info.gid !== gid) || info.mode !== mode ||
      info.links !== 1 || info.size > 65_536
    ) refuse("schedule.artifact_conflict");
    const content = await this.checked("/usr/bin/cat", ["--", path]);
    if (!content.split("\n").includes(marker)) {
      refuse("schedule.artifact_conflict");
    }
    return content;
  }
  async group(name: string) {
    const value = (await this.checked("/usr/bin/getent", ["group", name]))
      .trim().split(":");
    if (value[0] !== name || !/^\d+$/.test(value[2] ?? "")) {
      refuse("schedule.target_unavailable");
    }
    return Number(value[2]);
  }
  async runnable(
    user: string,
    directory: string,
    home?: string,
    shell?: string,
  ) {
    const account = (await this.checked("/usr/bin/getent", ["passwd", user]))
      .trim().split(":");
    if (
      account[0] !== user || !/^\d+$/.test(account[2] ?? "") ||
      Number(account[2]) === 0 ||
      (home !== undefined && account[5] !== home) ||
      (shell !== undefined && account[6] !== shell)
    ) refuse("schedule.target_unavailable");
    for (const flag of ["-d", "-x"]) {
      const result = await this.run(
        "/usr/bin/sudo",
        ["-n", "-u", user, "--", "/usr/bin/test", flag, directory],
        undefined,
        this.signal,
        5_000,
      );
      if (result.code !== 0) refuse("schedule.target_unavailable");
    }
  }
  async systemctl(...args: string[]) {
    return (await this.system(
      "/usr/bin/systemctl",
      args,
      undefined,
      45_000,
    ))
      .code === 0;
  }
  async state(query: string, unit: string) {
    const result = await this.system("/usr/bin/systemctl", [query, unit]);
    const value = result.stdout.trim();
    if (!/^[a-z-]+$/.test(value)) refuse();
    return value;
  }
  async timerState(unit: string) {
    return {
      enabled: await this.state("is-enabled", unit) === "enabled",
      active: await this.state("is-active", unit) === "active",
    };
  }
  async settle(unit: string, enabled: boolean) {
    if (
      !await this.systemctl(enabled ? "enable" : "disable", "--now", unit)
    ) {
      return false;
    }
    const state = await this.timerState(unit);
    return state.enabled === enabled && state.active === enabled;
  }
  async restoreState(
    unit: string,
    state: { enabled: boolean; active: boolean },
  ) {
    return await this.systemctl(
      state.enabled ? "enable" : "disable",
      unit,
    ) &&
      await this.systemctl(state.active ? "start" : "stop", unit);
  }
  async write(path: string, content: string, gid: number, mode: number) {
    const directory = path.slice(0, path.lastIndexOf("/"));
    const temporary = (await this.checked("/usr/bin/mktemp", [
      "--tmpdir=" + directory,
      ".orbit-schedule-XXXXXXXXXX",
    ])).trim();
    if (
      !temporary.startsWith(directory + "/.orbit-schedule-") ||
      !/^[-/.a-zA-Z0-9]+$/.test(temporary)
    ) refuse();
    try {
      await this.checked(
        "/usr/bin/dd",
        ["of=" + temporary, "status=none"],
        content,
      );
      await this.checked("/usr/bin/chown", ["0:" + gid, "--", temporary]);
      await this.checked("/usr/bin/chmod", [
        mode.toString(8),
        "--",
        temporary,
      ]);
      await this.checked("/usr/bin/mv", ["-T", "--", temporary, path]);
    } finally {
      await this.system("/usr/bin/rm", ["-f", "--", temporary]);
    }
  }
  async remove(path: string) {
    await this.checked("/usr/bin/rm", ["-f", "--", path]);
  }
}

async function readArtifacts(host: Host, id: string, gid?: number) {
  const p = paths(id);
  return {
    script: await host.owned(
      p.script,
      gid,
      0o750,
      `# X-Orbit-Schedule-ID=${id}`,
    ),
    callback: await host.owned(
      p.callback,
      0,
      0o700,
      `# X-Orbit-Schedule-ID=${id}`,
    ),
    service: await host.owned(
      p.service,
      0,
      0o644,
      `X-Orbit-Schedule-ID=${id}`,
    ),
    timer: await host.owned(p.timer, 0, 0o644, `X-Orbit-Schedule-ID=${id}`),
  };
}

function publicInput(input: z.infer<typeof Install>): PublicInput {
  const { command: _command, ...value } = input;
  return PublicInstall.parse(value);
}

function installedIntent(
  id: string,
  service: string | null,
  timer: string | null,
) {
  if (service === null || timer === null) {
    refuse("schedule.artifact_conflict");
  }
  const field = (text: string, key: string) => {
    const values = text.split("\n").filter((line) =>
      line.startsWith(key + "=")
    );
    if (values.length !== 1) refuse("schedule.artifact_conflict");
    return values[0].slice(key.length + 1);
  };
  const context = Context.parse(
    JSON.parse(field(service, "X-Orbit-Execution-Context")),
  );
  const intent = PublicInstall.parse({
    ...context,
    id,
    command_hash: field(service, "X-Orbit-Command-SHA256"),
    timeout_seconds: Number(field(service, "X-Orbit-Timeout-Seconds")),
    calendar: field(timer, "OnCalendar").replaceAll("%%", "%"),
    desired: "disabled",
  });
  const expected = renderScheduleUnits(intent);
  if (service !== expected.service || timer !== expected.timer) {
    refuse("schedule.artifact_conflict");
  }
  return intent;
}

function validateObsolete(
  input: z.infer<typeof Install>,
  previous: Awaited<ReturnType<typeof readArtifacts>>,
  expected: Artifacts,
) {
  if (input.command === undefined) refuse("schedule.artifact_conflict");
  const old = obsoleteArtifacts({ ...input, command: input.command });
  const publicUnit = previous.service === expected.service;
  const agentUnit = previous.service === old.agentService &&
    previous.callback === null;
  const directUnit = previous.service === old.directService &&
    previous.callback === old.callback;
  if (
    previous.timer !== old.timer ||
    (!publicUnit && !agentUnit && !directUnit) ||
    (publicUnit
      ? previous.script !== null && previous.script !== old.script
      : previous.script !== old.script) ||
    (previous.callback !== null && previous.callback !== old.callback)
  ) {
    refuse("schedule.artifact_conflict");
  }
}

async function install(host: Host, input: z.infer<typeof Install>) {
  const p = paths(input.id);
  if (
    input.command !== undefined &&
    await commandHash(input.command) !== input.command_hash
  ) {
    refuse("schedule.artifact_conflict");
  }
  await host.runnable(
    input.user,
    input.working_directory,
    input.home,
    input.shell,
  );
  const gid = await host.group(input.group);
  await host.inspectDirectories();
  const previous = await readArtifacts(host, input.id, gid);
  const expected = renderScheduleUnits(publicInput(input));
  const obsolete = previous.script !== null || previous.callback !== null;
  if (obsolete) {
    validateObsolete(input, previous, expected);
  } else {
    for (const key of ["service", "timer"] as const) {
      if (previous[key] !== null && previous[key] !== expected[key]) {
        refuse("schedule.artifact_conflict");
      }
    }
  }
  if (!obsolete && previous.service !== null && previous.timer !== null) {
    return;
  }
  const marker = `X-Orbit-Schedule-ID=${input.id}`;
  for (const candidate of [p.candidateService, p.candidateTimer]) {
    await host.owned(candidate, 0, 0o644, marker);
  }
  if (
    (await host.system("/usr/bin/systemd-analyze", [
      "calendar",
      input.calendar,
    ])).code !== 0
  ) {
    refuse("schedule.calendar_invalid");
  }
  if (
    obsolete &&
    !["inactive", "failed", "unknown"].includes(
      await host.state("is-active", p.serviceUnit),
    )
  ) {
    refuse("schedule.run_active");
  }
  const state = previous.timer === null
    ? { enabled: false, active: false }
    : await host.timerState(p.timerUnit);
  host.beginMutation();
  for (
    const [directory, mode] of [["/etc/orbit", "711"], [
      "/etc/orbit/schedule-candidates",
      "700",
    ]]
  ) {
    await host.checked("/usr/bin/install", [
      "-d",
      "-o",
      "0",
      "-g",
      "0",
      "-m",
      mode,
      "--",
      directory,
    ]);
  }
  try {
    await host.write(p.candidateService, expected.service, 0, 0o644);
    await host.write(p.candidateTimer, expected.timer, 0, 0o644);
    if (
      !await host.systemctl("disable", "--now", p.timerUnit) &&
      previous.timer !== null
    ) refuse();
    if (
      (await host.system(
        "/usr/bin/systemd-analyze",
        ["verify", p.candidateService, p.candidateTimer],
        undefined,
        30_000,
      )).code !== 0
    ) refuse();
    await host.checked("/usr/bin/mv", [
      "-T",
      "--",
      p.candidateService,
      p.service,
    ]);
    await host.checked("/usr/bin/mv", [
      "-T",
      "--",
      p.candidateTimer,
      p.timer,
    ]);
    if (!await host.systemctl("daemon-reload")) refuse();
    if (obsolete) {
      if (!await host.restoreState(p.timerUnit, state)) refuse();
      const restored = await host.timerState(p.timerUnit);
      if (
        restored.enabled !== state.enabled ||
        restored.active !== state.active
      ) refuse();
      // Only the exact prior private artifacts validated above can be removed.
      if (previous.script !== null) await host.remove(p.script);
      if (previous.callback !== null) await host.remove(p.callback);
    } else if (
      !await host.settle(p.timerUnit, input.desired === "enabled")
    ) {
      refuse();
    }
  } catch (error) {
    try {
      if (
        !await host.systemctl("disable", "--now", p.timerUnit) &&
        previous.timer !== null
      ) refuse();
      for (
        const key of ["service", "timer", "script", "callback"] as const
      ) {
        const content = previous[key];
        if (content === null) await host.remove(p[key]);
        else {await host.write(
            p[key],
            content,
            key === "script" ? gid : 0,
            key === "script" ? 0o750 : key === "callback" ? 0o700 : 0o644,
          );}
      }
      if (!await host.systemctl("daemon-reload")) refuse();
      if (
        previous.timer !== null &&
        !await host.restoreState(p.timerUnit, state)
      ) refuse();
    } catch {
      refuse("schedule.rollback_failed");
    }
    throw error;
  } finally {
    await host.remove(p.candidateService);
    await host.remove(p.candidateTimer);
  }
}

async function remove(host: Host, input: z.infer<typeof Remove>) {
  await host.inspectDirectories();
  const gid = await host.group(input.group);
  const previous = await readArtifacts(host, input.id, gid);
  const obsolete = previous.script !== null || previous.callback !== null;
  if (obsolete) {
    if (!("user" in input)) refuse("schedule.artifact_conflict");
    if (await commandHash(input.command) !== input.command_hash) {
      refuse("schedule.artifact_conflict");
    }
    const { cascade: _cascade, ...installation } = input;
    const expected = renderScheduleUnits(publicInput(installation));
    validateObsolete(installation, previous, expected);
  } else if (previous.service !== null || previous.timer !== null) {
    const intent = installedIntent(
      input.id,
      previous.service,
      previous.timer,
    );
    if (intent.group !== input.group) refuse("schedule.artifact_conflict");
    if ("user" in input) {
      const { cascade: _cascade, ...installation } = input;
      const expected = renderScheduleUnits(publicInput(installation));
      if (
        previous.service !== expected.service ||
        previous.timer !== expected.timer ||
        await commandHash(input.command) !== input.command_hash
      ) refuse("schedule.artifact_conflict");
    }
  }
  const p = paths(input.id);
  if (Object.values(previous).every((v) => v === null)) return;
  if (
    !input.cascade &&
    !["inactive", "failed", "unknown"].includes(
      await host.state("is-active", p.serviceUnit),
    )
  ) refuse("schedule.run_active");
  host.beginMutation();
  if (
    !await host.systemctl("disable", "--now", p.timerUnit) &&
    previous.timer !== null
  ) refuse();
  if (
    input.cascade && previous.service !== null &&
    !await host.systemctl("stop", p.serviceUnit)
  ) refuse();
  for (const key of ["timer", "service", "script", "callback"] as const) {
    if (previous[key] !== null) await host.remove(p[key]);
  }
  if (!await host.systemctl("daemon-reload")) refuse();
  await host.systemctl("reset-failed", p.serviceUnit);
  await host.systemctl("reset-failed", p.timerUnit);
}

/** Fixed Schedule actions. Private command source is used only on protected stdin. */
export async function scheduleSystem(
  name: ScheduleCommand,
  input: Record<string, unknown>,
  signal?: AbortSignal,
  run: SystemCommand = systemCommand,
): Promise<Record<string, unknown>> {
  const host = new Host(run, signal);
  try {
    switch (name) {
      case "schedule.install":
        await install(host, Install.parse(input));
        break;
      case "schedule.remove":
        await remove(host, Remove.parse(input));
        break;
    }
    return {};
  } catch (error) {
    return {
      error_code: error instanceof Refused ? error.code : "schedule.failed",
    };
  }
}

/** Read the actual local account used by a parent installation, without guessing its group. */
export async function lookupScheduleAccount(
  user: string,
  signal?: AbortSignal,
  run: SystemCommand = systemCommand,
): Promise<
  {
    user: string;
    uid: number;
    gid: number;
    group: string;
    home: string;
    shell: string;
  }
> {
  Account.parse(user);
  const host = new Host(run, signal);
  const fields = (await host.checked("/usr/bin/getent", ["passwd", user]))
    .trim().split(":");
  if (
    fields.length !== 7 || fields[0] !== user ||
    !/^[0-9]+$/.test(fields[2]) || Number(fields[2]) <= 0 ||
    !/^[0-9]+$/.test(fields[3])
  ) refuse("schedule.target_unavailable");
  const group = (await host.checked("/usr/bin/getent", ["group", fields[3]]))
    .trim().split(":");
  if (group.length !== 4 || group[2] !== fields[3]) {
    refuse("schedule.target_unavailable");
  }
  return {
    user,
    uid: Number(fields[2]),
    gid: Number(fields[3]),
    group: Account.parse(group[0]),
    home: Path.parse(fields[5]),
    shell: Path.parse(fields[6]),
  };
}
