import { assert, assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import {
  type LocalStat,
  replaceCachedUrl,
  type RouteLocalHost,
  routeWorkloadLocal,
} from "./route-workload-local.ts";
function fixture() {
  const files = new Map<string, string>();
  const stats = new Map<string, LocalStat>();
  const paths = new Map<string, string>();
  const calls: { binary: string; args: string[]; input?: string }[] = [];
  const host: RouteLocalHost = {
    stat: (path) => Promise.resolve(stats.get(path) ?? null),
    read: (path) => Promise.resolve(files.get(path) ?? null),
    realPath: (path) =>
      Promise.resolve(paths.get(path) ?? (stats.has(path) ? path : null)),
    write: (path, value, user, mode) => {
      files.set(path, value);
      stats.set(path, {
        kind: "file",
        uid: user === "root" ? 0 : 1000,
        gid: user === "root" ? 0 : 1000,
        mode,
      });
      return Promise.resolve();
    },
    download: () => Promise.resolve("/tmp/pinned.deb"),
    run: (binary, args, options) => {
      calls.push({ binary, args, input: options?.input });
      if (binary.endsWith("/getent")) {
        return Promise.resolve({
          success: true,
          code: 0,
          stdout: args[0] === "group"
            ? "caddy:x:33:\n"
            : "orbit:x:1000:1000::/home/orbit:/bin/sh\n",
        });
      }
      return Promise.resolve({ success: true, code: 0, stdout: "" });
    },
  };
  function dir(path: string, uid = 1000) {
    stats.set(path, { kind: "directory", uid, gid: uid, mode: 0o755 });
  }
  return { host, files, stats, paths, calls, dir };
}
Deno.test("environment replacement writes the existing secret only to the owned file", async () => {
  const f = fixture();
  f.dir("/home/orbit");
  f.files.set("/home/orbit/.env", "OLD=1\n");
  f.stats.set("/home/orbit/.env", {
    kind: "file",
    uid: 1000,
    gid: 1000,
    mode: 0o600,
  });
  const result = await routeWorkloadLocal(f.host).writeEnvironment({
    mode: "write",
    base: "/home/orbit",
    user: "orbit",
    maximum: 4096,
    require_home: true,
    file: ".env",
    managed_keys: [],
    content: 'KEY="private"\n',
  });
  assertEquals(f.files.get("/home/orbit/.env"), 'KEY="private"\n');
  assertEquals(result, { ready: true });
  assert(!JSON.stringify(f.calls).includes("private"));
});
Deno.test("environment writes refuse links and foreign owners before replacing bytes", async () => {
  for (
    const state of [{ kind: "link" as const, uid: 1000 }, {
      kind: "file" as const,
      uid: 2000,
    }]
  ) {
    const f = fixture();
    f.dir("/home/orbit");
    f.stats.set("/home/orbit/.env", { ...state, gid: 1000, mode: 0o600 });
    await assertRejects(() =>
      routeWorkloadLocal(f.host).writeEnvironment({
        mode: "write",
        base: "/home/orbit",
        user: "orbit",
        maximum: 4096,
        require_home: true,
        file: ".env",
        managed_keys: [],
        content: "SECRET=x\n",
      })
    );
    assertEquals(f.files.size, 0);
  }
});
Deno.test("testing environment merge retains unmanaged keys", async () => {
  const f = fixture();
  f.dir("/home/orbit");
  f.files.set("/home/orbit/.env.testing", "KEEP=operator\nDB_DATABASE=old\n");
  f.stats.set("/home/orbit/.env.testing", {
    kind: "file",
    uid: 1000,
    gid: 1000,
    mode: 0o600,
  });
  await routeWorkloadLocal(f.host).writeEnvironment({
    mode: "merge",
    base: "/home/orbit",
    user: "orbit",
    maximum: 4096,
    require_home: true,
    file: ".env.testing",
    managed_keys: ["DB_DATABASE"],
    content: "DB_DATABASE=new\n",
  });
  assertEquals(
    f.files.get("/home/orbit/.env.testing"),
    "KEEP=operator\nDB_DATABASE=new\n",
  );
});
Deno.test("literal cache update changes only app.url and does not evaluate PHP", () => {
  const source =
    "<?php return array ('app' => array ('url' => 'https://old.test',), 'services' => array ('url' => 'https://other.test',),);";
  assertEquals(
    replaceCachedUrl(source, "https://new.test"),
    source.replace("https://old.test", "https://new.test"),
  );
});
Deno.test("duplicate cached app.url fails before any URL file write", async () => {
  const f = fixture();
  f.dir("/srv/site");
  f.dir("/srv/site/bootstrap");
  f.dir("/srv/site/bootstrap/cache");
  f.files.set("/srv/site/.env", "APP_URL=https://old.test\n");
  f.stats.set("/srv/site/.env", {
    kind: "file",
    uid: 1000,
    gid: 1000,
    mode: 0o600,
  });
  f.files.set(
    "/srv/site/bootstrap/cache/config.php",
    "<?php return ['app'=>['url'=>'a','url'=>'b']];",
  );
  f.stats.set("/srv/site/bootstrap/cache/config.php", {
    kind: "file",
    uid: 1000,
    gid: 1000,
    mode: 0o600,
  });
  await assertRejects(() =>
    routeWorkloadLocal(f.host).writeDevelopmentUrl({
      directory: "/srv/site",
      owner: "orbit",
      url: "https://new.test",
      app_key: "APP_KEY=private",
      app_key_stored: false,
      app_name: "APP_NAME=Project",
      app_name_stored: false,
      require_artisan: false,
    })
  );
  assertEquals(f.files.get("/srv/site/.env"), "APP_URL=https://old.test\n");
});
Deno.test("stable directory URL stays under the selected production home", async () => {
  const f = fixture();
  f.dir("/home/orbit");
  f.dir("/home/orbit/releases/release-1");
  f.dir("/home/orbit/releases/release-1/docs");
  f.stats.set("/home/orbit/current", {
    kind: "link",
    uid: 1000,
    gid: 1000,
    mode: 0o777,
  });
  f.paths.set("/home/orbit/current", "/home/orbit/releases/release-1");
  f.stats.set("/home/orbit/releases/release-1/docs/artisan", {
    kind: "file",
    uid: 1000,
    gid: 1000,
    mode: 0o644,
  });
  f.host.run = (binary, args, options) =>
    new Promise((resolve) => {
      f.calls.push({ binary, args, input: options?.input });
      if (binary.endsWith("/getent")) {
        return resolve({
          success: true,
          code: 0,
          stdout: "orbit:x:1000:1000::/home/orbit:/bin/sh\n",
        });
      }
      if (binary.endsWith("/install")) f.dir(args.at(-1)!);
      return resolve({ success: true, code: 0, stdout: "" });
    });
  await routeWorkloadLocal(f.host).writeDirectoryUrl({
    user: "orbit",
    home: "/home/orbit",
    directory: "docs",
    url: "https://docs.test",
    app_key: "APP_KEY=private",
    app_name: "APP_NAME=Docs",
    app_name_stored: false,
  });
  assert(
    f.files.get("/home/orbit/env/docs/.env")?.includes(
      "APP_URL=https://docs.test",
    ),
  );
  await assertRejects(() =>
    routeWorkloadLocal(f.host).writeDirectoryUrl({
      user: "orbit",
      home: "/home/other",
      directory: "docs",
      url: "https://docs.test",
      app_key: "APP_KEY=private",
      app_name: "APP_NAME=Docs",
      app_name_stored: false,
    })
  );
});
Deno.test("package digest and Debian identity are checked before dpkg mutates", async () => {
  const f = fixture();
  f.files.set("/etc/os-release", "ID=ubuntu\nVERSION_CODENAME=noble\n");
  f.host.run = (binary, args) =>
    new Promise((resolve) => {
      f.calls.push({ binary, args });
      return resolve({
        success: true,
        code: 0,
        stdout: binary.endsWith("/dpkg")
          ? "amd64\n"
          : binary.endsWith("/dpkg-deb")
          ? "Package: foreign\nVersion: 1\nArchitecture: amd64\n"
          : "",
      });
    });
  await assertRejects(() =>
    routeWorkloadLocal(f.host).installPackages({
      versions: {
        "8.5": {
          packages: [{
            name: "php8.5-cli",
            version: "1",
            architecture: "amd64",
            url: "https://packages.test/pinned.deb",
            sha256: "a".repeat(64),
          }],
          extensions: ["Core"],
        },
      },
      extras: "keep",
      fpm: "none",
      pcov: false,
      opentelemetry: false,
      release: { id: "ubuntu", codenames: ["noble"] },
    })
  );
  assertEquals(
    f.calls.filter((call) =>
      call.binary.endsWith("/dpkg") && call.args.includes("--install")
    ).length,
    0,
  );
});
Deno.test("foreign runtime identity refuses before changing a generated file", async () => {
  const f = fixture();
  f.dir("/home/orbit");
  f.dir("/etc/orbit/php-fpm/orbit", 0);
  f.files.set("/etc/orbit/php-fpm/orbit/orbit.identity", "foreign");
  await assertRejects(() =>
    routeWorkloadLocal(f.host).convergeProductionRuntime({
      mode: "converge",
      user: "orbit",
      home: "/home/orbit",
      version: "8.5",
      marker: "owned",
      application: "/home/orbit/current",
      initial_application: "/home/orbit/releases/initial",
      configuration: {
        main: "",
        pool: "",
        local_defaults: "",
        master_ini: "",
        unit: "",
        initial_pool: "",
      },
    })
  );
  assertEquals(f.files.size, 1);
});
Deno.test("source-access failure restores ACL snapshots through stdin without an agent", async () => {
  const f = fixture();
  f.dir("/srv/site");
  f.dir("/srv/site/public");
  f.dir("/srv", 0);
  let failed = false;
  f.host.run = (binary, args, options) =>
    new Promise((resolve) => {
      f.calls.push({ binary, args, input: options?.input });
      if (binary.endsWith("/getent")) {
        return resolve({
          success: true,
          code: 0,
          stdout: "orbit:x:1000:1000::/home/orbit:/bin/sh\n",
        });
      }
      if (binary.endsWith("/getfacl")) {
        return resolve({ success: true, code: 0, stdout: "# saved ACL\n" });
      }
      if (
        binary.endsWith("/setfacl") && args.includes("u:caddy:---") && !failed
      ) {
        failed = true;
        return resolve({ success: false, code: 1, stdout: "" });
      }
      return resolve({ success: true, code: 0, stdout: "" });
    });
  await assertRejects(() =>
    routeWorkloadLocal(f.host).grantSourceAccess({
      user: "orbit",
      sites: [{
        checkout: "/srv/site",
        relative_root: "public",
        application: "/srv/site",
      }],
    })
  );
  assertEquals(
    f.calls.find((call) => call.args.includes("--restore=-"))?.input,
    "# saved ACL\n",
  );
  assert(f.calls.every((call) => !call.binary.includes("orbit-agent")));
});
