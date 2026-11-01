import { localCommand } from "../_lib/local-worker.ts";
import { z } from "npm:zod@4.4.3";

const Reply = z.discriminatedUnion("ok", [
    z.object({ ok: z.literal(true), data: z.record(z.string(), z.unknown()) })
        .strict(),
    z.object({
        ok: z.literal(false),
        error_code: z.string().regex(/^[a-z0-9_.-]{1,100}$/),
    }).strict(),
]);
/** Only the three SQLite operations are accepted; no executable, source or arbitrary argv is input. */
export type SqliteCall = {
    action: "copy" | "seedSource" | "seedTarget";
    input: Record<string, unknown>;
};
export type SqliteInvoke = (
    call: SqliteCall,
    signal?: AbortSignal,
) => Promise<Record<string, unknown>>;
const scriptFile = new URL(
    "../database-server-commands/scripts/sqlite_actions.py",
    import.meta.url,
).pathname;
export function sqliteLaunch(call: SqliteCall, script = scriptFile) {
    const seconds = call.action === "copy" ? 600 : 120;
    let sudo: string[] = [];
    if (call.action === "seedSource" || call.action === "seedTarget") {
        sudo = ["-n", "--", "/usr/bin/python3"];
    }
    return {
        command: sudo.length ? "/usr/bin/sudo" : "/usr/bin/python3",
        args: [
            ...sudo,
            "-B",
            script,
            call.action,
            String(seconds),
        ],
        timeoutMs: (seconds + 5) * 1000,
    };
}

/** A fixed domain program bundled in every native method; private inputs stay on stdin. */
export function sqliteFileInvoke(script: string): SqliteInvoke {
    return async (call, signal) => {
        signal?.throwIfAborted();
        if (
            !["copy", "seedSource", "seedTarget"].includes(call.action)
        ) {
            throw new Error("database.sqlite_action_invalid");
        }
        const launch = sqliteLaunch(call, script);
        const bytes = new TextEncoder().encode(JSON.stringify(call.input));
        if (bytes.length > 65536) {
            throw new Error("database.sqlite_input_invalid");
        }
        const child = localCommand(launch.command, {
            args: launch.args,
            clearEnv: true,
            env: { PATH: "/usr/bin:/bin", LANG: "C.UTF-8" },
            stdin: "piped",
            stdout: "piped",
            stderr: "null",
            // Once a mutation starts, the fixed per-action deadline owns its completion.
            signal: AbortSignal.timeout(launch.timeoutMs),
        }).spawn();
        let finished = false;
        try {
            const input = child.stdin.getWriter();
            await input.write(bytes);
            await input.close();
            const chunks: Uint8Array[] = [];
            let size = 0;
            for await (const chunk of child.stdout) {
                size += chunk.length;
                if (size > 16384) {
                    throw new Error("database.sqlite_output_invalid");
                }
                chunks.push(chunk);
            }
            const status = await child.status;
            finished = true;
            if (!status.success) {
                throw new Error("database.sqlite_outcome_unknown");
            }
            const output = new Uint8Array(size);
            let offset = 0;
            for (const chunk of chunks) {
                output.set(chunk, offset);
                offset += chunk.length;
            }
            const reply = Reply.parse(
                JSON.parse(new TextDecoder().decode(output)),
            );
            return reply.ok ? reply.data : { error_code: reply.error_code };
        } catch {
            throw new Error("database.sqlite_outcome_unknown");
        } finally {
            if (!finished) {
                try {
                    child.kill("SIGKILL");
                } catch { /* Exited already. */ }
                await child.status.catch(() => {});
            }
        }
    };
}
export const runSqliteLocal = sqliteFileInvoke(scriptFile);

export async function copySqlite(
    input: Record<string, unknown>,
    signal?: AbortSignal,
    invoke: SqliteInvoke = runSqliteLocal,
) {
    return await invoke({ action: "copy", input }, signal);
}
