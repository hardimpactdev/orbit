import { z } from "npm:zod@4.4.3";
import type { Http } from "../../automatic-deploy/gateway.ts";
import { type Context, Operation } from "../../operation-runner/mod.ts";
import { executeComposition } from "./execution.ts";
import {
  changePublication,
  changeTargets,
  changeWebRoot,
  convergeRoute,
  removeRoute,
  replaceDomain,
  replaceRouter,
  retireSource,
  RouteRefusal,
  webRootPhases,
} from "./lifecycle.ts";

export const rootKinds = [
  "route.create",
  "route.converge",
  "route.withdraw",
  "route.destroy",
  "route.update",
  "route.target_set",
  "route.target_unset",
  "route.retire_source",
  "cluster.router_set",
  "cluster.router_unset",
];

export async function executeRoot(
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
    operation.kind.startsWith("cluster.")
      ? "route.cluster-router"
      : "route.parent",
    async (stages, plan) => {
      const branch = operation.execution_context.result?.route_branch;
      if (typeof branch === "string") {
        const phases = branch === "rollback_initial"
          ? [
            "rollback-initial-dns",
            "rollback-initial-caddy",
            "rollback-initial-php",
            "synchronize-web-root-urls.rollback",
            "rollback-initial-certificates",
            "rollback-initial-firewall",
          ]
          : branch === "rollback_targets"
          ? [
            "rollback-preparations-caddy",
            "rollback-preparations-certificates",
            "rollback-preparations-record",
          ]
          : branch === "rollback_router"
          ? [
            "restore-dns",
            "await-restored-dns-expiry",
            "withdraw-candidate-sites",
            "remove-candidate-certificates",
            "remove-candidate-firewall",
          ]
          : branch === "rollback_web_root"
          ? webRootPhases(plan.production, true)
          : branch === "rollback_publication"
          ? ["rollback-public-edge", "rollback-public-firewall"]
          : branch === "rollback_public_edge"
          ? ["rollback-public-edge"]
          : [
            ...(plan.public ? ["rollback-public-edge"] : []),
            "rollback-environment",
            "rollback-dns",
            "rollback-caddy",
            "rollback-certificates",
            "rollback-record",
          ];
        for (const phase of phases) await stages.run(phase);
        throw new RouteRefusal(
          String(
            operation.execution_context.result?.failure_code ??
              "route.child_failed",
          ),
        );
      }
      switch (plan.mode) {
        case "router_set":
          await replaceRouter(stages);
          break;
        case "router_noop":
          for (const phase of ["prune-dns", "complete-router"]) {
            await stages.run(phase);
          }
          break;
        case "router_unset":
          for (
            const phase of [
              "withdraw-assignments",
              "dns-publication",
              "remove-old-baseline",
              "complete-router",
            ]
          ) await stages.run(phase);
          break;
        case "target_binding":
          for (const phase of ["target-source-access", "state-recorded"]) {
            await stages.run(phase);
          }
          break;
        case "publication":
          await changePublication(
            stages,
            plan.activate_public,
            plan.withdraw_public,
          );
          break;
        case "web_root":
          await changeWebRoot(stages, plan.production, plan.changed);
          break;
        case "converge":
          await convergeRoute(stages, {
            production: plan.production,
            public: plan.public,
            remoteRouter: plan.remote_router,
            webRoot: plan.web_root,
          });
          break;
        case "withdraw":
          await removeRoute(stages, plan.targeted);
          break;
        case "replacement_after_cutover":
          if (plan.public) {
            try {
              for (
                const phase of [
                  "public-edge-verified",
                  "public-activated",
                  "ingress-firewall",
                ]
              ) await stages.run(phase);
            } catch (error) {
              if (
                error instanceof RouteRefusal &&
                !stages.completed("public-activated")
              ) await stages.run("rollback-public-edge");
              throw error;
            }
          }
          for (
            const phase of [
              "prepare-cleanup",
              "cleanup-caddy",
              "cleanup-certificates",
              "synchronize-current-environment",
              "verify-current-workload",
              "complete-replacement",
            ]
          ) await stages.run(phase);
          break;
        case "replacement_cleanup":
          for (
            const phase of [
              "prepare-cleanup",
              "cleanup-caddy",
              "cleanup-certificates",
              "synchronize-current-environment",
              "verify-current-workload",
              "complete-replacement",
            ]
          ) await stages.run(phase);
          break;
        case "replacement":
          await replaceDomain(stages, {
            production: plan.production,
            public: plan.public,
          });
          break;
        case "retire_source":
          await retireSource(stages);
          break;
        case "targets_recover":
          for (
            const phase of [
              "database-committed",
              "environment-synchronized",
              "target-live-certificates",
              "target-live-caddy",
              "router-published",
              "vacated-published",
              "target-staging-cleanup",
              "removal",
              "completed",
            ]
          ) await stages.run(phase);
          break;
        case "targets":
          await changeTargets(stages);
          break;
        case "replace_pending":
          await stages.run("replace-pending");
          break;
        case "state_only":
          await stages.run("state-recorded");
          break;
        case "withdraw_replacement":
          for (
            const phase of [
              ...(plan.public ? ["rollback-public-edge"] : []),
              "rollback-environment",
              "rollback-dns",
              "rollback-caddy",
              "rollback-certificates",
              "rollback-record",
            ]
          ) await stages.run(phase);
          break;
        default:
          throw new Error("Unknown Gateway Route plan");
      }
      if (plan.metrics) await stages.run("metrics-reconciled");
    },
  );
}
