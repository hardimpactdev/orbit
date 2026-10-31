import { localCommand } from "../_lib/local-worker.ts";

export type ScriptExecutor = (
    input: Record<string, unknown>,
    signal?: AbortSignal,
) => Promise<unknown>;

/** The native step owns the process; abort requests stop its systemd cgroup before returning. */
export async function workspaceProgram(
    program: string,
    input: Record<string, unknown>,
    signal?: AbortSignal,
): Promise<unknown> {
    signal?.throwIfAborted();
    const process = localCommand("/usr/bin/python3", {
        args: ["-c", program],
        stdin: "piped",
        stdout: "piped",
        stderr: "null",
    }).spawn();
    const stop = () => {
        try {
            process.kill("SIGTERM");
        } catch { /* Already ended. */ }
    };
    signal?.addEventListener("abort", stop, { once: true });
    try {
        const writer = process.stdin.getWriter();
        await writer.write(new TextEncoder().encode(JSON.stringify(input)));
        await writer.close();
        const chunks: Uint8Array[] = [];
        let size = 0;
        for await (const chunk of process.stdout) {
            size += chunk.length;
            if (size > 1048576) {
                throw new Error("Native script output exceeded its bound");
            }
            chunks.push(chunk);
        }
        if (!(await process.status).success) {
            throw new Error("Native script outcome unknown");
        }
        const data = new Uint8Array(size);
        let offset = 0;
        for (const chunk of chunks) {
            data.set(chunk, offset);
            offset += chunk.length;
        }
        return JSON.parse(new TextDecoder().decode(data));
    } finally {
        signal?.removeEventListener("abort", stop);
        stop();
        await process.status;
    }
}
