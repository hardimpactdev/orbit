import { assertEquals, assertThrows } from "jsr:@std/assert@1";
import {
    directory,
    manifestWrite,
    plan,
    restore,
    result,
    snapshot as read,
    verify,
} from "./metadata.ts";
const target = {
    path: ".",
    manager: "composer" as const,
    package: "acme/lib",
    version: "2.0.0",
    purpose: "major" as const,
    advisories: [],
    advisoryUrls: [],
};
const place = {
    path: ".",
    manager: "composer" as const,
    checkout_path: "/owned",
    mode: "package" as const,
    targets: [target],
    minimum_days: 7,
    directory: "/owned",
    files: ["composer.json", "composer.lock"],
};
const snapshot = (version: string, range = "^1.0") => ({
    directory: "/owned",
    node_modules: false,
    files: {
        "composer.json": JSON.stringify({ require: { "acme/lib": range } }),
        "composer.lock": JSON.stringify({
            packages: [{ name: "acme/lib", version }],
        }),
    },
});
const report = JSON.stringify({ advisories: {} });
Deno.test("package policy preserves ranges and admits only an exact non-downgrade solve", () => {
    const p = plan(place, snapshot("1.0.0"), report);
    assertEquals(p.after.require["acme/lib"], "^2.0");
    assertEquals(p.manifest_path, "/owned/composer.json");
    assertEquals(JSON.parse(p.manifest_text), p.after);
    assertEquals(p.manifest_write, true);
    assertEquals(p.update_argv, [
        "env",
        "-C",
        "/owned",
        "composer",
        "update",
        "acme/lib",
        "--with",
        "acme/lib:2.0.0",
        "--with-dependencies",
        "--no-interaction",
    ]);
    assertEquals(
        verify(place, snapshot("1.0.0"), snapshot("2.0.0", "^2.0"), report)[0]
            .version,
        "2.0.0",
    );
    assertThrows(
        () => plan(place, snapshot("3.0.0"), report),
        Error,
        "downgrade",
    );
    assertThrows(
        () => verify(place, snapshot("1.0.0"), snapshot("2.1.0"), report),
        Error,
        "maintenance_exact_lock_mismatch",
    );
});
Deno.test("security policy refuses stale advisories and a still-affected selected target", () => {
    const security = {
        ...place,
        targets: [{
            ...target,
            purpose: "security" as const,
            advisories: ["PKSA-fixed"],
            advisoryUrls: ["https://example.test/advisory"],
        }],
    };
    assertThrows(
        () => plan(security, snapshot("1.0.0"), report),
        Error,
        "Stale advisory",
    );
    const affected = JSON.stringify({
        advisories: { "acme/lib": [{ advisoryId: "PKSA-fixed" }] },
    });
    assertThrows(
        () => verify(security, snapshot("1.0.0"), snapshot("2.0.0"), affected),
        Error,
        "maintenance_advisory_unfixed",
    );
});
Deno.test("JS solve pins exact versions before restoring the preserved declaration style", () => {
    const p = {
        ...place,
        manager: "js" as const,
        targets: [{ ...target, manager: "js" as const, package: "lib" }],
    };
    const s = {
        directory: "/owned",
        node_modules: true,
        files: {
            "package.json": JSON.stringify({
                packageManager: "npm@11",
                dependencies: { lib: "^1.0.0" },
            }),
            "package-lock.json": JSON.stringify({
                packages: { "node_modules/lib": { version: "1.0.0" } },
            }),
        },
    };
    const a = JSON.stringify({ vulnerabilities: {} });
    const planned = plan(p, s, a);
    assertEquals(planned.after.dependencies.lib, "2.0.0");
    assertEquals(planned.declarations.dependencies.lib, "^2.0.0");
    const after = {
        ...s,
        files: {
            ...s.files,
            "package-lock.json": JSON.stringify({
                packages: {
                    "node_modules/lib": { version: "2.0.0" },
                    "node_modules/lib/node_modules/transitive": {
                        version: "1.1.0",
                    },
                },
            }),
        },
    };
    assertEquals(verify(p, s, after, a).map((row) => row.name), [
        "lib",
        "transitive",
    ]);
});

Deno.test("the exact Expo batch must cover its fresh SDK catalog without changing target versions", async () => {
    const { verifyExpo } = await import("./metadata.ts");
    const p = {
        ...place,
        manager: "js" as const,
        targets: [{
            ...target,
            manager: "js" as const,
            package: "expo",
            version: "55.0.0",
        }, {
            ...target,
            manager: "js" as const,
            package: "react",
            version: "19.0.0",
        }],
    };
    const s = {
        directory: "/owned",
        node_modules: true,
        files: {
            "package.json": JSON.stringify({
                packageManager: "npm@11",
                dependencies: { expo: "^54.0.0", react: "^18.0.0" },
            }),
            "package-lock.json": JSON.stringify({
                packages: {
                    "node_modules/expo": { version: "54.0.0" },
                    "node_modules/react": { version: "18.0.0" },
                },
            }),
        },
    };
    assertEquals(verifyExpo(p, s, { react: "^19.0.0" }), { verified: true });
    assertThrows(
        () => verifyExpo(p, s, { react: "^20.0.0" }),
        Error,
        "maintenance_expo_catalog_mismatch",
    );
    assertThrows(
        () =>
            verifyExpo({ ...p, targets: [p.targets[0]] }, s, {
                react: "^19.0.0",
            }),
        Error,
        "maintenance_expo_catalog_mismatch",
    );
});

const ssh = (stdout: string, exitCode = 0, stderr = "") => ({
    status: "succeeded",
    outputs: { stdout, exitCode, stderr },
});

Deno.test("a configured path stays inside the captured checkout", () => {
    assertEquals(directory("/w/", "."), "/w");
    assertEquals(directory("/w", "apps/web"), "/w/apps/web");
    for (const bad of ["/etc", "../x", "a/../b", "a//b", "./a", "a/"]) {
        assertThrows(
            () => directory("/w", bad),
            Error,
            "maintenance_invalid_path",
        );
    }
});

Deno.test("a snapshot reads each manifest exec and records a missing file as absent", () => {
    const steps = {
        "read-after-0": ssh('{"require":{}}\n'),
        "read-after-1": ssh(
            "",
            1,
            "cat: /owned/composer.lock: No such file or directory\n",
        ),
        "node-modules": ssh("", 1),
    };
    assertEquals(read(place, "after", steps), {
        directory: "/owned",
        files: { "composer.json": '{"require":{}}\n' },
        node_modules: false,
    });
    assertThrows(
        () =>
            read(place, "after", {
                ...steps,
                "read-after-1": ssh(
                    "",
                    1,
                    "cat: /owned/composer.lock: Permission denied\n",
                ),
            }),
        Error,
        "maintenance_manifest_unreadable",
    );
    assertThrows(
        () => read(place, "before", steps),
        Error,
        "maintenance_manifest_unreadable",
    );
});

Deno.test("a manifest write is the whole pretty JSON and skips an equal manifest in any key order", () => {
    const write = manifestWrite(place, { b: 1, a: { d: 2, c: 3 } }, {
        a: { c: 3, d: 2 },
        b: 1,
    });
    assertEquals(write, {
        manifest_path: "/owned/composer.json",
        manifest_text:
            '{\n  "a": {\n    "c": 3,\n    "d": 2\n  },\n  "b": 1\n}\n',
        manifest_write: false,
    });
    assertEquals(manifestWrite(place, { a: 1 }, { a: 2 }).manifest_write, true);
});

Deno.test("JS restoration writes the declarations back and syncs only the lock in its directory", () => {
    const js = { ...place, manager: "js" as const, files: ["package.json"] };
    const after = {
        directory: "/owned",
        node_modules: true,
        files: {
            "package.json": JSON.stringify({ dependencies: { lib: "2.0.0" } }),
            "bun.lock": "{}",
        },
    };
    const restored = restore(js, after, { dependencies: { lib: "^2.0.0" } });
    assertEquals(restored.manifest_path, "/owned/package.json");
    assertEquals(restored.manifest_write, true);
    assertEquals(restored.sync_argv, [
        "env",
        "-C",
        "/owned",
        "bun",
        "install",
        "--lockfile-only",
        "--ignore-scripts",
    ]);
});

Deno.test("the result counts tracked and untracked changes against the provision base", () => {
    const intent = { group_id: 1, instance_id: 2, project_id: 3, subtask: 4 };
    const changed = (tracked: number, untracked: string) =>
        result(intent, {
            "snapshot-tracked": ssh("", tracked),
            "snapshot-untracked": ssh(untracked),
        }).changed;
    assertEquals(changed(0, ""), false);
    assertEquals(changed(1, ""), true);
    assertEquals(changed(0, "new.txt\0"), true);
    assertEquals(
        result(intent, {
            "snapshot-tracked": ssh("", 0),
            "snapshot-untracked": ssh(""),
        }),
        { ...intent, outcome: "passed", changed: false },
    );
    assertThrows(
        () =>
            result(intent, {
                "snapshot-tracked": ssh("", 128),
                "snapshot-untracked": ssh(""),
            }),
        Error,
        "maintenance_base_unavailable",
    );
});
