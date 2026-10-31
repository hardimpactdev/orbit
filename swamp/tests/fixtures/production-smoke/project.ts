import { projectConfig } from "../../../extensions/behaviors/_lib/project_extension.ts";
import { config } from "./fixture.ts";
export const behavior = {
  ...projectConfig("@nckrtl/project-native-smoke", config),
  type: "@nckrtl/project-native-smoke",
};
