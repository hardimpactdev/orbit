import { z } from "npm:zod@4.4.3";
import { gatewayHttp, type Http } from "../../automatic-deploy/gateway.ts";
import {
  DirectArgs,
  type DirectIntent,
  directOperation,
  GatewayOperation,
  Globals,
} from "../_lib/direct.ts";
import {
  DockerMigrationInput,
  DockerMigrationProgram,
  dockerMigrationSystem,
} from "../node/docker_migration_system.ts";
import {
  localSystemCommand,
  type SystemTransport,
} from "../node/system_command.ts";
import { processSystem } from "../processes-schedules/process-system.ts";

const Args = DirectArgs.extend({
  kind: z.literal("fleet.docker_migrate"),
  node_id: z.number().int().positive(),
});
const Input = z.object({
  mode: z.enum(["plan", "migrate", "restore-policies"]),
  migration: DockerMigrationInput,
}).strict();
const Process = z.object({
  process_id: z.number().int().positive(),
  container: z.string(),
  desired: z.enum(["running", "stopped"]),
  spec: z.string().regex(/^[a-f0-9]{64}$/),
}).passthrough();
const Report = z.object({
  healthy: z.boolean(),
  fallback: z.boolean(),
  containers: z.array(
    z.object({
      name: z.string().max(255),
      state: z.string().max(64),
      health: z.string().max(64).nullable(),
    }).strict(),
  ).max(10000),
  process_ids: z.array(z.string().regex(/^[0-9]+$/)).max(10000),
  failed_process_ids: z.array(z.number().int().positive()).max(10000)
    .optional(),
  planned: z.boolean().optional(),
  plan: z.object({
    recipe: z.string(),
    docker_version: z.string(),
    containerd_version: z.string(),
    resuming: z.boolean(),
    source_backups: z.array(z.string()).max(10000),
    fallback_data_root: z.string(),
    metadata_roots: z.record(z.string(), z.string()),
    space: z.object({ sufficient: z.boolean() }).strict(),
  }).strict().optional(),
  error_code: z.enum([
    "docker.migration_failed",
    "docker.migration_unhealthy",
    "docker.process_reconcile_failed",
  ]).optional(),
  stage: z.string().regex(/^[a-z-]{1,80}$/).optional(),
  resumable: z.boolean().optional(),
}).strict();
type Context = { globalArgs: z.infer<typeof Globals>; signal?: AbortSignal };
type Gateway = Pick<
  GatewayOperation,
  "read" | "project" | "withPrivateMaterial"
>;

/** Read fixed OS state using the same reviewed Docker inventory policy as the transaction. */
async function observe(
  mode: "plan" | "report",
  input: z.infer<typeof DockerMigrationInput>,
  invoke: SystemTransport,
  signal?: AbortSignal,
) {
  const script =
    `${DockerMigrationProgram}\n${DockerMigrationObservation}\nobserve(${
      JSON.stringify(mode)
    }, json.loads(${JSON.stringify(JSON.stringify(input))}))\n`;
  const result = await invoke({
    command: "/usr/bin/sudo",
    args: ["-n", "/usr/bin/python3", "-"],
    stdin: script,
    timeoutMs: 180000,
    maxOutputBytes: 2 * 1024 * 1024,
  }, signal);
  signal?.throwIfAborted();
  if (!result.success) {
    throw new Error("Docker observation has no confirmed outcome");
  }
  return Report.parse(JSON.parse(result.stdout));
}

/** One parent owns package migration, placed Process reconciliation and final state verification. */
export async function migrateDocker(
  input: z.infer<typeof Input>,
  gateway: Pick<Gateway, "withPrivateMaterial">,
  signal?: AbortSignal,
  dependencies = {
    migrate: dockerMigrationSystem,
    reconcile: processSystem(),
    observe: (
      mode: "plan" | "report",
      data: z.infer<typeof DockerMigrationInput>,
      signal?: AbortSignal,
    ) => observe(mode, data, localSystemCommand, signal),
  },
) {
  let outcome: z.infer<typeof Report> | undefined;
  await gateway.withPrivateMaterial(async (material) => {
    const processes = z.array(Process).max(10000).parse(material.processes);
    if (
      processes.length !== input.migration.process_ids.length ||
      new Set(processes.map((p) => p.process_id)).size !== processes.length ||
      processes.some((p) => {
        const id = String(p.process_id);
        const admitted = input.migration.process_specs[id];
        return !admitted || admitted.hash !== p.spec ||
          admitted.name !== p.container ||
          input.migration.process_states[id] !== p.desired;
      })
    ) throw new Error("Docker Process definitions differ from admission");
    if (input.mode === "plan") {
      outcome = await dependencies.observe("plan", input.migration, signal);
      return;
    }
    if (input.mode === "restore-policies" && input.migration.fallback) {
      throw new Error("Policy restore cannot select fallback");
    }
    const prepared = await dependencies.migrate(
      input.mode === "migrate" ? "prepare" : "restore-policies",
      input.migration,
      undefined,
      signal,
    );
    signal?.throwIfAborted();
    if (!prepared.ok) {
      const { ok: _ok, ...failure } = prepared;
      outcome = Report.parse(failure);
      return;
    }
    if (
      new Set(prepared.process_ids).size !== prepared.process_ids.length ||
      prepared.process_ids.some((id) =>
        !input.migration.process_ids.includes(id)
      )
    ) throw new Error("Docker migration returned foreign Process identities");
    const failed: number[] = [];
    for (const process of processes) {
      if (!prepared.process_ids.includes(String(process.process_id))) continue;
      signal?.throwIfAborted();
      const reply = await dependencies.reconcile(
        "process.converge-container",
        process,
        signal,
      );
      if (reply.error_code !== undefined) failed.push(process.process_id);
    }
    outcome = await dependencies.observe("report", input.migration, signal);
    if (failed.length) {
      outcome = {
        ...outcome,
        healthy: false,
        error_code: "docker.process_reconcile_failed",
        failed_process_ids: failed,
      };
    }
  });
  if (!outcome) throw new Error("Docker migration did not complete");
  return outcome;
}

export function dockerMigration(
  makeHttp: (ctx: Context) => Http = (ctx) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
  makeGateway: (http: Http, args: DirectIntent) => Gateway = (http, args) =>
    new GatewayOperation(http, args),
  action = migrateDocker,
) {
  return {
    type: "@nckrtl/fleet-docker-migrate",
    version: "2026.10.31.1",
    globalArguments: Globals,
    methods: {
      execute: {
        description:
          "Migrate Docker using direct system commands on the owning Node",
        arguments: Args,
        execute: (args: z.infer<typeof Args>, ctx: Context) => {
          const gateway = makeGateway(makeHttp(ctx), args);
          return directOperation(
            args,
            gateway,
            (input) => action(Input.parse(input), gateway, ctx.signal),
            Report.parse,
            ctx.signal,
          );
        },
      },
    },
  };
}
export const behavior = dockerMigration();

export const DockerMigrationObservation = String.raw`
def observe(mode,data):
    global DEADLINE, JOURNAL
    DEADLINE = time.monotonic() + 170
    try:
        release = dict(line.split('=',1) for line in Path('/etc/os-release').read_text().splitlines() if '=' in line)
        require(release.get('ID','').strip('"') == 'ubuntu' and release.get('VERSION_CODENAME','').strip('"') == 'resolute', 'platform')
        require(command(['dpkg','--print-architecture'])[1].strip() == data['architecture'], 'architecture')
        if mode == 'plan':
            journal(False)
            inventory = JOURNAL/'inventory.json'
            resuming = inventory.exists()
            if resuming:
                state = json.loads(inventory.read_bytes())
                require(state['recipe'] == data['recipe'], 'recipe-mismatch')
                for old in state['containers']: process(old,data)
            else: state = collect(data)
            original_journal = JOURNAL
            try:
                while not JOURNAL.exists(): JOURNAL = JOURNAL.parent
                sufficient = space(state)
            finally: JOURNAL = original_journal
            result = dict(healthy=True, planned=True, **report(state))
            result['plan'] = dict(recipe=data['recipe'],docker_version=data['docker_version'],containerd_version=data['containerd_version'],
                                 resuming=resuming,source_backups=[str(change[3]) for change in source_changes()],
                                 fallback_data_root=str(JOURNAL/'engine'),metadata_roots=state['metadata_roots'],space=dict(sufficient=sufficient))
        else:
            journal(False)
            state = json.loads((JOURNAL/'inventory.json').read_bytes())
            require(state['recipe'] == data['recipe'], 'recipe-mismatch')
            for old in state['containers']: process(old,data)
            until = time.monotonic()+120
            while True:
                result = dict(healthy=True, **report(state))
                for old,actual in zip(state['containers'],result['containers']):
                    expected = data['process_states'].get(old.get('Config',{}).get('Labels',{}).get('orbit.process.id'))
                    running = expected == 'running' if managed(old) else old.get('State',{}).get('Running',False)
                    if actual['state'] != ('running' if running else 'exited') and not (not running and actual['state'] == 'created'):
                        result['healthy'] = False
                    if running and actual['health'] not in (None,'healthy'): result['healthy'] = False
                if result['healthy'] or time.monotonic() >= until: break
                time.sleep(1)
            if not result['healthy']: result['error_code'] = 'docker.migration_unhealthy'
    except (Refused,OSError,ValueError,KeyError,TypeError,UnicodeError,http.client.HTTPException,subprocess.SubprocessError) as error:
        result = dict(healthy=False,fallback=False,containers=[],process_ids=[],error_code='docker.migration_failed',stage=error.stage if isinstance(error,Refused) else 'journal',resumable=True)
    print(json.dumps(result))
`;
