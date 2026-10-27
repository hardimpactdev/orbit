import { WEBSITE_PROJECT } from "./dependency_projects.ts";

/** Script-path routing fixtures still resolve Project 20 through its private extension. */
export async function scriptProjectExtension(dir: string) {
  await Deno.writeTextFile(
    `${dir}/extensions/behaviors/projects/nckrtl-com/mod.ts`,
    `import { projectConfig } from "../../_lib/project_extension.ts";\nexport const behavior = projectConfig("@nckrtl/project-nckrtl-com", ${
      JSON.stringify(WEBSITE_PROJECT)
    });\n`,
  );
}
