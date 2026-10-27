import { projectConfig } from "../../_lib/project_extension.ts";

/** srpm project configuration; maintenance stays in the shared extensions. */
export const behavior = projectConfig("@nckrtl/project-srpm", {
  "projectId": 25,
  "repository": "https://github.com/hardimpactdev/srpm.git",
  "worker": {
    "composerPaths": [
      ".",
    ],
    "jsPaths": [
      ".",
    ],
    "policy": {
      ".": {
        "minimumReleaseAgeDays": 7,
      },
    },
  },
  "check": "composer check",
  "defaultBranch": "main",
});
