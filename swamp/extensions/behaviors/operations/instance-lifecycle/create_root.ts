import { provisioningRoot } from "./provisioning_root.ts";
export const behavior = {
  ...provisioningRoot("instance.create"),
  type: "@nckrtl/instance-create",
};
