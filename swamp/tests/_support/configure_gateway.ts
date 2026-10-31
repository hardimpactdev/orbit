import { parse, stringify } from "jsr:@std/yaml@1.0.11";
import { type DependencyProject } from "../../extensions/behaviors/_lib/dependency_project_schema.ts";

/** Change only the fixture transport and additional synthetic project config. */
export async function configureGateway(
  dir: string,
  url: string,
  projects: DependencyProject[] = [],
) {
  const file = `${dir}/models/@nckrtl/orbit-gateway/orbit-gateway.yaml`;
  const definition = parse(await Deno.readTextFile(file)) as {
    globalArguments: {
      gatewayUrl: string;
      dependencyProjects: DependencyProject[];
    };
  };
  definition.globalArguments.gatewayUrl = url;
  definition.globalArguments.dependencyProjects = projects;
  await Deno.writeTextFile(file, stringify(definition));
}
