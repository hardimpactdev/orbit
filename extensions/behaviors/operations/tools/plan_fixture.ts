// Fake Gateway admission. Production consumes descriptors; it never builds this catalogue.
import { Operation } from "../../operation-runner/mod.ts";
import type { z } from "npm:zod@4.4.3";

export function fixturePlan(operation: z.infer<typeof Operation>) {
  const input = operation.input, manager = String(input.manager);
  const verb = operation.kind.slice(5);
  const prefix = manager === "brew"
    ? "tool.homebrew-formula-"
    : manager === "brew-cask"
    ? "tool.homebrew-cask-"
    : `tool.${manager}-`;
  const version = manager.startsWith("brew")
    ? "tool.homebrew-version"
    : `${prefix}version`;
  const packageInput = { package: input.package };
  const bottle = {
    ...packageInput,
    ...(input.platform === "macos" && manager.startsWith("brew")
      ? { architecture: input.architecture }
      : {}),
  };
  const descriptors: z.infer<
    typeof Operation
  >["execution_context"]["descriptors"] = [];
  const add = (key: string, name: string, body: Record<string, unknown> = {}) =>
    descriptors.push({
      key,
      step: key,
      transport: "agent",
      name,
      input: body,
      resumable: true,
      required: key === "tool-verified" || key.startsWith("manager-"),
    });
  if (
    ["install", "manager_converge"].includes(verb) &&
    typeof input.materialize_command === "string"
  ) {
    add(
      "manager-materialize",
      input.materialize_command,
      Object.fromEntries(
        (input.materialize_fields as string[]).map((
          field,
        ) => [field, input[field]]),
      ),
    );
  }
  add("manager-ready", version);
  if (verb === "manager_converge" || input.intent_only) {
    add("tool-verified", version);
  } else if (verb === "adopt") {
    if (manager === "composer") {
      add("adoption-scope", "tool.inspect-composer-scope");
    }
    add(
      "tool-verified",
      `${prefix}${manager.startsWith("brew") ? "adoption" : "inventory"}`,
      manager.startsWith("brew") ? bottle : {},
    );
  } else {
    add("package-before", `${prefix}inventory`);
    if (["install", "update"].includes(verb)) {
      add(
        "package-candidate",
        `${prefix}candidate`,
        manager === "composer" ? { ...packageInput, operation: verb } : bottle,
      );
    }
    if (verb === "remove" && ["apt", "vp", "composer"].includes(manager)) {
      add("package-removal-plan", `${prefix}removal-plan`, packageInput);
    }
    add(
      "package-mutate",
      `${prefix}${verb}`,
      manager === "brew" && verb === "remove" ? packageInput : bottle,
    );
    add("tool-verified", `${prefix}inventory`);
  }
  return { descriptors };
}
