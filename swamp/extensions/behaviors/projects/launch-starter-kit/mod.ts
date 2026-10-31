import { projectConfig } from "../../_lib/project_extension.ts";

/** launch-starter-kit project configuration; maintenance stays in the shared extensions. */
export const behavior = projectConfig("@nckrtl/project-launch-starter-kit", {
  "projectId": 30,
  "repository": "https://github.com/nckrtl/launch-starter-kit.git",
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
  "check": "composer test && composer analyse && vp check",
  "defaultBranch": "main",
});
