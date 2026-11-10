import { z } from "npm:zod@4.4.3";
import { allows, PackageInput, safeVersion, validateConstraint } from "../tools/contract.ts";
import { candidate, commands, inventory } from "../tools/native.ts";

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
    }).parse(value);
    const input = { ...PackageInput.parse({ ...payload.tool, node_id: payload.node_id, user: payload.user,
        platform: "linux", architecture: "x86_64" }), intent_only: false, materialize: null };
    validateConstraint(input);
    const nativeInput = { ...input, kind: "tool.install" };
    const argv = commands(nativeInput);
    if (mode === "plan") {
        if (z.array(z.string()).parse(payload.tool.protected).includes(input.package)) {
            throw new Error("Protected recipe package");
        }
        return argv;
    }
    const facts = inventory(nativeInput, step(payload.version), step(payload.query));
    const installed = facts.versions;
    if (installed.length > 1 || installed.some((v) => !safeVersion(v))) throw new Error("Ambiguous Tool inventory");
    const version = installed.length === 1 ? installed[0] as string : null;
    const matching = version !== null && allows(input, version);
    if (mode === "inspect") return { installed_version: version, needs_write: !matching };
    if (mode === "verify") {
        if (!matching) throw new Error("Tool postcondition failed");
        return { installed_version: version };
    }
    if (mode !== "candidate") throw new Error("Unknown Tool policy operation");
    const next = candidate(nativeInput, step(payload.candidate), facts);
    const selected = "version" in next ? next.version
        : z.object({ versions: z.object({ stable: z.string() }) }).parse(next.homebrew).versions.stable;
    if (!safeVersion(selected) || !allows(input, selected)) throw new Error("Candidate violates recorded constraint");
    let write = argv.write;
    if (input.manager === "apt") write = ["/usr/bin/env", "LC_ALL=C", "/usr/bin/apt-get", "install", "-y", "--allow-downgrades", "--no-install-recommends", "--", input.package + "=" + selected];
    else if (input.manager === "vp") write = commands({ ...nativeInput, package: input.package + "@" + selected }).write;
    else if (input.manager === "composer") write = argv.write.map((arg) => arg === input.package + ":*" ? input.package + ":" + selected : arg);
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
    } catch {
        // Never return package-manager output or arbitrary exception text to an Operation.
        console.log(JSON.stringify({ error_code: "tool.install_failed" }));
        Deno.exit(1);
    }
}
