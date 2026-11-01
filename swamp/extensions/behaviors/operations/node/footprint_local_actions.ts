import { z } from "npm:zod@4.4.3";
import { prepareRouter } from "../routes/router-preparation.ts";
import { caddyProgram } from "../routes/caddy-program.ts";
import { footprintSystem } from "./footprint_system.ts";
import {
    type PhpRuntimeInput,
    phpRuntimeSystem,
    type SharedPoolsInput,
} from "./php_runtime_system.ts";
import { localSystemCommand, type SystemTransport } from "./system_command.ts";

export type FootprintCall = {
    name:
        | "caddy.publish"
        | "caddy.install-package"
        | "php.configure-runtime"
        | "php.converge-shared-pools"
        | "node.publish-tmpfiles"
        | "node.install-private-dns-listener";
    input: Record<string, unknown>;
};
export type FootprintAction = (
    call: FootprintCall,
    signal?: AbortSignal,
) => Promise<Record<string, unknown>>;
/** Closed native composition; these names select code-owned system programs, never agent commands. */
export function footprintLocalActions(
    invoke: SystemTransport = localSystemCommand,
    scripts?: { php: string; footprint: string },
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
    const php = phpRuntimeSystem(invoke, scripts?.php),
        footprint = footprintSystem(invoke, scripts?.footprint);
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
            case "php.configure-runtime":
                return await php(
                    call.name,
                    call.input as PhpRuntimeInput,
                    signal,
                );
            case "php.converge-shared-pools":
                return await php(
                    call.name,
                    call.input as SharedPoolsInput,
                    signal,
                );
            case "node.publish-tmpfiles":
            case "node.install-private-dns-listener":
                return await footprint(call.name, call.input, signal);
        }
    };
}
