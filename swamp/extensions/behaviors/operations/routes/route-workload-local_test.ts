import { assert, assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import {
  localRouteHost,
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

Deno.test("workload default runner keeps private stdin and users on each logical proxy", async () => {
  const { commandLaunch } = await import("../_lib/node-commands.ts");
  const { proxyAddress } = await import("../_lib/node-proxy-config.ts");
  for (const node of [1, 18] as const) {
    const command: import("../_lib/node-commands.ts").NodeCommand = (
      argv,
      stdin,
      user,
      options,
    ) => {
      assertEquals(argv, ["/usr/bin/php", "-r", "fixed code"]);
      assertEquals(user, "orbit");
      assertEquals(new TextDecoder().decode(stdin), "private-stdin");
      assertEquals(options, { deadlineMs: 45000, maxOutputBytes: 16777216 });
      const launch = commandLaunch(argv, user, {
        node_id: node,
        host: proxyAddress(node),
        user: "orbit",
      }, options?.deadlineMs);
      assert(launch.args.includes("orbit@" + proxyAddress(node)));
      assert(!JSON.stringify(launch).includes("private-stdin"));
      return Promise.resolve({
        success: true,
        code: 0,
        stdout: new TextEncoder().encode("ready"),
      });
    };
    assertEquals(
      await localRouteHost(undefined, command).run("/usr/bin/php", [
        "-r",
        "fixed code",
      ], {
        user: "orbit",
        input: "private-stdin",
        timeoutMs: 45000,
      }),
      { success: true, code: 0, stdout: "ready" },
    );
  }
});

Deno.test("workload helpers and downloads use the injected placed runner", async () => {
  const calls: { binary: string; args: string[]; input?: string }[] = [];
  const host = localRouteHost((binary, args, options) => {
    calls.push({ binary, args, input: options?.input });
    let stdout = "";
    if (binary === "/usr/bin/stat") stdout = "regular file:1000:1000:600";
    if (binary === "/usr/bin/cat") stdout = "old private file";
    if (binary === "/usr/bin/readlink") stdout = "/home/orbit/.env\n";
    if (binary === "/usr/bin/python3") {
      assertEquals(JSON.parse(options!.input!), {
        url: "https://example.test/pkg.deb",
        sha256: "a".repeat(64),
      });
      assertEquals(options?.timeoutMs, 120000);
      stdout = "/tmp/orbit-route-php-pinned.deb\n";
    }
    return Promise.resolve({ success: true, code: 0, stdout });
  });
  assertEquals((await host.stat("/home/orbit/.env"))?.uid, 1000);
  assertEquals(await host.read("/home/orbit/.env"), "old private file");
  assertEquals(await host.realPath("/home/orbit/.env"), "/home/orbit/.env");
  await host.write("/home/orbit/.env", "private-new", "orbit", 0o600);
  const install = calls.find((c) => c.binary === "/usr/bin/install")!;
  assertEquals(install.input, "private-new");
  assert(install.args.includes("/dev/stdin"));
  assert(!JSON.stringify(calls.map((c) => c.args)).includes("private-new"));
  assertEquals(
    await host.download("https://example.test/pkg.deb", "a".repeat(64)),
    "/tmp/orbit-route-php-pinned.deb",
  );
});

Deno.test("workload transport bounds failures and hides private output", async () => {
  for (
    const command of [
      (() => {
        throw new Error("private-transport-detail");
      }) as import("../_lib/node-commands.ts").NodeCommand,
      (() =>
        Promise.resolve({
          success: true,
          code: 0,
          stdout: new Uint8Array(16777217),
        })) as import("../_lib/node-commands.ts").NodeCommand,
    ]
  ) {
    const error = await assertRejects(
      () => localRouteHost(undefined, command).run("/usr/bin/true", []),
      Error,
      "route.local_command_failed",
    );
    assert(!error.message.includes("private-transport-detail"));
  }
  for (
    const stdout of [
      "private-output",
      "/tmp/foreign.deb",
      "/tmp/orbit-route-php-good.deb\nextra",
    ]
  ) {
    await assertRejects(
      () =>
        localRouteHost(() =>
          Promise.resolve({ success: true, code: 0, stdout })
        ).download("https://example.test/pkg.deb", "a".repeat(64)),
      Error,
      "php.build_download_failed",
    );
  }
});

Deno.test("placed download program verifies bytes and cleans checksum failures", async () => {
  let program = "";
  await localRouteHost((_binary, args) => {
    program = args[2];
    return Promise.resolve({
      success: true,
      code: 0,
      stdout: "/tmp/orbit-route-php-fixture.deb",
    });
  }).download("https://example.test/pkg.deb", "a".repeat(64));
  const fixture = String
    .raw`import hashlib, io, json, os, pathlib, subprocess, sys, tempfile, urllib.request
program = json.load(sys.stdin)
with tempfile.TemporaryDirectory() as directory:
    prefix = """import io, tempfile, urllib.request
original = tempfile.mkstemp
tempfile.mkstemp = lambda **kw: original(**dict(kw, dir=DIRECTORY))
class Response(io.BytesIO):
    status = 200
    headers = {}
class Opener:
    def open(self, url, timeout):
        assert url == 'https://example.test/pkg.deb' and timeout == 120
        return Response(b'pinned bytes')
def opener(handler):
    try: handler.redirect_request(None, None, None, None, None, None)
    except ValueError: pass
    else: raise AssertionError('redirect allowed')
    return Opener()
urllib.request.build_opener = opener
""".replace('DIRECTORY', repr(directory))
    for digest, success in ((hashlib.sha256(b'pinned bytes').hexdigest(), True), ('a'*64, False)):
        result = subprocess.run([sys.executable, '-I', '-c', prefix + program], input=json.dumps({'url': 'https://example.test/pkg.deb', 'sha256': digest}), text=True, capture_output=True)
        assert (result.returncode == 0) == success and not result.stderr
        if success:
            path = pathlib.Path(result.stdout.strip())
            assert path.parent == pathlib.Path(directory) and path.read_bytes() == b'pinned bytes'
            assert path.stat().st_mode & 0o777 == 0o600
            path.unlink()
        else: assert not result.stdout
        assert not list(pathlib.Path(directory).iterdir())
`;
  const child = new Deno.Command("python3", {
    args: ["-I", "-c", fixture],
    stdin: "piped",
    stdout: "piped",
    stderr: "piped",
  }).spawn();
  const writer = child.stdin.getWriter();
  await writer.write(new TextEncoder().encode(JSON.stringify(program)));
  await writer.close();
  const result = await child.output();
  assertEquals(new TextDecoder().decode(result.stderr), "");
  assertEquals(result.code, 0);
});
