import { queryWorkerData } from "./worker_query.ts";
/** Input identity for a parked group. Status/report materializations are excluded. */
export async function groupFingerprint(
    ctx: {
        queryData: (predicate: string, select?: string) => Promise<unknown[]>;
    },
    factory: string,
    workItem: string,
    preapprovalModel: string,
) {
    const models = [factory, preapprovalModel, `orbit-group-${workItem}`];
    const rows = await queryWorkerData(
        ctx,
        `modelName in ${JSON.stringify(models)} && (` +
            ["state-", "artifact-", "evidence-", "approval-", "preapproval-"]
                .map((p) => `name.startsWith(${JSON.stringify(p + workItem)})`)
                .join(
                    " || ",
                ) +
            ")",
        "attributes",
    );
    const bytes = new TextEncoder().encode(
        JSON.stringify(rows.map((row) => JSON.stringify(row)).sort()),
    );
    const digest = new Uint8Array(await crypto.subtle.digest("SHA-256", bytes));
    return Array.from(digest, (byte) => byte.toString(16).padStart(2, "0"))
        .join(
            "",
        );
}
