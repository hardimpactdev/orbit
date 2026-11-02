import { z } from "npm:zod@4.4.3";
import { prepareRouter } from "../routes/router-preparation.ts";
import { caddyProgram } from "../routes/caddy-program.ts";
import { localSystemCommand, type SystemTransport } from "./system_command.ts";

export type FootprintCall = {
    name: "caddy.publish" | "caddy.install-package";
    input: Record<string, unknown>;
};
export type FootprintAction = (
    call: FootprintCall,
    signal?: AbortSignal,
) => Promise<Record<string, unknown>>;
/** Closed native composition; these names select code-owned system programs, never agent commands. */
export function footprintLocalActions(
    invoke: SystemTransport = localSystemCommand,
): FootprintAction {
    const program = async (
        source: string,
        input: Record<string, unknown>,
        signal?: AbortSignal,
    ) => {
        const result = await invoke({
            command: "/usr/bin/sudo",
            args: ["-n", "--", "/usr/bin/python3", "-I", "-c", source],
            stdin: JSON.stringify(input),
            timeoutMs: 300000,
        }, signal);
        if (!result.success) {
            throw new Error(
                "The native footprint command has no confirmed outcome",
            );
        }
        try {
            return z.record(z.string(), z.unknown()).parse(
                JSON.parse(result.stdout),
            );
        } catch {
            throw new Error(
                "The native footprint command returned no bounded outcome",
            );
        }
    };
    return async (call, signal) => {
        signal?.throwIfAborted();
        switch (call.name) {
            case "caddy.publish":
                return await program(caddyProgram, call.input, signal);
            case "caddy.install-package":
                return await prepareRouter(
                    call.name,
                    call.input,
                    program,
                    signal,
                );
        }
    };
}
