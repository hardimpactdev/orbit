import { assert, assertEquals, assertFalse } from "jsr:@std/assert@1.0.19";
import { parse } from "jsr:@std/yaml@1.0.12";
import { expandGlob } from "jsr:@std/fs@1.0.19/expand-glob";
import { ROOT } from "../bin/gate-test-support.ts";

// Every serve loads the same `swamp/workflows`: the production Swamp Node's and a nested fleet's. A built-in trigger is therefore
// registered on every serve, so it may not read the production checkout or serve's TLS certificate. The production Swamp Node's
// own schedules have no built-in trigger: only the release Swamp load sets them, as serve trigger overrides
// (docs/reference/operations.md#schedules-of-the-swamp-node).
const production = ["/apps/orbit-monorepo-staging/checkout", "/serve-tls/"];

const schedules = JSON.parse(
    await Deno.readTextFile(`${ROOT}/config/swamp-node-schedules.json`),
) as Record<string, string>;

Deno.test("a workflow with a built-in trigger reads nothing of the production Swamp Node", async () => {
    for await (const path of expandGlob(`${ROOT}/workflows/*.yaml`)) {
        const source = await Deno.readTextFile(path.path);
        const flow = parse(source) as { trigger?: { schedule?: unknown } };
        if (!flow.trigger?.schedule) continue;
        for (const part of production) {
            assertFalse(source.includes(part), `${path.path} names ${part}`);
        }
    }
});

Deno.test("the production Swamp Node's schedules have no built-in trigger", async () => {
    assertEquals(Object.keys(schedules).sort(), [
        "swamp-credential-monitor",
        "swamp-datastore-watch",
    ]);
    assertEquals(schedules["swamp-credential-monitor"], "0 7 * * * *");
    assertEquals(schedules["swamp-datastore-watch"], "0 */15 * * * *");
    for (const name of Object.keys(schedules)) {
        const flow = parse(
            await Deno.readTextFile(`${ROOT}/workflows/workflow-${name}.yaml`),
        ) as { name: string; trigger?: unknown };
        assertEquals(flow.name, name);
        assertEquals(flow.trigger, undefined, name);
    }
});

const load = (parse(
    await Deno.readTextFile(
        `${ROOT}/workflows/workflow-operation-gateway-swamp-load.yaml`,
    ),
) as {
    jobs: { steps: { name: string; task: { inputs: { run: string } } }[] }[];
}).jobs[0].steps.find((step) => step.name === "load")!.task.inputs.run;

/** Runs the release load's `load` script against a stand-in `swamp` that logs each call, in a checkout that holds `config`, if any. */
async function runLoad(config: string | null, refuse = "", drain = false) {
    const folder = await Deno.makeTempDir();
    await Deno.mkdir(`${folder}/checkout/swamp/config`, { recursive: true });
    if (config !== null) {
        await Deno.writeTextFile(
            `${folder}/checkout/swamp/config/swamp-node-schedules.json`,
            config,
        );
    }
    await Deno.writeTextFile(
        `${folder}/swamp`,
        `#!/bin/sh
printf '%s\\n' "$*" >> ${folder}/calls
${drain ? "cat >/dev/null" : ""}
case "$1 $2" in
  "serve reload"|"access reload") echo '{"success": true}' ;;
  "workflow list") echo '{"results": [{"id": "a", "name": "swamp-datastore-watch"}]}' ;;
  "workflow trigger")
    [ "$4" = "${refuse}" ] && { echo '{"error": "refused"}'; exit 1; }
    printf '{"workflowName": "%s", "entry": {"schedule": "%s"}}\\n' "$4" "$6" ;;
  *) exit 2 ;;
esac
`,
    );
    await Deno.chmod(`${folder}/swamp`, 0o755);
    const script = load
        .replaceAll("/usr/local/bin/swamp", `${folder}/swamp`)
        .replace(
            "cd /home/orbit/apps/orbit-monorepo-staging/checkout",
            `cd ${folder}/checkout`,
        );
    const done = await new Deno.Command("sh", {
        args: ["-c", script],
        env: { RELEASE_SHA: "a".repeat(40), PREVIOUS_SHA: "b".repeat(40) },
        stdout: "piped",
        stderr: "piped",
    }).output();
    const calls = await Deno.readTextFile(`${folder}/calls`).catch(() => "");
    await Deno.remove(folder, { recursive: true });
    return { code: done.code, calls: calls.trim().split("\n") };
}

const token = "--server ws://127.0.0.1:9418 --token-file /home/orbit/.config/swamp/serve-driver-token --json";

Deno.test("the release load sets and confirms each production Swamp Node schedule on serve after the reload", async () => {
    const run = await runLoad(JSON.stringify(schedules));
    assertEquals(run.code, 0);
    const triggers = run.calls.filter((call) => call.startsWith("workflow trigger set"));
    assertEquals(triggers, [
        `workflow trigger set swamp-credential-monitor --schedule 0 7 * * * * ${token}`,
        `workflow trigger set swamp-datastore-watch --schedule 0 */15 * * * * ${token}`,
    ]);
    assert(
        run.calls.findIndex((call) => call.startsWith("serve reload")) <
            run.calls.indexOf(triggers[0]),
    );
});

Deno.test("a schedule that serve does not confirm fails the release load", async () => {
    const run = await runLoad(JSON.stringify(schedules), "swamp-datastore-watch");
    assertEquals(run.code === 0, false);
    assertFalse(run.calls.some((call) => call.startsWith("workflow list")));
});

Deno.test("a checkout without the schedules file sets no schedule and passes, as a rollback to an older commit needs", async () => {
    const run = await runLoad(null);
    assertEquals(run.code, 0);
    assertFalse(run.calls.some((call) => call.startsWith("workflow trigger")));
    assert(run.calls.some((call) => call.startsWith("workflow list")));
});

Deno.test("an empty schedules file sets no schedule and passes", async () => {
    const run = await runLoad("");
    assertEquals(run.code, 0);
    assertFalse(run.calls.some((call) => call.startsWith("workflow trigger")));
});

Deno.test("a schedules file that jq cannot read fails the release load", async () => {
    const run = await runLoad('{"swamp-credential-monitor": ');
    assertEquals(run.code === 0, false);
    assertFalse(run.calls.some((call) => call.startsWith("workflow trigger")));
    assertFalse(run.calls.some((call) => call.startsWith("workflow list")));
});

Deno.test("a swamp that reads its standard input still sets every schedule", async () => {
    const run = await runLoad(JSON.stringify(schedules), "", true);
    assertEquals(run.code, 0);
    assertEquals(
        run.calls.filter((call) => call.startsWith("workflow trigger set")).map((call) => call.split(" ")[3]),
        ["swamp-credential-monitor", "swamp-datastore-watch"],
    );
});
