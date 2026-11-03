import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { calls, exists, read, ssh, toolFixture } from "./tool_fixture_test.ts";

Deno.test("install uses native argv and records the checked version", async () => {
    await using f = await toolFixture();
    const { run, result } = await f.runKind();
    assertEquals([run.status, result.installed_version], [
        "succeeded",
        "2.0-1",
    ]);
    const argv = calls(f.audit);
    assert(
        argv.some((a) =>
            a.includes("--no-remove") && a.includes("DPkg::Lock::Timeout=300")
        ),
    );
    assert(
        !argv.some((a) => a.includes("-c") || a.some((p) => p.includes(".py"))),
    );
});
Deno.test("Homebrew shares native package write and remove paths", async () => {
    await using f = await toolFixture();
    for (
        const [kind, installed] of [["tool.install", null], [
            "tool.remove",
            "2.0",
        ]] as const
    ) {
        const { run, result } = await f.runKind(kind, true, { installed }, {
            manager: "brew",
            installed_version: installed,
        });
        assertEquals(run.status, "succeeded");
        assertEquals(
            read(f.state).installed,
            kind === "tool.install" ? "2.0" : null,
        );
        assertEquals(result.outcome, "applied");
    }
});
Deno.test("successful command without the package fails the native end assert", async () => {
    await using f = await toolFixture();
    const { steps } = await f.runKind("tool.install", false, {
        no_write: true,
    });
    assertEquals(steps["Apply the package"], "succeeded");
    assertEquals(steps["Assert package state"], "failed");
    assertEquals(read(f.state).installed, null);
});
Deno.test("constraint refusal writes nothing", async () => {
    await using f = await toolFixture();
    const { result } = await f.runKind("tool.install", false, {}, {
        version_constraint: "^1.0",
    });
    assertEquals(result.error_code, "tool.version_constraint_blocked");
    assertEquals(read(f.state).installed, null);
});
Deno.test("update skips a current or blocked candidate", async () => {
    await using f = await toolFixture();
    for (
        const [constraint, candidate, outcome] of [[null, "1.0", "unchanged"], [
            "^1.0",
            "2.0",
            "blocked_by_constraint",
        ]]
    ) {
        const { result } = await f.runKind("tool.update", true, {
            installed: "1.0",
            candidate,
        }, {
            installed_version: "1.0",
            tool_id: 1,
            version_constraint: constraint,
        });
        assertEquals(result.outcome, outcome);
        assertEquals(read(f.state).installed, "1.0");
    }
});
Deno.test("remove refuses a wide plan and proves absence", async () => {
    await using f = await toolFixture();
    let run = await f.runKind("tool.remove", true, { installed: "1.0" }, {
        installed_version: "1.0",
        tool_id: 1,
    });
    assertEquals(run.result.outcome, "applied");
    assertEquals(read(f.state).installed, null);
    run = await f.runKind("tool.remove", false, {
        installed: "1.0",
        unsafe: true,
    }, { installed_version: "1.0", tool_id: 1 });
    assertEquals(run.result.error_code, "tool.removal_plan_unsafe");
    assertEquals(read(f.state).installed, "1.0");
});
const repository = {
    name: "vendor",
    uris: ["https://packages.example.test/apt"],
    suites: ["stable"],
    components: ["main"],
    signing_key: {
        url: "https://packages.example.test/key.asc",
        fingerprint: "A".repeat(40),
    },
};
Deno.test("verified key reaches native source publication", async () => {
    await using f = await toolFixture();
    const { result, steps } = await f.runKind("tool.install", true, {
        requires_refresh: true,
    }, { repository, repository_given: true });
    assertEquals(result.outcome, "applied");
    assertEquals(steps["Write the apt source"], "succeeded");
    assert(read(f.state).refreshed);
});
Deno.test("mismatched key fails native assert before publishing", async () => {
    await using f = await toolFixture();
    const { result, steps } = await f.runKind("tool.install", false, {
        bad_key: true,
    }, { repository, repository_given: true });
    assertEquals(result.error_code, "tool.repository_key_mismatch");
    assertEquals(read(f.state).installed, null);
    assertEquals(steps["Publish the signing key"], "skipped");
    assert(!exists(f.audit), "A mismatched key must not contact the Node");
    const keys = exists(`${f.repo.dir}/worker-keys`)
        ? Array.from(Deno.readDirSync(`${f.repo.dir}/worker-keys`))
        : [];
    assertEquals(keys, []);
});

async function manager(missing = false, fail = false) {
    const f = await toolFixture();
    f.save({
        installed: null,
        candidate: "2.0",
        manager_missing: missing,
        fail_setup: fail,
    });
    try {
        await f.repo.cli([
            "workflow",
            "validate",
            "operation-tool-manager-setup",
        ]);
        const run = await f.repo.runWorkflow("operation-tool-manager-setup", {
            operation_id: 1,
            node_id: 9,
            ssh,
            intent: {
                manager: "brew",
                user: "orbit",
                platform: "linux",
                architecture: "x86_64",
                setup: {
                    manager: "homebrew",
                    input: {
                        user: "orbit",
                        revision: "a".repeat(40),
                        version: "4.0.0",
                    },
                    apt: null,
                    packages: [],
                },
            },
        }, { success: !fail });
        return { run, state: read(f.state), calls: calls(f.audit) };
    } finally {
        await f[Symbol.asyncDispose]();
    }
}
Deno.test("ready manager uses only native version commands", async () => {
    const { run, calls: argv } = await manager();
    assertEquals(run.run.status, "succeeded");
    assertEquals(argv.length, 2);
    assert(argv.every((a) => a.includes("--version")));
});
Deno.test("first use applies the pinned clone then checks readiness", async () => {
    const { run, state, calls: argv } = await manager(true);
    assertEquals(run.run.status, "succeeded");
    assertEquals(state.manager_missing, false);
    assertEquals(run.steps["Clone pinned Homebrew"], "succeeded");
    assert(
        argv.some((a) => a.includes("a".repeat(40))),
        "The clone must use the admitted revision",
    );
    assert(argv.at(-1)!.includes("--version"));
});
Deno.test("failed pinned setup fails without a readiness retry", async () => {
    const { run, state, calls: argv } = await manager(true, true);
    assertEquals(run.run.status, "failed");
    assert(state.manager_missing);
    assertEquals(run.steps["Clone pinned Homebrew"], "failed");
    assertEquals(argv.filter((a) => a.includes("--version")).length, 1);
});
