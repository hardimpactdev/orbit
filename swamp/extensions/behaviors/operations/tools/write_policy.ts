import { z } from "npm:zod@4.4.3";
import { allows, exactMatches, Facts, normalizeVersion, safeVersion, type ToolInput, ToolRefusal } from "./contract.ts";
import { homebrewBlock, homebrewCandidateVersion } from "./homebrew.ts";

type PolicyInput = ToolInput & { kind: "tool.install" | "tool.update" };
const Public = z.record(z.string(), z.unknown());

/** The manager's package facts: one admitted scope, a readable manager version and at most one installed version. */
export function writeInventory(value: unknown) {
    const facts = Facts.parse(value);
    if (facts.scope !== "present") {
        throw new ToolRefusal("tool.manager_unavailable");
    }
    if (
        facts.error_code || !safeVersion(facts.manager_version) ||
        facts.complete !== true || !facts.versions ||
        facts.versions.length > 1 ||
        facts.versions.some((version) => !safeVersion(version))
    ) throw new ToolRefusal("tool.version_probe_failed");
    return { facts, present: facts.versions.length === 1 };
}
/** The manager's normal candidate, checked against the constraint without selecting a version. */
export function candidate(
    input: PolicyInput,
    value: unknown,
): { version: string; allowed: boolean } {
    const raw = Public.safeParse(value);
    if (!raw.success) {
        throw new ToolRefusal("tool.candidate_version_probe_failed");
    }
    const version = input.manager.startsWith("brew")
        ? homebrewCandidateVersion(input, raw.data)
        : raw.data.version;
    if (
        input.manager.startsWith("brew") && homebrewBlock(input, raw.data, true)
    ) throw new ToolRefusal("tool.candidate_version_probe_failed");
    if (raw.data.error_code !== undefined) {
        throw new ToolRefusal("tool.candidate_version_probe_failed");
    }
    if (!safeVersion(version)) {
        throw new ToolRefusal(input.desired_version === null ? "tool.candidate_version_unavailable" : "tool.desired_version_unavailable");
    }
    if (!exactMatches(input, version)) {
        throw new ToolRefusal("tool.desired_version_unavailable");
    }
    if (
        input.version_constraint !== null &&
        normalizeVersion(version, input.manager) === null
    ) throw new ToolRefusal("tool.candidate_version_unparseable");
    const allowed = allows(input, version);
    if (!allowed && (input.kind === "tool.install" || input.desired_version !== null)) {
        throw new ToolRefusal("tool.version_constraint_blocked");
    }
    return { version, allowed };
}
