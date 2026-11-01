import { assertEquals, assertThrows } from "jsr:@std/assert@1";
import { plan, verify } from "./metadata.ts";
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
const report = JSON.stringify({
    code: 0,
    stdout: JSON.stringify({ advisories: {} }),
});
Deno.test("package policy preserves ranges and admits only an exact non-downgrade solve", () => {
    const p = plan(place, snapshot("1.0.0"), report);
    assertEquals(p.patch.after.require["acme/lib"], "^2.0");
    assertEquals(p.update.argv, [
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
        code: 1,
        stdout: JSON.stringify({
            advisories: { "acme/lib": [{ advisoryId: "PKSA-fixed" }] },
        }),
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
    const a = JSON.stringify({
        code: 0,
        stdout: JSON.stringify({ vulnerabilities: {} }),
    });
    const result = plan(p, s, a);
    assertEquals(result.patch.after.dependencies.lib, "2.0.0");
    assertEquals(result.declarations.dependencies.lib, "^2.0.0");
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
