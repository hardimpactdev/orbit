import { spawnSync } from "node:child_process";
import { existsSync, readdirSync, readFileSync } from "node:fs";
import { join } from "node:path";
import { setTimeout as delay } from "node:timers/promises";
import { fileURLToPath } from "node:url";
import type { ToolResult } from "./contract";

export const repo = fileURLToPath(new URL("../../../../", import.meta.url));
const bin = join(repo, "bin/web-verify");

export function runCLI(home: string, args: readonly string[], env: NodeJS.ProcessEnv = {}) {
    const result = spawnSync(bin, args, {
        cwd: repo,
        encoding: "utf8",
        env: { ...process.env, ...env, ORBIT_WEB_VERIFY_HOME: home },
        timeout: 120_000,
    });
    const stdout = result.stdout ?? "";
    if (result.error !== undefined || stdout === "") {
        throw new Error(
            `${result.error?.message ?? "empty stdout"} (status ${result.status ?? "none"})\n${result.stderr ?? ""}`,
        );
    }

    return {
        status: result.status ?? -1,
        json: JSON.parse(stdout) as ToolResult,
        stderr: result.stderr ?? "",
    };
}

export function sessionPid(home: string): number | null {
    const sessionFile = join(home, "session.json");
    if (!existsSync(sessionFile)) return null;
    try {
        const session = JSON.parse(readFileSync(sessionFile, "utf8")) as { pid?: unknown };

        return typeof session.pid === "number" ? session.pid : null;
    } catch {
        return null;
    }
}

export function processAlive(pid: number): boolean {
    try {
        const stat = readFileSync(`/proc/${pid}/stat`, "utf8");
        const state = stat.slice(stat.lastIndexOf(")") + 2).split(" ")[0];

        return state !== undefined && state !== "" && state !== "Z";
    } catch {
        return false;
    }
}

function childPids(parent: number): number[] {
    const children: number[] = [];
    let entries: string[];
    try {
        entries = readdirSync("/proc");
    } catch {
        return children;
    }
    for (const entry of entries) {
        if (!/^[0-9]+$/.test(entry)) continue;
        let stat: string;
        try {
            stat = readFileSync(`/proc/${entry}/stat`, "utf8");
        } catch {
            continue;
        }
        const ppid = stat.slice(stat.lastIndexOf(")") + 2).split(" ")[1];
        if (ppid === String(parent)) children.push(Number(entry));
    }

    return children;
}

export function descendantPids(root: number): number[] {
    const out: number[] = [];
    const walk = (pid: number): void => {
        for (const child of childPids(pid)) {
            walk(child);
            out.push(child);
        }
    };
    walk(root);

    return out;
}

export function cmdline(pid: number): string {
    try {
        return readFileSync(`/proc/${pid}/cmdline`).toString("utf8").replaceAll("\0", " ");
    } catch {
        return "";
    }
}

export async function waitUntilGone(pids: readonly number[]): Promise<void> {
    const deadline = Date.now() + 10_000;
    for (;;) {
        const left = pids.filter(processAlive);
        if (left.length === 0) return;
        if (Date.now() > deadline) {
            throw new Error(
                `processes still running: ${left.map((pid) => `${pid} ${cmdline(pid)}`).join("; ")}`,
            );
        }
        await delay(50);
    }
}

export async function stop(home: string): Promise<void> {
    const pid = sessionPid(home);
    if (pid === null) return;
    const children = descendantPids(pid);
    try {
        process.kill(pid, "SIGTERM");
    } catch {
        // The daemon already exited.
    }
    const deadline = Date.now() + 10_000;
    while (Date.now() < deadline && processAlive(pid)) await delay(100);
    if (processAlive(pid)) {
        try {
            process.kill(pid, "SIGKILL");
        } catch {
            // The daemon already exited.
        }
    }
    for (const child of children) {
        if (!processAlive(child)) continue;
        try {
            process.kill(child, "SIGKILL");
        } catch {
            // The child already exited.
        }
    }
}
