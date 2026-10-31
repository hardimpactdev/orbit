import { assertEquals } from "jsr:@std/assert@1.0.19";
import { phpPackagesDirect, preparePhpPackages } from "./packages.ts";
import { PhpBuildInput, PhpLocalError } from "../php/build_local.ts";
const input = () =>
  PhpBuildInput.parse({
    versions: {
      "8.5": {
        packages: [{
          name: "php8.5-common",
          version: "8.5.12-1",
          architecture: "amd64",
          url: "https://example.com/php.deb",
          sha256: "a".repeat(64),
        }],
        extensions: ["json"],
      },
    },
    pcov: false,
    opentelemetry: false,
    extras: "keep",
    fpm: "none",
    repack: { package: "dpkg-repack", version: "1.72" },
    release: { id: "ubuntu", codenames: ["resolute"] },
  });
const data = () => ({
  changed: [],
  repaired: [],
  purged: [],
  disabled: [],
  restarted: [],
  reloaded: [],
  deferred: [],
  stale_masters: [],
  fpm_disabled: false,
});
Deno.test("package-only action passes immutable pins to the one native PHP installer", async () => {
  let calls = 0;
  const result = await preparePhpPackages(input(), undefined, (raw) => {
    calls++;
    assertEquals(raw, input());
    return Promise.resolve({
      outcome: "current",
      data: { ...data(), stale_masters: ["php8.5-fpm@instance-a.service"] },
    });
  });
  assertEquals(calls, 1);
  assertEquals(result, {
    outcome: "current",
    stale_masters: ["php8.5-fpm@instance-a.service"],
    deferred: [],
  });
});
Deno.test("package-only action refuses removal or FPM policy widening before effects", async () => {
  for (
    const raw of [{ ...input(), extras: "purge" }, { ...input(), fpm: "all" }, {
      ...input(),
      versions: {},
    }]
  ) {
    let calls = 0;
    const result = await preparePhpPackages(raw, undefined, () => {
      calls++;
      return Promise.resolve({ outcome: "applied", data: data() });
    });
    assertEquals(calls, 0);
    assertEquals(result, { error_code: "app-prod.php_install_failed" });
  }
});
Deno.test("source and install failures remain bounded and preserve their meaning", async () => {
  for (
    const [code, wanted] of [[
      "php.build_download_failed",
      "app-prod.php_package_source_unavailable",
    ], [
      "php.build_conflicting_source",
      "app-prod.php_package_source_unavailable",
    ], ["php.build_install_failed", "app-prod.php_install_failed"]] as const
  ) {
    assertEquals(
      await preparePhpPackages(
        input(),
        undefined,
        () => Promise.reject(new PhpLocalError(code)),
      ),
      { error_code: wanted },
    );
  }
  assertEquals(
    await preparePhpPackages(
      input(),
      undefined,
      () => Promise.reject(new Error("private raw installer detail")),
    ),
    { error_code: "app-prod.php_install_failed" },
  );
});
Deno.test("package root binds the native intent and skips cancelled work", async () => {
  const args = {
    operation_id: 5,
    kind: "node.prepare_php_packages" as const,
    node_id: 209,
    run_id: "0d363abd-ec96-4979-8866-f1c0233c576c",
  };
  const operation = {
    id: 5,
    kind: args.kind,
    node_id: 209,
    status: "queued",
    result: null,
    cancel_requested: false,
    input: { packages: input() },
  };
  const posts: unknown[] = [];
  let effects = 0;
  const http = (method: string, _path: string, body?: unknown) => {
    if (method === "POST") posts.push(body);
    return Promise.resolve({ status: 200, body: { data: operation } });
  };
  const behavior = phpPackagesDirect(() => http, (raw) => {
    effects++;
    assertEquals(raw, input());
    return Promise.resolve({
      outcome: "applied",
      stale_masters: [],
      deferred: [],
    });
  });
  const ctx = {
    globalArgs: { gatewayUrl: "https://gateway.orbit", caFile: "/ca" },
  };
  assertEquals(await behavior.methods.apply.execute(args, ctx), {
    status: "succeeded",
  });
  assertEquals(effects, 1);
  operation.cancel_requested = true;
  assertEquals(await behavior.methods.apply.execute(args, ctx), {
    status: "cancelled",
  });
  assertEquals(effects, 1);
  assertEquals((posts.at(-1) as { status: string }).status, "cancelled");
});
