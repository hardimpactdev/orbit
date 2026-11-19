import { z } from "npm:zod@4";
import { type TaskPlan } from "./tasks.ts";

export type Http = (
    method: string,
    path: string,
    body?: Record<string, unknown>,
) => Promise<{ status: number; body: Record<string, unknown> | null }>;
export const ReceiptSchema = z.strictObject({
    key: z.string(),
    projectId: z.number().int().positive(),
    brief: z.string(),
    state: z.enum(["uncertain", "created"]),
    groupId: z.number().int().positive().nullable(),
});
export type Receipt = z.infer<typeof ReceiptSchema>;
export interface Queue {
    http: Http;
    read: (key: string) => Promise<Receipt | null>;
    save: (receipt: Receipt) => Promise<void>;
}
export function repository(value: unknown): string {
    return typeof value === "string"
        ? value.replace(/^git@github.com:/, "").replace(
            /^https:\/\/github.com\//,
            "",
        ).replace(/\.git$/, "").replace(/\/$/, "")
        : "";
}
/** Every task group of the Project. The Gateway pages the list, so a group the first page misses is still found. */
async function projectGroups(
    projectId: number,
    http: Http,
): Promise<Record<string, unknown>[]> {
    const groups: Record<string, unknown>[] = [];
    let cursor: string | null = null;
    do {
        const page: string = cursor === null ? "" : `&cursor=${cursor}`;
        const listed: Awaited<ReturnType<Http>> = await http(
            "GET",
            `/api/v1/tasks?project_id=${projectId}&limit=100${page}`,
        );
        const next: unknown = (listed.body?.meta as
            | Record<string, unknown>
            | undefined)?.next_cursor;
        if (
            listed.status !== 200 || !Array.isArray(listed.body?.data) ||
            (next !== null && next !== undefined &&
                (typeof next !== "string" || !/^[1-9][0-9]*$/.test(next)))
        ) {
            throw new Error("Cannot reconcile Horizon task groups");
        }
        groups.push(...listed.body.data as Record<string, unknown>[]);
        cursor = typeof next === "string" ? next : null;
    } while (cursor !== null);
    return groups;
}
/** Creates backlog tasks only. An uncertain create is reconciled, never blindly repeated. */
export async function enqueue(
    plan: TaskPlan,
    projectId: number,
    expectedRepo: string,
    deps: Queue,
): Promise<Receipt | null> {
    if (!plan.tasks.length) return null;
    if (plan.tasks.length > 50) {
        throw new Error(
            "Horizon tag exceeds the Gateway group limit of 50 tasks; ask the orchestrator to split it",
        );
    }
    const response = await deps.http("GET", `/api/v1/projects/${projectId}`);
    const project = response.body?.data as Record<string, unknown> | undefined;
    if (
        response.status !== 200 || !project || project.id !== projectId ||
        repository(project.repository_url) !== expectedRepo ||
        project.review_and_merge !== false
    ) {
        throw new Error(
            "Horizon task Project must match the package repository and wait for Nick to merge",
        );
    }
    const key = plan.baseline ? "baseline" : `${plan.synced_tag}-${plan.tag}`;
    const marker = `Swamp Horizon sync: ${expectedRepo}:${key}`;
    // The brief that names this schedule in receipts and on the Gateway. It stays as it was before tasks with no subtask, so
    // queued work keeps its identity.
    const brief = `${marker}\n\n${
        plan.baseline
            ? "Record the one-time parity baseline before upstream detection."
            : `Implement every candidate from laravel/horizon ${plan.synced_tag} to ${plan.tag} in order. Only the last task advances synced_tag.`
    }\n\nPull requests wait for Nick. Never merge.`;
    // One planned task is a task with no subtask. Its brief is that task's brief, which the plan holds within the Gateway limit
    // and which names the candidate, so it identifies the schedule too.
    const [only] = plan.tasks.length === 1 ? plan.tasks : [];
    const posted = only ? only.brief : brief;
    const old = await deps.read(key);
    if (old && (old.projectId !== projectId || old.brief !== brief)) {
        throw new Error(
            "Horizon queue intent differs; reconcile with the orchestrator",
        );
    }
    // A previously-created group's tasks can progress; do not recreate or rewrite them.
    if (old?.state === "created") return old;
    const found = (await projectGroups(projectId, deps.http)).filter((g) =>
        g.brief === brief || g.brief === posted
    );
    if (found.length > 1) {
        throw new Error(
            "Duplicate Horizon task groups; ask the orchestrator to reconcile",
        );
    }
    if (found.length === 1) {
        const group = found[0];
        if (
            !Number.isSafeInteger(group.id) || group.project_id !== projectId ||
            group.orchestration !== "external"
        ) throw new Error("Horizon group identity differs");
        const saved: Receipt = {
            key,
            projectId,
            brief,
            state: "created",
            groupId: Number(group.id),
        };
        await deps.save(saved);
        return saved;
    }
    if (old) {
        throw new Error(
            "Horizon group creation is uncertain; inspect the Gateway before retrying",
        );
    }
    const intent: Receipt = {
        key,
        projectId,
        brief,
        state: "uncertain",
        groupId: null,
    };
    await deps.save(intent);
    const result = await deps.http("POST", "/api/v1/tasks", {
        project_id: projectId,
        title: plan.baseline
            ? "Horizon New Dawn parity baseline"
            : `Horizon ${plan.tag} parity`,
        brief: posted,
        status: "backlog",
        orchestration: "external",
        ...(only ? { deliverables: only.deliverables } : {
            subtasks: plan.tasks.map(({ title, brief, deliverables }) => ({
                title,
                brief,
                deliverables,
            })),
        }),
    });
    const group = result.body?.data as Record<string, unknown> | undefined;
    if (
        result.status !== 201 || !group || !Number.isSafeInteger(group.id) ||
        group.project_id !== projectId || group.orchestration !== "external"
    ) {
        throw new Error(
            "Horizon group creation has no verified receipt; reconcile before retrying",
        );
    }
    const receipt: Receipt = {
        ...intent,
        state: "created",
        groupId: Number(group.id),
    };
    await deps.save(receipt);
    return receipt;
}
