import { z } from "npm:zod@4.4.3";
import catalogue from "../catalogue.json" with { type: "json" };
import { GlobalArgsSchema } from "../../.swamp/pulled-extensions/@swamp/ssh/models/_lib/schemas.ts";
import { effectiveHosts } from "../../.swamp/pulled-extensions/@swamp/ssh/models/_lib/hosts.ts";
import {
  hostKeyAlias,
  knownHostsContent,
  parseKeyLine,
} from "../../.swamp/pulled-extensions/@swamp/ssh/models/_lib/host_keys.ts";
import {
  makeSafeTempDir,
  prepareIdentities,
} from "../../.swamp/pulled-extensions/@swamp/ssh/models/_lib/identities.ts";
import {
  argvRemoteCommand,
  buildExecArgv,
} from "../../.swamp/pulled-extensions/@swamp/ssh/models/_lib/runner.ts";
import { AgentCallError, type AgentProcess, agentProcess } from "./process.ts";

const Step = z.string().regex(/^[a-z0-9_.:-]+$/).min(1).max(160);
const Identity = z.strictObject({
  nonce: z.uuid(),
  created_at: z.number().int().positive(),
  redactions: z.array(z.string().min(1).max(65536)).max(256),
  booleans: z.array(z.boolean()).max(2),
});
export const Arguments = z.strictObject({
  node_id: z.number().int().positive().safe(),
  operation_id: z.number().int().positive().safe(),
  step: Step,
  command: z.string().min(1),
  input: z.record(z.string(), z.unknown()).meta({ sensitive: true }),
  effect: Identity.meta({ sensitive: true }).optional(),
});
const Attributes = z.strictObject({
  node_id: z.number().int().positive().safe(),
  platform: z.enum(["linux", "macos"]),
  agent_sha256: z.string().regex(/^[a-f0-9]{64}$/),
  agent_path: z.string().max(4096).optional(),
});
export type CommandContract = {
  name: string;
  mutates: boolean;
  /** JSON-only commands. Input/result schemas and sensitive metadata are code-owned. */
  input: z.ZodType<Record<string, unknown>>;
  output: z.ZodType<Record<string, unknown>>;
  privilege: {
    linux: "account" | "root" | "owner" | null;
    macos: "account" | null;
  };
  timeout_seconds: number;
  errors: readonly string[];
};
export type AgentContext = {
  globalArgs: Record<string, unknown>;
  signal?: AbortSignal;
  writeResource(
    spec: string,
    name: string,
    data: Record<string, unknown>,
  ): Promise<{ name: string }>;
};
export type AgentDependencies = {
  prepare: typeof prepareIdentities;
  process: AgentProcess;
};
const defaults: AgentDependencies = {
  prepare: prepareIdentities,
  process: agentProcess,
};
const need = (condition: unknown, code = "agent.call_invalid") => {
  if (!condition) throw new AgentCallError(code);
};

function target(raw: Record<string, unknown>, node: number) {
  const g = GlobalArgsSchema.parse(raw);
  need(g.hosts.length === 1 && g.identityContentMode === "agent");
  need(
    g.sshBinary === "ssh" && g.sshAgentBinary === "ssh-agent" &&
      g.sshAddBinary === "ssh-add",
  );
  const h = g.hosts[0];
  need(
    h.name === `node-${node}` && h.transport === undefined &&
      Object.keys(h.env ?? {}).length === 0,
  );
  const attrs = Attributes.parse(h.attrs);
  need(attrs.node_id === node);
  need(z.ipv4().safeParse(h.address).success && h.address.startsWith("10.44."));
  if (attrs.platform === "macos") {
    need(
      attrs.agent_path &&
        /^(?:\/[A-Za-z0-9_.-]+)+\/\.orbit\/bin\/orbit-agent$/.test(
          attrs.agent_path,
        ),
    );
    need(
      !attrs.agent_path!.split("/").some((part) =>
        part === "." || part === ".."
      ),
    );
  } else need(attrs.agent_path === undefined);
  const t = g.transport;
  need(t.kind === "ssh");
  if (t.kind !== "ssh") throw new AgentCallError("agent.call_invalid");
  need(t.port === 22 && t.auth.kind === "key" && t.hostKeyPolicy === "pinned");
  need(
    t.hostKeys?.length && t.identityContent?.length && !t.identityFile &&
      !t.identityAgent,
  );
  need(t.user && t.user !== "root" && /^[a-z_][a-z0-9_-]{0,31}$/.test(t.user));
  need(t.identitiesOnly === true && t.controlMaster.enabled === false);
  need(!t.proxyCommand && !t.proxyJump && t.extraOptions.length === 0);
  return { g, attrs, host: effectiveHosts(g)[0] };
}

/** No production registry is installed by this factory-only slice. */
export function orbitAgentExtension(
  commands: readonly CommandContract[],
  deps: AgentDependencies = defaults,
) {
  const entries = new Map(commands.map((command) => [command.name, command]));
  need(entries.size === commands.length);
  const resources: Record<string, {
    description: string;
    schema: z.ZodType;
    lifetime: string;
    garbageCollection: number;
    vaultName: string;
  }> = {};
  const specs = new Map<string, string>();
  const secretOutputs = new Map<string, string[]>();
  commands.forEach((command) => {
    need(
      !["agent.install", "agent.remove", "agent.configure", "cli.self-update"]
        .includes(command.name),
      "agent.command_forbidden",
    );
    const declared = catalogue.commands["orbit-agent"].find((entry) =>
      entry.name === command.name
    );
    need(declared && declared.mutates === command.mutates);
    need(
      Number.isInteger(command.timeout_seconds) &&
        command.timeout_seconds > 0 && command.timeout_seconds <= 3600,
    );
    for (const schema of [command.input, command.output]) {
      const shape = z.toJSONSchema(schema);
      need(shape.type === "object" && shape.additionalProperties === false);
    }
    const spec = `agent_${command.name.replaceAll(/[.-]/g, "_")}`;
    need(!resources[spec]);
    specs.set(command.name, spec);
    secretOutputs.set(
      command.name,
      sensitivePaths(z.toJSONSchema(command.output)),
    );
    resources[spec] = {
      description:
        `Validated ${command.name} output; raw transport output is never data`,
      schema: z.strictObject({
        node_id: z.number().int().positive(),
        operation_id: z.number().int().positive(),
        step: Step,
        command: z.literal(command.name),
        effect_key: z.string().nullable(),
        data: command.output,
      }),
      lifetime: "infinite",
      garbageCollection: 10,
      vaultName: "orbit-control-outputs",
    };
  });
  return {
    type: "@swamp/ssh",
    resources,
    methods: [{
      orbit_agent: {
        description:
          "Call one admitted Node using its pinned identity and a typed Orbit-agent command",
        // Native execution merges global arguments into the method parse.
        // Strip those here; execute revalidates the isolated method shape.
        arguments: z.object(Arguments.shape),
        execute: async (
          input: z.input<typeof Arguments>,
          ctx: AgentContext,
        ) => {
          let directory: string | undefined;
          let identities:
            | Awaited<ReturnType<typeof prepareIdentities>>
            | undefined;
          try {
            const args = Arguments.parse(input);
            const command = entries.get(args.command);
            need(command, "agent.command_unavailable");
            if (!command) throw new AgentCallError("agent.command_unavailable");
            const { attrs, host } = target(ctx.globalArgs, args.node_id);
            const privilege = command.privilege[attrs.platform];
            need(privilege !== null, "agent.platform_unsupported");
            need(!Object.hasOwn(args.input, "__orbit_effect_receipt"));
            if (command.mutates) {
              need(!Object.hasOwn(args.input, "effect_key") && args.effect);
            } else need(args.effect === undefined);
            const effectKey = command.mutates
              ? `op-${args.operation_id}.${args.step}`
              : null;
            need(effectKey === null || effectKey.length <= 192);
            const data = command.input.parse(args.input);
            const body = JSON.stringify({
              agent_sha256: attrs.agent_sha256,
              deadline_ms: command.timeout_seconds * 1000,
              input: {
                ...data,
                ...(effectKey === null ? {} : { effect_key: effectKey }),
              },
              ...(args.effect
                ? {
                  effect_receipt: {
                    ...args.effect,
                    secret_outputs: secretOutputs.get(command.name)!,
                  },
                }
                : {}),
            }) + "\n";
            need(
              new TextEncoder().encode(body).length <= 4 * 1024 * 1024,
              "agent.input_limit",
            );
            const signal = AbortSignal.any([
              ...(ctx.signal ? [ctx.signal] : []),
              AbortSignal.timeout(command.timeout_seconds * 1000 + 15000),
            ]);
            need(!signal.aborted, "agent.transport_uncertain");
            identities = await deps.prepare([host], {
              mode: "agent",
              sshAgentBinary: "/usr/bin/ssh-agent",
              sshAddBinary: "/usr/bin/ssh-add",
              keyLifetimeSec: command.timeout_seconds + 60,
              logger: { warn: () => {} },
            });
            need(!signal.aborted, "agent.transport_uncertain");
            const prepared = identities.hosts[0];
            directory = await makeSafeTempDir("orbit-agent-host-");
            await Deno.chmod(directory, 0o700);
            const alias = await hostKeyAlias(host.address, 22);
            const known = `${directory}/known_hosts`;
            if (
              host.transport.kind !== "ssh" || prepared.transport.kind !== "ssh"
            ) throw new AgentCallError("agent.call_invalid");
            const keys = (host.transport.hostKeys ?? []).map(parseKeyLine);
            need(keys.length > 0 && keys.every((key) => key !== null));
            await Deno.writeTextFile(
              known,
              knownHostsContent(alias, keys.filter((key) => key !== null)),
              { mode: 0o600 },
            );
            const executable = attrs.platform === "linux"
              ? "/usr/local/bin/orbit-agent"
              : attrs.agent_path!;
            const sudo = attrs.platform === "linux" &&
              (command.mutates || privilege === "root" ||
                privilege === "owner");
            const remote = argvRemoteCommand([
              ...(sudo ? ["sudo", "-n", "--"] : []),
              executable,
              "command",
              command.name,
            ], false);
            const argv = buildExecArgv(
              {
                ...prepared,
                transport: {
                  ...prepared.transport,
                  extraOptions: [
                    "BatchMode=yes",
                    "PasswordAuthentication=no",
                    "KbdInteractiveAuthentication=no",
                    "ForwardAgent=no",
                    "RequestTTY=no",
                    "PermitLocalCommand=no",
                    "ClearAllForwardings=yes",
                  ],
                },
              },
              remote,
              {
                binaries: {
                  ssh: "/usr/bin/ssh",
                  scp: "",
                  rsync: "",
                  tailscale: "",
                  sshpass: "",
                },
                sendEnvKeys: [],
                noMultiplex: true,
                hostKey: { strict: true, knownHostsFile: known, alias },
              },
            );
            const result = await deps.process({
              args: ["-F", "/dev/null", "-T", ...argv.slice(1)],
              input: body,
              signal,
            });
            need(
              !signal.aborted && result.code === 0,
              "agent.transport_uncertain",
            );
            const reply = z.discriminatedUnion("ok", [
              z.strictObject({ ok: z.literal(true), data: command.output }),
              z.strictObject({
                ok: z.literal(false),
                error: z.strictObject({
                  code: z.string(),
                  message: z.string(),
                  details: z.unknown(),
                }),
              }),
            ]).parse(JSON.parse(result.stdout));
            if (!reply.ok) {
              need(
                command.errors.includes(reply.error.code),
                "agent.reply_invalid",
              );
              throw new AgentCallError(reply.error.code);
            }
            const handle = await ctx.writeResource(
              specs.get(command.name)!,
              `op-${args.operation_id}.${args.step}`,
              {
                node_id: args.node_id,
                operation_id: args.operation_id,
                step: args.step,
                command: command.name,
                effect_key: effectKey,
                data: reply.data,
              },
            );
            return { dataHandles: [handle] };
          } catch (error) {
            throw error instanceof AgentCallError
              ? error
              : new AgentCallError("agent.call_invalid");
          } finally {
            await cleanup(identities, directory);
          }
        },
      },
    }],
  };
}

async function cleanup(
  identities: Awaited<ReturnType<typeof prepareIdentities>> | undefined,
  directory: string | undefined,
) {
  let failed = false;
  try {
    await identities?.cleanup();
  } catch {
    failed = true;
  }
  try {
    if (directory) await Deno.remove(directory, { recursive: true });
  } catch {
    failed = true;
  }
  if (failed) throw new AgentCallError("agent.cleanup_failed");
}

// One schema declares both native output vaulting and Node receipt sanitization.
function sensitivePaths(schema: unknown): string[] {
  const paths: string[] = [];
  const visit = (value: unknown, path: string) => {
    if (!value || typeof value !== "object") return;
    const node = value as Record<string, unknown>;
    if (node.sensitive === true) {
      need(
        path.length > 0 && path.length <= 192 &&
          /^[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*$/.test(path),
      );
      paths.push(path);
      return;
    }
    if (node.properties && typeof node.properties === "object") {
      for (const [key, child] of Object.entries(node.properties)) {
        visit(child, path ? `${path}.${key}` : key);
      }
    }
    // Node receipt paths do not support array elements or schema alternatives.
    for (const key of ["items", "$defs", "anyOf", "oneOf", "allOf"]) {
      if (node[key]) need(!containsSensitive(node[key]));
    }
  };
  visit(schema, "");
  need(paths.length <= 64);
  return paths;
}

function containsSensitive(value: unknown): boolean {
  if (!value || typeof value !== "object") return false;
  if ((value as Record<string, unknown>).sensitive === true) return true;
  return Object.values(value).some(containsSensitive);
}
