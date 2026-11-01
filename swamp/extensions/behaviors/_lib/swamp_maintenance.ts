import { localCommand } from "../operations/_lib/local-worker.ts";
import type { WorkerModelContext } from "./worker_model.ts";
export interface MaintenanceContext extends WorkerModelContext {
    globalArgs: {
        projectExtensions?: { projectId: number; type: string }[];
        enrollmentVault: string;
        workerUrl: string;
        serveUrl: string;
        serveTokenFile: string;
        caFile: string;
    };
    definition: { name: string };
    queryData: (predicate: string, select?: string) => Promise<unknown[]>;
    writeResource: (
        spec: string,
        name: string,
        data: Record<string, unknown>,
    ) => Promise<{ name: string }>;
    runModel?: (
        args: {
            modelType: string;
            name: string;
            method: string;
            arguments?: Record<string, unknown>;
        },
    ) => Promise<{ ok: boolean }>;
    vaultService: {
        get: (vault: string, key: string, consumer: string) => Promise<string>;
    };
}

export async function workflowCommand(
    context: MaintenanceContext,
    args: string[],
    input?: Record<string, unknown>,
): Promise<Record<string, unknown>> {
    const g = context.globalArgs;
    const child = localCommand(Deno.execPath(), {
        args: [
            ...args,
            "--server",
            g.serveUrl,
            "--token-file",
            g.serveTokenFile,
            "--ca-cert",
            g.caFile,
            "--json",
        ],
        stdin: input ? "piped" : "null",
        stdout: "piped",
        stderr: "piped",
    }).spawn();
    if (input) {
        const writer = child.stdin.getWriter();
        await writer.write(new TextEncoder().encode(JSON.stringify(input)));
        await writer.close();
    }
    const output = await child.output();
    if (!output.success) {
        throw new Error(
            "Swamp maintenance workflow request failed; inspect its report and history",
        );
    }
    return JSON.parse(new TextDecoder().decode(output.stdout));
}

export interface MaintenanceResult {
    state: "running" | "succeeded" | "failed";
    runId?: string;
    result?: Record<string, unknown>;
    lastError?: string;
    code?: string;
    retryable?: boolean;
    workerName?: string;
}
