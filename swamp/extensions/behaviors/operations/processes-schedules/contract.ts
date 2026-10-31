import templates from "./unit-templates.json" with { type: "json" };
import { z } from "npm:zod@4.4.3";

export const Account = z.string().regex(/^[a-z_][a-z0-9_-]{0,255}$/);
export const Path = z.string().startsWith("/").max(4096).regex(/^[^\p{Cc}]+$/u);
export const Id = z.string().regex(
  /^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/,
);
export const Context = z.object({
  user: Account,
  group: Account,
  home: Path,
  working_directory: Path,
  shell: Path,
  login_shell: z.boolean(),
}).strict();
export const Digest = z.string().regex(/^[a-f0-9]{64}$/);
export const PublicInstall = Context.extend({
  id: Id,
  command_hash: Digest,
  calendar: z.string().min(1).max(255).regex(/^[\x20-\x7e]+$/),
  timeout_seconds: z.number().int().min(1).max(86400),
  desired: z.enum(["enabled", "disabled"]),
}).strict();
export function paths(id: string) {
  const service = `orbit-schedule-${id}.service`;
  const timer = `orbit-schedule-${id}.timer`;
  return {
    script: `/etc/orbit/schedules/${id}.sh`,
    callback: `/etc/orbit/schedules/${id}.complete`,
    service: `/etc/systemd/system/${service}`,
    timer: `/etc/systemd/system/${timer}`,
    serviceUnit: service,
    timerUnit: timer,
    candidateService: `/etc/orbit/schedule-candidates/${service}`,
    candidateTimer: `/etc/orbit/schedule-candidates/${timer}`,
  };
}
export type PublicInput = z.infer<typeof PublicInstall>;
export type Artifacts = Record<"service" | "timer", string>;

export function contextOf(input: z.infer<typeof Context>) {
  return Context.parse({
    user: input.user,
    group: input.group,
    home: input.home,
    working_directory: input.working_directory,
    shell: input.shell,
    login_shell: input.login_shell,
  });
}

/** Render inert unit-file data. No transport or host action occurs here. */
function render(template: string, input: PublicInput, direct = false) {
  const escape = (value: string) =>
    value.replaceAll("\\", "\\\\").replaceAll('"', '\\"').replaceAll("%", "%%");
  const environment = (value: string) =>
    direct ? escape(value).replaceAll("$", "$$") : escape(value);
  const values: Record<string, string> = {
    id: input.id,
    command_hash: input.command_hash,
    user: input.user,
    group: input.group,
    context: JSON.stringify(contextOf(input)),
    home: environment(input.home),
    shell: environment(input.shell),
    working_directory: direct
      ? input.working_directory.replaceAll("\\", "\\\\").replaceAll("%", "%%")
      : input.working_directory.replaceAll("%", "%%"),
    calendar: input.calendar.replaceAll("%", "%%"),
    timeout_seconds: String(input.timeout_seconds),
    login_shell: input.login_shell ? " --login" : "",
  };
  return template.replace(
    /\{\{([a-z_]+)\}\}/g,
    (_match, name: string) => values[name],
  );
}

export function renderScheduleUnits(value: PublicInput): Artifacts {
  const input = PublicInstall.parse(value);
  return {
    service: render(templates.service, input),
    timer: render(templates.timer, input),
  };
}

/** Exact obsolete data is recognized only for removal, never execution. */
export function legacyScheduleUnits(value: PublicInput) {
  const input = PublicInstall.parse(value);
  return {
    script_prefix: render(templates.script_prefix, input),
    callback: render(templates.callback, input),
    timer: render(templates.timer, input),
    agentService: render(templates.agentService, input),
    directService: render(templates.directService, input, true),
  };
}
