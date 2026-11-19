/**
 * Runs real workflows on the Swamp binary in ORBIT_SWAMP_PROOF_BINARY with every step's task replaced by a recording stub
 * (`_support/rendered_run.ts`), so the engine itself decides levels, conditions, guards and forEach lists. Each case states what both
 * Swamp db3f6373 and 537e03d1 must do with these workflows:
 *  - an empty forEach: 537e03d1 reads it as a skipped step, db3f6373 left its dependents' conditions without a status; a dependent
 *    that must run after an empty list waits with `not(failed)`, which both read alike;
 *  - a level throttle: db3f6373 ran a level one stream at a time when one of its steps set `concurrency: 1`; 537e03d1 does not, so
 *    the order is named with `dependsOn` (workflow_engine_order_test checks every workflow).
 * The suite passes on both binaries; the PR that moves the pin runs it on the new one.
 */
import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { Swamp } from "../extensions/behaviors/node-worker/config.ts";
import { type Call, renderedRun } from "./_support/rendered_run.ts";

const binary = Deno.env.get("ORBIT_SWAMP_PROOF_BINARY");
const text = (bytes: Uint8Array) => new TextDecoder().decode(bytes);
async function pinned() {
    const version = await new Deno.Command(binary!, { args: ["--version"] }).output();
    assert(text(version.stdout).includes(Swamp.version), "Use the release-pinned Swamp binary for this proof.");
}
const ran = (calls: Call[]) => [...new Set(calls.map((call) => call.step))];
const of = (calls: Call[], step: string) => calls.filter((call) => call.step === step);
/** Every call of `first` ended before any call of `then` started. */
const before = (calls: Call[], first: string, then: string) => {
    const a = of(calls, first), b = of(calls, then);
    assert(a.length > 0 && b.length > 0, `${first} and ${then} both ran`);
    assert(Math.max(...a.map((c) => c.end)) <= Math.min(...b.map((c) => c.start)), `${first} ended before ${then} started`);
};
const oneAtATime = (calls: Call[], step: string) => {
    const items = of(calls, step);
    for (let i = 1; i < items.length; i++) assert(items[i - 1].end <= items[i].start, `${step} runs one item at a time`);
};

const secrets = { git_read: { vault: "orbit-control-inputs", key: "k1" }, environment: { vault: "orbit-control-inputs", key: "k2" },
    git_credential: { vault: "orbit-control-inputs", key: "k3" } };
const deployIntent = {
    operation_id: 41, node_id: 4, kind: "instance.deploy",
    execution_secrets: { environment: { vault: "orbit-control-inputs", key: "instance-environment-7" } },
    intent: {
        commit: "c".repeat(40),
        deployment: { variant: "production", needs_vp: false, steps: [] },
        php_input: { configure: true, directories: ["/srv/php/a", "/srv/php/b"], files: [{ path: "/srv/php/a/x.ini" }, { path: "/srv/php/b/y.ini" }] },
        process_members: [{ id: 1 }, { id: 2 }, { id: 3 }],
        production: { after: [], before: [], cleanup: [{ path: "/home/app/releases/old" }], commit: "c".repeat(40), grants: [], home: "/home/app",
            links: [], prepare: [], shared: [] },
        runtime_definitions: { restart: [{ name: "web", runtime: "systemd" }, { name: "queue", runtime: "systemd" }, { name: "box", runtime: "docker" }],
            schedules: [{ name: "s1" }, { name: "s2" }] },
    },
};
// What the deploy's guards read from earlier steps: a release and PHP tuning that exist, nothing to clean or link.
const deployOutputs = () => ({ stdout: "", current: { exists: true }, actions: [], links: [], manager_required: false, runtime: null, status: "ok", assets: [] });

Deno.test({ name: "deploy (production): the steps the level throttle ordered run in that order, and Processes install one at a time", ignore: !binary, async fn() {
    await pinned();
    const run = await renderedRun("workflow-operation-instance-deploy-direct.yaml", deployIntent, { outputs: deployOutputs, hold: 150 });
    assertEquals(run.status, "succeeded", run.stderr);
    for (const [first, then] of [
        ["Check PHP runtime", "Write Schedule services"], ["Write Schedule services", "Install Processes"], ["Install Processes", "Install Schedule timers"],
        ["Install Schedule timers", "Restart Processes"], ["Read Schedule timers", "Restart Processes"], ["Restart Processes", "Check Schedule timers"],
        ["Check Process state", "Read container state"], ["Read container state", "Read deployed commit"],
    ]) before(run.calls, first, then);
    for (const step of ["Install Processes", "Install Schedule timers", "Restart Processes", "Check Process state"]) oneAtATime(run.calls, step);
    assertEquals(of(run.calls, "Install Processes").length, 3);
} });

Deno.test({ name: "deploy (production): a failed Process install stops the other members, the Schedule timers and the restarts", ignore: !binary, async fn() {
    await pinned();
    const run = await renderedRun("workflow-operation-instance-deploy-direct.yaml", deployIntent, { outputs: deployOutputs, fail: ["Install Processes"] });
    assertEquals(run.status, "failed");
    // The first member failed; its guard (`steps.exists(... failed)`) skips every later member and step, as with one stream at a time.
    assertEquals(of(run.calls, "Install Processes").length, 1);
    for (const step of ["Install Processes-1", "Install Processes-2", "Install Schedule timers-0", "Install Schedule timers-1", "Restart Processes-0"]) {
        assertEquals(run.steps[`deploy/${step}`], "skipped", step);
    }
    for (const step of ["Install Schedule timers", "Read Schedule timers", "Restart Processes", "Check Schedule timers"]) {
        assertEquals(of(run.calls, step), [], step);
    }
} });

Deno.test({ name: "deploy (production) without Processes or Schedules: a failed PHP check still keeps the after steps from running", ignore: !binary, async fn() {
    await pinned();
    const intent = structuredClone(deployIntent);
    intent.intent.process_members = [];
    intent.intent.runtime_definitions = { restart: [], schedules: [] };
    (intent.intent.production as Record<string, unknown>).after = [{ name: "a1" }];
    const failed = await renderedRun("workflow-operation-instance-deploy-direct.yaml", intent, { outputs: deployOutputs, fail: ["Check PHP runtime"], hold: 150 });
    assertEquals(failed.status, "failed");
    assertEquals(of(failed.calls, "Run after steps"), []);
    const passed = await renderedRun("workflow-operation-instance-deploy-direct.yaml", intent, { outputs: deployOutputs, hold: 150 });
    assertEquals(passed.status, "succeeded", passed.stderr);
    before(passed.calls, "Check PHP runtime", "Run after steps");
    before(passed.calls, "Check PHP runtime", "Find releases in use");
} });

Deno.test({ name: "restore: a restore without database servers still republishes the Route; a failed server check does not", ignore: !binary, async fn() {
    await pinned();
    const input = (servers: unknown[]) => ({ operation_id: 1, node_id: 3, kind: "instance.restore", intent: { servers }, execution_secrets: {} });
    const none = await renderedRun("workflow-operation-instance-restore.yaml", input([]));
    assertEquals([none.status, ran(none.calls)], ["succeeded", ["Republish the Route"]], none.stderr);
    const failed = await renderedRun("workflow-operation-instance-restore.yaml", input([{ id: 1 }]), { fail: ["Check the databases"] });
    assertEquals(failed.status, "failed");
    assertEquals(of(failed.calls, "Republish the Route"), []);
} });

Deno.test({ name: "clone: a clone without a Route skips the certificate and still checks the clone and reports its result", ignore: !binary, async fn() {
    await pinned();
    const run = await renderedRun("workflow-operation-instance-clone.yaml",
        { operation_id: 1, node_id: 3, kind: "instance.clone", intent: { route: null, sqlite: null }, execution_secrets: secrets },
        { outputs: () => ({ candidate: "c", digest: "d" }) });
    assertEquals(run.status, "succeeded", run.stderr);
    assertEquals(ran(run.calls), ["Check candidate source", "Fetch the source", "Write .env, prepare Vite+", "Add Processes, Schedules", "Check the clone", "Report the result"]);
} });

Deno.test({ name: "Process install: an Instance without members still writes the result; a failed member stops it", ignore: !binary, async fn() {
    await pinned();
    const input = (members: unknown[]) => ({ operation_id: 7, node_id: 3, kind: "process.install_instance_definitions", intent: { instance_id: 5, members },
        execution_secrets: {} });
    const none = await renderedRun("workflow-operation-process-install-instance-definitions.yaml", input([]));
    assertEquals([none.status, ran(none.calls)], ["succeeded", ["result"]], none.stderr);
    const failed = await renderedRun("workflow-operation-process-install-instance-definitions.yaml", input([{ id: 1, intent: {} }]), { fail: ["members"] });
    assertEquals(failed.status, "failed");
    assertEquals(of(failed.calls, "result"), []);
} });

Deno.test({ name: "metrics exporter: with no files the units reload after the downloads and folders, not before them", ignore: !binary, async fn() {
    await pinned();
    const run = await renderedRun("workflow-metrics-exporter-node.yaml", { operation_id: 1, node_id: 3, kind: "metrics.exporter_enable",
        intent: { enabled: true, service: { caddy: null, downloads: [{ url: "u" }], files: [], pools: [], units: [{ name: "a", enabled: true }] } } }, { hold: 150 });
    assertEquals(run.status, "succeeded", run.stderr);
    before(run.calls, "downloads", "reload_units");
    before(run.calls, "directories", "reload_units");
    before(run.calls, "reload_units", "started");
} });

Deno.test({ name: "destroy: with no Processes, a failed Route release still keeps the teardown steps from running; they run after the release, one at a time", ignore: !binary, async fn() {
    await pinned();
    const intent = { push_check_paths: [], members: [{ route_id: 1 }], native_runtime: { processes: [] }, teardown_steps: [{ id: 1 }, { id: 2 }],
        direct_destroy: { database: { sqlite: [], servers: [] }, hibernation: [] } };
    const input = { operation_id: 1, node_id: 3, kind: "instance.destroy", intent, execution_secrets: {} };
    const outputs = () => ({ result: { ready: true }, stdout: "" });
    const failed = await renderedRun("workflow-operation-instance-destroy.yaml", input, { outputs, fail: ["Release the Route Nodes"], hold: 150 });
    assertEquals(failed.status, "failed");
    assertEquals(of(failed.calls, "Run teardown steps"), []);
    const passed = await renderedRun("workflow-operation-instance-destroy.yaml", input, { outputs, hold: 150 });
    assertEquals(passed.status, "succeeded", passed.stderr);
    before(passed.calls, "Release the Route Nodes", "Run teardown steps");
    oneAtATime(passed.calls, "Run teardown steps");
} });

Deno.test({ name: "harness: items of a forEach without a limit overlap, so a one-at-a-time check can fail", ignore: !binary, async fn() {
    await pinned();
    const run = await renderedRun("workflow-metrics-exporter-node.yaml", { operation_id: 1, node_id: 3, kind: "metrics.exporter_enable",
        intent: { enabled: true, service: { caddy: null, downloads: [{ url: "a" }, { url: "b" }, { url: "c" }], files: [], pools: [], units: [] } } }, { hold: 400 });
    assertEquals(run.status, "succeeded", run.stderr);
    const items = of(run.calls, "downloads");
    assertEquals(items.length, 3);
    assert(items.some((a, i) => items.some((b, k) => i !== k && a.start < b.end && b.start < a.end)), "the three downloads overlap");
} });

Deno.test({ name: "seed copy: the copies of two targets overlap; they run after both certificates and before both checks", ignore: !binary, async fn() {
    await pinned();
    const targets = [{ node_id: 12 }, { node_id: 13 }];
    const run = await renderedRun("workflow-operation-instance-seed-copy.yaml", { operation_id: 1, node_id: 3, kind: "instance.seed_copy",
        intent: { targets }, execution_secrets: {} }, { outputs: () => ({ assets: "/tmp/assets", stdout: "" }), hold: 400 });
    assertEquals(run.status, "succeeded", run.stderr);
    assertEquals(of(run.calls, "Copy the seed").length, 2);
    const [a, b] = of(run.calls, "Copy the seed");
    assert(a.start < b.end && b.start < a.end, "the two copies overlap");
    before(run.calls, "Read its Incus certificate", "Copy the seed");
    before(run.calls, "Copy the seed", "Check the holder");
} });

Deno.test({ name: "firewall: one planned change runs, the others are skipped by their guard, then the result is read and checked", ignore: !binary, async fn() {
    await pinned();
    const run = await renderedRun("workflow-operation-firewall-direct.yaml", { operation_id: 1, node_id: 3, kind: "firewall.allow", intent: {}, execution_secrets: {} },
        { outputs: () => ({ commands: [["ufw", "allow", "22"]], ok: true, stdout: "" }) });
    assertEquals(run.status, "succeeded", run.stderr);
    assertEquals(ran(run.calls), ["Read the rules", "Plan the changes", "Change the rules", "Read the result", "Check the rules", "accepted"]);
    assertEquals(of(run.calls, "Change the rules").length, 1);
} });

Deno.test({ name: "task-check-ci: a tick without a pending check runs only its collect step", ignore: !binary, async fn() {
    await pinned();
    const run = await renderedRun("workflow-task-check-ci.yaml", {});
    assertEquals([run.status, ran(run.calls)], ["succeeded", ["collect"]], run.stderr);
} });
