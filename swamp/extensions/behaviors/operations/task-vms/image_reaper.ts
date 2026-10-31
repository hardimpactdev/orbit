import { z } from "npm:zod@4.4.3";
import { gatewayHttp, type Http } from "../../automatic-deploy/gateway.ts";
import { inspect } from "../../node-worker/canary.ts";
import { Globals } from "../_lib/direct.ts";
import {
  localSystemCommand,
  type SystemTransport,
} from "../node/system_command.ts";

const Id = z.number().int().positive();
const Name = z.string().regex(/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,62}$/);
const Builder =
  /^tvm-image-([1-9][0-9]*)(?:-attempt-([1-9][0-9]*))?(?:-builder|-smoke)?$/;
const Active = ["queued", "running", "waiting"] as const;
const OperatorOwned = z.array(
  z.string().regex(
    /^[A-Za-z0-9][A-Za-z0-9_.-]{0,62}\/(?:[A-Za-z0-9][A-Za-z0-9_.-]{0,62}|\*)$/,
  ),
).max(4096).default([]);
const Host = z.object({
  node_id: Id,
  user: z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/),
  project: Name,
  pool: Name,
  operator_owned: OperatorOwned,
}).strict();
export type ImageReaperHost = z.infer<typeof Host>;
const Node = z.object({
  id: Id,
  status: z.string(),
  user: z.string(),
  settings: z.object({
    task_vms: z.object({
      project: Name,
      pool: Name,
      operator_owned: OperatorOwned,
    }).passthrough().nullable()
      .optional(),
  }).passthrough().nullable(),
}).passthrough();
const Operation = z.object({
  id: Id,
  kind: z.literal("task_vm.image_build"),
  status: z.enum(Active),
  input: z.object({
    build_id: Id,
    host_node_id: Id,
    project: Name,
    name: z.string().regex(Builder),
  }).passthrough(),
}).passthrough();
const Vm = z.object({
  name: z.string(),
  type: z.string(),
  status: z.string(),
  created_at: z.string(),
  expanded_devices: z.record(
    z.string(),
    z.object({
      type: z.string(),
      path: z.string().optional(),
      pool: z.string().optional(),
    }).passthrough(),
  ),
}).passthrough();
type Vm = z.infer<typeof Vm>;
const Deletion = Host.extend({
  name: z.string().regex(Builder),
  created_at: z.string(),
  deleted_at: z.iso.datetime(),
  storage_removed: z.literal(true),
});
export type ImageBuilderDeletion = z.infer<typeof Deletion>;
const failed = () => new Error("task_vm.image_reaper_failed");

export class ImageReaperApi {
  constructor(private http: Http) {}
  private async get(path: string): Promise<unknown> {
    const response = await this.http("GET", path);
    if (response.status !== 200) throw failed();
    return z.object({ data: z.unknown() }).parse(response.body).data;
  }
  async hosts(): Promise<ImageReaperHost[]> {
    return z.array(Node).max(4096).parse(await this.get("/api/v1/nodes"))
      .filter((node) => node.status === "active" && node.settings?.task_vms)
      .map((node) =>
        Host.parse({
          node_id: node.id,
          user: node.user,
          project: node.settings!.task_vms!.project,
          pool: node.settings!.task_vms!.pool,
          operator_owned: node.settings!.task_vms!.operator_owned,
        })
      );
  }
  async host(id: number): Promise<ImageReaperHost> {
    const node = Node.parse(await this.get(`/api/v1/nodes/${id}`));
    if (
      node.id !== id || node.status !== "active" || !node.settings?.task_vms
    ) throw failed();
    return Host.parse({
      node_id: id,
      user: node.user,
      project: node.settings.task_vms.project,
      pool: node.settings.task_vms.pool,
      operator_owned: node.settings.task_vms.operator_owned,
    });
  }
  async active(host: ImageReaperHost): Promise<Set<number>> {
    const protectedBuilds = new Set<number>();
    for (const status of Active) {
      const rows = z.array(Operation).max(50).parse(
        await this.get(
          `/api/v1/operations?kind=task_vm.image_build&status=${status}`,
        ),
      );
      // This API has a hard 50-row limit and no pagination. A full response is incomplete proof.
      if (rows.length === 50 || rows.some((op) => op.status !== status)) {
        throw failed();
      }
      for (const op of rows) {
        if (op.input.host_node_id === host.node_id) {
          if (
            op.input.project !== host.project ||
            Number(Builder.exec(op.input.name)![1]) !== op.input.build_id
          ) throw failed();
          protectedBuilds.add(op.input.build_id);
        }
      }
    }
    return protectedBuilds;
  }
}

/** Only fixed Incus commands on this host's recorded project; no guest or cloud action. */
export async function reapImageBuilders(
  host: ImageReaperHost,
  api: ImageReaperApi,
  record: (deletion: ImageBuilderDeletion) => Promise<void>,
  invoke: SystemTransport = localSystemCommand,
  signal?: AbortSignal,
  now: () => number = Date.now,
) {
  host = Host.parse(host);
  const run = async (args: string[]) => {
    signal?.throwIfAborted();
    return await invoke({
      command: "/usr/bin/sudo",
      args: ["-n", "--", "/usr/bin/incus", "--project", host.project, ...args],
      timeoutMs: 150000,
      maxOutputBytes: 16 * 1024 * 1024,
    }, signal);
  };
  const json = async (args: string[]) => {
    const reply = await run(args);
    if (!reply.success) throw failed();
    return JSON.parse(reply.stdout);
  };
  const eligible = (vm: Vm) => {
    const roots = Object.values(vm.expanded_devices).filter((d) =>
      d.type === "disk" && d.path === "/"
    );
    const created = Date.parse(vm.created_at);
    return Builder.test(vm.name) && vm.type === "virtual-machine" &&
      !host.operator_owned.some((name) =>
        name === `${host.project}/*` || name === `${host.project}/${vm.name}`
      ) &&
      ["Running", "Stopped"].includes(vm.status) && roots.length === 1 &&
      roots[0].pool === host.pool && Number.isFinite(created) &&
      now() - created > 2 * 60 * 60 * 1000;
  };
  const active = await api.active(host);
  const rows = z.array(Vm).max(4096).parse(
    await json(["list", "--format", "json"]),
  );
  if (new Set(rows.map((vm) => vm.name)).size !== rows.length) throw failed();
  const summary = {
    node_id: host.node_id,
    deleted: [] as string[],
    protected: [] as string[],
    failed: [] as string[],
  };
  for (const vm of rows.filter(eligible)) {
    if (active.has(Number(Builder.exec(vm.name)![1]))) {
      summary.protected.push(vm.name);
      continue;
    }
    try {
      const observe = async () => {
        const current = z.array(Vm).max(4096).parse(
          await json(["list", "--format", "json", "--", vm.name]),
        ).filter((row) => row.name === vm.name);
        if (current.length === 0) return null;
        if (
          current.length !== 1 || current[0].created_at !== vm.created_at ||
          !eligible(current[0])
        ) throw failed();
        return current[0];
      };
      const guard = async () => {
        if (
          JSON.stringify(await api.host(host.node_id)) !==
            JSON.stringify(host) ||
          (await api.active(host)).has(Number(Builder.exec(vm.name)![1]))
        ) throw failed();
        return await observe();
      };
      let current = await guard();
      if (!current) continue;
      if (current?.status === "Running") {
        try {
          if (!(await run(["stop", "--force", "--", vm.name])).success) {
            throw failed();
          }
        } catch {
          if ((await observe())?.status === "Running") throw failed();
        }
        current = await observe();
        if (current?.status === "Running") throw failed();
        if (!current) continue;
      }
      if (current) {
        current = await guard();
        if (!current) continue;
        if (current) {
          try {
            if (!(await run(["delete", "--", vm.name])).success) throw failed();
          } catch {
            if (await observe()) throw failed();
          }
          if (await observe()) throw failed();
        }
      }
      const volumes = z.array(z.object({ type: z.string(), name: z.string() }))
        .max(65536).parse(
          await json([
            "storage",
            "volume",
            "list",
            host.pool,
            "--format",
            "json",
          ]),
        );
      if (
        volumes.some((v) =>
          v.type === "virtual-machine" &&
          (v.name === vm.name || v.name.startsWith(vm.name + "/"))
        )
      ) throw failed();
      await record(
        Deletion.parse({
          ...host,
          name: vm.name,
          created_at: vm.created_at,
          deleted_at: new Date(now()).toISOString(),
          storage_removed: true,
        }),
      );
      summary.deleted.push(vm.name);
    } catch {
      signal?.throwIfAborted();
      summary.failed.push(vm.name);
    }
  }
  return summary;
}

type Context = {
  globalArgs: z.infer<typeof Globals>;
  definition: { name: string };
  signal?: AbortSignal;
  writeResource(
    spec: string,
    name: string,
    value: unknown,
  ): Promise<{ name: string }>;
};
const Args = z.object({ run_id: z.string().uuid() });
export function imageReaper(
  makeHttp = (ctx: Context) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile, ctx.signal),
  invoke: SystemTransport = localSystemCommand,
  identity: typeof inspect = inspect,
) {
  return {
    type: "@nckrtl/task-vm-image-reaper",
    version: "2026.10.31.1",
    globalArguments: Globals,
    resources: {
      plan: {
        description:
          "Current recorded image hosts for native per-Node placement",
        schema: z.object({ nodes: z.array(Host) }),
        lifetime: "1h",
        garbageCollection: 1,
      },
      deletion: {
        description:
          "One confirmed leaked builder deletion and absent root storage",
        schema: Deletion,
        lifetime: "30d",
        garbageCollection: 1,
      },
      sweep: {
        description: "Per-host cleanup report",
        schema: z.object({
          node_id: Id,
          deleted: z.array(z.string()),
          protected: z.array(z.string()),
          failed: z.array(z.string()),
        }),
        lifetime: "7d",
        garbageCollection: 1,
      },
    },
    methods: {
      plan: {
        description:
          "Discover current image hosts without changing Gateway state",
        arguments: Args,
        async execute(args: z.infer<typeof Args>, ctx: Context) {
          return {
            dataHandles: [
              await ctx.writeResource("plan", `plan-${args.run_id}`, {
                nodes: await new ImageReaperApi(makeHttp(ctx)).hosts(),
              }),
            ],
          };
        },
      },
      sweep: {
        description:
          "Delete old unreferenced image builders on their exact host",
        arguments: Args.extend({ node_id: Id }),
        async execute(
          args: z.infer<typeof Args> & { node_id: number },
          ctx: Context,
        ) {
          if (
            ctx.definition.name !== `task-vm-image-reaper-node-${args.node_id}`
          ) throw failed();
          const api = new ImageReaperApi(makeHttp(ctx));
          const host = await api.host(args.node_id);
          await identity({ node_id: host.node_id, user: host.user });
          const handles: { name: string }[] = [];
          const summary = await reapImageBuilders(
            host,
            api,
            async (deletion) => {
              handles.push(
                await ctx.writeResource(
                  "deletion",
                  `deleted-${deletion.name}-${args.run_id}`,
                  deletion,
                ),
              );
            },
            invoke,
            ctx.signal,
          );
          handles.push(
            await ctx.writeResource("sweep", `sweep-${args.run_id}`, summary),
          );
          if (summary.failed.length) throw failed();
          return { dataHandles: handles, ...summary };
        },
      },
    },
  };
}
export const behavior = {
  ...imageReaper(),
  type: "@nckrtl/task-vm-image-reaper",
};
