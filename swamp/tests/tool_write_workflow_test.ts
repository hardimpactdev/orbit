/**
 * The apt preference order of operation-tool-write-209 (docs/reference/tools.md#source-trust), on the pinned engine's scheduling rule:
 * the held preference, owned packages only, is written after the key is verified and before the key and source are published; the
 * acting package joins it only after its write is verified. A key that is never verified writes nothing.
 */
import { assertEquals } from "jsr:@std/assert@1.0.19";
import { type Outcome, runWorkflow } from "./_support/swamp_workflow.ts";

const repository = { name: "proof", uris: ["https://packages.example.test/apt"], suites: ["stable"], components: ["main"],
    signing_key: { url: "https://packages.example.test/key.asc", fingerprint: "A".repeat(40) } };
const fingerprint = `pub:u:4096:1:0:0:::::::scESC:\nfpr:::::::::${"A".repeat(40)}:\n`;
const run = (given: boolean, outcomes: Record<string, Outcome>) =>
    runWorkflow("workflow-operation-tool-write-209.yaml", "tool.install", {
        instance_id: 0, manager: "apt", package: "tree", platform: "linux", architecture: "x86_64", repository, repository_given: given,
    }, {
        "Prepare package commands": { outputs: { repository, repository_preferences: "acquired", repository_preferences_held: "held" } },
        "Read the key fingerprint": { outputs: { stdout: fingerprint, exitCode: 0 } },
        "Choose package action": { outputs: { ready: true, mutate: true } },
        "Decode package state": { outputs: { compliant: true, present: true } },
        ...outcomes,
    }, () => ({}));
const writes = ["Pin repository packages", "Publish the signing key", "Write the apt source", "Prefer the acquired package"];
const statuses = (ran: Record<string, { status: string }>) => Object.fromEntries(writes.map((name) => [name, ran[name]?.status]));

Deno.test("a verified write holds the source to owned packages before publication and prefers the acquired package last", () => {
    const ran = run(true, {});
    assertEquals(statuses(ran), Object.fromEntries(writes.map((name) => [name, "succeeded"])));
    assertEquals(ran["Pin repository packages"].end <= ran["Publish the signing key"].start, true);
    assertEquals(ran["Decode package state"].end <= ran["Prefer the acquired package"].start, true);
    assertEquals(ran["Assert package state"].status, "succeeded");
});

Deno.test("a key that fails to fetch or verify writes no preference, key or source", () => {
    for (const failed of ["Fetch the signing key", "Read the key fingerprint"]) {
        // No package is read either, so the package decision has no observation to accept.
        const ran = run(true, { [failed]: { status: "failed" }, "Choose package action": { status: "failed", outputs: { ready: false, mutate: false } } });
        assertEquals(statuses(ran), Object.fromEntries(writes.map((name) => [name, "skipped"])), failed);
        assertEquals([ran["Read installed package"].status, ran["Apply the package"].status, ran["Assert package state"].status], ["skipped", "skipped", "failed"]);
    }
});

Deno.test("a refusal or an unverified write never prefers the acting package, and a verified source is not published again", () => {
    const refused = run(false, { "Choose package action": { outputs: { ready: false, mutate: false } } });
    assertEquals(statuses(refused), { "Pin repository packages": "succeeded", "Publish the signing key": "skipped", "Write the apt source": "skipped",
        "Prefer the acquired package": "skipped" });
    const unverified = run(true, { "Decode package state": { outputs: { compliant: false, present: false } } });
    assertEquals(unverified["Prefer the acquired package"].status, "skipped");
    assertEquals(unverified["Assert package state"].status, "failed");
});

Deno.test("a failed write of the acquired package's preference fails the run after the acquisition", () => {
    const ran = run(true, { "Prefer the acquired package": { status: "failed" } });
    assertEquals([ran["Decode package state"].status, ran["Assert package state"].status], ["succeeded", "failed"]);
});
