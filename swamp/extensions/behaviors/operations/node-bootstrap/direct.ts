import { z } from "npm:zod@4.4.3";
import { gatewayHttp } from "../../automatic-deploy/gateway.ts";
import {
  DirectArgs,
  directOperation,
  GatewayOperation,
  Globals,
} from "../_lib/direct.ts";
import {
  BootstrapInput,
  BootstrapObservation,
  nodeBootstrapActions,
} from "./bootstrap_local.ts";
import { bootstrapSystem } from "./bootstrap_ssh.ts";
import { convergeFirewall } from "../routes/firewall-local.ts";

const Kind = z.enum([
  "node.bootstrap_install",
  "node.bootstrap_host",
  "node.bootstrap_verify",
  "node.macos_inspect",
]);
const Args = DirectArgs.extend({
  kind: Kind,
  node_id: z.number().int().positive(),
});
const Private = z.object({ bootstrap_identity: z.string().min(1).max(4096) })
  .strict();
const Code = z.string().regex(/^(?:node|firewall)\.[a-z0-9_.-]{1,90}$/);
const Result = z.object({
  probed: z.boolean().optional(),
  installed: z.boolean().optional(),
  changed: z.boolean().optional(),
  bootstrapped: z.boolean().optional(),
  bootstrap_verified: z.boolean().optional(),
  architecture: z.enum(["x86_64", "arm64"]).optional(),
  host_type: z.string().max(64).optional(),
  public_key: z.string().max(16384).optional(),
  host_key_fingerprint: z.string().max(100).optional(),
  error_code: Code.optional(),
}).strict();
type Environment = Parameters<
  ReturnType<typeof nodeBootstrapActions>["install"]
>[2];
type Context = Environment & {
  globalArgs: z.infer<typeof Globals>;
  signal?: AbortSignal;
};
type Actions = ReturnType<typeof nodeBootstrapActions>;

/** Canonical bootstrap actions inside one admitted root, with no agent invocation. */
export async function applyBootstrap(
  kind: z.infer<typeof Kind>,
  raw: unknown,
  identity: string,
  environment: Environment,
  signal?: AbortSignal,
  actions: Actions = nodeBootstrapActions(),
  firewall = convergeFirewall,
) {
  const input = BootstrapInput.parse(raw);
  if (kind === "node.macos_inspect" && input.platform !== "macos") {
    throw new Error("node.platform_mismatch");
  }
  if (
    ["node.bootstrap_host", "node.bootstrap_verify"].includes(kind) &&
    input.platform !== "linux"
  ) throw new Error("node.platform_mismatch");
  const observed =
    kind === "node.bootstrap_install" || kind === "node.bootstrap_host"
      ? await actions.install(input, identity, environment, signal)
      : await actions.probe(input, identity, environment, signal);
  if ("error_code" in observed) {
    return Result.parse({ error_code: observed.error_code });
  }
  const common = {
    architecture: observed.architecture,
    host_type: observed.host_key_type,
    public_key: observed.host_public_key,
    host_key_fingerprint: input.target.fingerprint,
  };
  if (kind === "node.bootstrap_install") {
    return Result.parse({
      ...common,
      installed: true,
      changed: "installed" in observed && observed.installed,
    });
  }
  const key = input.platform === "macos" && observed.architecture === "arm64"
    ? "macos-aarch64"
    : `${input.platform}-x86_64` as const;
  const artifact = input.artifacts[key];
  if (!artifact) {
    return Result.parse({ error_code: "node.agent_delivery_missing" });
  }
  const observation = BootstrapObservation.parse({
    schema_version: 1,
    node_id: input.node_id,
    platform: input.platform,
    architecture: observed.architecture,
    agent_sha256: artifact.sha256,
    installed: "installed" in observed && observed.installed,
    host_key_type: observed.host_key_type,
    host_public_key: observed.host_public_key,
  });
  const outcome = kind === "node.bootstrap_host"
    ? await actions.host(input, observation, identity, environment, signal)
    : await actions.verify(input, observation, identity, environment, signal);
  if (!outcome.bootstrapped) {
    return Result.parse({ error_code: outcome.error_code });
  }
  if (kind === "node.bootstrap_verify" && input.firewall !== null) {
    const system = bootstrapSystem(
      { ...input.target, user: input.managed_user },
      identity,
      environment,
    );
    const checked = await firewall(
      input.firewall,
      async (program, payload, abort) => {
        const reply = await system(
          ["/usr/bin/python3", "-c", program],
          JSON.stringify(payload) + "\n",
          abort,
        );
        return JSON.parse(reply.stdout);
      },
      signal,
    );
    if ("error_code" in checked) {
      return Result.parse({ error_code: checked.error_code });
    }
  }
  return Result.parse({
    ...common,
    [kind === "node.bootstrap_host" ? "bootstrapped" : "bootstrap_verified"]:
      true,
  });
}

export function nodeBootstrapDirect(
  makeHttp = (ctx: Context) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
  apply = applyBootstrap,
) {
  return {
    type: "@nckrtl/node-bootstrap-direct",
    version: "2026.10.31.1",
    globalArguments: Globals,
    methods: {
      apply: {
        description:
          "Run one admitted first-contact action on its exact Gateway worker",
        arguments: Args,
        execute: async (args: z.infer<typeof Args>, ctx: Context) => {
          const gateway = new GatewayOperation(makeHttp(ctx), args);
          return await directOperation(
            args,
            gateway,
            async (raw) => {
              let result: z.infer<typeof Result> = {};
              await gateway.withPrivateMaterial(async (material) => {
                const identity = Private.parse(material).bootstrap_identity;
                try {
                  result = await apply(
                    args.kind,
                    raw.bootstrap,
                    identity,
                    ctx,
                    ctx.signal,
                  );
                } catch (error) {
                  result = {
                    error_code: Code.safeParse(
                      error instanceof Error ? error.message : null,
                    ).data ?? "node.bootstrap_failed",
                  };
                }
              });
              return result;
            },
            (raw) => Result.parse(raw),
            ctx.signal,
          );
        },
      },
    },
  };
}
export const behavior = nodeBootstrapDirect();
