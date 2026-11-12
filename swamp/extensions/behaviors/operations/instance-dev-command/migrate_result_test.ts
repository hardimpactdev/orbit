import { assertEquals } from "jsr:@std/assert@1.0.19";
import { migrateResult, writtenFailure } from "./migrate_result.ts";

const commit = "b".repeat(40);
const workspace = { status: "succeeded", outputs: { stdout: `ok: [node] => {\n    "msg": "orbit-commit=${commit}"\n}\n` } };
const server = JSON.stringify({ environment_id: "env-1", gateway_session: { vault: "orbit-control-inputs", key: "k" } });
// A default with a Route: no agent, no T3, a seed.
const defaultIntent = { instance_id: 53, container: { clone: null }, route_mutations: { forward: {} } };
const cloneIntent = { instance_id: 761, container: { clone: { t3: {} } }, route_mutations: { forward: {} } };
const moved = {
    quiesce: "succeeded", container: "succeeded", workspace: "succeeded", files: "succeeded", agent: "skipped",
    processes: "succeeded", seed: "succeeded", t3: "skipped", register: "skipped", route: "succeeded", retire: "succeeded",
    restore_route: "skipped", restore: "skipped",
};
const result = (...args: Parameters<typeof migrateResult>) => JSON.parse(migrateResult(...args).stdout);

Deno.test("a default that moved reports its commit and no T3 server", () => {
    assertEquals(result(defaultIntent, moved, workspace, null, null), { instance_id: 53, ready: true, moved: true, restored: false, commit });
});

Deno.test("a non-default Instance that moved reports its T3 server, and needs its registration", () => {
    const phases = { ...moved, agent: "succeeded", seed: "skipped", t3: "succeeded", register: "succeeded" };
    assertEquals(result(cloneIntent, phases, workspace, server, null), {
        instance_id: 761, ready: true, moved: true, restored: false, commit, t3: JSON.parse(server),
    });
    assertEquals(result(cloneIntent, { ...phases, retire: "skipped", restore: "succeeded" }, workspace, null, null).failed_step, "register");
});

Deno.test("a move that failed and restored names the failed phase with its own refusal, and says restored", () => {
    const phases = { ...moved, container: "failed", quiesce: "skipped", workspace: "skipped", files: "skipped", processes: "skipped", seed: "skipped", route: "skipped", retire: "skipped", restore: "succeeded" };
    assertEquals(result(defaultIntent, phases, null, null, { error_code: "instance.database_unreachable" }), {
        instance_id: 53, ready: false, moved: false, restored: true, failed_step: "container", error_code: "instance.database_unreachable",
    });
    // A phase that failed without a refusal names itself; the container phases' own generic code is not passed on.
    assertEquals(result(defaultIntent, phases, workspace, null, { error_code: "instance.container_workspace_failed" }).error_code, "instance.migrate_container_failed");
    assertEquals(result(defaultIntent, phases, workspace, null, null).error_code, "instance.migrate_container_failed");
});

Deno.test("a Route that did not move fails the move, and a failed restore is not restored", () => {
    const phases = { ...moved, route: "failed", retire: "skipped", restore_route: "succeeded", restore: "succeeded" };
    // The Route child writes no result file: a code left by an earlier phase is not its code.
    assertEquals(result(defaultIntent, phases, workspace, null, { error_code: "instance.database_unreachable" }), {
        instance_id: 53, ready: false, moved: false, restored: true, commit, failed_step: "route", error_code: "instance.migrate_route_failed",
    });
    assertEquals(result(defaultIntent, { ...phases, restore_route: "failed" }, workspace, null, null).restored, false);
    assertEquals(result(defaultIntent, { ...phases, restore: "failed" }, workspace, null, null).restored, false);
});

Deno.test("a failed removal after the Route moved keeps the Instance moved, and is no restore", () => {
    const phases = { ...moved, retire: "failed" };
    assertEquals(result(defaultIntent, phases, workspace, null, { error_code: "instance.migrate_retire_failed" }), {
        instance_id: 53, ready: false, moved: true, restored: false, commit, failed_step: "retire", error_code: "instance.migrate_retire_failed",
    });
});

Deno.test("an Instance without a Route needs no Route step, and a first phase that never ran is the failure", () => {
    const intent = { instance_id: 9, container: { clone: null }, route_mutations: null };
    assertEquals(result(intent, { ...moved, route: "skipped" }, workspace, null, null).ready, true);
    const phases = { ...moved, quiesce: "skipped", container: "failed", workspace: "skipped", files: "skipped", processes: "skipped", seed: "skipped", route: "skipped", retire: "skipped", restore: "succeeded" };
    assertEquals(result(intent, phases, null, null, { error_code: "instance.migrate_branch_changed" }), {
        instance_id: 9, ready: false, moved: false, restored: true, failed_step: "container", error_code: "instance.migrate_branch_changed",
    });
});

Deno.test("the code a phase wrote is read from the run folder", async () => {
    const folder = await Deno.makeTempDir();
    try {
        assertEquals(await writtenFailure(folder), null);
        await Deno.writeTextFile(`${folder}/result.json`, JSON.stringify({ commit: null }));
        assertEquals(await writtenFailure(folder), null);
        await Deno.writeTextFile(`${folder}/result.json`, JSON.stringify({ error_code: "instance.database_unreachable" }));
        assertEquals(await writtenFailure(folder), { error_code: "instance.database_unreachable" });
    } finally {
        await Deno.remove(folder, { recursive: true });
    }
});

for (const [error_code, error_message] of [
    ["instance.migrate_retirement_blocked", 'Cannot retire checkout "/fixture". Other worktrees (1 total; first 20): ["/detached"].'],
    ["instance.migrate_unpushed_commits", 'Cannot retire checkout "/fixture". Unpushed branches or detached HEAD (1 total): [{"branch": "spare", "commits": 2}].'],
    ["instance.migrate_stashes_present", 'Cannot retire checkout "/fixture". Stashes: 2.'],
    ["instance.migrate_env_service_unreachable", ".env: DB_HOST=127.0.0.1:3308. Attach the exact target, correct the .env, or use a non-network driver."],
]) {
    Deno.test(`${error_code} keeps the public message and preflight marker before and after cutover`, async () => {
        const folder = await Deno.makeTempDir();
        try {
            const failure = { error_code, error_message, preflight_refused: true };
            await Deno.writeTextFile(`${folder}/result.json`, JSON.stringify(failure));
            const phases = { ...moved, quiesce: "skipped", container: "failed", workspace: "skipped", files: "skipped", processes: "skipped", seed: "skipped", route: "skipped", retire: "skipped", restore: "succeeded" };
            assertEquals(result(defaultIntent, phases, null, null, await writtenFailure(folder)), {
                instance_id: 53, ready: false, moved: false, restored: true, preflight_refused: true,
                failed_step: "container", error_code: failure.error_code, error_message,
            });
            const late = result(defaultIntent, { ...moved, retire: "failed" }, workspace, null, { ...failure, preflight_refused: false });
            assertEquals([late.moved, late.restored, late.error_code, late.error_message], [true, false, failure.error_code, error_message]);
            // A Route failure cannot reuse a message from a phase that did not fail.
            assertEquals(result(defaultIntent, { ...moved, route: "failed", retire: "skipped" }, workspace, null, failure).error_message, undefined);
        } finally {
            await Deno.remove(folder, { recursive: true });
        }
    });
}

Deno.test("retained Git is recorded after success and after a later retirement failure", async () => {
    const folder = await Deno.makeTempDir();
    const retained = { retained_git_path: "/checkout.orbit-retired-git", retained_git_at: "2026-11-10T19:00:00+00:00" };
    try {
        await Deno.writeTextFile(`${folder}/retained-git.json`, JSON.stringify({ ...retained, changed: true }));
        assertEquals(await writtenFailure(folder), retained);
        const success = result(defaultIntent, moved, workspace, null, await writtenFailure(folder));
        assertEquals([success.retained_git_path, success.retained_git_at], [retained.retained_git_path, retained.retained_git_at]);
        await Deno.writeTextFile(`${folder}/result.json`, JSON.stringify({ error_code: "instance.migrate_retire_failed" }));
        const failed = result(defaultIntent, { ...moved, retire: "failed" }, workspace, null, await writtenFailure(folder));
        assertEquals([failed.error_code, failed.retained_git_path, failed.retained_git_at], ["instance.migrate_retire_failed", retained.retained_git_path, retained.retained_git_at]);
        const beforeRetirement = result(defaultIntent, { ...moved, retire: "skipped", restore: "succeeded" }, workspace, null, await writtenFailure(folder));
        assertEquals(beforeRetirement.retained_git_path, undefined);
    } finally {
        await Deno.remove(folder, { recursive: true });
    }
});

Deno.test("the migration prepares and probes the container before quiesce and builds the workspace after it", async () => {
    const { parse } = await import("jsr:@std/yaml@1");
    const flow = parse(await Deno.readTextFile(new URL("../../../../workflows/workflow-operation-instance-migrate.yaml", import.meta.url))) as {
        jobs: { steps: { name: string; dependsOn?: { step: string; condition?: unknown }[]; task: { inputs?: { run?: string } } }[] }[];
    };
    const steps = Object.fromEntries(flow.jobs[0].steps.map((step) => [step.name, step]));
    assertEquals(steps.container.dependsOn?.map((edge) => edge.step), ["input", "source-credential"]);
    assertEquals(steps.container.dependsOn?.find((edge) => edge.step === "source-credential")?.condition,
        { type: "or", conditions: [{ type: "succeeded" }, { type: "skipped" }] });
    assertEquals(steps.quiesce.dependsOn?.map((edge) => edge.step), ["node-runtime"]);
    assertEquals(steps.workspace.dependsOn?.map((edge) => edge.step), ["quiesce"]);
    assertEquals(steps.container.task.inputs?.run?.includes('--tags container "$ORBIT_ANSIBLE/instance-migrate.yml"'), true);
});

Deno.test("symlink refusals keep every path and target and the preflight flag in the public receipt", () => {
    const error_message = 'Cannot migrate checkout "/fixture". Unsupported symlinks: [{"path":"public/storage","target":"/missing/storage","reason":"broken"},{"path":"local-package","target":"/shared/package","reason":"outside checkout"}].';
    const failure = { error_code: "instance.migrate_symlink_unsupported", error_message, preflight_refused: true };
    const phases = { ...moved, quiesce: "skipped", container: "failed", workspace: "skipped", files: "skipped", processes: "skipped", seed: "skipped", route: "skipped", retire: "skipped", restore: "succeeded" };
    assertEquals(result(defaultIntent, phases, null, null, failure), {
        instance_id: 53, ready: false, moved: false, restored: true, preflight_refused: true,
        failed_step: "container", error_code: failure.error_code, error_message,
    });
    const late = result(defaultIntent, { ...moved, files: "failed", retire: "skipped", restore: "succeeded" }, workspace, null, { ...failure, preflight_refused: false });
    assertEquals([late.error_code, late.error_message, late.preflight_refused], [failure.error_code, error_message, undefined]);
});

for (const status of ["pending", "unknown"]) {
    Deno.test(`retirement ${status} does not claim the container moved`, () => {
        const phases = { ...moved, processes: "failed", seed: "skipped", route: "skipped", retire: status, restore: "succeeded" };
        const receipt = result(defaultIntent, phases, workspace, null, null);
        assertEquals([receipt.moved, receipt.restored, receipt.ready], [false, true, false]);
    });
    Deno.test(`Route restoration ${status} does not prove the checkout restored`, () => {
        const phases = { ...moved, route: "failed", retire: "skipped", restore_route: status, restore: "succeeded" };
        const receipt = result(defaultIntent, phases, workspace, null, null);
        assertEquals([receipt.moved, receipt.restored, receipt.ready], [false, false, false]);
    });
}

Deno.test("a moved worker readiness failure keeps its Process name and restored outcome before cutover", () => {
    const failure = { error_code: "instance.migrate_processes_failed", error_message: "Process orbit-process-70-worker.service did not stay active for 5 seconds." };
    const phases = { ...moved, processes: "failed", seed: "skipped", route: "skipped", retire: "skipped", restore: "succeeded" };
    assertEquals(result(defaultIntent, phases, workspace, null, failure), {
        instance_id: 53, ready: false, moved: false, restored: true, commit,
        failed_step: "processes", ...failure,
    });
});

Deno.test("the receipt lists every projected Process command translation on success and restoration", () => {
    const process_translations = [
        { process_id: 70, process_name: "worker", from: "/usr/bin/php8.5", to: "/opt/orbit-runtime/current/php/8.5/bin/php" },
        { process_id: 71, process_name: "vite", from: "/home/x/.vite-plus/bin/vp", to: "/opt/orbit-runtime/current/bin/vp" },
    ];
    const intent = { ...defaultIntent, migrate: { processes: process_translations.map((translation) => ({ translations: [translation] })) } };
    assertEquals(result(intent, moved, workspace, null, null).process_translations, process_translations);
    const phases = { ...moved, processes: "failed", seed: "skipped", route: "skipped", retire: "skipped", restore: "succeeded" };
    const failed = result(intent, phases, workspace, null, { error_code: "instance.migrate_processes_failed" });
    assertEquals([failed.moved, failed.restored, failed.process_translations], [false, true, process_translations]);
});

Deno.test("mail failure is an advisory warning and cannot fail or restore a completed migration", () => {
    const receipt = result(defaultIntent, {...moved, mail: "failed"}, workspace, null, null);
    assertEquals(receipt.ready, true);
    assertEquals(receipt.moved, true);
    assertEquals(receipt.restored, false);
    assertEquals(receipt.warnings, [{code: "instance.mail_update_failed", step: "mail"}]);
    assertEquals(receipt.error_code, undefined);
});

Deno.test("migration retains each exact-line outcome and the named path conflict without values", async () => {
    const folder = await Deno.makeTempDir();
    try {
        for (const status of ["changed", "left_as_written", "not_present", "already_smtp"]) {
            const mail = {status: "unchanged", ports: [1025, 2525]};
            const mailer = {status, ...(["changed", "left_as_written"].includes(status) ? {lines: [3]} : {})};
            await Deno.writeTextFile(`${folder}/mail-result.json`, JSON.stringify({mail, mailer}));
            const receipt = result(defaultIntent, {...moved, mail: "succeeded"}, workspace, null, await writtenFailure(folder));
            assertEquals(receipt.mail, mail);
            assertEquals(receipt.mailer, mailer);
            assertEquals(receipt.ready, true);
        }
        const guarded = {mail: {status: "unchanged", ports: [1025, 2525]},
            mailer: {status: "left_as_written", lines: [19], guard_line: 3}};
        await Deno.writeTextFile(`${folder}/mail-result.json`, JSON.stringify(guarded));
        const guardedResult = result(defaultIntent, {...moved, mail: "succeeded"}, workspace, null, await writtenFailure(folder));
        assertEquals(guardedResult.mailer, guarded.mailer);
        assertEquals(guardedResult.ready, true);
        const mail = {status: "skipped", reason: "mail_port_in_use", ports: [1025, 2525]};
        await Deno.writeTextFile(`${folder}/mail-result.json`, JSON.stringify({mail}));
        assertEquals(result(defaultIntent, {...moved, mail: "succeeded"}, workspace, null, await writtenFailure(folder)).mail, mail);
    } finally { await Deno.remove(folder, {recursive: true}); }
});

Deno.test("missing or malformed successful mail receipts become advisory warnings", async () => {
    const folder = await Deno.makeTempDir();
    try {
        for (const value of [null, "invalid-json", {}, {mail: {status: "unknown", ports: [1025, 2525]}},
            {mail: {status: "applied", ports: [1025, 2525]}},
            {mail: {status: "unchanged", ports: [1025, 2525]}, mailer: {status: "changed"}},
            {mail: {status: "applied", ports: [1025, 2525]}, mailer: {status: "changed", lines: []}},
            {mail: {status: "skipped", ports: [1025, 2525]}},
            {mail: {status: "unchanged", ports: [25, 2525]}, mailer: {status: "already_smtp"}}]) {
            if (value !== null) await Deno.writeTextFile(`${folder}/mail-result.json`, typeof value === "string" ? value : JSON.stringify(value));
            const receipt = result(defaultIntent, {...moved, mail: "succeeded"}, workspace, null, await writtenFailure(folder));
            assertEquals([receipt.ready, receipt.moved, receipt.restored], [true, true, false]);
            assertEquals(receipt.warnings, [{code: "instance.mail_update_failed", step: "mail"}]);
            assertEquals([receipt.mail, receipt.mailer, receipt.error_code], [undefined, undefined, undefined]);
        }
        assertEquals(result(defaultIntent, {...moved, mail: "skipped"}, workspace, null, null).warnings, undefined);
    } finally { await Deno.remove(folder, {recursive: true}); }
});

Deno.test("Node preparation fails before quiesce with its pin reason and restores only the new container", () => {
    const phases = {...moved, node: "failed", quiesce: "skipped", workspace: "skipped", files: "skipped",
        processes: "skipped", seed: "skipped", route: "skipped", retire: "skipped", restore: "succeeded"};
    const receipt = result(defaultIntent, phases, null, null, {
        error_code: "instance.node_runtime_prepare_failed", error_message: ".nvmrc pins Node 22: checksum verification failed",
    });
    assertEquals([receipt.ready, receipt.moved, receipt.restored, receipt.failed_step, receipt.error_code],
        [false, false, true, "node", "instance.node_runtime_prepare_failed"]);
    assertEquals(receipt.error_message, ".nvmrc pins Node 22: checksum verification failed");
});

Deno.test("the copied untracked count includes zero, excludes paths and survives later failure", async () => {
    const folder = await Deno.makeTempDir();
    try {
        for (const count of [0, 56]) {
            await Deno.writeTextFile(`${folder}/untracked-files.json`, JSON.stringify({
                untracked_files_copied: count, paths: ["credentials.json"], content: "private",
            }));
            const written = await writtenFailure(folder);
            assertEquals(written, { untracked_files_copied: count });
            assertEquals(result(defaultIntent, moved, workspace, null, written), {
                instance_id: 53, ready: true, moved: true, restored: false, commit, untracked_files_copied: count,
            });
            assertEquals(result(defaultIntent, { ...moved, retire: "failed" }, workspace, null, written).untracked_files_copied, count);
            assertEquals(result(defaultIntent, { ...moved, files: "failed" }, workspace, null, written).untracked_files_copied, undefined);
            assertEquals(result(defaultIntent, { ...moved, files: "skipped" }, workspace, null, written).untracked_files_copied, undefined);
        }
    } finally {
        await Deno.remove(folder, { recursive: true });
    }
});

Deno.test("invalid or incomplete copied-file receipts do not claim a count", async () => {
    const folder = await Deno.makeTempDir();
    try {
        for (const value of [-1, 1.5, "56", null]) {
            await Deno.writeTextFile(`${folder}/untracked-files.json`, JSON.stringify({ untracked_files_copied: value }));
            assertEquals(await writtenFailure(folder), null);
        }
        for (const value of ["{", "{}", "null"]) {
            await Deno.writeTextFile(`${folder}/untracked-files.json`, value);
            assertEquals(await writtenFailure(folder), null);
        }
    } finally {
        await Deno.remove(folder, { recursive: true });
    }
});

Deno.test("carried Schedule activation failures and missing receipts warn without failing completed migration", async () => {
    const schedule = {id: "11111111-1111-4111-8111-000000000001", name: "laravel-scheduler"};
    const intent = {...defaultIntent, migrate: {schedules: [schedule]}};
    const folder = await Deno.makeTempDir();
    try {
        for (const receipt of [
            {...schedule, outcome: "activated"},
            {...schedule, outcome: "disabled"},
            {...schedule, outcome: "not_activated", cause: "schedule.container_activation_failed"},
            {...schedule, outcome: "not_activated"},
            {id: "invalid", name: schedule.name, outcome: "activated"},
        ]) {
            await Deno.writeTextFile(folder + "/schedule-result.json", JSON.stringify({schedules: [receipt]}));
            const observed = result(intent, moved, workspace, null, await writtenFailure(folder));
            assertEquals(observed.ready, true);
            assertEquals(observed.moved, true);
            assertEquals(observed.restored, false);
            assertEquals(observed.schedules[0].id, schedule.id);
            assertEquals(observed.schedules[0].name, schedule.name);
            if (receipt.id === schedule.id && ["activated", "disabled"].includes(receipt.outcome)) {
                assertEquals(observed.warnings, undefined);
            } else {
                assertEquals(observed.schedules[0].outcome, "not_activated");
                assertEquals(observed.warnings[0].code.startsWith("schedule.container_"), true);
            }
        }
    } finally { await Deno.remove(folder, {recursive: true}); }
});

Deno.test("environment warning receipts survive later phase results and remain generic", async () => {
    const folder = Deno.makeTempDirSync();
    try {
        const warnings = [
            {code: "instance.migrate_env_service_unused", message: ".env: DB_HOST=127.0.0.1:3306: no TCP service answered on the Node."},
            {code: "instance.future_environment_warning", message: "A safe future environment warning."},
        ];
        await Deno.writeTextFile(folder + "/environment-warnings.json", JSON.stringify({warnings}));
        await Deno.writeTextFile(folder + "/result.json", JSON.stringify({commit}));
        const written = await writtenFailure(folder);
        assertEquals(result(defaultIntent, moved, workspace, null, written).warnings, warnings);
        assertEquals(result(defaultIntent, {...moved, retire:"skipped", route:"failed", restore:"succeeded"},
                            workspace, null, written).warnings, warnings);
        await Deno.writeTextFile(folder + "/environment-warnings.json",
                                 JSON.stringify({warnings:[{code:"not a code",message:"not accepted"}]}));
        assertEquals((await writtenFailure(folder))?.warnings, undefined);
    } finally { Deno.removeSync(folder, {recursive:true}); }
});
