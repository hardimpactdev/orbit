import { projectConfig } from "../../_lib/project_extension.ts";

/** orbit-website project configuration; maintenance stays in the shared extensions. */
export const behavior = projectConfig("@nckrtl/project-orbit-website", {
  "projectId": 33,
  "repository": "https://github.com/nckrtl/orbit-website.git",
  "worker": {
    "composerPaths": [
      ".",
    ],
    "jsPaths": [
      ".",
    ],
    "policy": {},
  },
  "check": "composer test && composer analyse && vp check",
  "defaultBranch": "main",
});
