import { assertEquals, assertThrows } from "jsr:@std/assert@1";
import { enrollmentStorage } from "./enrollment_settings.ts";
const input = {
    storage: {
        root: null,
        user: "orbit",
        create_missing: true,
        protected_roots: ["/etc"],
        managed_checkouts: ["/srv/existing"],
    },
    account: { user: "orbit", group: "orbit", home: "/home/orbit" },
};
Deno.test("enrollment storage binds the actual account while preserving create versus inspect", () => {
    assertEquals(enrollmentStorage(input), {
        root: "/home/orbit/apps",
        user: "orbit",
        group: "orbit",
        create_missing: true,
    });
    assertEquals(
        enrollmentStorage({
            ...input,
            storage: {
                ...input.storage,
                root: "/srv/managed",
                create_missing: false,
            },
        }).create_missing,
        false,
    );
});
Deno.test("enrollment refuses account mismatch, hidden home roots, protected paths and checkout nesting", () => {
    for (
        const root of [
            "/home/orbit",
            "/home/orbit/.private/apps",
            "/home",
            "/etc/apps",
            "/srv/existing/subdir",
            "/srv/existing",
        ]
    ) {
        assertThrows(() =>
            enrollmentStorage({ ...input, storage: { ...input.storage, root } })
        );
    }
    assertThrows(() =>
        enrollmentStorage({
            ...input,
            account: { ...input.account, user: "other" },
        })
    );
});
