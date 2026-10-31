import { parse } from "jsr:@std/toml@1.0.9";
import { z } from "npm:zod@4.4.3";
import {
  type NodeCommand,
  run as nodeRun,
} from "../operations/_lib/node-commands.ts";
import {
  type NodeProxy,
  runtimeNodeProxy,
} from "../operations/_lib/node-proxy.ts";

export const Args = z.object({
  node_id: z.number().int().positive(),
  user: z.string().regex(/^[a-z_][a-z0-9_-]{0,31}$/),
}).strict();
export const Proof = Args.extend({
  hostname: z.string().min(1).max(255),
  effective_user: z.string().min(1),
  sudo_ready: z.literal(true),
});

/** Read-only host checks. Sudo starts only id; it changes no host state. */
export async function inspect(
  args: z.infer<typeof Args>,
  invoke: NodeCommand = nodeRun,
  placement?: NodeProxy | null,
) {
  const proxy = placement === undefined ? await runtimeNodeProxy() : placement;
  if (proxy && (proxy.node_id !== args.node_id || proxy.user !== args.user)) {
    throw new Error("Proxy placement differs from intent");
  }
  async function run(command: string, argv: string[]) {
    const result = await invoke([command, ...argv], undefined, "node", {
      maxOutputBytes: 16384,
    });
    if (!result.success) {
      throw new Error("Node worker identity or sudo check failed");
    }
    return new TextDecoder().decode(result.stdout).trim();
  }
  const config = "/etc/orbit/agent/config.toml";
  const custody = await run("/usr/bin/sudo", [
    "-n",
    "/usr/bin/stat",
    "--format=%u:%a:%F",
    config,
  ]);
  if (custody !== "0:644:regular file") {
    throw new Error("Node identity configuration is not root-owned");
  }
  if (proxy) {
    const interfaces = z.array(
      z.object({
        addr_info: z.array(z.object({ local: z.string() }).passthrough()),
      }).passthrough(),
    ).parse(JSON.parse(await run("/usr/sbin/ip", ["-j", "address", "show"])));
    if (
      !interfaces.some((i) => i.addr_info.some((a) => a.local === proxy.host))
    ) throw new Error("Proxy command reached the wrong effect Node");
    const state = await invoke(
      ["/usr/bin/systemctl", "is-enabled", "orbit-node-worker.service"],
      undefined,
      "node",
    );
    if (new TextDecoder().decode(state.stdout).trim() !== "masked") {
      throw new Error("Light Node local worker must remain masked");
    }
  } else {
    const identity = z.object({ swamp_worker: Args }).passthrough().parse(
      parse(await run("/usr/bin/sudo", ["-n", "/usr/bin/cat", config])),
    ).swamp_worker;
    if (identity.node_id !== args.node_id || identity.user !== args.user) {
      throw new Error("Node identity differs from placement");
    }
  }
  const user = await run("/usr/bin/id", ["-un"]);
  if (user !== args.user) {
    throw new Error("Node worker account differs from intent");
  }
  if (await run("/usr/bin/sudo", ["-n", "/usr/bin/id", "-u"]) !== "0") {
    throw new Error("Node worker cannot use passwordless sudo");
  }
  return Proof.parse({
    ...args,
    effective_user: user,
    hostname: await run("/usr/bin/hostname", []),
    sudo_ready: true,
  });
}

/** Disposable direct-command effect; leaves no file or directory behind. */
export async function exercise(
  args: z.infer<typeof Args>,
  invoke: NodeCommand = nodeRun,
  placement?: NodeProxy | null,
) {
  if (![1, 18].includes(args.node_id)) {
    throw new Error("Proxy canary is limited to the two approved light Nodes");
  }
  const proof = await inspect(args, invoke, placement);
  const result = await invoke(
    [
      "/usr/bin/python3",
      "-c",
      `import os,tempfile,json
with tempfile.TemporaryDirectory(prefix='orbit-proxy-canary-',dir='/run') as directory:
 path=directory+'/proof'
 with open(path,'xb') as f:f.write(b'orbit-proxy-direct-command');f.flush();os.fsync(f.fileno())
 with open(path,'rb') as f:
  if f.read()!=b'orbit-proxy-direct-command':raise RuntimeError('canary readback failed')
if os.path.lexists(directory):raise RuntimeError('canary cleanup failed')
print(json.dumps({'effect':True,'cleaned':True}))
`,
    ],
    undefined,
    "root",
  );
  if (!result.success) throw new Error("Proxy direct-command effect failed");
  const effect = z.object({ effect: z.literal(true), cleaned: z.literal(true) })
    .strict().parse(JSON.parse(new TextDecoder().decode(result.stdout)));
  return { ...proof, ...effect };
}

export const behavior = {
  type: "@nckrtl/node-worker-canary",
  version: "2026.10.31.1",
  globalArguments: z.object({}).strict(),
  resources: {
    effect: {
      description: "Disposable direct-command effect and cleanup",
      schema: Proof.extend({
        effect: z.literal(true),
        cleaned: z.literal(true),
      }),
      lifetime: "infinite",
      garbageCollection: 10,
    },
    proof: {
      description: "Placed Node identity and effective sudo readiness",
      schema: Proof,
      lifetime: "infinite",
      garbageCollection: 10,
    },
  },
  methods: {
    exercise: {
      description:
        "Write, read and remove one disposable runtime file on the exact proxy Node",
      arguments: Args,
      execute: async (
        args: z.infer<typeof Args>,
        ctx: {
          writeResource: (
            spec: string,
            name: string,
            value: unknown,
          ) => Promise<unknown>;
        },
      ) => ({
        dataHandles: [
          await ctx.writeResource("effect", "effect", await exercise(args)),
        ],
      }),
    },
    inspect: {
      description:
        "Prove worker account and effective sudo without host mutation",
      arguments: Args,
      execute: async (
        args: z.infer<typeof Args>,
        ctx: {
          writeResource: (
            spec: string,
            name: string,
            value: unknown,
          ) => Promise<unknown>;
        },
      ) => {
        const proof = await ctx.writeResource(
          "proof",
          "proof",
          await inspect(args),
        );
        return { dataHandles: [proof] };
      },
    },
  },
};
