import { assert, assertEquals, assertRejects } from "jsr:@std/assert@1";
import { readApplications } from "./doctor_native_applications.ts";
import type { Row } from "./doctor_contract.ts";

async function command(argv: string[], cwd?: string): Promise<string> {
  const result = await new Deno.Command(argv[0], {
    args: argv.slice(1),
    cwd,
    stdout: "piped",
    stderr: "piped",
  }).output();
  if (!result.success) throw new Error(`${argv[0]} failed (${result.code})`);
  return new TextDecoder().decode(result.stdout).trimEnd();
}
async function write(path: string, content: string, mode = 0o644) {
  await Deno.mkdir(path.slice(0, path.lastIndexOf("/")), { recursive: true });
  await Deno.writeTextFile(path, content);
  await Deno.chmod(path, mode);
}
async function fixture() {
  const root = await Deno.makeTempDir({
    prefix: "doctor-native-applications-",
  });
  const user = await command(["/usr/bin/id", "-un"]);
  const group = await command(["/usr/bin/id", "-gn"]);
  const uid = Number(await command(["/usr/bin/id", "-u"]));
  const gid = Number(await command(["/usr/bin/id", "-g"]));
  const host: Row = {
    homes: `${root}/homes`,
    etc: `${root}/etc`,
    units: `${root}/units`,
    sockets: `${root}/sockets`,
    proc: `${root}/proc`,
    sbin: `${root}/sbin`,
    php_conf: `${root}/php`,
    systemctl: `${root}/bin/systemctl`,
    caddy: `${root}/Caddyfile`,
    roots: `${root}/roots`,
    ufw: `${root}/bin/ufw`,
    getent: `${root}/bin/getent`,
    root_uid: uid,
    root_gid: gid,
    caddy_gid: gid,
    tls_address: ["127.0.0.1", 443],
  };
  await write(
    String(host.ufw),
    "#!/usr/bin/python3\nprint('Status: active')\n",
    0o755,
  );
  await write(
    String(host.getent),
    "#!/usr/bin/python3\nprint('10.44.0.7 STREAM app.test')\n",
    0o755,
  );
  let calls = 0;
  const local = async (argv: string[]) => {
    calls++;
    assertEquals(argv.slice(0, 5), [
      "sudo",
      "-n",
      "/usr/bin/python3",
      "-I",
      "-c",
    ]);
    assert(!argv.join(" ").includes("orbit-agent"));
    // Inject system paths at the local process boundary, as Rust's Host test fixture did.
    const program = argv[5].replace(
      /^HOST = .*$/m,
      `HOST = json.loads(${JSON.stringify(JSON.stringify(host))})`,
    );
    const result = await new Deno.Command("/usr/bin/python3", {
      args: ["-I", "-c", program, ...argv.slice(6)],
      env: { PATH: `${root}/bin:/usr/bin:/bin` },
      stdout: "piped",
      stderr: "piped",
    }).output();
    assertEquals(result.success, true, new TextDecoder().decode(result.stderr));
    const text = new TextDecoder().decode(result.stdout);
    assert(!text.includes("fixture-private-value"));
    return text;
  };
  const read = async (name: string, input: Row) =>
    (await readApplications(name, input, local))!;
  return {
    root,
    user,
    group,
    uid,
    gid,
    host,
    read,
    local,
    calls: () => calls,
    close: () => Deno.remove(root, { recursive: true }),
  };
}

Deno.test("native reader declines unknown selectors without starting a process", async () => {
  let called = false;
  assertEquals(
    await readApplications("doctor.other", {}, () => {
      called = true;
      return Promise.resolve("");
    }),
    undefined,
  );
  assertEquals(called, false);
});
Deno.test("native Project reader canonicalizes repository identity and keeps partial unavailable checkouts", async () => {
  const f = await fixture();
  try {
    const path = `${f.root}/apps/site`;
    await Deno.mkdir(path, { recursive: true });
    await command(["/usr/bin/git", "init", "--quiet", path]);
    await command([
      "/usr/bin/git",
      "-C",
      path,
      "remote",
      "add",
      "origin",
      "git@github.com:acme/site.git",
    ]);
    const input = {
      repository: "https://github.com/acme/site",
      checkouts: [{
        instance_id: 1,
        path,
        root: `${f.root}/apps`,
        user: f.user,
        production: false,
      }, {
        instance_id: 2,
        path,
        root: `${f.root}/other`,
        user: f.user,
        production: false,
      }],
    };
    assertEquals(await f.read("doctor.inspect-project", input), {
      checkouts: [{ instance_id: 1, matches: true }, {
        instance_id: 2,
        matches: null,
      }],
    });
    await command([
      "/usr/bin/git",
      "-C",
      path,
      "remote",
      "set-url",
      "origin",
      "https://github.com/fork/site",
    ]);
    assertEquals(await f.read("doctor.inspect-project", input), {
      checkouts: [{ instance_id: 1, matches: false }, {
        instance_id: 2,
        matches: null,
      }],
    });
    assertEquals(
      await f.read("doctor.inspect-project", {
        ...input,
        checkouts: [{ ...input.checkouts[0], path: `${path}/missing` }],
      }),
      { checkouts: [{ instance_id: 1, matches: false }] },
    );
    await assertRejects(
      () =>
        f.read("doctor.inspect-project", {
          ...input,
          checkouts: [input.checkouts[0], input.checkouts[0]],
        }),
      Error,
      "doctor.native_input_invalid",
    );
  } finally {
    await f.close();
  }
});
Deno.test("native development reader distinguishes raw origin, Git worktree ownership and source history", async () => {
  const f = await fixture();
  try {
    const path = `${f.root}/apps/site`;
    await Deno.mkdir(path, { recursive: true });
    await command(["/usr/bin/git", "init", "--quiet", path]);
    await command([
      "/usr/bin/git",
      "-C",
      path,
      "remote",
      "add",
      "origin",
      "https://github.com/acme/site.git",
    ]);
    await write(`${path}/file`, "source\n");
    await command(["/usr/bin/git", "-C", path, "add", "file"]);
    await command([
      "/usr/bin/git",
      "-C",
      path,
      "-c",
      "user.name=Doctor",
      "-c",
      "user.email=doctor@example.test",
      "commit",
      "--quiet",
      "-m",
      "initial",
    ]);
    const starting_commit = await command([
      "/usr/bin/git",
      "-C",
      path,
      "rev-parse",
      "HEAD",
    ]);
    const input = {
      repository: "https://github.com/acme/site.git",
      checkout: path,
      root: `${f.root}/apps`,
      user: f.user,
      group: f.group,
      layout: "checkout",
      starting_commit,
    };
    assertEquals(await f.read("doctor.inspect-development-source", input), {
      checkout: true,
      layout: true,
      origin: true,
      history: true,
    });
    await command([
      "/usr/bin/git",
      "-C",
      path,
      "remote",
      "set-url",
      "origin",
      "git@github.com:acme/site.git",
    ]);
    assertEquals(await f.read("doctor.inspect-development-source", input), {
      checkout: true,
      layout: true,
      origin: false,
      history: true,
    });
    const worktree = `${f.root}/apps/linked`;
    await command([
      "/usr/bin/git",
      "-C",
      path,
      "worktree",
      "add",
      "--quiet",
      "-b",
      "linked",
      worktree,
    ]);
    assertEquals(
      (await f.read("doctor.inspect-development-source", {
        ...input,
        checkout: worktree,
        layout: "worktree",
      })).layout,
      true,
    );
    assertEquals(
      (await f.read("doctor.inspect-development-source", {
        ...input,
        checkout: worktree,
      })).layout,
      false,
    );
    assertEquals(
      (await f.read("doctor.inspect-development-source", {
        ...input,
        starting_commit: "",
      })).history,
      false,
    );
    await Deno.symlink(path, `${f.root}/apps/symlink`);
    const linked = await f.read("doctor.inspect-development-source", {
      ...input,
      checkout: `${f.root}/apps/symlink`,
    });
    assertEquals(linked.checkout, false);
    assertEquals(linked.history, true);
    assertEquals(
      await command(["/usr/bin/git", "-C", path, "status", "--porcelain"]),
      "",
    );
  } finally {
    await f.close();
  }
});
Deno.test("native application URL reader preserves duplicate, quote, missing-artisan and symlink cases", async () => {
  const f = await fixture();
  try {
    const checkout = `${f.root}/app`;
    await write(`${checkout}/artisan`, "artisan");
    await write(
      `${checkout}/.env`,
      "SECRET=fixture-private-value\nAPP_URL='https://app.test'\r\n",
    );
    const input = {
      checkout,
      applications: [{ directory: "", url: "https://app.test" }, {
        directory: "other",
        url: "https://other.test",
      }],
    };
    assertEquals(await f.read("doctor.inspect-application-urls", input), {
      matches: [true, true],
    });
    await write(
      `${checkout}/.env`,
      "APP_URL=https://app.test\nAPP_URL=https://app.test\n",
    );
    assertEquals(await f.read("doctor.inspect-application-urls", input), {
      matches: [false, true],
    });
    await Deno.rename(`${checkout}/.env`, `${checkout}/private-env`);
    await Deno.symlink("private-env", `${checkout}/.env`);
    assertEquals(await f.read("doctor.inspect-application-urls", input), {
      matches: [false, true],
    });
    await assertRejects(
      () =>
        f.read("doctor.inspect-application-urls", {
          checkout,
          applications: [{ directory: "../other", url: "https://app.test" }],
        }),
      Error,
      "doctor.native_input_invalid",
    );
  } finally {
    await f.close();
  }
});
Deno.test("native private Route reads only the live projection and returns independent nullable checks", async () => {
  const f = await fixture();
  try {
    await write(
      `${f.root}/version`,
      "app.test { reverse_proxy 10.44.0.8:443 }\n",
    );
    await Deno.symlink("version", String(f.host.caddy));
    const certificates = `${f.root}/certificates/current`;
    await write(`${certificates}/cert.pem`, "certificate");
    await write(`${certificates}/key.pem`, "fixture-private-value");
    await write(
      `${f.root}/.env`,
      "SECRET=fixture-private-value\nAPP_URL=https://app.test\n",
    );
    const input = {
      domain: "app.test",
      certificates,
      projection: {
        kind: "workload",
        dns_address: "10.44.0.7",
        laravel: {
          kind: "check",
          environment: `${f.root}/.env`,
          url: "https://app.test",
        },
      },
    };
    assertEquals(await f.read("doctor.inspect-private-route", input), {
      caddy: true,
      tls: true,
      firewall: true,
      dns: true,
      laravel: true,
      pool: null,
    });
    assertEquals(
      await f.read("doctor.inspect-private-route", {
        ...input,
        projection: {
          kind: "router",
          upstreams: ["10.44.0.8:443", "10.44.0.9:443"],
        },
      }),
      {
        caddy: false,
        tls: true,
        firewall: true,
        dns: null,
        laravel: null,
        pool: false,
      },
    );
    assertEquals(
      (await f.read("doctor.inspect-private-route", {
        ...input,
        projection: { kind: "workload", dns_address: null, laravel: null },
      })).laravel,
      null,
    );
    await Deno.remove(`${f.root}/version`);
    await Deno.remove(`${certificates}/key.pem`);
    const absent = await f.read("doctor.inspect-private-route", input);
    assertEquals(absent.caddy, false);
    assertEquals(absent.tls, false);
    assertEquals(
      await Deno.readTextFile(`${f.root}/.env`),
      "SECRET=fixture-private-value\nAPP_URL=https://app.test\n",
    );
  } finally {
    await f.close();
  }
});
Deno.test("native custom proxy tests actual loopback reachability and refuses an external upstream", async () => {
  const f = await fixture();
  const listener = Deno.listen({ hostname: "127.0.0.1", port: 0 });
  try {
    const upstream = `127.0.0.1:${listener.addr.port}`;
    await write(
      String(f.host.caddy),
      `app.test { reverse_proxy ${upstream} }\n`,
    );
    await write(`${f.root}/certs/cert.pem`, "cert");
    await write(`${f.root}/certs/key.pem`, "fixture-private-value");
    const input = {
      domain: "app.test",
      certificates: `${f.root}/certs`,
      upstream,
      dns_address: "10.44.0.7",
    };
    assertEquals(await f.read("doctor.inspect-custom-proxy", input), {
      caddy: true,
      tls: true,
      dns: true,
      upstream: true,
    });
    listener.close();
    assertEquals(
      (await f.read("doctor.inspect-custom-proxy", input)).upstream,
      false,
    );
    await assertRejects(
      () =>
        f.read("doctor.inspect-custom-proxy", {
          ...input,
          upstream: "192.0.2.1:80",
        }),
      Error,
      "doctor.native_input_invalid",
    );
  } finally {
    try {
      listener.close();
    } catch { /* already closed */ }
    await f.close();
  }
});

async function productionFixture() {
  const f = await fixture();
  const home = `${f.host.homes}/${f.user}`;

  await Deno.mkdir(`${home}/releases/initial/public`, { recursive: true });
  await write(`${home}/.env`, "APP_KEY=fixture-private-value\n", 0o600);
  await write(String(f.host.caddy), "app.test { respond ok }\n");
  const configuration = {
    main: "main\n",
    pool: `chdir = ${home}/current\n`,
    initial_pool: `chdir = ${home}/releases/initial\n`,
    local_defaults: "; tune here\n",
    master_ini: "master\n",
    unit: "unit\n",
  };
  const runtime = {
    version: "8.5",
    marker: "marker\n",
    configuration,
    application: `${home}/current`,
    initial_application: `${home}/releases/initial`,
  };
  const input = {
    user: f.user,
    home,
    root: "public",
    application_suffix: "",
    environment: "APP_KEY=fixture-private-value\n",
    caddy_sites: ["app.test { respond ok }\n"],
    association: true,
    runtime,
  };
  const directory = `${f.host.etc}/php-fpm/${f.user}`;
  const generated = `${directory}/generated`;
  await Deno.mkdir(generated, { recursive: true });
  await Deno.chmod(directory, 0o755);
  await Deno.chmod(generated, 0o755);
  const pool = configuration.initial_pool +
    `\n; Orbit application release: ${runtime.initial_application}\n`;
  for (
    const [path, value] of [
      [`${generated}/php-fpm.conf`, configuration.main],
      [`${generated}/pool.conf`, pool],
      [`${generated}/master.ini`, configuration.master_ini],
      [`${directory}/orbit.identity`, runtime.marker],
      [`${directory}/local.conf`, "; tuning\n"],
      [
        `${f.host.units}/orbit-${f.user}-php8.5-fpm.service`,
        configuration.unit,
      ],
    ]
  ) await write(path, value);
  const values: Record<string, string> = {
    ActiveState: "active",
    User: "",
    MainPID: "200",
    ExecStart:
      `{ path=${f.host.sbin}/php-fpm8.5 ; argv[]=${f.host.sbin}/php-fpm8.5 --nodaemonize --fpm-config ${generated}/php-fpm.conf ; }`,
    Environment:
      `PHP_INI_SCAN_DIR=${f.host.php_conf}/8.5/fpm/conf.d:${generated}`,
  };
  await write(
    String(f.host.systemctl),
    `#!/usr/bin/python3\nimport pathlib,sys\nkey=sys.argv[2].split('=',1)[1]\np=pathlib.Path(${
      JSON.stringify(`${f.root}/state`)
    })/key\nprint(p.read_text(),end='')\n`,
    0o755,
  );
  for (const [key, value] of Object.entries(values)) {
    await write(`${f.root}/state/${key}`, value);
  }
  await write(`${f.host.sbin}/php-fpm8.5`, "binary");
  await write(`${f.host.proc}/200/status`, "Uid:\t0\t0\t0\t0\n");
  const fields = Array.from({ length: 20 }, () => "1");
  fields[19] = "9001";
  await write(`${f.host.proc}/200/stat`, `200 (php-fpm) ${fields.join(" ")}\n`);
  await Deno.symlink(`${f.host.sbin}/php-fpm8.5`, `${f.host.proc}/200/exe`);
  await write(`${f.host.proc}/200/task/200/children`, "");
  await Deno.mkdir(`${f.host.proc}/200/fd`, { recursive: true });
  await Deno.mkdir(String(f.host.sockets), { recursive: true });
  const socket = Deno.listen({
    transport: "unix",
    path: `${f.host.sockets}/${f.user}.sock`,
  });
  await Deno.chmod(`${f.host.sockets}/${f.user}.sock`, 0o660);
  await write(
    `${f.host.proc}/net/unix`,
    `0: 2 0 00010000 0001 01 100 ${f.host.sockets}/${f.user}.sock\n`,
  );
  await Deno.symlink("socket:[100]", `${f.host.proc}/200/fd/8`);
  const boot = "aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee";
  await write(`${f.host.proc}/sys/kernel/random/boot_id`, boot + "\n");
  const selected = pool.split("\n").filter((line) =>
    line.startsWith("chdir = ") ||
    line.startsWith("; Orbit application release: ")
  ).map((line) => line + "\n").join("");
  const hash = [
    ...new Uint8Array(
      await crypto.subtle.digest("SHA-256", new TextEncoder().encode(selected)),
    ),
  ].map((n) => n.toString(16).padStart(2, "0")).join("");
  await write(
    `${directory}/.runtime-generation.applied`,
    `${hash}\n${boot}\n200\n9001\n`,
    0o600,
  );
  return {
    ...f,
    home,
    input,
    directory,
    fields,
    values,
    close: async () => {
      socket.close();
      await f.close();
    },
  };
}
Deno.test("native production reader checks exact files, PHP identity and stable master socket without exposing environment", async () => {
  const f = await productionFixture();
  try {
    const healthy = {
      home: true,
      selection: true,
      root: true,
      environment: true,
      php: true,
      caddy: true,
    };
    assertEquals(
      await f.read("doctor.inspect-production-instance", f.input),
      healthy,
    );
    assertEquals(
      await f.read("doctor.inspect-production-instance", f.input),
      healthy,
    );
    await write(`${f.directory}/local.conf`, "listen = /tmp/other.sock\n");
    assertEquals(
      (await f.read("doctor.inspect-production-instance", f.input)).php,
      false,
    );
    await write(`${f.directory}/local.conf`, "pm.max_children = 8\n");
    await write(`${f.host.proc}/200/status`, "Uid:\t1\t1\t1\t1\n");
    assertEquals(
      (await f.read("doctor.inspect-production-instance", f.input)).php,
      false,
    );
    await Deno.remove(`${f.host.proc}/200/status`);
    assertEquals(
      (await f.read("doctor.inspect-production-instance", f.input)).php,
      null,
    );
  } finally {
    await f.close();
  }
});
Deno.test("native production reader distinguishes service failure, generation and release/env drift", async () => {
  const f = await productionFixture();
  try {
    for (
      const [key, value, expected] of [
        ["ActiveState", "inactive", false],
        ["ActiveState", "unexpected", null],
        ["MainPID", "invalid", null],
        ["ExecStart", "{ path=/other ; argv[]=/other ; }", false],
        ["ExecStart", "invalid", null],
        ["Environment", "PHP_INI_SCAN_DIR=/other", false],
      ] as const
    ) {
      await write(`${f.root}/state/${key}`, value);
      assertEquals(
        (await f.read("doctor.inspect-production-instance", f.input)).php,
        expected,
        `${key}=${value}`,
      );
      await write(`${f.root}/state/${key}`, f.values[key]);
    }
    await write(`${f.directory}/.runtime-generation.pending`, "pending");
    assertEquals(
      (await f.read("doctor.inspect-production-instance", f.input)).php,
      false,
    );
    await Deno.remove(`${f.directory}/.runtime-generation.pending`);
    await Deno.symlink("releases/initial", `${f.home}/current`);
    await Deno.symlink("../../.env", `${f.home}/releases/initial/.env`);
    let result = await f.read("doctor.inspect-production-instance", f.input);
    assertEquals([
      result.selection,
      result.environment,
      result.root,
      result.php,
    ], [true, true, true, false]);
    await Deno.remove(`${f.home}/current`);
    await Deno.symlink("../outside", `${f.home}/current`);
    result = await f.read("doctor.inspect-production-instance", f.input);
    assertEquals([result.selection, result.root, result.environment], [
      false,
      false,
      false,
    ]);
    await Deno.remove(`${f.home}/current`);
    await Deno.chmod(`${f.home}/.env`, 0o644);
    assertEquals(
      (await f.read("doctor.inspect-production-instance", f.input)).environment,
      false,
    );
    assertEquals(
      (await f.read("doctor.inspect-production-instance", {
        ...f.input,
        runtime: null,
      })).php,
      true,
    );
  } finally {
    await f.close();
  }
});
Deno.test("native production reader checks worker custody but ignores exited workers", async () => {
  const f = await productionFixture();
  try {
    await write(`${f.host.proc}/200/task/200/children`, "700 ");
    await write(
      `${f.host.proc}/700/status`,
      `State:\tS\nPPid:\t200\nUid:\t${f.uid}\t${f.uid}\t${f.uid}\t${f.uid}\nGid:\t${f.gid}\t${f.gid}\t${f.gid}\t${f.gid}\n`,
    );
    await write(
      `${f.host.proc}/700/stat`,
      `700 (php-fpm) ${f.fields.join(" ")}\n`,
    );
    await Deno.symlink("/", `${f.host.proc}/700/root`);
    assertEquals(
      (await f.read("doctor.inspect-production-instance", f.input)).php,
      true,
    );
    await write(
      `${f.host.proc}/700/status`,
      `State:\tS\nPPid:\t200\nUid:\t1\t1\t1\t1\nGid:\t${f.gid}\t${f.gid}\t${f.gid}\t${f.gid}\n`,
    );
    assertEquals(
      (await f.read("doctor.inspect-production-instance", f.input)).php,
      false,
    );
    await write(
      `${f.host.proc}/700/status`,
      "State:\tZ\nPPid:\t200\nUid:\t1\t1\t1\t1\nGid:\t1\t1\t1\t1\n",
    );
    assertEquals(
      (await f.read("doctor.inspect-production-instance", f.input)).php,
      true,
    );
    await write(
      `${f.host.proc}/700/status`,
      `State:\tS\nPPid:\t999\nUid:\t${f.uid}\t${f.uid}\t${f.uid}\t${f.uid}\nGid:\t${f.gid}\t${f.gid}\t${f.gid}\t${f.gid}\n`,
    );
    assertEquals(
      (await f.read("doctor.inspect-production-instance", f.input)).php,
      null,
    );
  } finally {
    await f.close();
  }
});
Deno.test("native Laravel reader runs the selected production owner and conservatively detects Reverb prefixes", async () => {
  const f = await fixture();
  try {
    const home = `${f.root}/home`;
    const release = `${home}/releases/one`;
    await Deno.mkdir(release, { recursive: true });
    await Deno.symlink("releases/one", `${home}/current`);
    const input = {
      user: f.user,
      home,
      application: `${home}/current`,
      php_version: "9.9",
    };
    for (
      const [uri, collision] of [
        ["dashboard", false],
        ["application/{id}", false],
        ["{fallbackPlaceholder}", false],
        ["APP/{id}", true],
        ["ap{suffix}", true],
        ["app.{format?}", true],
        ["{page?}", true],
      ] as const
    ) {
      await write(
        `${f.root}/bin/php9.9`,
        `#!/usr/bin/python3\nimport json,os\nassert os.getcwd()==${
          JSON.stringify(release)
        }\nprint(json.dumps([{'uri':${JSON.stringify(uri)}}]))\n`,
        0o755,
      );
      assertEquals(await f.read("instance.inspect-laravel-routes", input), {
        collision,
      }, uri);
    }
    await assertRejects(
      () =>
        f.read("instance.inspect-laravel-routes", {
          ...input,
          application: f.root,
        }),
      Error,
      "doctor.native_input_invalid",
    );
    await assertRejects(
      () =>
        f.read("instance.inspect-laravel-routes", {
          ...input,
          php_version: "../../sh",
        }),
      Error,
      "doctor.native_input_invalid",
    );
    await write(
      `${f.root}/bin/php9.9`,
      "#!/usr/bin/python3\nprint('not JSON')\n",
      0o755,
    );
    await assertRejects(
      () => f.read("instance.inspect-laravel-routes", input),
      Error,
      "doctor.native_inspection_failed",
    );
  } finally {
    await f.close();
  }
});
Deno.test("native public Route verifies the live block, exact forwarding and packaged-root TLS independently", async () => {
  const f = await fixture();
  let server: Deno.HttpServer | undefined;
  const backend = Deno.listen({ hostname: "127.0.0.1", port: 0 });
  try {
    await Deno.mkdir(String(f.host.roots), { recursive: true });
    await command([
      "/usr/bin/openssl",
      "req",
      "-x509",
      "-newkey",
      "rsa:2048",
      "-nodes",
      "-days",
      "2",
      "-subj",
      "/CN=app.test",
      "-addext",
      "subjectAltName=DNS:app.test",
      "-keyout",
      `${f.root}/leaf.key`,
      "-out",
      `${f.host.roots}/leaf.crt`,
    ]);
    server = Deno.serve({
      hostname: "127.0.0.1",
      port: 0,
      cert: await Deno.readTextFile(`${f.host.roots}/leaf.crt`),
      key: await Deno.readTextFile(`${f.root}/leaf.key`),
      onListen() {},
      handler: () => new Response("unused"),
    });
    f.host.tls_address = ["127.0.0.1", (server.addr as Deno.NetAddr).port];
    const expected =
      "# orbit: ingress route-1-ingress\napp.test {\n    bind 0.0.0.0 10.44.0.7\n    tls force_automate\n    reverse_proxy https://10.44.0.8\n}\n";
    await write(
      `${f.root}/version`,
      "{\n    auto_https disable_certs\n}\n" + expected,
    );
    await Deno.symlink("version", String(f.host.caddy));
    const input = {
      domain: "app.test",
      expected_site: expected,
      certificates: `${f.root}/certificates/current`,
      forwarding: [`127.0.0.1:${backend.addr.port}`],
    };
    assertEquals(await f.read("doctor.inspect-public-route", input), {
      ingress: true,
      forwarding: true,
      tls: true,
    });
    await write(
      `${f.root}/version`,
      "{\n    auto_https disable_certs\n}\n" +
        expected.replace("    tls force_automate\n", ""),
    );
    assertEquals(await f.read("doctor.inspect-public-route", input), {
      ingress: true,
      forwarding: true,
      tls: false,
    });
    await write(
      `${f.root}/version`,
      expected.replace("10.44.0.8", "10.44.0.99"),
    );
    assertEquals(await f.read("doctor.inspect-public-route", input), {
      ingress: false,
      forwarding: true,
      tls: true,
    });
    await write(
      `${f.root}/version`,
      expected.replace(
        "tls force_automate",
        `tls ${input.certificates}/cert.pem ${input.certificates}/key.pem`,
      ),
    );
    assertEquals(await f.read("doctor.inspect-public-route", input), {
      ingress: true,
      forwarding: true,
      tls: false,
    });
    backend.close();
    assertEquals(
      (await f.read("doctor.inspect-public-route", input)).forwarding,
      false,
    );
    await write(`${f.root}/version`, "import fragments/*.caddy\n");
    assertEquals(
      await f.read("doctor.inspect-public-route", { ...input, forwarding: [] }),
      { ingress: false, forwarding: true, tls: false },
    );
    await assertRejects(
      () =>
        f.read("doctor.inspect-public-route", {
          ...input,
          domain: "app.test\nother",
        }),
      Error,
      "doctor.native_input_invalid",
    );
    await Deno.remove(`${f.root}/version`);
    await Deno.mkdir(`${f.root}/version`);
    await assertRejects(
      () => f.read("doctor.inspect-public-route", input),
      Error,
      "doctor.native_inspection_failed",
    );
  } finally {
    try {
      backend.close();
    } catch { /* already closed */ }
    await server?.shutdown();
    await f.close();
  }
});
