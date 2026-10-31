import { z } from "npm:zod@4.4.3";
import { gatewayHttp, type Http } from "../../automatic-deploy/gateway.ts";
import { Globals } from "../_lib/direct.ts";
export type Context = {
  globalArgs: z.infer<typeof Globals>;
  signal?: AbortSignal;
};
import {
  DirectArgs,
  directOperation,
  GatewayOperation,
} from "../_lib/direct.ts";
import { metricsRuntimeActions } from "./metrics_runtime_actions.ts";

const Args = DirectArgs.extend({
  kind: z.literal("metrics.credentials_reset"),
  node_id: z.number().int().positive(),
});
const Plan = z.object({
  address: z.ipv4(),
});
const Credential = z.object({
  active_password: z.string().min(1).max(1024),
  pending_password: z.string().min(1).max(1024),
});
type CredentialAction = (
  input: { address: string; action: Record<string, unknown> },
) => Promise<Record<string, unknown>>;

/** One local native action; private credentials never leave this callback. */
export function metricsCredentialsReset(
  makeHttp = (ctx: Context): Http =>
    gatewayHttp(ctx.globalArgs.gatewayUrl, ctx.globalArgs.caFile),
  credentialAction: CredentialAction = metricsRuntimeActions().credential,
) {
  return {
    type: "@nckrtl/metrics-credentials-reset",
    version: "2026.10.31.1",
    globalArguments: Globals,
    methods: {
      reset: {
        description:
          "Verify, reset and verify the admitted pending Grafana password",
        arguments: Args,
        async execute(raw: z.infer<typeof Args>, ctx: Context) {
          const args = Args.parse(raw);
          const gateway = new GatewayOperation(makeHttp(ctx), args);
          return await directOperation(
            args,
            gateway,
            async (input) => {
              const plan = Plan.parse(input);
              let result: Record<string, unknown> | undefined;
              await gateway.withPrivateMaterial(async (material) => {
                const credential = Credential.parse(material.credential);
                const call = (action: Record<string, unknown>) =>
                  credentialAction({ address: plan.address, action });
                // A lost projection may follow a successful reset. Verify first on every resume.
                const before = await call({
                  operation: "verify",
                  password: credential.pending_password,
                });
                if (before.verified === true) {
                  result = { reset: true };
                  return;
                }
                if (before.verified !== false) {
                  result = { error_code: "metrics.credentials_unverified" };
                  return;
                }
                const applied = await call({
                  operation: "apply",
                  ...credential,
                });
                if (applied.changed !== true) {
                  result = { error_code: "metrics.credentials_reset_failed" };
                  return;
                }
                const after = await call({
                  operation: "verify",
                  password: credential.pending_password,
                });
                result = after.verified === true
                  ? { reset: true }
                  : { error_code: "metrics.credentials_reset_unverified" };
              });
              if (!result) {
                throw new Error("metrics.credentials_outcome_unknown");
              }
              return result;
            },
            (value) => value,
            ctx.signal,
          );
        },
      },
    },
  };
}
export const behavior = metricsCredentialsReset();
