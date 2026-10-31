import { assertEquals } from "jsr:@std/assert@1.0.19";
import { validValkeyAcl, writeValkeyAcl } from "./valkey_acl_local.ts";
const hash = "#" + "a".repeat(64);
Deno.test("local ACL validator retains the exact Rust default and managed-user frames", () => {
  for (
    const acl of [
      `user default on ${hash} ~* &* +@all\n`,
      "user default on nopass ~* &* +@all\n",
      `user default ${hash} on ~* &* +@all sanitize-payload\nuser app-1 on ${hash} ~app:* resetchannels -@all +get +set -@admin -@dangerous -function -script\n`,
    ]
  ) assertEquals(validValkeyAcl(acl), true);
  for (
    const acl of [
      `user default on ${hash} ~* &* +@all`,
      `user app on ${hash} ~* &* +@all\n`,
      `user default on ${hash} ~* &* +@all\nuser default on ${hash} ~a resetchannels -@all -@admin -@dangerous -function -script\n`,
      `user default on ${hash} ~* &* +@all\nuser app on ${hash} ~a resetchannels -@all +get -@admin -@dangerous -function\n`,
      `user default on ${hash} ~* &* +@all\nuser app on ~a resetchannels -@all -@admin -@dangerous -function -script\n`,
      `user default on ${hash} ~* &* +@all\nuser App on ${hash} ~a resetchannels -@all -@admin -@dangerous -function -script\n`,
    ]
  ) assertEquals(validValkeyAcl(acl), false);
});
Deno.test("ACL bytes go to stdin of the fixed network-isolated volume writer, never argv", async () => {
  const acl = `user default on ${hash} ~* &* +@all\n`;
  assertEquals(
    await writeValkeyAcl(
      { volume: "orbit-data", image: "valkey/valkey:8.1", acl },
      undefined,
      (args, stdin) => {
        assertEquals(stdin, acl);
        assertEquals(args.includes(acl), false);
        assertEquals(args.slice(0, 8), [
          "run",
          "--rm",
          "-i",
          "--network",
          "none",
          "--mount",
          "type=volume,source=orbit-data,target=/data",
          "--entrypoint",
        ]);
        assertEquals(
          args.at(-1),
          "umask 077 && cat > /data/users.acl.orbit-new && mv -f /data/users.acl.orbit-new /data/users.acl",
        );
        return Promise.resolve(true);
      },
    ),
    {},
  );
});
Deno.test("unsafe ACL, volume or image refuses before Docker; Docker diagnostics never escape", async () => {
  let calls = 0;
  const invoke = () => {
    calls++;
    return Promise.resolve(true);
  };
  const acl = "user default on nopass ~* &* +@all\n";
  for (
    const raw of [{ volume: "-x", image: "valkey/valkey:8", acl }, {
      volume: "orbit-data",
      image: "redis:7",
      acl,
    }, { volume: "orbit-data", image: "valkey/valkey:8", acl: "unsafe" }]
  ) {
    assertEquals(await writeValkeyAcl(raw, undefined, invoke), {
      error_code: "database.valkey_acl_invalid",
    });
  }
  assertEquals(calls, 0);
  assertEquals(
    await writeValkeyAcl(
      { volume: "orbit-data", image: "valkey/valkey:8", acl },
      undefined,
      () => {
        throw new Error("private-acl-diagnostic");
      },
    ),
    { error_code: "database.valkey_acl_failed" },
  );
});
