import { z } from "npm:zod@4.4.3";
import {
  metricsTransport,
  runMetricsProcess,
  type Runner,
} from "./metrics_system.ts";
import { DockerEngine } from "../_lib/docker_engine.ts";

import {
  hashLabel,
  image,
  labels,
  metricsPaths,
  metricsRuntimeSpec as spec,
  type MetricsService,
  name,
  services,
  volume,
} from "./metrics_runtime_spec.ts";
export { metricsPaths, type MetricsService } from "./metrics_runtime_spec.ts";
const backup = (s: MetricsService) => `${name(s)}-orbit-rollback`;
export class MetricsRuntimeError extends Error {
  constructor(readonly code: string) {
    super(code);
  }
}
class Unknown extends Error {
  constructor() {
    super("metrics.outcome_unknown");
  }
}
const fail = (code: string): never => {
  throw new MetricsRuntimeError(`metrics.${code}`);
};

// Fixed local filesystem action, not an agent/broker. Input and snapshots stay callback-local.
const filesProgram = String.raw`
import base64,json,os,pathlib,stat,sys,tempfile
v=json.load(sys.stdin);root=pathlib.Path(v.get('root','/'));base='/etc/orbit/metrics';marker=base+'/.orbit-owner'
paths=${JSON.stringify(metricsPaths)}
dirs=[base,base+'/grafana',base+'/grafana/provisioning',base+'/grafana/provisioning/datasources',base+'/grafana/provisioning/dashboards',base+'/grafana/dashboards']
def p(path):return root/path.lstrip('/')
def check():
 for path in dirs+paths+[marker]:
  current=root
  for part in pathlib.Path(path).parts[1:]:
   current=current/part
   try:st=current.lstat()
   except FileNotFoundError:break
   if stat.S_ISLNK(st.st_mode) or (current!=p(path) and not stat.S_ISDIR(st.st_mode)):raise ValueError()
   if current==p(path) and (stat.S_ISDIR(st.st_mode) if path in dirs else stat.S_ISREG(st.st_mode))==False:raise ValueError()
 if p(base).exists() and (not p(marker).is_file() or p(marker).read_bytes()!=b'metrics\n'):raise ValueError()
def remove():
 for path in paths+[marker]:p(path).unlink(missing_ok=True)
 for path in reversed(dirs):
  try:p(path).rmdir()
  except (FileNotFoundError,OSError):pass
def write(path,data,mode,uid,gid):
 fd,tmp=tempfile.mkstemp(prefix='.orbit-',dir=p(path).parent)
 try:
  with os.fdopen(fd,'wb') as out:
   out.write(data);os.fchown(out.fileno(),uid,gid);os.fchmod(out.fileno(),mode);out.flush();os.fsync(out.fileno())
  os.replace(tmp,p(path))
 finally:pathlib.Path(tmp).unlink(missing_ok=True)
try:
 check();op=v['operation'];out={}
 if op=='snapshot':
  out={'existed':p(base).exists(),'files':{}}
  for path in paths:
   try:
    st=p(path).stat()
    if st.st_size>4194304:raise ValueError()
    out['files'][path]={'bytes':base64.b64encode(p(path).read_bytes()).decode(),'mode':stat.S_IMODE(st.st_mode),'uid':st.st_uid,'gid':st.st_gid}
   except FileNotFoundError:out['files'][path]=None
 elif op=='publish':
  for path in dirs:p(path).mkdir(parents=True,exist_ok=True);os.chown(p(path),0,0);os.chmod(p(path),0o755)
  write(marker,b'metrics\n',0o640,0,0)
  for path in paths:
   secret=path==paths[-1];write(path,v['configuration'][path].encode(),0o400 if secret else 0o644,472 if secret else 0,472 if secret else 0)
 elif op=='restore':
  for path in paths:
   state=v['snapshot']['files'][path]
   if state is None:p(path).unlink(missing_ok=True)
   else:write(path,base64.b64decode(state['bytes']),state['mode'],state['uid'],state['gid'])
  if not v['snapshot']['existed']:remove()
 elif op=='remove':remove()
 else:raise ValueError()
 print(json.dumps(out))
except ValueError:print(json.dumps({'error_code':'metrics.configuration_ownership_drift'}))
except Exception:print(json.dumps({'error_code':'metrics.configuration_'+{'snapshot':'snapshot_failed','publish':'publish_failed','restore':'rollback_failed','remove':'remove_failed'}.get(v.get('operation'),'input_invalid')}))
`;
export type MetricsRuntimeDeps = {
  run?: Runner;
  engine?: Pick<DockerEngine, "call">;
  fetch?: typeof fetch;
  sleep?: (ms: number) => Promise<void>;
  /** Disposable filesystem fixtures only; production always uses /. */
  root?: string;
};
type Progress = {
  service: MetricsService;
  stopped: boolean;
  renamed: boolean;
  started: boolean;
};

export function metricsRuntimeActions(deps: MetricsRuntimeDeps = {}) {
  const run = deps.run ?? runMetricsProcess;
  const request = deps.fetch ?? fetch;
  const sleep = deps.sleep ??
    ((ms) => new Promise<void>((resolve) => setTimeout(resolve, ms)));
  const docker = async (
    args: string[],
    input?: string,
    timeoutMs = 45000,
  ) => {
    try {
      const result = await (deps.engine ?? new DockerEngine(metricsTransport))
        .call(
          args,
          undefined,
          timeoutMs,
          input,
        );
      return { code: result.success ? 0 : 1, stdout: result.stdout };
    } catch {
      throw new Unknown();
    }
  };
  const execute = async (args: string[], code: string) => {
    if ((await docker(args)).code !== 0) fail(code);
  };
  const files = async (
    operation: string,
    value: Record<string, unknown> = {},
  ) => {
    let response;
    try {
      response = await run("/usr/bin/python3", ["-c", filesProgram], {
        input: JSON.stringify({
          operation,
          ...value,
          ...(deps.root ? { root: deps.root } : {}),
        }),
        timeoutMs: 60000,
        maxOutputBytes: 48 * 1024 * 1024,
      });
    } catch {
      throw new Unknown();
    }
    if (response.code !== 0) throw new Unknown();
    const result = JSON.parse(response.stdout) as Record<string, unknown>;
    if (typeof result.error_code === "string") {
      throw new MetricsRuntimeError(result.error_code);
    }
    return result;
  };
  const inspect = async (
    service: MetricsService,
    isVolume = false,
    isBackup = false,
  ): Promise<Record<string, string> | null> => {
    const kind = isVolume ? "volume" : "container",
      resource = isVolume
        ? volume(service)
        : isBackup
        ? backup(service)
        : name(service);
    const result = await docker([
      kind,
      "inspect",
      `--format={{json .${isVolume ? "Labels" : "Config.Labels"}}}`,
      "--",
      resource,
    ]);
    if (result.code === 0) {
      let found: unknown;
      try {
        found = JSON.parse(result.stdout);
      } catch {
        fail(`${kind}_inspection_invalid`);
      }
      if (found === null) found = {};
      if (
        typeof found !== "object" || Array.isArray(found) ||
        Object.values(found as object).some((v) => typeof v !== "string")
      ) fail(`${kind}_inspection_invalid`);
      const owned = found as Record<string, string>;
      if (
        Object.entries(labels(service, isVolume)).some(([k, v]) =>
          owned[k] !== v
        )
      ) fail(`${kind}_ownership_drift`);
      return owned;
    }
    const absence = await docker([
      kind,
      "ls",
      ...(isVolume ? [] : ["--all"]),
      "--filter",
      `name=^${isVolume ? "" : "/"}${resource}$`,
      `--format={{.${isVolume ? "Name" : "Names"}}}`,
    ]);
    if (absence.code === 0 && absence.stdout.trim() === "") return null;
    return fail(`${kind}_inspection_failed`);
  };
  const healthy = async (service: MetricsService, wait = false) => {
    for (let i = 0; i < (wait ? 30 : 1); i++) {
      const state = await docker([
        "container",
        "inspect",
        "--format={{.State.Status}} {{.State.Health.Status}}",
        "--",
        name(service),
      ]);
      if (state.code !== 0) return false;
      if (state.stdout.trim() === "running healthy") return true;
      if (
        !wait || state.stdout.trim() !== "running starting" || i === 29
      ) {
        return false;
      }
      await sleep(2000);
    }
    return false;
  };
  const removeContainer = (resource: string) =>
    execute(
      ["container", "rm", "--force", "--volumes", "--", resource],
      "container_remove_failed",
    );
  const rename = (from: string, to: string) =>
    execute(
      ["container", "rename", from, to],
      "container_convergence_failed",
    );
  const start = (resource: string) =>
    execute(
      ["container", "start", "--", resource],
      "container_convergence_failed",
    );
  const converge = async (
    address: string,
    assignment: number,
    configuration: Record<string, string>,
  ) => {
    if (
      !Number.isInteger(assignment) || assignment <= 0 ||
      Object.keys(configuration).length !== metricsPaths.length ||
      metricsPaths.some((path) => typeof configuration[path] !== "string") ||
      !configuration[metricsPaths[7]]
    ) fail("runtime_input_invalid");
    const snapshot = await files("snapshot");
    const specs = await Promise.all(
      services.map((s) => spec(s, assignment, address, configuration)),
    );
    const states = [];
    for (const service of services) {
      states.push({
        current: await inspect(service),
        backup: await inspect(service, false, true),
        volume: await inspect(service, true),
      });
    }
    for (const service of services) {
      if (
        (await docker([
          "image",
          "inspect",
          "--format={{.Id}}",
          "--",
          image(service),
        ])).code === 0
      ) continue;
      await execute(
        ["info", "--format={{.ServerVersion}}"],
        "docker_unavailable",
      );
      const pulled = await docker(
        ["image", "pull", "--", image(service)],
        undefined,
        300000,
      );
      if (pulled.code !== 0) {
        fail(
          [124, 137].includes(pulled.code)
            ? "image_pull_timed_out"
            : "image_pull_failed",
        );
      }
    }
    const validation = await docker(
      [
        "container",
        "run",
        "--rm",
        "--interactive",
        "--entrypoint",
        "/bin/promtool",
        "--",
        image("prometheus"),
        "check",
        "config",
        "/dev/stdin",
      ],
      configuration[metricsPaths[0]],
      60000,
    );
    if (validation.code !== 0) {
      fail(
        [124, 137].includes(validation.code)
          ? "prometheus_configuration_check_timed_out"
          : "prometheus_configuration_invalid",
      );
    }
    const progress: Progress[] = services.map((service) => ({
      service,
      stopped: false,
      renamed: false,
      started: false,
    }));
    const volumes: MetricsService[] = [];
    try {
      await files("publish", { configuration });
      for (let i = 0; i < specs.length; i++) {
        const desired = specs[i],
          service = desired.service,
          state = states[i],
          done = progress[i];
        if (!state.current && state.backup) {
          await rename(backup(service), name(service));
          await start(name(service));
          state.current = state.backup;
          state.backup = null;
        }
        if (state.current && state.backup) {
          await removeContainer(backup(service));
        }
        if (!state.volume) {
          const args = ["volume", "create"];
          for (
            const [key, value] of Object.entries(
              labels(service, true),
            ).sort()
          ) args.push("--label", `${key}=${value}`);
          const created = await docker([
            ...args,
            "--",
            volume(service),
          ]);
          if (created.code !== 0 && !await inspect(service, true)) {
            fail("container_convergence_failed");
          }
          volumes.push(service);
        }
        if (
          state.current?.[hashLabel] === desired.hash &&
          await healthy(service)
        ) continue;
        if (state.current) {
          const stopped = await docker([
            "container",
            "stop",
            "--time",
            "30",
            "--",
            name(service),
          ]);
          if (stopped.code !== 0) {
            await inspect(service);
            const actual = await docker([
              "container",
              "inspect",
              "--format={{.State.Running}}",
              "--",
              name(service),
            ]);
            if (
              actual.code !== 0 ||
              actual.stdout.trim() !== "false"
            ) {
              fail("container_convergence_failed");
            }
          }
          done.stopped = true;
          const renamed = await docker([
            "container",
            "rename",
            name(service),
            backup(service),
          ]);
          if (
            renamed.code !== 0 &&
            (await inspect(service) ||
              !await inspect(service, false, true))
          ) fail("container_convergence_failed");
          done.renamed = true;
        }
        const created = await docker(desired.args);
        if (
          created.code !== 0 &&
          (await inspect(service))?.[hashLabel] !== desired.hash
        ) fail("container_convergence_failed");
        done.started = true;
        if (!await healthy(service, true)) {
          fail("container_convergence_failed");
        }
      }
    } catch (error) {
      if (error instanceof Unknown) throw error; // Preserve recovery resources when Docker cannot establish the outcome.
      let recovery = true;
      try {
        for (const done of [...progress].reverse()) {
          if (done.started && await inspect(done.service)) {
            await removeContainer(name(done.service));
          }
          if (done.renamed) {
            await rename(backup(done.service), name(done.service));
          }
          if (done.stopped) await start(name(done.service));
        }
        for (const service of [...volumes].reverse()) {
          await execute(
            ["volume", "rm", "--", volume(service)],
            "container_rollback_failed",
          );
        }
      } catch {
        recovery = false;
      }
      try {
        await files("restore", { snapshot });
      } catch {
        fail("runtime_rollback_failed");
      }
      if (!recovery) fail("container_rollback_failed");
      throw error;
    }
    // Both containers are healthy: cleanup failure must not restore old configuration.
    for (const done of progress) {
      if (done.renamed) {
        try {
          await removeContainer(backup(done.service));
        } catch {
          if (await inspect(done.service, false, true)) {
            fail("container_cleanup_failed");
          }
        }
      }
    }
  };
  const remove = async (purge: boolean) => {
    await files("snapshot");
    const states = [];
    for (const service of services) {
      states.push({
        service,
        current: await inspect(service),
        backup: await inspect(service, false, true),
        volume: purge && await inspect(service, true),
      });
    }
    for (const state of states) {
      if (state.current) await removeContainer(name(state.service));
      if (state.backup) await removeContainer(backup(state.service));
    }
    await files("remove");
    for (const state of states) {
      if (state.volume) {
        await execute(
          ["volume", "rm", "--", volume(state.service)],
          "volume_purge_failed",
        );
      }
    }
  };
  return {
    async runtime(
      input: { address: string; action: Record<string, unknown> },
    ): Promise<Record<string, unknown>> {
      try {
        if (!z.ipv4().safeParse(input.address).success) {
          fail("wireguard_ip_missing");
        }
        if (input.action.operation === "converge") {
          await converge(
            input.address,
            input.action.assignment as number,
            input.action.configuration as Record<string, string>,
          );
        } else if (
          input.action.operation === "remove" &&
          typeof input.action.purge_data === "boolean"
        ) await remove(input.action.purge_data);
        else fail("runtime_input_invalid");
        return { changed: true };
      } catch (error) {
        if (error instanceof MetricsRuntimeError) {
          return { error_code: error.code };
        }
        throw error;
      }
    },
    async health(service: MetricsService) {
      try {
        return {
          healthy: await inspect(service) !== null &&
            await healthy(service),
        };
      } catch {
        return { healthy: false };
      }
    },
    async credential(
      input: { address: string; action: Record<string, unknown> },
    ): Promise<Record<string, unknown>> {
      if (!z.ipv4().safeParse(input.address).success) {
        return { error_code: "metrics.wireguard_ip_missing" };
      }
      const apply = input.action.operation === "apply";
      const password = apply
        ? input.action.active_password
        : input.action.password;
      const pending = input.action.pending_password;
      if (
        !["apply", "verify"].includes(String(input.action.operation)) ||
        typeof password !== "string" || !password ||
        (apply && (typeof pending !== "string" || !pending))
      ) return { error_code: "metrics.runtime_input_invalid" };
      try {
        const response = await request(
          `http://${input.address}:3000${
            apply ? "/api/admin/users/1/password" : "/api/user"
          }`,
          {
            method: apply ? "PUT" : "GET",
            redirect: "manual",
            signal: AbortSignal.timeout(30000),
            headers: {
              Authorization: `Basic ${btoa("admin:" + password)}`,
              ...(apply ? { "Content-Type": "application/json" } : {}),
            },
            ...(apply ? { body: JSON.stringify({ password: pending }) } : {}),
          },
        );
        await response.body?.cancel();
        if (response.status >= 500) throw new Unknown();
        if (
          !apply && !response.ok &&
          ![401, 403].includes(response.status)
        ) {
          throw new Unknown();
        }
        return apply
          ? response.ok
            ? { changed: true }
            : { error_code: "metrics.credentials_reset_failed" }
          : { verified: response.ok };
      } catch {
        throw new Unknown();
      }
    },
  };
}
