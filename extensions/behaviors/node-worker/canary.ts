import { parse } from "jsr:@std/toml@1.0.9";
import { z } from "npm:zod@4.4.3";

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
export async function inspect(args: z.infer<typeof Args>) {
  async function run(command: string, argv: string[]) {
    const result = await new Deno.Command(command, {
      args: argv,
      stdin: "null",
      stdout: "piped",
      stderr: "null",
    }).output();
    if (!result.success || result.stdout.length > 16384) {
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
  const identity = z.object({ swamp_worker: Args }).passthrough().parse(
    parse(await run("/usr/bin/sudo", ["-n", "/usr/bin/cat", config])),
  ).swamp_worker;
  if (identity.node_id !== args.node_id || identity.user !== args.user) {
    throw new Error("Node identity differs from placement");
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

export const behavior = {
  type: "@nckrtl/node-worker-canary",
  version: "2026.10.30.2",
  globalArguments: z.object({}).strict(),
  resources: {
    proof: {
      description: "Placed Node identity and effective sudo readiness",
      schema: Proof,
      lifetime: "infinite",
      garbageCollection: 10,
    },
  },
  methods: {
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
