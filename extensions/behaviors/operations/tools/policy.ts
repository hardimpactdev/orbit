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
  if (verb !== "remove") validateConstraint(input);
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
  } else if (verb === "adopt") {
    let composerRoots: unknown;
    if (input.manager === "composer") {
      const scope = await run("adoption-scope");
      composerRoots = scope.roots;
    }
    final = await run("tool-verified");
    if (final.installed_version === null && final.block === null) {
      throw new ToolRefusal("tool.package_absent");
    }
    if (
      input.manager === "composer" &&
      (!Array.isArray(composerRoots) ||
        !composerRoots.includes(input.package))
    ) {
      throw new ToolRefusal("tool.adoption_unsupported", {
        block: "dependency",
      });
    }
    if (final.block !== null) {
      throw new ToolRefusal("tool.adoption_unsupported", {
        block: final.block,
      });
    }
    if (!safeVersion(final.installed_version)) {
      throw new ToolRefusal("tool.version_probe_failed");
    }
    verifyInstalled(input, final.installed_version);
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
    if (before !== null && verb !== "remove") {
      verifyInstalled(input, before);
    }
    let mutate = !(verb === "install" && before !== null) &&
      !(verb === "remove" && before === null);
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
    if (
      mutate && verb === "remove" &&
      ["apt", "vp", "composer"].includes(input.manager)
    ) {
      const plan = await run("package-removal-plan");
      if (
        !Array.isArray(plan.packages) || plan.packages.length !== 1 ||
        plan.packages[0] !== input.package
      ) throw new ToolRefusal("tool.removal_plan_unsafe");
    }
    if (mutate) {
      await run("package-mutate");
    }
    final = await run("tool-verified");
    const after = final.installed_version;
    if (after !== null && !safeVersion(after)) {
      throw new ToolRefusal("tool.version_probe_failed");
    }
    if (verb === "remove") {
      if (after !== null) throw new ToolRefusal("tool.remove_failed");
    } else {
      if (after === null) {
        throw new ToolRefusal("tool.version_probe_failed");
      }
      verifyInstalled(input, after);
    }
  }

  return final;
}
