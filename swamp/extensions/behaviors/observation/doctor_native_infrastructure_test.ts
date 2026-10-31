import { assert, assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import {
  readInfrastructure,
  usableUplink,
} from "./doctor_native_infrastructure.ts";

type File = { text: string; kind?: string; mode?: string; owner?: number };
function fixture() {
  const files = new Map<string, File>();
  const replies = new Map<string, string>();
  const calls: string[][] = [];
  const local = (argv: string[]): Promise<string> => {
    calls.push(argv);
    if (argv.includes("stat")) {
      const path = argv.at(-1)!;
      const file = files.get(path);
      if (!file) return Promise.reject(new Error("absent"));
      return Promise.resolve(
        `${file.owner ?? 0}\t${file.kind ?? "regular file"}\t${
          file.mode ?? "644"
        }\t${new TextEncoder().encode(file.text).length}`,
      );
    }
    if (argv.includes("head")) {
      const file = files.get(argv.at(-1)!);
      return file
        ? Promise.resolve(file.text)
        : Promise.reject(new Error("absent"));
    }
    const value = replies.get(JSON.stringify(argv));
    return value === undefined
      ? Promise.reject(new Error("unavailable"))
      : Promise.resolve(value);
  };
  const reply = (argv: string[], value: string) => {
    replies.set(JSON.stringify(argv), value);
  };
  const directory = (path: string) => {
    files.set(path, { text: "", kind: "directory", mode: "755" });
  };
  const service = (name: string, state = "active") => {
    const argv = ["systemctl", "is-active", "--quiet", name];
    if (state === "active") reply(argv, "");
    else replies.delete(JSON.stringify(argv));
  };
  const packageName = (name: string, installed = true) =>
    reply(
      ["dpkg-query", "-W", "-f=${Status}", "--", name],
      installed ? "install ok installed\n" : "unknown ok not-installed\n",
    );
  return {
    files,
    calls,
    local,
    reply,
    directory,
    service,
    packageName,
  };
}
const pool = (id: number, path: string) =>
  `[orbit-app-instance-${id}]\nchdir = ${path}\n`;

Deno.test("native roles report independent packages services and first Caddy version token", async () => {
  const f = fixture();
  f.packageName("caddy");
  f.packageName("git", false);
  f.service("caddy");
  f.service("docker", "inactive");
  f.reply(["caddy", "version"], "v2.11.4 h1:digest\nsecond-line");
  assertEquals(
    await readInfrastructure(
      "doctor.inspect-role-runtime",
      {
        packages: ["caddy", "git"],
        services: ["caddy", "docker"],
      },
      f.local,
    ),
    { packages: false, services: false, caddy_version: "v2.11.4" },
  );
  assert(
    !f.calls.flat().some((arg) => arg.includes("orbit-agent")),
  );
});

Deno.test("Docker CE satisfies docker.io only with every package executable binary and active service", async () => {
  for (
    const missing of [
      "",
      "docker-ce",
      "docker-ce-cli",
      "containerd.io",
      "binary",
      "docker",
    ]
  ) {
    const f = fixture();
    for (const name of ["docker-ce", "docker-ce-cli", "containerd.io"]) {
      f.packageName(name, missing !== name);
    }
    f.files.set("/usr/bin/docker", {
      text: "binary",
      mode: missing === "binary" ? "644" : "755",
    });
    f.service("docker", missing === "docker" ? "inactive" : "active");
    const result = await readInfrastructure(
      "doctor.inspect-role-runtime",
      {
        packages: ["docker.io"],
        services: [],
      },
      f.local,
    );
    assertEquals(result?.packages, missing === "");
  }
});

Deno.test("role names are bounded before command execution and missing Caddy has no version", async () => {
  const f = fixture();
  for (const bad of ["-option", "bad;command", "", "bad\nname"]) {
    await assertRejects(
      () =>
        readInfrastructure(
          "doctor.inspect-role-runtime",
          {
            packages: [bad],
            services: [],
          },
          f.local,
        ),
      Error,
      "doctor.role_input_invalid",
    );
  }
  assertEquals(f.calls, []);
  assertEquals(
    await readInfrastructure(
      "doctor.inspect-role-runtime",
      {
        packages: ["caddy"],
        services: [],
      },
      f.local,
    ),
    { packages: false, services: true, caddy_version: null },
  );
});

Deno.test("Caddy native reader follows its live path and keeps a missing file empty", async () => {
  const f = fixture();
  assertEquals(
    await readInfrastructure("caddy.read-live", {}, f.local),
    {
      content: "",
    },
  );
  f.files.set("/etc/caddy/Caddyfile", { text: "managed caddy\n" });
  assertEquals(
    await readInfrastructure("caddy.read-live", {}, f.local),
    {
      content: "managed caddy\n",
    },
  );
  assert(f.calls.some((argv) => argv.includes("stat") && argv.includes("-L")));
  f.files.set("/etc/caddy/Caddyfile", { text: "x".repeat(4194305) });
  await assertRejects(
    () => readInfrastructure("caddy.read-live", {}, f.local),
    Error,
    "doctor.file_read_bound",
  );
});

Deno.test("shared PHP reports installed missing pools once and desired missing pools without writes", async () => {
  const f = fixture();
  for (
    const path of [
      "/etc",
      "/etc/php",
      "/etc/php/8.5",
      "/etc/php/8.5/fpm",
      "/etc/php/8.5/fpm/pool.d",
      "/apps/present",
    ]
  ) f.directory(path);
  f.files.set("/etc/php/8.5/fpm/pool.d/orbit-scopes.conf", {
    text: pool(1, "/apps/missing") + pool(2, "/apps/present"),
  });
  f.reply([
    "sudo",
    "-n",
    "find",
    "/etc/php",
    "-mindepth",
    "1",
    "-maxdepth",
    "1",
    "-printf",
    "%f\\n",
  ], "8.5\n8.4\nREADME\n");
  f.directory("/etc/php/8.4");
  const result = await readInfrastructure(
    "php.inspect-shared-pools",
    {
      configurations: {
        "8.5": pool(1, "/apps/missing") + pool(3, "/apps/gone") +
          pool(2, "/apps/present"),
      },
    },
    f.local,
  );
  assertEquals(result, {
    versions: ["8.5"],
    missing_pools: [{
      pool: "orbit-app-instance-1",
      version: "8.5",
      directory: "/apps/missing",
      installed: true,
    }, {
      pool: "orbit-app-instance-3",
      version: "8.5",
      directory: "/apps/gone",
      installed: false,
    }],
  });
  assert(
    !f.calls.flat().some((arg) =>
      ["install", "tee", "mv", "rm", "restart"].includes(arg)
    ),
  );
});

Deno.test("PHP pool inspection refuses foreign owners symlinks invalid sections and duplicate desired pools", async () => {
  const f = fixture();
  f.directory("/etc");
  f.files.set("/etc/php", { text: "", kind: "symbolic link", mode: "777" });
  await assertRejects(
    () =>
      readInfrastructure(
        "php.inspect-shared-pools",
        { configurations: {} },
        f.local,
      ),
    Error,
    "php.shared_pool_unsafe",
  );
  f.files.set("/etc/php", { text: "", kind: "directory", owner: 1000 });
  await assertRejects(
    () =>
      readInfrastructure(
        "php.inspect-shared-pools",
        { configurations: {} },
        f.local,
      ),
    Error,
    "php.shared_pool_unsafe",
  );
  for (
    const configurations of [
      { "8.5": "[foreign]\nchdir=/apps\n" },
      { "8.5": "[orbit-instance-1]\n" },
      { "8.5": pool(1, "relative") },
      { "8.5": pool(1, "/apps") + "chdir=/else\n" },
      { "8.5": pool(1, "/apps"), "8.4": pool(1, "/apps") },
      { "invalid": pool(1, "/apps") },
    ]
  ) {
    await assertRejects(
      () =>
        readInfrastructure(
          "php.inspect-shared-pools",
          { configurations },
          f.local,
        ),
      Error,
      "php.shared_pool_invalid",
    );
  }
});

Deno.test("hub inspection emits only activity and hashes never the WireGuard key", async () => {
  const f = fixture();
  f.service("wg-quick@orbit");
  f.files.set("/etc/wireguard/orbit.conf", { text: "private-key-sentinel" });
  f.reply(
    ["sudo", "-n", "sha256sum", "--", "/etc/wireguard/orbit.conf"],
    "a".repeat(64) + "  /etc/wireguard/orbit.conf\n",
  );
  const result = await readInfrastructure(
    "wireguard.inspect-hub",
    {},
    f.local,
  );
  assertEquals(result, {
    active: true,
    config_sha256: "a".repeat(64),
    dns_records_sha256: null,
  });
  assert(!JSON.stringify(result).includes("private-key-sentinel"));
  assert(
    !f.calls.some((argv) =>
      argv.includes("head") && argv.includes("/etc/wireguard/orbit.conf")
    ),
  );
  f.service("wg-quick@orbit", "inactive");
  f.files.set("/etc/dnsmasq.d/orbit-records.conf", { text: "records" });
  f.reply([
    "sudo",
    "-n",
    "sha256sum",
    "--",
    "/etc/dnsmasq.d/orbit-records.conf",
  ], "b".repeat(64) + "  /etc/dnsmasq.d/orbit-records.conf\n");
  assertEquals(
    (await readInfrastructure("wireguard.inspect-hub", {}, f.local))
      ?.active,
    false,
  );
});

Deno.test("peer DNS uses one shared lock and exposes only public DNS fields", async () => {
  const calls: string[][] = [];
  const values = [
    "orbit\n10.43.0.53\norbit",
    "PostUp = resolvectl dns %i 10.43.0.53; resolvectl domain %i \\~orbit",
    "Link 7 (orbit): 10.43.0.53",
    "Link 7 (orbit): ~orbit",
    "Link 7 (orbit): no",
    "Link 7 (orbit): 10.43.0.53\nLink 2 (eth0): 1.1.1.1",
  ];
  const result = await readInfrastructure("wireguard.inspect-peer-dns", {
    server: "10.43.0.53",
  }, (argv) => {
    calls.push(argv);
    return Promise.resolve(
      values.map((value) => btoa(value)).join("\n") + "\n",
    );
  });
  assertEquals(result, {
    state: values[0],
    hooks: values[1],
    dns: values[2],
    domains: values[3],
    default_route: values[4],
    uplink: true,
  });
  assertEquals(calls.length, 1);
  assert(calls[0].includes("flock") && calls[0].includes("--shared"));
  assert(calls[0].at(-1)!.includes("sed -n '/^PostUp = resolvectl/p'"));
  assert(!calls[0].at(-1)!.includes("cat /etc/wireguard/orbit.conf"));
});

Deno.test("peer DNS rejects malformed or oversized snapshots and preserves lock failures", async () => {
  await assertRejects(
    () =>
      readInfrastructure(
        "wireguard.inspect-peer-dns",
        { server: "10.43.0.53" },
        () => Promise.resolve("invalid\n"),
      ),
    Error,
    "wireguard.peer_dns_unreadable",
  );
  await assertRejects(
    () =>
      readInfrastructure(
        "wireguard.inspect-peer-dns",
        { server: "10.43.0.53" },
        () => Promise.reject(new Error("busy")),
      ),
    Error,
    "busy",
  );
});

Deno.test("uplink discovery counts only usable DNS outside orbit", () => {
  for (
    const [listing, usable] of [
      ["Link 7 (orbit): 1.1.1.1", false],
      ["Link 2 (eth0): 10.43.0.53", false],
      ["Link 2 (eth0): 10.0.0.2", false],
      ["Link 2 (eth0): 127.0.0.53 ::1 :: 0.0.0.0", false],
      ["Global: 1.1.1.1", true],
      ["Link 2 (eth0): 999.1.1.1", false],
      ["Link 2 (eth0): 2001:4860:4860::8888%eth0", true],
      ["Link 2 (eth0): 1.1.1.1#dns.example", true],
    ] as const
  ) assertEquals(usableUplink(listing, "10.43.0.53", "10.0.0.2"), usable);
});

Deno.test("uncached DNS lookup returns each answer or null and refuses invalid names before reads", async () => {
  const f = fixture();
  f.reply(
    ["resolvectl", "query", "--cache=no", "--type=A", "gateway.orbit"],
    "gateway.orbit: 10.43.0.1 -- link: orbit\n",
  );
  assertEquals(
    await readInfrastructure(
      "wireguard.query-dns",
      {
        names: ["gateway.orbit", "example.com"],
      },
      f.local,
    ),
    { answers: ["gateway.orbit: 10.43.0.1 -- link: orbit", null] },
  );
  const before = f.calls.length;
  for (
    const names of [
      [],
      ["bad name"],
      ["-bad;command"],
      Array(9).fill("valid.example"),
    ]
  ) {
    await assertRejects(
      () => readInfrastructure("wireguard.query-dns", { names }, f.local),
      Error,
      "wireguard.dns_name_invalid",
    );
  }
  assertEquals(f.calls.length, before);
});

Deno.test("unknown selectors are never executed", async () => {
  const f = fixture();
  assertEquals(
    await readInfrastructure("wireguard.project-hub", {}, f.local),
    undefined,
  );
  assertEquals(f.calls, []);
});

Deno.test("native file metadata and bounded reads use working stat and head formats", async () => {
  const path = await Deno.makeTempFile();
  try {
    await Deno.writeTextFile(path, "fixture Caddyfile\n");
    const result = await readInfrastructure(
      "caddy.read-live",
      {},
      async (argv) => {
        const native = argv.slice(2).map((arg) =>
          arg === "/etc/caddy/Caddyfile" ? path : arg
        );
        const output = await new Deno.Command(native[0], {
          args: native.slice(1),
          stdin: "null",
          stdout: "piped",
          stderr: "null",
        }).output();
        if (!output.success) throw new Error("Native fixture read failed");
        return new TextDecoder().decode(output.stdout);
      },
    );
    assertEquals(result, { content: "fixture Caddyfile\n" });
  } finally {
    await Deno.remove(path);
  }
});

Deno.test("runtime service checks use only fixed native systemctl reads without agent or observer changes", async () => {
  const f = fixture();
  f.service("caddy", "inactive");
  assertEquals(
    await readInfrastructure("doctor.inspect-role-runtime", {
      packages: [],
      services: ["caddy"],
    }, f.local),
    { packages: true, services: false, caddy_version: null },
  );
  f.service("caddy", "active");
  assertEquals(
    await readInfrastructure("doctor.inspect-role-runtime", {
      packages: [],
      services: ["caddy"],
    }, f.local),
    { packages: true, services: true, caddy_version: null },
  );
  assertEquals(f.calls, [["systemctl", "is-active", "--quiet", "caddy"], [
    "systemctl",
    "is-active",
    "--quiet",
    "caddy",
  ]]);
});
