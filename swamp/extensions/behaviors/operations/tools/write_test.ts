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
    // A Tool Orbit owns; the cases about an unowned one say so.
    package_owned: true,
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
    assertEquals(retry.error_code, "tool.package_already_installed");
    const known = decide(input({ tool_id: 1, tool_status: "failed", installed_version: "1.0.0" }), {
        facts: { ...facts, versions: ["1.0.0"] },
    });
    assertEquals([known.ready, known.mutate, known.installed_version], [
        true,
        false,
        "1.0.0",
    ]);
    // A version observed for a Tool Orbit does not own is no claim on the package.
    const unowned = decide(input({ tool_id: 1, tool_status: "failed", installed_version: "1.0.0", package_owned: false }), {
        facts: { ...facts, versions: ["1.0.0"] },
    });
    assertEquals(unowned.error_code, "tool.package_already_installed");
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

Deno.test("an update of a package Orbit does not own refuses before any write, also one queued without ownership", () => {
    const present = { facts: { ...facts, versions: ["1.0.0"] } };
    for (const unowned of [{ package_owned: false }, { package_owned: undefined }]) {
        const refused = decide(input({ kind: "tool.update", tool_id: 1, installed_version: "1.0.0", desired_version: "2.0.0", ...unowned }), present);
        assertEquals([refused.ready, refused.mutate, refused.error_code], [false, false, "tool.state_invalid"]);
    }
});

Deno.test("a refusal says whether it read the package, so a repeated admitted version is never taken for an observation", () => {
    const read = decide(input({ kind: "tool.update", tool_id: 1, installed_version: "1.0.0", desired_version: "3.0.0" }), {
        facts: { ...facts, versions: ["2.0.0"] }, refresh_error: "tool.candidate_version_probe_failed",
    });
    assertEquals([read.ready, read.installed_version, read.inventory_read], [false, "2.0.0", true]);
    const unread = decide(input({ kind: "tool.update", tool_id: 1, installed_version: "1.0.0" }), { facts: { ...facts, versions: [null] } });
    assertEquals([unread.ready, unread.installed_version, unread.inventory_read], [false, "1.0.0", false]);
});

Deno.test("a held apt package at its coordinate is unchanged, and at another version the hold refuses before any write", () => {
    const held = { ...facts, versions: ["1.0.0"], held: true };
    const owned = { tool_id: 1, installed_version: "1.0.0" };
    for (const changes of [{ kind: "tool.update", desired_version: "1.0.0" }, { kind: "tool.update" }, { kind: "tool.install" }]) {
        const kept = decide(input({ ...owned, ...changes }), { facts: held });
        assertEquals([kept.ready, kept.mutate, kept.outcome, kept.installed_version], [true, false, "unchanged", "1.0.0"]);
    }
    for (const changes of [{ desired_version: "2.0.0" }, { version_constraint: "^2.0" }]) {
        const refused = decide(input({ ...owned, kind: "tool.update", ...changes }), { facts: held, candidate: { version: "2.0.0" } });
        assertEquals([refused.ready, refused.mutate, refused.error_code, refused.installed_version], [false, false, "tool.package_held", "1.0.0"]);
    }
});
