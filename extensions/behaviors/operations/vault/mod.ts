import { z } from "npm:zod@4.4.3";
import { gatewayHttp, type Http } from "../../automatic-deploy/gateway.ts";
import { type Context, Globals } from "../../operation-runner/mod.ts";
import {
  DirectArgs,
  directOperation,
  GatewayOperation,
} from "../_lib/direct.ts";
import { collection } from "./collection.ts";
const Args = DirectArgs.extend({
  kind: z.enum(["vault.sync", "vault.setup", "vault.teardown"]),
});
const Text = z.string().max(1024);
const Metadata = z.object({
  backend: z.enum(["configured", "synced", "absent"]),
  email: Text.optional(),
  organization_id: Text.optional(),
  organization_name: Text.optional(),
  collection_id: Text.optional(),
  collection_name: Text.optional(),
  sources: z.array(Text).max(4096).optional(),
  cipher_ids: z.array(Text).max(4096).optional(),
  revisions: z.array(Text).max(4096).optional(),
  fingerprints: z.array(Text).max(4096).optional(),
}).strict();
const Safe = z.union([
  z.object({ outcome: z.literal("succeeded"), data: Metadata }).strict(),
  z.object({ error_code: z.string().regex(/^vault\.[a-z_]{1,80}$/) }).strict(),
]);
type Invoke = (
  action: string,
  config: Record<string, unknown>,
  ca: string,
  signal?: AbortSignal,
) => Promise<unknown>;

/** Match the encrypted intent's protected fields before leaving private scope. */
function containsPrivate(
  value: unknown,
  config: Record<string, unknown>,
): boolean {
  const protectedValues: unknown[] = [
    config.client_secret,
    config.master_password,
  ];
  for (const item of Array.isArray(config.wanted) ? config.wanted : []) {
    const fields = item?.cipher?.fields;
    for (const field of Array.isArray(fields) ? fields : []) {
      if (field?.hidden === true) protectedValues.push(field.value);
    }
  }
  const secrets = protectedValues.filter((value): value is string =>
    typeof value === "string" && value.length > 0
  );
  const matches = (item: unknown): boolean =>
    typeof item === "string"
      ? secrets.some((secret) => item.includes(secret))
      : item !== null && typeof item === "object"
      ? Object.values(item).some(matches)
      : false;
  return matches(value);
}
/** Ported collection program, no SSH, PHP executor, argv credentials or durable input. */
export async function localCollection(
  action: string,
  config: Record<string, unknown>,
  ca: string,
  signal?: AbortSignal,
  makeDeadline = AbortSignal.timeout,
): Promise<unknown> {
  const deadline = makeDeadline(900_000);
  const overall = signal ? AbortSignal.any([signal, deadline]) : deadline;
  const child = new Deno.Command("/usr/bin/python3", {
    args: ["-c", collection],
    stdin: "piped",
    stdout: "piped",
    stderr: "null",
    signal: overall,
  }).spawn();
  const writer = child.stdin.getWriter();
  await writer.write(
    new TextEncoder().encode(
      JSON.stringify({ action, configuration: { ...config, ca_file: ca } }),
    ),
  );
  await writer.close();
  const output = await child.output();
  if (!output.success || output.stdout.length > 262144) {
    throw new Error("vault.unreachable");
  }
  return JSON.parse(new TextDecoder().decode(output.stdout));
}
export async function executeVault(
  args: z.infer<typeof Args>,
  http: Http,
  ca: string,
  invoke: Invoke,
  signal?: AbortSignal,
) {
  let result: Record<string, unknown> = { error_code: "vault.unreachable" };
  try {
    await new GatewayOperation(http, args).withPrivateMaterial(
      async (material) => {
        try {
          const config = z.record(z.string(), z.unknown()).parse(
            material.configuration,
          );
          const action = args.kind.slice(6);
          const raw = z.object({
            outcome: z.enum(["succeeded", "failed"]),
            data: z.record(z.string(), z.unknown()),
          }).parse(await invoke(action, config, ca, signal));
          if (containsPrivate(raw, config)) {
            result = { error_code: "vault.result_invalid" };
            return;
          }
          if (raw.outcome === "failed") {
            const code = z.string().regex(/^vault\.[a-z_]{1,80}$/).safeParse(
              raw.data.error_code,
            );
            result = {
              error_code: code.success ? code.data : "vault.unreachable",
            };
            return;
          }
          const safe = Safe.parse(raw);
          result = "data" in safe &&
              safe.data.backend ===
                ({
                  setup: "configured",
                  sync: "synced",
                  teardown: "absent",
                }[action])
            ? safe
            : { error_code: "vault.result_invalid" };
        } catch {
          result = { error_code: "vault.unreachable" };
        }
      },
    );
  } catch { /* Private callback diagnostics are never public. */ }
  return result;
}

export function vaultDirect(
  makeHttp = (ctx: Context) =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
  invoke: Invoke = localCollection,
) {
  return {
    type: "@nckrtl/vault-direct",
    version: "2026.10.30.1",
    globalArguments: Globals,
    resources: {},
    methods: {
      apply: {
        description:
          "Reconcile the admitted Vault collection on a Swamp worker",
        arguments: Args,
        execute: async (args: z.infer<typeof Args>, ctx: Context) => {
          const http = makeHttp(ctx);
          return await directOperation(
            args,
            new GatewayOperation(http, args),
            () =>
              executeVault(
                args,
                http,
                ctx.globalArgs.caFile,
                invoke,
                ctx.signal,
              ),
            Safe.parse,
            ctx.signal,
          );
        },
      },
    },
  };
}
export const behavior = { ...vaultDirect(), type: "@nckrtl/vault-direct" };
