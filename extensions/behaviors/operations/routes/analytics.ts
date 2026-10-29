import { z } from "npm:zod@4.4.3";
import type { Http } from "../../automatic-deploy/gateway.ts";
import { type Context, Operation } from "../../operation-runner/mod.ts";
import { executeComposition } from "./execution.ts";

export const analyticsKinds = [
  "route.analytics_publish",
  "route.analytics_remove",
  "route.analytics_host_prepare",
  "route.analytics_host_withdraw",
];

export async function executeAnalytics(
  args: { operationId: number; worker: string },
  ctx: Context,
  http: Http,
  operation: z.infer<typeof Operation>,
) {
  return await executeComposition(
    args,
    ctx,
    http,
    operation,
    "route.analytics-parent",
    async (stages, plan) => {
      const sequence = operation.kind === "route.analytics_host_prepare"
        ? ["host-certificate"]
        : operation.kind === "route.analytics_host_withdraw"
        ? ["await-dns-expiry", "host-withdraw", "host-certificate-remove"]
        : operation.kind === "route.analytics_remove"
        ? ["dns", "caddy", "certificates", "firewall", "record"]
        : [
          "host-certificate",
          "publish-sites",
          "host-caddy",
          "dns-published",
          ...(plan.public
            ? ["public-edge-verified", "public-activated", "ingress-firewall"]
            : []),
          "activate-route",
        ];
      for (const stage of sequence) await stages.run(stage);
    },
  );
}
