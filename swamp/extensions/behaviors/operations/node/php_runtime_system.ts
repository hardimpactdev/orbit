import {
    localSystemCommand,
    nodeWorkerCommand,
    type SystemTransport,
} from "./system_command.ts";

export type PhpRuntimeInput = {
    version: string;
    runtime_ini: string;
    release: { id: string; codenames: string[] };
};
export type SharedPoolsInput = { configurations: Record<string, string> };
export type MissingPhpPool = {
    pool: string;
    version: string;
    directory: string;
    installed: boolean;
};
export type PhpRuntimeOutput = {
    changed: boolean;
    reloaded?: boolean;
    deferred?: string[];
    missing_pools?: MissingPhpPool[];
    error_code?: string;
    restored?: boolean;
};

/** Node-owned runtime configuration only. Recipe installation belongs to Fleet. */
export function phpRuntimeSystem(
    invoke: SystemTransport = localSystemCommand,
    source?: string,
) {
    return async (
        name: "php.configure-runtime" | "php.converge-shared-pools",
        input: PhpRuntimeInput | SharedPoolsInput,
        signal?: AbortSignal,
    ): Promise<PhpRuntimeOutput> => {
        signal?.throwIfAborted();
        const result = await nodeWorkerCommand(invoke, signal)(
            [
                "/usr/bin/flock",
                "/run/lock/orbit-php-runtime.lock",
                "/usr/bin/python3",
                "-c",
                source ?? (() => {
                    throw new Error(
                        "The native root must supply its shipped PHP script",
                    );
                })(),
                name,
            ],
            new TextEncoder().encode(JSON.stringify(input)),
            "root",
            { deadlineMs: 900000 },
        );
        if (!result.success) {
            throw new Error("PHP runtime command has no confirmed outcome");
        }
        try {
            const output = JSON.parse(new TextDecoder().decode(result.stdout));
            if (typeof output.changed !== "boolean") throw new Error();
            if (
                output.error_code !== undefined && ![
                    "php.runtime_input_invalid",
                    "php.runtime_platform_unsupported",
                    "php.runtime_configuration_failed",
                    "php.shared_pool_invalid",
                    "php.shared_pool_unsafe",
                    "php.shared_pool_failed",
                    "php.shared_pool_recovery_failed",
                ].includes(output.error_code)
            ) throw new Error();
            return output;
        } catch {
            throw new Error("PHP runtime command returned an invalid outcome");
        }
    };
}
