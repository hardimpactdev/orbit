import { localSystemCommand, type SystemTransport } from "./system_command.ts";

/** Fixed Node-owned footprint programs. Public input is JSON data on stdin only. */
export function footprintSystem(
    invoke: SystemTransport = localSystemCommand,
    source?: string,
) {
    return async (
        name: "node.publish-tmpfiles" | "node.install-private-dns-listener",
        input: unknown,
        signal?: AbortSignal,
    ): Promise<
        { changed: boolean; installed?: boolean; error_code?: string }
    > => {
        signal?.throwIfAborted();
        const result = await invoke({
            command: "/usr/bin/sudo",
            args: [
                "-n",
                "/usr/bin/python3",
                "-c",
                source ?? (() => {
                    throw new Error(
                        "The native root must supply the shipped footprint script",
                    );
                })(),
                name,
            ],
            stdin: JSON.stringify(input),
            timeoutMs: name === "node.publish-tmpfiles" ? 30000 : 60000,
        }, signal);
        if (!result.success) {
            throw new Error("The footprint command has no confirmed outcome");
        }
        try {
            const output = JSON.parse(result.stdout);
            if (typeof output.changed !== "boolean") throw new Error();
            if (
                output.error_code !== undefined && ![
                    "node.tmpfiles_invalid",
                    "node.tmpfiles_failed",
                    "node.private_dns_listener_invalid",
                    "node.private_dns_listener_failed",
                ].includes(output.error_code)
            ) throw new Error();
            return output.error_code === undefined
                ? {
                    changed: output.changed,
                    ...(output.installed === true ? { installed: true } : {}),
                }
                : { changed: false, error_code: output.error_code };
        } catch {
            throw new Error(
                "The footprint command returned an invalid outcome",
            );
        }
    };
}
