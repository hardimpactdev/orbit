import {
    assertEquals,
    assertRejects,
    assertThrows,
} from "jsr:@std/assert@1.0.19";
import {
    checkGrantPatterns,
    loadNativeRegexCheck,
    requirePinnedSource,
} from "./check-grant-patterns.ts";

const nativeCheck = await loadNativeRegexCheck();
const policy = JSON.parse(
    await Deno.readTextFile(
        new URL("../config/gateway-workflow-grants.json", import.meta.url),
    ),
);
const current = policy.vaults.find((row: { key_pattern: string }) =>
    row.key_pattern.includes("instance-create-environment-input-")
);
const rejected =
    "^instance-(?:git-operation-|create-environment-(?:input-|result-)|create-)[1-9][0-9]*(?:-setup-[0-9]+)?$";

Deno.test("every declared grant pattern passes the actual native safety check", () => {
    assertEquals(checkGrantPatterns(policy, nativeCheck), []);
});

Deno.test("native check rejects the original create pattern and accepts the exact create key set", () => {
    assertEquals(
        nativeCheck(rejected)?.includes("catastrophic backtracking"),
        true,
    );
    assertEquals(nativeCheck(current.key_pattern), undefined);
    const safePattern = new RegExp(current.key_pattern);
    // Create writes the git token, the rendered environment and the setup list, one key each per Operation.
    const created = ["git-operation-", "create-environment-input-", "create-"];
    for (
        const prefix of [
            ...created,
            "create-environment-result-",
            "destroy-",
            "clone-environment-operation-",
        ]
    ) {
        for (const id of ["0", "1", "9", "10", "01", "99999", "-1", "x"]) {
            for (const suffix of ["", "-setup-0", "-setup-1", "-extra"]) {
                const key = `instance-${prefix}${id}${suffix}`;
                const expected = created.includes(prefix) && suffix === "" && /^[1-9][0-9]*$/.test(id);
                assertEquals(safePattern.test(key), expected, key);
            }
        }
    }
});

Deno.test("gate rejects an unsafe later entry and invalid regex syntax with its vault location", () => {
    assertEquals(
        checkGrantPatterns({
            vaults: [
                { vault: "first", key_pattern: "^safe$" },
                { vault: "second", key_pattern: rejected },
                { vault: "third", key_pattern: "[" },
            ],
        }, nativeCheck).map((error) => error.split(": ")[0]),
        ["vaults[1] second", "vaults[2] third"],
    );
});

Deno.test("source checksum and runtime pin changes fail closed", async () => {
    await assertRejects(
        () => loadNativeRegexCheck("changed source"),
        Error,
        "checksum mismatch",
    );
    requirePinnedSource("20261031.063736.0-sha.e459d219");
    assertThrows(
        () => requirePinnedSource("20261101.000000.0-sha.12345678"),
        Error,
        "runtime pin",
    );
});
