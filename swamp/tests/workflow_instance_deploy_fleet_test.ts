/**
 * The container steps of a default's deployment (docs/cli/instance/deploy.mmd, docs/cli/instance/container/fleet-refresh.mmd), with
 * the pinned CEL compiler. Two Ansible steps each run, in one play, the phases that share their failure handling: "Prepare the
 * container" before the checkout, whose failure restores nothing, and "Finish the container" after it, whose failure restores the old
 * seed of a nested-fleet default. Only an intent with `fleet` runs the fleet phases and the restore.
 */
import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { Environment } from "npm:@marcbachmann/cel-js@7.6.1";

type Condition = { type: string; conditions?: Condition[] };
type Step = {
    name: string;
    guard?: string;
    dependsOn?: { step: string; condition: Condition }[];
    task: { behaviorName?: string; globalArgs?: { name?: string }; inputs: { run?: string; env?: Record<string, string> } };
};
const flow = parse(
    Deno.readTextFileSync(new URL("../workflows/workflow-operation-instance-deploy-direct.yaml", import.meta.url)),
) as { jobs: { name: string; steps: Step[] }[] };
const deploy = flow.jobs.find((job) => job.name === "deploy")!.steps;
const names = deploy.map((s) => s.name);
const step = (name: string) => deploy.find((s) => s.name === name)!;
const cel = new Environment({ enableOptionalTypes: true, unlistedVariablesAreDyn: true, homogeneousAggregateLiterals: false });
const expression = (text: string) => text.slice(3, -2).trim();
/** A guard that is true skips its step. */
const skips = (name: string, intent: Record<string, unknown>, statuses: Record<string, string>) =>
    cel.evaluate(expression(step(name).guard!), {
        inputs: { intent },
        steps: Object.fromEntries(Object.entries(statuses).map(([key, status]) => [key, { status }])),
    });
/** The phases a merged step runs for an intent, in their order. */
const phases = (name: string, intent: Record<string, unknown>) =>
    (cel.evaluate(expression(step(name).task.inputs.env!.ORBIT_PHASES), { inputs: { intent } }) as string).split(",");
/** The bound of a merged step for an intent: the sum of the bounds of the phases it runs. */
const seconds = (name: string, intent: Record<string, unknown>) =>
    cel.evaluate(expression(step(name).task.inputs.env!.ORBIT_PHASE_SECONDS), { inputs: { intent } });

const container = { deployment: { variant: "container" } };
const nested = { ...container, fleet: { seed: "e".repeat(40) } };
const passed = {
    "Write the container intent": "succeeded",
    "Prepare the container": "succeeded",
    "Check out in the container": "succeeded",
    "Finish the container": "succeeded",
};
const restoreSteps = ["Restore the old seed", "Start on the old seed", "Start the restored fleet"];
const merged = ["Prepare the container", "Finish the container"];

Deno.test("a container deployment runs its phases in two steps, each one play of the phases that share their failure handling", () => {
    // One step per failure handling: no step of a single phase stays (docs/cli/instance/deploy.mmd).
    for (const gone of ["Check the inner fleet", "Keep the current seed", "Fetch into the mirror", "Install the Processes",
        "Refresh the inner fleet", "Take the seed snapshot", "Check the container"]) assert(!names.includes(gone), gone);
    // The Project's PHP, and any restart it needs, runs first after the seed hold, never in the probe before it.
    assertEquals(phases("Prepare the container", container), ["php", "fetch"]);
    assertEquals(phases("Prepare the container", nested), ["php", "fleet_start", "seed", "fetch"]);
    // The start and the probe run first, in a step of their own, which leaves the Project's PHP to "Prepare the container".
    assertEquals(step("Start the container").task.inputs.run,
        'timeout 580 /opt/orbit/ansible/venv/bin/ansible-playbook -i localhost, --tags start,probe -e phases=start,probe -e checkout_probe=true "$ORBIT_ANSIBLE/instance-container.yml"');
    assertEquals(phases("Finish the container", container), ["processes", "seed", "check"]);
    assertEquals(phases("Finish the container", nested), ["processes", "fleet_refresh", "seed", "check"]);
    // The same list selects the tags and orders the phases; only the fleet check reads the room a refresh needs.
    assertEquals(step("Prepare the container").task.inputs.run,
        'timeout "$ORBIT_PHASE_SECONDS" /opt/orbit/ansible/venv/bin/ansible-playbook -i localhost, --tags "$ORBIT_PHASES" -e "phases=$ORBIT_PHASES" -e fleet_room_check=true -e fleet_deploy_allow_missing_build=true "$ORBIT_ANSIBLE/instance-container.yml"');
    assertEquals(step("Finish the container").task.inputs.run,
        'timeout "$ORBIT_PHASE_SECONDS" /opt/orbit/ansible/venv/bin/ansible-playbook -i localhost, --tags "$ORBIT_PHASES" -e "phases=$ORBIT_PHASES" "$ORBIT_ANSIBLE/instance-container.yml"');
    // Each bound is the sum of its phases' bounds: php 60 + fetch 400; processes 120 + seed 120 + check 60; a nested fleet adds the check 400
    // and the first seed 120, and the refresh 4,200.
    assertEquals([seconds("Prepare the container", container), seconds("Prepare the container", nested)], ["460", "980"]);
    assertEquals([seconds("Finish the container", container), seconds("Finish the container", nested)], ["300", "4500"]);
    // The order: start before the fetch, the checkout and the deploy steps between the two, and the check last.
    assert(names.indexOf("Write the container intent") < names.indexOf("Start the container"));
    assert(names.indexOf("Start the container") < names.indexOf("Hold the seed"));
    assert(names.indexOf("Hold the seed") < names.indexOf("Prepare the container"));
    assert(names.indexOf("Prepare the container") < names.indexOf("Check out in the container"));
    assert(names.indexOf("Run dev deploy steps") < names.indexOf("Finish the container"));
    assertEquals(step("Start the container").dependsOn, [{ step: "Write the container intent", condition: { type: "succeeded" } }]);
    assertEquals(step("Hold the seed").dependsOn, [{ step: "Start the container", condition: { type: "succeeded" } }]);
    assertEquals(step("Prepare the container").dependsOn, [{ step: "Start the container", condition: { type: "succeeded" } },
        { step: "Hold the seed", condition: { type: "or", conditions: [{ type: "succeeded" }, { type: "skipped" }] } }]);
    assertEquals(step("Check out in the container").dependsOn, [{ step: "Prepare the container", condition: { type: "succeeded" } }]);
    // With no dev deploy step the forEach is dropped from the graph; the last edge keeps the finish after the skip list then too.
    assertEquals(step("Finish the container").dependsOn, [
        { step: "Check out in the container", condition: { type: "succeeded" } },
        { step: "Run dev deploy steps", condition: { type: "always" } },
        { step: "Read the steps to skip", condition: { type: "always" } },
    ]);
    // Each secret keeps its one vault expression: the Club key as the restart's start delivers it, the read token, the Processes' files.
    const prepare = step("Prepare the container").task.inputs.env!;
    assertEquals(step("Start the container").task.inputs.env!.ORBIT_CLUB_KEY, step("Start on the old seed").task.inputs.env!.ORBIT_CLUB_KEY);
    assertEquals(prepare.ORBIT_CLUB_KEY, undefined);
    assertEquals(prepare.ORBIT_GIT_CREDENTIAL, "${{ vault.get(inputs.execution_secrets.credential.vault, inputs.execution_secrets.credential.key) }}");
    assertEquals(step("Finish the container").task.inputs.env!.ORBIT_PROCESS_ENVIRONMENTS,
        '${{ vault.get(inputs.execution_secrets.credential.vault, inputs.intent.deployment.variant == "container" ? inputs.execution_secrets.process_environments.key : inputs.execution_secrets.credential.key) }}');
    assert(!("ORBIT_PROCESS_ENVIRONMENTS" in prepare));
    assert(!("ORBIT_CLUB_KEY" in step("Finish the container").task.inputs.env!));
    // A checkout or production deployment skips both.
    for (const name of merged) {
        assertEquals(skips(name, { deployment: { variant: "development" } }, {}), true, name);
        assertEquals(skips(name, container, { "Write the container intent": "succeeded", "Prepare the container": "succeeded",
            "Check out in the container": "succeeded" }), false, name);
    }
});

Deno.test("the playbook runs the phases of a merged step in order, and the handlers of one phase before the next phase", () => {
    const play = (parse(Deno.readTextFileSync(new URL("../ansible/instance-container.yml", import.meta.url))) as Record<string, unknown>[])[1];
    const vars = play.vars as Record<string, string>;
    // Without `phases` a run is the one phase of its first tag, as for every other caller.
    assertEquals(vars.container_phases, "{{ (phases | default(ansible_run_tags | first)).split(',') }}");
    const block = (play.tasks as { block: Record<string, unknown>[]; rescue: Record<string, unknown>[] }[])[0];
    const loop = block.block.find((task) => task.name === "Run each phase in order")!;
    assertEquals(loop["ansible.builtin.include_tasks"], { file: "tasks/instance-container-run-phase.yml" });
    assertEquals(loop.loop, "{{ container_phases }}");
    const failure = block.rescue.find((task) => task.name === "Write the failure")!;
    assertEquals((failure.vars as Record<string, string>).failure_phase, "{{ container_phase_running }}");
    const phase = parse(Deno.readTextFileSync(new URL("../ansible/tasks/instance-container-run-phase.yml", import.meta.url))) as Record<string, unknown>[];
    // Each phase writes the result as it ends, with the phases that passed, so a play killed in a later phase keeps them.
    assertEquals(phase.map((task) => task.name),
        ["Name the running phase", "Run the phase's tasks", "Run the phase's handlers", "Count the phase as passed", "Write the result"]);
    // A fetch that found the checkout already past the commit skips the fleet check and the seed: they neither run nor count.
    assertEquals(phase[0]["ansible.builtin.set_fact"], { container_phase_running: "{{ container_phase }}" });
    assertEquals(phase[2]["ansible.builtin.meta"], "flush_handlers");
    assertEquals(phase[3]["ansible.builtin.set_fact"], { container_phases_passed: "{{ container_phases_passed + [container_phase] }}" });
    assert(!block.block.some((task) => task.name === "Write the result"), "one writer of the success result: the phase's own");
});

Deno.test("a normal container deployment never restores, after success and after a failure", () => {
    for (const intent of [container, { deployment: { variant: "development" } }]) {
        for (const failing of [null, "Prepare the container", "Check out in the container", "Run dev deploy steps-0", "Finish the container"]) {
            const statuses = failing === null ? passed : { ...passed, [failing]: "failed" };
            for (const name of restoreSteps) assertEquals(skips(name, intent, statuses), true, `${name} after ${failing}`);
        }
    }
});

Deno.test("a failed Prepare the container changes nothing in the workspace: every later step skips and no seed is restored", () => {
    // The start, the fleet check, the first seed or the fetch failed; the result file names the phase.
    const statuses = { "Write the container intent": "succeeded", "Prepare the container": "failed" };
    for (const name of ["Check out in the container", "Finish the container", ...restoreSteps]) {
        assertEquals(skips(name, nested, statuses), true, name);
    }
});

Deno.test("a nested-fleet deployment that failed from its checkout on restores the old seed, starts through start and keeps the failed phase's result", () => {
    for (const failing of ["Check out in the container", "Run dev deploy steps-0", "Finish the container"]) {
        const statuses = { ...passed, [failing]: "failed" };
        for (const name of restoreSteps) assertEquals(skips(name, nested, statuses), false, `${name} after ${failing}`);
    }
    for (const name of restoreSteps) assertEquals(skips(name, nested, passed), true, name);
    // Without the intent file there is nothing to run the restore with.
    assertEquals(skips("Restore the old seed", nested, { "Write the container intent": "failed" }), true);
    const before = ["Prepare the container", "Check out in the container", "Run dev deploy steps", "Finish the container"];
    assertEquals(step("Restore the old seed").dependsOn, before.map((name) => ({ step: name, condition: { type: "always" } })));
    assertEquals(step("Start on the old seed").dependsOn, [{ step: "Restore the old seed", condition: { type: "succeeded" } }]);
    const restore = step("Restore the old seed").task.inputs.run!;
    assert(restore.includes("--tags fleet_restore "));
    assert(restore.startsWith('k="$ORBIT_RUN/failed.json"; cp -- "$ORBIT_RUN/result.json" "$k"'));
    assert(step("Start the restored fleet").task.inputs.run!.includes("--tags fleet_resume -e fleet_deploy_allow_missing_build=true "));
    const restart = step("Start on the old seed").task.inputs.run!;
    assert(restart.includes("--tags start "));
    assert(restart.includes('cp -- "$k" "$ORBIT_RUN/result.json"'));
});

Deno.test("the result step waits for every step that writes the result file", () => {
    const writers = ["Start the container", "Prepare the container", "Check out in the container", "Run dev deploy steps", "Finish the container", ...restoreSteps];
    assertEquals(step("Read the container result").dependsOn, writers.map((name) => ({ step: name, condition: { type: "always" } })));
});

Deno.test("the deployment's one fleet check waits for no Doctor, and the refresh starts again what it stopped for base before the seed", () => {
    // The check runs `fleet_start` (instance-fleet-start.yml ends with instance-fleet-reach.yml, never the Doctor wait); readiness ends
    // the refresh, after its migrate and restart.
    const start = Deno.readTextFileSync(new URL("../ansible/tasks/instance-fleet-start.yml", import.meta.url));
    assert(start.includes("ansible.builtin.import_tasks: instance-fleet-reach.yml"));
    assert(!start.includes("instance-fleet-ready.yml"));
    // A retaken base starts its containers again inside the refresh, so a failed start fails the refresh and restores the old seed.
    const refresh = Deno.readTextFileSync(new URL("../ansible/tasks/instance-fleet-refresh.yml", import.meta.url));
    assert(refresh.includes("ansible.builtin.include_tasks: instance-fleet-resume.yml"));
    // The fleet starts in no other step of a passed run: one check first, and the restored fleet only after a restore.
    assertEquals(deploy.filter((s) => s.task.inputs?.run?.includes("--tags fleet_resume ")).map((s) => s.name), ["Start the restored fleet"]);
    for (const gone of ["Start the inner fleet", "Start the refreshed fleet"]) assert(!names.includes(gone), gone);
});

Deno.test("after a restore the restored fleet is started and waited for, and the first failure stays the run's result", () => {
    const statuses = { ...passed, "Finish the container": "failed", "Restore the old seed": "succeeded", "Start on the old seed": "succeeded" };
    assertEquals(skips("Start the restored fleet", nested, statuses), false);
    assertEquals(step("Start the restored fleet").dependsOn, [{ step: "Start on the old seed", condition: { type: "succeeded" } }]);
    assert(names.indexOf("Start on the old seed") < names.indexOf("Start the restored fleet"));
    assert(step("Start the restored fleet").task.inputs.run!.includes("--tags fleet_resume "));
    assertEquals(skips("Start the restored fleet", container, statuses), true);
});

Deno.test("two deployments of different defaults on one Node never share a behavior lock for a long step", () => {
    // Swamp holds a behavior's lock for the whole step and fails a second step after 60 s (Operation 22649 waited on 2454's Finish).
    // Only default Instances deploy, so the per-Instance names stay bounded by the defaults a Node ever held.
    const render = (text: string, instance: number) =>
        String(cel.evaluate(expression(text), { inputs: { node_id: 9, intent: { instance_id: instance } }, self: { _index: 3 }, run: { id: "run-1" } }));
    const containerSteps = deploy.filter((s) => s.task.behaviorName?.includes("instance-deploy-container"));
    assertEquals(containerSteps.length, 11);
    const scoped = containerSteps.filter((s) => render(s.task.behaviorName!, 2454) !== render(s.task.behaviorName!, 1898)).map((s) => s.name);
    assertEquals(scoped, ["Start the container", "Prepare the container", "Check out in the container", "Run dev deploy steps", "Finish the container", ...restoreSteps]);
    assertEquals(render(step("Finish the container").task.behaviorName!, 2454), "instance-deploy-container-finish-9-2454");
    const ssh = step("Run dev deploy steps");
    assertEquals(render(ssh.task.behaviorName!, 2454), "instance-deploy-container-step-9-2454-3");
    assertEquals(render(ssh.task.globalArgs!.name!, 2454), render(ssh.task.behaviorName!, 2454));
    // The short steps (a cat, an rm, the intent file) keep one name per Node or one fixed name.
    assertEquals(render(step("Read the container result").task.behaviorName!, 2454), "instance-deploy-container-result-9");
});

Deno.test("only the proof default holds its seed, after the start and the probe, and a busy hold at its bound changes nothing", () => {
    // docs/reference/development-containers.md#the-seed-hold: the intent's `seed_hold` names the default whose seed a seed copy sends.
    const proof = { ...nested, container: { seed_hold: true } };
    const written = { "Write the container intent": "succeeded", "Start the container": "succeeded" };
    assertEquals(skips("Hold the seed", proof, written), false);
    for (const intent of [{ ...container, container: {} }, { ...nested, container: {} }, { deployment: { variant: "development" } }]) {
        assertEquals(skips("Hold the seed", intent, written), true);
    }
    // The first phase waits for the hold; a normal default's skipped hold lets it run.
    assertEquals(step("Prepare the container").dependsOn!.find((d) => d.step === "Hold the seed")!.condition,
        { type: "or", conditions: [{ type: "succeeded" }, { type: "skipped" }] });
    const hold = step("Hold the seed").task.inputs;
    assert(hold.env!.ORBIT_HOLD_URL.includes('"/seed-hold"'));
    // The call asks again within ProofSeeds::HoldWaitSeconds (1,800 s), which the deployment's budget counts.
    assert(hold.run!.startsWith("timeout 1810 curl ") && hold.run!.includes("--retry-max-time 1800"));
    // A hold that stayed busy fails the run before any change after the start: no later step runs and no seed is restored.
    const busy = { ...written, "Hold the seed": "failed" };
    for (const name of ["Prepare the container", "Check out in the container", "Finish the container", ...restoreSteps]) {
        assertEquals(skips(name, proof, busy), true, name);
    }
});

Deno.test("a probe that finds the checkout already past the commit ends the run: no seed hold, fleet check, seed, bundle or restart, and nothing restores", () => {
    // The probe runs right after the start, before any other change, and prints the line only for a release or automatic deployment.
    const newer = { "Start the container": { status: "succeeded", outputs: { stdout: "ok: [node] => {\n    \"msg\": \"orbit-checkout=already_newer\"\n}\n" } } };
    const moved = { "Start the container": { status: "succeeded", outputs: { stdout: "ok: [node]\n" } } };
    const evaluate = (name: string, intent: Record<string, unknown>, statuses: Record<string, unknown>) =>
        cel.evaluate(expression(step(name).guard!), {
            inputs: { intent }, self: { step: { inputs: { a: "b" }, name: "migrate" } },
            steps: { "Write the container intent": { status: "succeeded" }, "Hold the seed": { status: "skipped" }, "Prepare the container": { status: "skipped" },
                "Check out in the container": { status: "skipped" }, "Read the steps to skip": { status: "skipped" }, ...statuses },
        });
    const withInputs = { ...nested, container: { seed_hold: true }, deployment: { variant: "container", steps: [{ inputs: { a: "b" } }] } };
    const later = ["Hold the seed", "Prepare the container", "Check out in the container", "Read the steps to skip", "Run dev deploy steps", "Finish the container"];
    for (const name of [...later, ...restoreSteps]) assertEquals(evaluate(name, withInputs, newer), true, name);
    for (const name of later) assertEquals(evaluate(name, withInputs, moved), false, name);
    // A refusal in the probe (an older commit by hand, a dirty tree, a diverged branch) fails the start step: nothing restores or restarts.
    // The deploy steps depend on a checkout that passed, which skipped.
    const refused = { "Start the container": { status: "failed" } };
    for (const name of [...later.filter((name) => name !== "Run dev deploy steps"), ...restoreSteps]) {
        assertEquals(evaluate(name, withInputs, refused), true, name);
    }
    // The result file is read whatever happened, so the kept commit reaches the Gateway.
    assert(step("Read the container result").dependsOn!.some((d) => d.step === "Start the container" && d.condition.type === "always"));
});

Deno.test("the restart after a restore keeps the failed phase's result and adds the receipt of the order it signed", () => {
    // As Operation 31729: the default ran, so only the restart signed an order. Without its receipt the Gateway ended the
    // Operation with executor.result_unknown and kept its lock (ADR 0266, R2).
    const folder = Deno.makeTempDirSync();
    try {
        Deno.mkdirSync(`${folder}/bin`);
        Deno.mkdirSync(`${folder}/venv/bin`, { recursive: true });
        // The worker's Ansible venv Python stands in as this machine's python3.
        const python = new TextDecoder().decode(new Deno.Command("sh", { args: ["-c", "command -v python3"] }).outputSync().stdout).trim();
        Deno.symlinkSync(python, `${folder}/venv/bin/python`);
        const receipt = { status: "done", operation: "31729", action: "discard", target: "2454", delivery: "swamp" };
        // The restart's play writes its own result, with the receipt.
        Deno.writeTextFileSync(`${folder}/venv/bin/ansible-playbook`,
            `#!/bin/sh\nprintf '%s' '${JSON.stringify({ commit: "c".repeat(40), executor: receipt })}' > "$ORBIT_RUN/result.json"\n`);
        Deno.chmodSync(`${folder}/venv/bin/ansible-playbook`, 0o755);
        Deno.writeTextFileSync(`${folder}/failed.json`, JSON.stringify({ error_code: "deployment.branch_diverged" }));
        const run = step("Start on the old seed").task.inputs.run!.replaceAll("/opt/orbit/ansible/venv", `${folder}/venv`);
        const done = new Deno.Command("sh", { args: ["-c", run], env: { ORBIT_RUN: folder, ORBIT_ANSIBLE: folder } }).outputSync();
        assert(done.success, new TextDecoder().decode(done.stderr));
        assertEquals(JSON.parse(Deno.readTextFileSync(`${folder}/result.json`)), { error_code: "deployment.branch_diverged", executor: receipt });
        // A failed "Start the restored fleet" starts its failure from failed.json, which keeps the receipt too.
        assertEquals(JSON.parse(Deno.readTextFileSync(`${folder}/failed.json`)), { error_code: "deployment.branch_diverged", executor: receipt });
        // A failed run that has a receipt of its own keeps it.
        const first = { ...receipt, action: "wake" };
        Deno.writeTextFileSync(`${folder}/failed.json`, JSON.stringify({ error_code: "deployment.branch_diverged", executor: first }));
        assert(new Deno.Command("sh", { args: ["-c", run], env: { ORBIT_RUN: folder, ORBIT_ANSIBLE: folder } }).outputSync().success);
        assertEquals(JSON.parse(Deno.readTextFileSync(`${folder}/result.json`)).executor, first);
    } finally {
        Deno.removeSync(folder, { recursive: true });
    }
});
