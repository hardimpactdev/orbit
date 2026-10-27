import { projectConfig } from "../../_lib/project_extension.ts";

/** operator project configuration; maintenance stays in the shared extensions. */
export const behavior = projectConfig("@nckrtl/project-operator", {
  "projectId": 48,
  "repository": "https://github.com/nckrtl/operator.git",
  "worker": {
    "composerPaths": [
      "server/web",
    ],
    "jsPaths": [
      "server/web",
    ],
    "policy": {
      "server/web": {
        "minimumReleaseAgeDays": 7,
      },
    },
  },
  "check": "composer --working-dir=server/web check",
  "defaultBranch": "main",
});
