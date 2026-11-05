import { assertEquals } from "jsr:@std/assert@1";
import { behavior, decide, ManagerInput, WriteInput } from "./write.ts";

const facts = {
    scope: "present",
    manager_version: "apt 3.0.0",
    versions: [] as (string | null)[],
    complete: true,
    roots: ["vendor/dependency"],
};
const base = {
    kind: "tool.install",
    manager: "apt",
    node_id: 9,
    package: "hello",
    user: "orbit",
    platform: "linux",
    architecture: "x86_64",
    tool_id: null,
};
const input = (changes: Record<string, unknown> = {}) =>
    WriteInput.parse({ ...base, ...changes });

Deno.test("refusals happen before any write: silent adoption, missing updates and malformed inventories", () => {
    assertEquals(
        decide(input({ tool_id: 1 }), {
            facts: { ...facts, versions: ["1.0.0"] },
        }).error_code,
        "tool.package_already_installed",
    );
    assertEquals(
        decide(input({ kind: "tool.update", tool_id: 1 }), { facts })
            .error_code,
        "tool.version_probe_failed",
    );
    for (
        const changes of [{ versions: [null] }, { versions: ["1", "2"] }, {
            complete: false,
        }]
    ) {
        const refused = decide(input(), { facts: { ...facts, ...changes } });
        assertEquals([refused.ready, refused.manager_version], [
            false,
            "apt 3.0.0",
        ]);
    }
    assertEquals(decide(input(), null).error_code, "tool.version_probe_failed");
    const retry = decide(input({ tool_id: 1, tool_status: "failed" }), {
        facts: { ...facts, versions: ["1.0.0"] },
    });
    assertEquals([retry.ready, retry.mutate, retry.installed_version], [
        true,
        false,
        "1.0.0",
    ]);
});

Deno.test("the constraint blocks an install, an update stays unchanged on the same version, and no candidate fails", () => {
    const pinned = { version_constraint: "^1.0" };
    assertEquals(
        decide(input(pinned), { facts, candidate: { version: "2.0.0" } })
            .error_code,
        "tool.version_constraint_blocked",
    );
    assertEquals(
        decide(input(pinned), { facts, candidate: { version: "garbage" } })
            .error_code,
        "tool.candidate_version_unparseable",
    );
    const update = input({ ...pinned, kind: "tool.update", tool_id: 1 });
    const blocked = decide(update, {
        facts: { ...facts, versions: ["1.0.0"] },
        candidate: { version: "2.0.0" },
    });
    assertEquals([blocked.ready, blocked.mutate, blocked.outcome], [
        true,
        false,
        "blocked_by_constraint",
    ]);
    const same = decide(update, {
        facts: { ...facts, versions: ["1.2.0"] },
        candidate: { version: "1.2.0" },
    });
    assertEquals([same.mutate, same.outcome], [false, "unchanged"]);
    assertEquals(
        decide(input(), { facts, candidate: null }).error_code,
        "tool.candidate_version_probe_failed",
    );
    const newer = decide(update, {
        facts: { ...facts, versions: ["1.2.0"] },
        candidate: { version: "1.3.0" },
    });
    assertEquals([newer.mutate, newer.candidate_json], [
        true,
        '{"version":"1.3.0"}',
    ]);
});

Deno.test("a Composer dependency already in the inventory is still an installable root", () => {
    const value = input({
        manager: "composer",
        package: "vendor/dependency",
        kind: "tool.update",
        tool_id: 41,
    });
    const decision = decide(value, {
        facts: { ...facts, versions: ["1.0.0"], roots: [] },
        candidate: { version: "1.1.0" },
    });
    assertEquals([decision.ready, decision.mutate], [true, true]);
});

Deno.test("the shared manager setup reads only the manager, with no package", async () => {
    const ctx = {
        extensionFile: (p: string) => new URL(p, import.meta.url).pathname,
        writeResource: () => Promise.resolve(null),
    };
    const deploy = {
        node_id: 9,
        user: "orbit",
        manager: "vp",
        platform: "linux",
        architecture: "x86_64",
        setup: null,
    };
    const assets = await behavior.methods.manager_assets.execute({
        input: ManagerInput.parse(deploy),
    }, ctx);
    assertEquals(assets.version, [
        "/usr/bin/env",
        "LC_ALL=C",
        "/usr/local/bin/vp",
        "--version",
    ]);
    assertEquals(assets.packages, []);
    assertEquals(
        WriteInput.safeParse({ ...base, kind: "tool.manager_converge" })
            .success,
        false,
    );
});
Deno.test("manager writes carry public arguments and no script source", async () => {
    const ctx = {
        extensionFile: (p: string) => new URL(p, import.meta.url).pathname,
        writeResource: () => Promise.resolve(null),
    };
    for (const manager of ["apt", "composer", "vp", "brew", "brew-cask"]) {
        const value = input({
            manager,
            package: "vendorsentinel",
            platform: manager === "brew-cask" ? "macos" : "linux",
        });
        const argv = await behavior.methods.assets.execute(
            { input: value },
            ctx,
        );
        assertEquals(argv.candidate.includes("-c"), false);
        assertEquals(
            argv.inventory.some((arg) => arg.includes("vendorsentinel")) ||
                manager !== "apt",
            true,
        );
    }
});
