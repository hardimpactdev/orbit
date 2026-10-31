import { assert, assertEquals } from "jsr:@std/assert@1.0.14";
import {
    matchesMountPaths,
    mountPathDirective,
    RequiredMountPaths,
} from "./required-mount-paths.ts";

Deno.test("required mount paths enforce the public canonical UTF8 list contract", () => {
    assertEquals(
        RequiredMountPaths.parse(["/", "/srv/state with space", "/srv/%n"]),
        ["/", "/srv/state with space", "/srv/%n"],
    );
    for (
        const bad of [
            null,
            "/srv/state",
            ["relative"],
            ["/a/../b"],
            ["/a/./b"],
            ["/a//b"],
            ["/a/"],
            ["/a\n"],
            ["/a\x7f"],
            ["/a", "/a"],
            ["/" + "é".repeat(2048)],
            Array.from({ length: 65 }, (_, n) => `/a${n}`),
        ]
    ) assert(!RequiredMountPaths.safeParse(bad).success);
});

Deno.test("captured mount directives must be exact literal Unit dependencies", () => {
    const paths = ["/srv/state with space", '/srv/%n"$literal'];
    const directive = mountPathDirective(paths);
    assertEquals(
        directive,
        "RequiresMountsFor=/srv/state\\x20with\\x20space /srv/%%n\\x22\\x24literal",
    );
    assert(matchesMountPaths(`[Unit]\n${directive}\n[Service]\n`, paths));
    for (
        const content of [
            `[Unit]\n[Service]\n`,
            `[Service]\n${directive}\n`,
            `[Unit]\n${directive}\n${directive}\n`,
            `[Unit]\nRequiresMountsFor=/other\n`,
        ]
    ) assert(!matchesMountPaths(content, paths));
    assert(matchesMountPaths("[Unit]\n", []));
});
