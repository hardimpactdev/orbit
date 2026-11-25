import { z } from "npm:zod@4.4.3";
import { allows, exactMatches, PackageInput, safeVersion, ToolRefusal, validateConstraint } from "../tools/contract.ts";
import { aptWrite, candidate, commands, inventory, refreshed } from "../tools/native.ts";
import { candidate as checkCandidate, writeInventory } from "../tools/write_policy.ts";

const Observation = z.object({ rc: z.number().int(), stdout: z.string(), stderr: z.string().default("") });
const step = (value: unknown) => {
    const observation = Observation.parse(value);
    return { status: "succeeded", outputs: { exitCode: observation.rc, stdout: observation.stdout, stderr: observation.stderr } };
};

/** Local policy only. Ansible owns every machine read, mutation and failure boundary. */
export function toolState(mode: string, value: unknown): Record<string, unknown> {
    const payload = z.object({
        tool: z.record(z.string(), z.unknown()), node_id: z.number().int().positive(), user: z.string(),
        version: z.unknown().optional(), query: z.unknown().optional(), candidate: z.unknown().optional(),
        os: Observation.optional(), refresh: Observation.optional(),
    }).parse(value);
    const input = { ...PackageInput.parse({ platform: "linux", architecture: "x86_64", ...payload.tool,
        node_id: payload.node_id, user: payload.user }), kind: "tool.install" as const, intent_only: false, materialize: null };
    validateConstraint(input);
    const argv = commands(input);
    if (mode === "plan") {
        if (z.array(z.string()).parse(payload.tool.protected).includes(input.package)) {
            throw new ToolRefusal("tool.package_protected");
        }
        return argv;
    }
    const raw = inventory(input, step(payload.version), step(payload.query), payload.os ? step(payload.os) : null);
    const { facts, present } = writeInventory(raw);
    const version = present ? facts.versions![0] as string : null;
    if (mode === "inspect" && present && input.tool_status === "failed" && input.installed_version === null) {
        throw new ToolRefusal("tool.package_already_installed");
    }
    const matching = version !== null && allows(input, version) && exactMatches(input, version);
    if (mode === "inspect") return { installed_version: version, needs_write: !matching };
    if (mode === "verify") {
        if (!matching) throw new ToolRefusal("tool.installed_version_mismatch");
        return { installed_version: version };
    }
    if (mode !== "candidate") throw new Error("Unknown Tool policy operation");
    if (input.manager === "brew-cask" && input.platform === "linux") refreshed(payload.refresh ? step(payload.refresh) : null);
    const { version: selected } = checkCandidate(input, candidate(input, step(payload.candidate), raw));
    if (!safeVersion(selected)) throw new ToolRefusal("tool.candidate_version_unavailable");
    const write = input.manager === "apt" ? aptWrite(input.package, selected, input.desired_version !== null)
        : commands({ ...input, desired_version: selected,
            kind: present && input.manager.startsWith("brew") ? "tool.update" : input.kind }).write;
    return { write };
}

if (import.meta.main) {
    // The phase asks this once, before it judges a Tool. Reaching this line proves that the runtime loaded the policy and its imports.
    if (Deno.args[0] === "check") {
        console.log(JSON.stringify({ ready: true }));
        Deno.exit(0);
    }
    try {
        const input = await new Response(Deno.stdin.readable).json();
        console.log(JSON.stringify(toolState(Deno.args[0], input)));
    } catch (error) {
        // Never return package-manager output or arbitrary exception text to an Operation.
        console.log(JSON.stringify({ error_code: error instanceof ToolRefusal ? error.code : "tool.install_failed" }));
        Deno.exit(1);
    }
}
