import {
  allows,
  normalizeVersion,
  type Result,
  safeVersion,
  type ToolInput,
  ToolRefusal,
  validateConstraint,
  verifyInstalled,
} from "./contract.ts";

/** Shared package policy; execution and native checkpoints belong to its caller. */
export async function executeToolPolicy(
  input: ToolInput,
  kind: string,
  run: (step: string) => Promise<Result>,
  hasMaterialize: boolean,
): Promise<Result> {
  const verb = kind.slice(5);
  validateConstraint(input);
  let final: Result;
  if (
    hasMaterialize
  ) {
    await run("manager-materialize");
  }
  await run("manager-ready");
  if (verb === "manager_converge") {
    final = await run("tool-verified");
  } else if (input.intent_only) {
    final = await run("tool-verified");
  } else {
    const beforeReceipt = await run("package-before");
    const before = beforeReceipt.installed_version;
    if (before !== null && !safeVersion(before)) {
      throw new ToolRefusal("tool.version_probe_failed");
    }
    if (
      verb === "install" && input.tool_id === null && before !== null
    ) throw new ToolRefusal("tool.package_already_installed");
    if (verb === "update" && before === null) {
      throw new ToolRefusal("tool.version_probe_failed");
    }
    if (before !== null) {
      verifyInstalled(input, before);
    }
    let mutate = !(verb === "install" && before !== null);
    if (
      mutate && (verb === "install" || verb === "update") &&
      input.version_constraint !== null
    ) {
      const candidate = await run("package-candidate");
      if (!safeVersion(candidate.version)) {
        throw new ToolRefusal("tool.candidate_version_unavailable");
      }
      if (
        normalizeVersion(candidate.version, input.manager) === null
      ) throw new ToolRefusal("tool.candidate_version_unparseable");
      if (!allows(input, candidate.version)) {
        if (verb === "install") {
          throw new ToolRefusal("tool.version_constraint_blocked", {
            outcome: "blocked_by_constraint",
          });
        }
        mutate = false;
      }
    }
    if (mutate) {
      await run("package-mutate");
    }
    final = await run("tool-verified");
    const after = final.installed_version;
    if (after !== null && !safeVersion(after)) {
      throw new ToolRefusal("tool.version_probe_failed");
    }
    if (after === null) throw new ToolRefusal("tool.version_probe_failed");
    verifyInstalled(input, after);
  }

  return final;
}
