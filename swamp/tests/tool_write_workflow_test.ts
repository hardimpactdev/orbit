/**
 * The apt preference order of operation-tool-write-209 (docs/reference/tools.md#apt-repositories), on the pinned engine's scheduling
 * rule: the repository's own add published it, so a write holds it to owned packages before the package is read, and the acting
 * package joins that preference only after its write is verified.
 */
import { assertEquals } from "jsr:@std/assert@1.0.19";
import { type Outcome, runWorkflow } from "./_support/swamp_workflow.ts";

const repository = { name: "proof", uris: ["https://packages.example.test/apt"], suites: ["stable"], components: ["main"],
    signing_key: { url: "https://packages.example.test/key.asc", fingerprint: "A".repeat(40) } };
const run = (outcomes: Record<string, Outcome>, named = true) =>
    runWorkflow("workflow-operation-tool-write-209.yaml", "tool.install", {
        instance_id: 0, manager: "apt", package: "tree", platform: "linux", architecture: "x86_64", repository: named ? repository : null,
    }, {
        "Prepare package commands": { outputs: { repository, repository_preferences: "acquired", repository_preferences_held: "held", readable: null } },
        "Choose package action": { outputs: { ready: true, mutate: true } },
        "Decode package state": { outputs: { compliant: true, present: true } },
        ...outcomes,
    }, () => ({}));
const writes = ["Pin repository packages", "Prefer the verified package"];
const statuses = (ran: Record<string, { status: string }>) => Object.fromEntries(writes.map((name) => [name, ran[name]?.status]));

Deno.test("a write holds the repository to owned packages before the package is read and prefers the acquired package last", () => {
    const ran = run({});
    assertEquals(statuses(ran), { "Pin repository packages": "succeeded", "Prefer the verified package": "succeeded" });
    assertEquals(ran["Pin repository packages"].end <= ran["Read installed package"].start, true);
    assertEquals(ran["Decode package state"].end <= ran["Prefer the verified package"].start, true);
    assertEquals(ran["Assert package state"].status, "succeeded");
});

Deno.test("a write without a repository writes no preference", () => {
    const ran = run({}, false);
    assertEquals(statuses(ran), { "Pin repository packages": "skipped", "Prefer the verified package": "skipped" });
    assertEquals(ran["Assert package state"].status, "succeeded");
});

Deno.test("an unchanged owned package still ends with its repository's own preference", () => {
    const ran = run({ "Choose package action": { outputs: { ready: true, mutate: false } } });
    assertEquals([ran["Prefer the verified package"].status, ran["Assert package state"].status], ["succeeded", "succeeded"]);
});

Deno.test("a refusal or an unverified write never prefers the acting package", () => {
    const refused = run({ "Choose package action": { outputs: { ready: false, mutate: false } } });
    assertEquals(statuses(refused), { "Pin repository packages": "succeeded", "Prefer the verified package": "skipped" });
    const unverified = run({ "Decode package state": { outputs: { compliant: false, present: false } } });
    assertEquals(unverified["Prefer the verified package"].status, "skipped");
    assertEquals(unverified["Assert package state"].status, "failed");
});

Deno.test("a failed write of the acquired package's preference fails the run after the acquisition", () => {
    const ran = run({ "Prefer the verified package": { status: "failed" } });
    assertEquals([ran["Decode package state"].status, ran["Assert package state"].status], ["succeeded", "failed"]);
});

Deno.test("a Linux Homebrew write makes the prefix readable after its package step, and an apt write does not", () => {
    const brew = runWorkflow("workflow-operation-tool-write-209.yaml", "tool.install", {
        instance_id: 0, manager: "brew", package: "git", platform: "linux", architecture: "x86_64", repository: null,
    }, {
        "Prepare package commands": { outputs: { readable: ["/usr/bin/find"] } },
        "Choose package action": { outputs: { ready: true, mutate: true } },
        "Decode package state": { outputs: { compliant: true, present: true } },
    }, () => ({}));
    assertEquals(brew["Make the Homebrew prefix readable"].status, "succeeded");
    assertEquals(brew["Apply the package"].end <= brew["Make the Homebrew prefix readable"].start, true);
    assertEquals(run({})["Make the Homebrew prefix readable"].status, "skipped");
});

Deno.test("a failed Linux Homebrew write still makes the prefix readable, and the run keeps the failure", () => {
    const outcomes = {
        "Prepare package commands": { outputs: { readable: ["/usr/bin/find"] } },
        "Choose package action": { outputs: { ready: true, mutate: true } },
        "Decode package state": { outputs: { compliant: false, present: false } },
        "Apply the package": { status: "failed" },
    };
    const brew = runWorkflow("workflow-operation-tool-write-209.yaml", "tool.install", {
        instance_id: 0, manager: "brew", package: "git", platform: "linux", architecture: "x86_64", repository: null,
    }, outcomes, () => ({}));
    const unprepared = runWorkflow("workflow-operation-tool-write-209.yaml", "tool.install", {
        instance_id: 0, manager: "brew", package: "git", platform: "linux", architecture: "x86_64", repository: null,
    }, { ...outcomes, "Prepare package commands": { status: "failed" } }, () => ({}));
    assertEquals(unprepared["Make the Homebrew prefix readable"].status, "skipped");
    assertEquals([brew["Apply the package"].status, brew["Make the Homebrew prefix readable"].status, brew["Assert package state"].status], ["failed", "succeeded", "failed"]);
});

Deno.test("a Linux Homebrew write whose package step was skipped skips the repair, and the run succeeds", () => {
    const ran = runWorkflow("workflow-operation-tool-write-209.yaml", "tool.install", {
        instance_id: 0, manager: "brew", package: "git", platform: "linux", architecture: "x86_64", repository: null,
    }, {
        "Prepare package commands": { outputs: { readable: ["/usr/bin/find"] } },
        "Choose package action": { outputs: { ready: true, mutate: false } },
        "Decode package state": { outputs: { compliant: true, present: true } },
    }, () => ({}));
    assertEquals([ran["Apply the package"].status, ran["Make the Homebrew prefix readable"].status, ran["Assert package state"].status], ["skipped", "skipped", "succeeded"]);
});
