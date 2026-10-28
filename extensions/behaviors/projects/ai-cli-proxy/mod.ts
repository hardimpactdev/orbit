import { projectConfig } from "../../_lib/project_extension.ts";

/** ai-cli-proxy project configuration; maintenance stays in the shared extensions. */
export const behavior = projectConfig("@nckrtl/project-ai-cli-proxy", {
  "projectId": 51,
  "repository": "https://github.com/nckrtl/ai-cli-proxy.git",
  "worker": {
    "composerPaths": [
      ".",
    ],
    "jsPaths": [
      ".",
    ],
    "jsPackageManagers": { ".": "bun" },
    "policy": {},
  },
  "check": "composer check",
  "defaultBranch": "main",
});
