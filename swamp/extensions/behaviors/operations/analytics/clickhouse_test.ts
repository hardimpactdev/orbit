import { assertEquals } from "jsr:@std/assert@1.0.19";
import { configureClickhouse } from "./clickhouse.ts";
import { clickhouseFiles } from "./clickhouse_files.ts";
import type { SystemCommand } from "../processes-schedules/system.ts";

async function fixture(
  test: (f: {
    command: SystemCommand;
    path: (name: string) => string;
  }) => Promise<void>,
) {
  const root = await Deno.makeTempDir({ prefix: "analytics-clickhouse-" });
  const execute: SystemCommand = async (program, args, stdin) => {
    const child = new Deno.Command(program, {
      args: args.map((arg) =>
        arg === "/etc" || arg.startsWith("/etc/") ? `${root}${arg}` : arg
      ),
      stdin: stdin === undefined ? "null" : "piped",
      stdout: "piped",
      stderr: "null",
    }).spawn();
    if (stdin !== undefined) {
      const writer = child.stdin.getWriter();
      await writer.write(new TextEncoder().encode(stdin));
      await writer.close();
    }
    const result = await child.output();
    return {
      code: result.code,
      stdout: args.includes("/usr/bin/mktemp")
        ? new TextDecoder().decode(result.stdout).replace(root, "")
        : new TextDecoder().decode(result.stdout),
    };
  };
  const path = (name: string) =>
    `${root}/etc/orbit/analytics/clickhouse/${name}`;
  try {
    await test({ command: execute, path });
  } finally {
    await execute("/usr/bin/sudo", [
      "-n",
      "--",
      "/usr/bin/rm",
      "-rf",
      "--",
      root,
    ]);
  }
}

Deno.test("native ClickHouse publication preserves upstream bytes and repeats without rewriting files", () =>
  fixture(async ({ command, path }) => {
    assertEquals(await configureClickhouse(command), { changed: true });
    const hashes = [
      "8fba0c4d96c8cef933b33ec80017e3024f19e1534a4cc5a2f91a69a81ae8fc12",
      "8f1e01eee64c62fbe6565d3f0a2d2cd1c2755b2f18ebc64f44af6bf47a4a1c56",
      "7c4e4105a0d76661a97bdcbe768d199b96706a88812ef5e37bb95ed8626c435c",
      "5d4b3297f73a757ee273bf9ea45cefd039baf2b3f29e6bf6bd7520002f9286d4",
    ];
    const inodes = [];
    for (const [index, name] of Object.keys(clickhouseFiles).entries()) {
      const bytes = await Deno.readFile(path(name));
      const hash = Array.from(
        new Uint8Array(await crypto.subtle.digest("SHA-256", bytes)),
        (b) => b.toString(16).padStart(2, "0"),
      ).join("");
      assertEquals(hash, hashes[index]);
      const stat = await Deno.stat(path(name));
      assertEquals(stat.uid, 0);
      assertEquals(stat.mode! & 0o7777, 0o644);
      inodes.push(stat.ino);
    }
    assertEquals(await configureClickhouse(command), { changed: false });
    assertEquals(
      await Promise.all(
        Object.keys(clickhouseFiles).map(async (name) =>
          (await Deno.stat(path(name))).ino
        ),
      ),
      inodes,
    );
  }));

Deno.test("unsafe later ClickHouse paths refuse before earlier content changes", () =>
  fixture(async ({ command, path }) => {
    assertEquals(await configureClickhouse(command), { changed: true });
    await command("/usr/bin/sudo", [
      "-n",
      "--",
      "/usr/bin/tee",
      "--",
      path("config.d/ipv4-only.xml"),
    ], "keep-prior");
    await command("/usr/bin/sudo", [
      "-n",
      "--",
      "/usr/bin/chmod",
      "0666",
      path("users.d/default-profile-low-resources-overrides.xml"),
    ]);
    assertEquals(await configureClickhouse(command), {
      error_code: "analytics.clickhouse_config_unsafe",
    });
    assertEquals(
      await Deno.readTextFile(path("config.d/ipv4-only.xml")),
      "keep-prior",
    );
  }));

Deno.test("ClickHouse partial publication restores old content and absence before returning failure", () =>
  fixture(async ({ command, path }) => {
    assertEquals(await configureClickhouse(command), { changed: true });
    for (const name of Object.keys(clickhouseFiles)) {
      await command("/usr/bin/sudo", [
        "-n",
        "--",
        "/usr/bin/tee",
        "--",
        path(name),
      ], "prior");
    }
    let publications = 0;
    const fail: SystemCommand = (program, args, stdin, signal, timeout) => {
      if (args[2] === "/usr/bin/mv" && ++publications === 3) {
        return Promise.resolve({ code: 1, stdout: "" });
      }
      return command(program, args, stdin, signal, timeout);
    };
    assertEquals(await configureClickhouse(fail), {
      error_code: "analytics.clickhouse_config_failed",
    });
    for (const name of Object.keys(clickhouseFiles)) {
      assertEquals(await Deno.readTextFile(path(name)), "prior");
    }
  }));

Deno.test("ClickHouse recovery failure is explicit", () =>
  fixture(async ({ command }) => {
    let publications = 0;
    const fail: SystemCommand = (program, args, stdin, signal, timeout) => {
      if (args[2] === "/usr/bin/mv" && ++publications >= 3) {
        return Promise.resolve({ code: 1, stdout: "" });
      }
      if (
        publications >= 3 && args[2] === "/usr/bin/rm" &&
        !args.at(-1)!.includes(".xml.")
      ) return Promise.resolve({ code: 1, stdout: "" });
      return command(program, args, stdin, signal, timeout);
    };
    assertEquals(await configureClickhouse(fail), {
      error_code: "analytics.clickhouse_recovery_failed",
    });
  }));
