import { provisioningRoot } from "./provisioning_root.ts";
export const behavior = {
  ...provisioningRoot("instance.clone"),
  type: "@nckrtl/instance-clone",
};
