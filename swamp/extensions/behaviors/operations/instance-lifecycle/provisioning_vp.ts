import { ProvisioningRefusal } from "./source_profile.ts";
import { prepareDeployVp } from "../instance-deploy/deploy_vp.ts";
import { type Provisioning } from "./provisioning_source.ts";
export { vpRequirements as provisioningVpRequirements } from "../instance-deploy/vp_requirements.ts";
export type ProvisioningVpPort = Parameters<typeof prepareDeployVp>[2];
type Plan = Pick<
  Provisioning,
  | "node_id"
  | "platform"
  | "architecture"
  | "application"
  | "user"
  | "node_user"
  | "vp_materialize"
>;

/** Compose the canonical native installer; manifests and registry settings stay on the worker. */
export async function prepareProvisioningVp(
  plan: Plan,
  port: ProvisioningVpPort,
  signal?: AbortSignal,
) {
  const result = await prepareDeployVp(
    {
      node_id: plan.node_id,
      platform: plan.platform,
      architecture: plan.architecture,
      path: plan.application,
      user: plan.user,
      node_user: plan.node_user,
      materialize: plan.vp_materialize,
    },
    Date.now() + 900000,
    port,
    signal,
  );
  if ("error_code" in result) {
    throw new ProvisioningRefusal(result.error_code, "vp");
  }
  return result.runtime;
}
