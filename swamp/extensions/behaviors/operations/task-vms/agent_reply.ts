import { z } from "npm:zod@4.4.3";

/**
 * Pure shaping for agent envelopes and replies. The UpCloud template build still talks to the agent in its guest;
 * the Incus image build does not, so nothing here is shared with it.
 */
const hash = z.string().regex(/^[a-f0-9]{64}$/);
const object = z.record(z.string(), z.unknown());
const Code = /^[a-z0-9_]+\.[a-z0-9_.-]{1,80}$/;

/** One agent call on stdin: the frozen `{agent_sha256, deadline_ms, input}` line. */
export function envelope(
    pin: string,
    seconds: number,
    input: Record<string, unknown>,
): string {
    return JSON.stringify({
        agent_sha256: hash.parse(pin),
        deadline_ms: seconds * 1000,
        input,
    }) + "\n";
}

const Reply = z.discriminatedUnion("ok", [
    z.object({ ok: z.literal(true), data: object }),
    z.object({
        ok: z.literal(false),
        error: z.object({ code: z.string() }).passthrough(),
    }),
]);

/** A failed agent reply keeps its own public code; anything unreadable is a build failure. */
export function agentData(stdout: string): Record<string, unknown> {
    let reply: z.infer<typeof Reply>;
    try {
        reply = Reply.parse(JSON.parse(stdout));
    } catch {
        throw new Error("task_vm.image_build_failed");
    }
    if (!reply.ok) {
        throw new Error(
            Code.test(reply.error.code)
                ? reply.error.code
                : "task_vm.image_build_failed",
        );
    }
    return reply.data;
}

export const Expect = z.enum([
    "ready",
    "installed",
    "started",
    "complete",
    "seed-volume",
]);
const Observation = z.object({
    boot_id: z.string().uuid(),
    state: z.enum(["missing", "running", "complete", "failed"]),
}).passthrough();

/** Check one agent reply against what its step needs before the next step may run. */
export function agentReply(args: {
    stdout: string;
    expect: z.infer<typeof Expect>;
    pin?: string;
    boot_id?: string;
    fingerprint?: string;
}): { ok: true } {
    const data = agentData(args.stdout);
    const failed = () => new Error("task_vm.image_build_failed");
    switch (args.expect) {
        case "ready":
            if (data.ready !== true) throw failed();
            break;
        case "installed":
            if (
                data.sha256 !== args.pin || typeof data.replaced !== "boolean"
            ) {
                throw new Error("task_vm.bootstrap_failed");
            }
            break;
        case "started":
        case "complete": {
            const observed = Observation.parse(data);
            if (observed.boot_id !== args.boot_id) {
                throw new Error("task_vm.image_builder_rebooted");
            }
            const accepted = args.expect === "started"
                ? ["running", "complete"]
                : ["complete"];
            if (!accepted.includes(observed.state)) throw failed();
            break;
        }
        case "seed-volume":
            if (
                data.name !==
                    `orbit-topology-seed-${
                        hash.parse(args.fingerprint).slice(0, 12)
                    }`
            ) throw failed();
            break;
    }
    return { ok: true };
}
