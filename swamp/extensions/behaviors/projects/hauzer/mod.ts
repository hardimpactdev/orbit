import { projectConfig } from "../../_lib/project_extension.ts";

/** hauzer project configuration; maintenance stays in the shared extensions. */
export const behavior = projectConfig("@nckrtl/project-hauzer", {
  "projectId": 16,
  "repository": "https://github.com/hardimpactdev/hauzer.git",
  "worker": {
    "composerPaths": [
      ".",
    ],
    "jsPaths": [
      ".",
      "mobile",
    ],
    "jsPackageManagers": { ".": "npm", "mobile": "npm" },
    "policy": {
      ".": {
        "minimumReleaseAgeDays": 7,
      },
      "mobile": {
        "minimumReleaseAgeDays": 7,
      },
    },
  },
  "check": "composer check",
  "defaultBranch": "main",
});
