import { assertEquals, assertThrows } from "jsr:@std/assert@1.0.19";
import { cleanup } from "../development-deploy/mod.ts";

const lock = ["/usr/bin/flock", "--nonblock", "--conflict-exit-code", "73", "/srv/app"];

Deno.test("cleanup removes only untracked entries in directories the target commit lacks", () => {
    // packages/old is gone in the target; packages/kept and the root stay.
    const untracked = ["packages/old/node_modules/", "packages/old/.env", "packages/kept/node_modules/", ".env", "vendor/", ""].join("\0");
    assertEquals(cleanup("/srv/app", untracked, ["packages", "packages/kept", ""].join("\0")), [
        [...lock, "/usr/bin/chmod", "-R", "u+w", "--", "/srv/app/packages/old/node_modules"],
        [...lock, "/usr/bin/rm", "-rf", "--", "/srv/app/packages/old/node_modules"],
        [...lock, "/usr/bin/rm", "-f", "--", "/srv/app/packages/old/.env"],
    ]);
    assertEquals(cleanup("/srv/app", "", ""), []);
});

Deno.test("cleanup refuses an entry outside the checkout", () => {
    assertThrows(() => cleanup("/srv/app", "a/../../etc/", ""), Error, "deployment.identity_changed");
    assertThrows(() => cleanup("/srv/app", "/etc/passwd", ""), Error, "deployment.identity_changed");
});
