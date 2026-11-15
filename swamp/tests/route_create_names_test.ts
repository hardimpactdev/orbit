/**
 * The behavior names of a Route create's Node steps stay bounded: Swamp keeps every definition and its data for ever, and its data catalog
 * grows with the number of distinct names. A clone's create (`merge`) names its site, cleanup and TLS probe steps from a pool of 16 per Node;
 * every other caller keeps one name per Node. The expressions are read from the workflows and evaluated with the pinned Swamp's CEL.
 */
import { assertEquals } from "jsr:@std/assert@1";
import { parse } from "jsr:@std/yaml@1";
import { environment } from "./_support/swamp_workflow.ts";

type Step = { name: string; task: { behaviorName?: string } };
const steps = (workflow: string) =>
    Object.fromEntries((parse(Deno.readTextFileSync(new URL(`../workflows/workflow-${workflow}.yaml`, import.meta.url))) as { jobs: { steps: Step[] }[] })
        .jobs.flatMap((job) => job.steps).map((step) => [step.name, String(step.task.behaviorName ?? "")]));
const site = steps("route-create-site");
const create = steps("operation-route-create");
const families = { site: site.site, cleanup: site.cleanup, probe: create["Probe the site over TLS"] };
const evaluate = (expression: string, routeId: number, node: number, merge: boolean | null) =>
    String(environment.evaluate(expression.replace(/^\$\{\{|\}\}$/g, "").trim(), {
        inputs: { node_id: node, intent: { route_id: routeId }, ...(merge === null ? {} : { merge }) }, self: { probe: { node_id: node } },
    }));

Deno.test("a thousand clone creates make at most 16 names per family on each Node; other callers make one", () => {
    for (const [family, expression] of Object.entries(families)) {
        for (const node of [9, 12]) {
            const pooled = new Set(Array.from({ length: 1000 }, (_, index) => evaluate(expression, index + 1, node, true)));
            assertEquals(pooled.size, 16, `${family} on Node ${node}`);
            for (const name of pooled) assertEquals(name.startsWith(`route-${family === "probe" ? "probe-209" : family === "site" ? "site" : "site-cleanup"}-${node}-`), true, name);
            for (const merge of [false, null]) {
                assertEquals(new Set(Array.from({ length: 1000 }, (_, index) => evaluate(expression, index + 1, node, merge))).size, 1, `${family} unpooled`);
            }
        }
    }
    // Two Nodes never share a name, and 16 consecutive Routes, as creates started together get, never share a slot.
    assertEquals(evaluate(families.site, 41, 9, true), "route-site-9-9");
    assertEquals(evaluate(families.site, 41, 12, true), "route-site-12-9");
    assertEquals(new Set(Array.from({ length: 16 }, (_, index) => evaluate(families.site, 100 + index, 9, true))).size, 16);
    assertEquals(evaluate(families.site, 43, 9, null), "route-site-9");
});

Deno.test("a refused merge marks the folder that both result builders read", () => {
    // route-create-site.yml writes route-deferred-<node>.json next to ORBIT_INTENT_PATH, which is clone-route's admitted path, which is
    // clone-route-input's path; finish and clone-register read `folder` (routeDeferred) before the run folder goes.
    type Task = { inputs?: Record<string, unknown> };
    const create = parse(Deno.readTextFileSync(new URL("../workflows/workflow-operation-instance-create.yaml", import.meta.url))) as
        { jobs: { steps: { name: string; task: Task }[] }[] };
    const step = Object.fromEntries(create.jobs.flatMap((job) => job.steps).map((s) => [s.name, s.task]));
    const value = (expression: unknown) =>
        String(environment.evaluate(String(expression).replace(/^\$\{\{|\}\}$/g, "").trim(), { run: { id: "r-1" }, inputs: {}, steps: {} }));
    const intent = value(step["clone-route-input"].inputs?.path);
    assertEquals(String(step["clone-route"].inputs?.admitted).includes('steps["clone-route-input"].outputs.path'), true);
    assertEquals(String(step["clone-route"].inputs?.merge), "true");
    for (const builder of ["finish", "clone-register"]) assertEquals(intent.slice(0, intent.lastIndexOf("/")), value(step[builder].inputs?.folder), builder);
});
