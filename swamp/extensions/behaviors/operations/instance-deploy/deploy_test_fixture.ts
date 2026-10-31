import type { DeploySourceLocal } from "./deploy_local.ts";

export type LocalCall = {
  name: string;
  input: Record<string, unknown>;
  mutates: boolean;
  sudo: boolean;
};

/** Traces the typed local boundary without running system programs in parent tests. */
export function localFixture(
  dispatch: (call: LocalCall) => Promise<Record<string, unknown>>,
): DeploySourceLocal {
  const call = (name: string, input: Record<string, unknown>, mutates = true) =>
    dispatch({ name, input, mutates, sudo: true });
  return {
    inspectDevelopment: (home) =>
      call("inspectDevelopment", home, false) as ReturnType<
        DeploySourceLocal["inspectDevelopment"]
      >,
    resolveDevelopment: (home, input) =>
      call("resolveDevelopment", { ...home, ...input }) as ReturnType<
        DeploySourceLocal["resolveDevelopment"]
      >,
    checkoutDevelopment: (home, input) =>
      call("checkoutDevelopment", { ...home, ...input }) as ReturnType<
        DeploySourceLocal["checkoutDevelopment"]
      >,
    prepareRelease: (home, input) =>
      call("prepareRelease", { ...home, ...input }) as ReturnType<
        DeploySourceLocal["prepareRelease"]
      >,
    inspectRelease: (home, name) =>
      call("inspectRelease", {
        ...home,
        ...(name === undefined ? {} : { name }),
      }, false) as ReturnType<DeploySourceLocal["inspectRelease"]>,
    activateRelease: (home, input) =>
      call("activateRelease", { ...home, ...input }) as ReturnType<
        DeploySourceLocal["activateRelease"]
      >,
    pruneReleases: (home, input) =>
      call("pruneReleases", { ...home, ...input }) as ReturnType<
        DeploySourceLocal["pruneReleases"]
      >,
  };
}
