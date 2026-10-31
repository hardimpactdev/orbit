import {
  assert,
  assertEquals,
  assertFalse,
  assertRejects,
} from "jsr:@std/assert@1.0.19";
import {
  processObservation,
  readRuntime,
  runtimeToolProgram,
} from "./doctor_native_runtime.ts";
import type { Row } from "./doctor_contract.ts";

async function python(program: string, input: Row): Promise<Row> {
  const output = await new Deno.Command("/usr/bin/python3", {
    args: ["-c", program, JSON.stringify(input)],
    stdout: "piped",
    stderr: "piped",
  }).output();
  assert(output.success, new TextDecoder().decode(output.stderr));
  return JSON.parse(new TextDecoder().decode(output.stdout));
}
function fixture(root: string, commands: Row = {}, hook = "") {
  return async (argv: string[]) => {
    const payload = JSON.parse(argv.at(-1)!);
    const script = argv[argv.indexOf("-c") + 1];
    const result = await python(
      `import json,sys\nns={'__name__':'fixture'}\nexec(${
        JSON.stringify(script)
      },ns)\nns['ROOT']=ns['Path'](${JSON.stringify(root)})\ncommands=${
        JSON.stringify(commands)
      }\ndef command(argv):\n key=' '.join(argv)\n if key not in commands:raise RuntimeError('Unexpected native command '+key)\n return (0,commands[key])\nns['run']=command\n${hook}\npayload=json.loads(sys.argv[1])\ntry:result=ns['runtime_read'](payload['name'],payload['input'])\nexcept Exception:result={'error_code':'observation.inspection_unavailable'}\nprint(json.dumps(result))`,
      payload,
    );
    return JSON.stringify(result);
  };
}
const process = {
  process_id: 7,
  runtime: "systemd",
  name: "orbit-process-7-web.service",
  desired: "running",
};
Deno.test("fresh Docker snapshots distinguish known stops, absence and unavailable inventory", async () => {
  const input = { ...process, runtime: "docker", name: "orbit-process-7-web" };
  for (
    const state of [
      "running",
      "created",
      "exited",
      "restarting",
      "paused",
      "dead",
    ]
  ) {
    const observed = processObservation(input, {
      freshness: "fresh",
      docker: "available",
      units: {
        "docker:orbit-process-7-web": state,
      },
    });
    assertEquals(
      observed.status,
      ["running", "created", "exited"].includes(state) ? state : "other",
    );
  }
  assertEquals(
    processObservation(input, {
      freshness: "fresh",
      docker: "available",
      units: {},
    }),
    { present: false, status: null, systemd_observations: [] },
  );
  await assertRejects(() =>
    readRuntime("doctor.inspect-process", input, () => Promise.resolve(""), {
      freshness: "stale",
      docker: "available",
      units: {},
    })
  );
  await assertRejects(() =>
    readRuntime(
      "doctor.inspect-process",
      { ...input, name: "orbit-process-8-web" },
      () => Promise.resolve(""),
      {},
    )
  );
});
const id = "123e4567-e89b-42d3-a456-426614174000",
  orphan = "123e4567-e89b-42d3-a456-426614174001";
Deno.test("native Schedule orphan scan reads files, deduplicates UUIDs and excludes foreign names and symlinks", async () => {
  const root = await Deno.makeTempDir();
  try {
    await Deno.mkdir(root + "/etc/orbit/schedules", { recursive: true });
    await Deno.mkdir(root + "/etc/systemd/system", { recursive: true });
    await Deno.writeTextFile(`${root}/etc/orbit/schedules/${id}.sh`, "known");
    await Deno.writeTextFile(
      `${root}/etc/systemd/system/orbit-schedule-${orphan}.service`,
      "orphan",
    );
    await Deno.writeTextFile(
      `${root}/etc/systemd/system/orbit-schedule-${orphan}.timer`,
      "same orphan",
    );
    await Deno.writeTextFile(
      `${root}/etc/orbit/schedules/private-command.sh`,
      "ignored",
    );
    await Deno.symlink(
      `${root}/etc/orbit/schedules/${id}.sh`,
      `${root}/etc/orbit/schedules/123e4567-e89b-42d3-a456-426614174002.sh`,
    );
    assertEquals(
      await readRuntime(
        "doctor.schedule-orphans",
        { known_ids: [id] },
        fixture(root),
      ),
      { ids: [orphan] },
    );
  } finally {
    await Deno.remove(root, { recursive: true });
  }
});
Deno.test("native Schedule inspection hashes private command locally and never places it in argv", async () => {
  const root = await Deno.makeTempDir();
  const input = {
    id,
    user: "orbit",
    group: "orbit",
    home: "/home/orbit",
    working_directory: "/home/orbit/project",
    shell: "/bin/bash",
    login_shell: true,
    command: "private schedule sentinel",
    calendar: "daily",
    timeout_seconds: 30,
    desired: "disabled",
  };
  try {
    let called = false;
    const local = async (argv: string[]) => {
      called = true;
      assertFalse(JSON.stringify(argv).includes(input.command));
      return await fixture(root)(argv);
    };
    const result = await readRuntime("doctor.inspect-schedule", input, local);
    assert(called);
    assertEquals(result?.artifacts_present, false);
    assertFalse(JSON.stringify(result).includes(input.command));
  } finally {
    await Deno.remove(root, { recursive: true });
  }
});
Deno.test("Schedule inspection compares exact artifacts and independently reports context/calendar/callback drift", async () => {
  const root = await Deno.makeTempDir(),
    user = (await python(
      "import pwd,os,json;print(json.dumps({'user':pwd.getpwuid(os.getuid()).pw_name,'group':pwd.getpwuid(os.getuid()).pw_name}))",
      {},
    )).user;
  const input = {
    id,
    user,
    group: user,
    home: "/home/orbit",
    working_directory: "/srv/app",
    shell: "/bin/bash",
    login_shell: true,
    command: "printf private",
    calendar: "daily",
    timeout_seconds: 30,
    desired: "disabled",
  };
  const context = {
    user,
    group: user,
    home: input.home,
    working_directory: input.working_directory,
    shell: input.shell,
    login_shell: true,
  };
  const script = `# X-Orbit-Schedule-ID=${id}\n# X-Orbit-Execution-Context=${
    JSON.stringify(context)
  }\n${input.command}\n`;
  const service =
    `[Unit]\nDescription=Orbit Schedule ${id}\nX-Orbit-Schedule-ID=${id}\nAfter=network-online.target\nWants=network-online.target\n\n[Service]\nType=oneshot\nUser=${user}\nGroup=${user}\nWorkingDirectory=/srv/app\nEnvironment="HOME=/home/orbit"\nEnvironment="SHELL=/bin/bash"\nExecStart=/usr/local/bin/orbit-agent service schedules-run ${id}\nExecStopPost=+/usr/local/bin/orbit-agent service schedules-complete ${id}\nTimeoutStartSec=30\n`;
  const timer =
    `[Unit]\nDescription=Orbit Schedule timer ${id}\nX-Orbit-Schedule-ID=${id}\n\n[Timer]\nOnCalendar=daily\nPersistent=true\nUnit=orbit-schedule-${id}.service\n\n[Install]\nWantedBy=timers.target\n`;
  try {
    await Deno.mkdir(root + "/etc/orbit/schedules", { recursive: true });
    await Deno.mkdir(root + "/etc/systemd/system", { recursive: true });
    await Deno.writeTextFile(`${root}/etc/orbit/schedules/${id}.sh`, script, {
      mode: 0o750,
    });
    await Deno.writeTextFile(
      `${root}/etc/systemd/system/orbit-schedule-${id}.service`,
      service,
    );
    const timerPath = `${root}/etc/systemd/system/orbit-schedule-${id}.timer`;
    await Deno.writeTextFile(timerPath, timer);
    const commands = {
      [`/usr/bin/systemctl is-enabled -- orbit-schedule-${id}.timer`]:
        "disabled",
      [`/usr/bin/systemctl is-active -- orbit-schedule-${id}.timer`]:
        "inactive",
    };
    const actual = await readRuntime(
      "doctor.inspect-schedule",
      input,
      fixture(root, commands),
    );
    assertEquals(actual, {
      artifacts_present: true,
      permissions_match: false,
      specification_match: true,
      timer_state_match: true,
      calendar_match: true,
      execution_context_match: true,
      completion_callback_match: true,
    });
    await Deno.writeTextFile(
      timerPath,
      timer.replace("OnCalendar=daily", "OnCalendar=hourly"),
    );
    const changed = await readRuntime(
      "doctor.inspect-schedule",
      input,
      fixture(root, commands),
    );
    assertEquals(changed?.calendar_match, false);
    assertEquals(changed?.completion_callback_match, true);
  } finally {
    await Deno.remove(root, { recursive: true });
  }
});
const shape = (comment: string, family: string | null = null): Row => ({
  comment,
  action: "allow",
  direction: "in",
  source: "any",
  destination: "any",
  port: "443",
  protocol: "tcp",
  in_interface: null,
  out_interface: null,
  family,
});
Deno.test("Firewall native parser preserves dual-stack ownership, duplicates and malformed owned evidence", async () => {
  const expected = [
    shape("orbit:dual"),
    shape("orbit:duplicate", "v4"),
    shape("orbit:malformed", "v4"),
    shape("orbit:missing", "v4"),
  ];
  const text =
    "Status: active\n[ 1] 443/tcp ALLOW IN Anywhere # orbit:dual\n[ 2] 443/tcp (v6) ALLOW IN Anywhere (v6) # orbit:dual\n[ 3] 443/tcp ALLOW IN Anywhere # orbit:duplicate\n[ 4] 443/tcp ALLOW IN Anywhere # orbit:duplicate\nnot parseable # orbit:malformed";
  const result = await readRuntime("firewall.inspect", {
    expected,
    exclusive_families: [],
    stored: false,
  }, fixture("/", { "/usr/sbin/ufw status numbered": text }));
  assertEquals(result?.backend, "active");
  assertEquals(result?.ownerships, ["exact", "drift", "drift", "missing"]);
  assertEquals(result?.matches, false);
});
Deno.test("Firewall inactive and unrecognized backend remain distinct", async () => {
  const input = {
    expected: [shape("orbit:rule", "v4")],
    exclusive_families: [],
    stored: false,
  };
  assertEquals(
    (await readRuntime(
      "firewall.inspect",
      input,
      fixture("/", { "/usr/sbin/ufw status numbered": "Status: inactive" }),
    ))?.backend,
    "inactive",
  );
  try {
    await readRuntime(
      "firewall.inspect",
      input,
      fixture("/", { "/usr/sbin/ufw status numbered": "private bad output" }),
    );
    throw new Error("Expected refusal");
  } catch (error) {
    assertEquals(error, { error_code: "observation.inspection_unavailable" });
  }
});
Deno.test("native hibernation marker reads distinguish absent and present without writes", async () => {
  const root = await Deno.makeTempDir(),
    path = "/dev/shm/orbit/hibernation/app-instance-7.awake";
  try {
    await Deno.mkdir(root + "/dev/shm/orbit/hibernation", { recursive: true });
    assertEquals(
      await readRuntime("hibernation.times", { paths: [path] }, fixture(root)),
      { times: { [path]: null } },
    );
    await Deno.writeTextFile(root + path, "");
    const result = await readRuntime(
      "hibernation.times",
      { paths: [path] },
      fixture(root),
    );
    assert(Number((result?.times as Row)[path]) > 0);
  } finally {
    await Deno.remove(root, { recursive: true });
  }
});
Deno.test("Tools fixed native reads use admitted account metadata and refuse action selectors", async () => {
  let calls = 0;
  const local = (argv: string[]) => {
    calls++;
    const input = JSON.parse(argv.at(-1)!);
    assertEquals(input.node, {
      user: "orbit",
      platform: "linux",
      architecture: "x86_64",
    });
    assertEquals(input.name, "tool.apt-inventory");
    assertFalse(argv.includes("/usr/local/bin/orbit-agent"));
    return Promise.resolve(JSON.stringify({ packages: [] }));
  };
  assertEquals(
    await readRuntime("tool.apt-inventory", {}, local, {
      node: { user: "orbit", platform: "linux", architecture: "x86_64" },
    }),
    { packages: [] },
  );
  assertEquals(
    await readRuntime("tool.apt-install", { package: "curl" }, local, {}),
    undefined,
  );
  assertEquals(calls, 1);
});
Deno.test("reused Tools native inventory preserves incomplete VP scan and package ambiguity", async () => {
  const fixtureProgram =
    `import json,sys,types\nns={'__name__':'fixture'}\nexec(${
      JSON.stringify(runtimeToolProgram)
    },ns)\nclass Host:\n node={'platform':'linux','architecture':'x86_64','user':'orbit','manager':'vp'}\n def run(self,argv,**kwargs):return (0,json.dumps([{'name':'present','version':'1.0.0'},{'name':'duplicate','version':'1.0.0'},{'name':'duplicate','version':'2.0.0'},{'name':'unknown','version':None}]),'')\nns['vp_scope']=lambda host:ns['Path']('/fixture/vp')\nprint(json.dumps(ns['vp'](Host(),'inventory',{})))`;
  const result = await python(fixtureProgram, {});
  assertEquals(result.scan_complete, false);
  assertEquals((result.packages as Row[]).length, 4);
});
Deno.test("Homebrew native discovery retains omitted names and isolates formula/cask scan failures", async () => {
  const fixtureProgram =
    `import json,sys,types\nns={'__name__':'fixture'}\nexec(${
      JSON.stringify(runtimeToolProgram)
    },ns)\nclass Host:\n node={'platform':'macos','architecture':'arm64','user':'orbit'}\n home='/home/orbit'\n def run(self,argv,**kwargs):\n  args=argv[1:]\n  if args==['info','--json=v2','--formula','--installed']:return (0,json.dumps({'formulae':[],'casks':[]}),'')\n  if args==['list','--formula','-1']:return (0,'wireguard-tools\\nforeign-package\\n','')\n  raise Exception('private cask diagnostics')\nns['brew_scope']=lambda host:ns['Path']('/fixture/brew')\nns['brew_tag']=lambda host:'arm64_tahoe'\nprint(json.dumps(ns['doctor_scan'](Host())))`;
  const result = await python(fixtureProgram, {});
  assertEquals(result, {
    formulae: {
      state: "complete",
      packages: [{
        name: "foreign-package",
        installed_version: null,
        dependency: false,
        block: "unsupported_source",
      }, {
        name: "wireguard-tools",
        installed_version: null,
        dependency: false,
        block: "protected",
      }],
    },
    casks: { state: "incomplete", packages: [] },
  });
  assertFalse(JSON.stringify(result).includes("private cask"));
});
Deno.test("vp store inspection proves exact immutable runtime manifests and executable presence without mutation", async () => {
  const root = await Deno.makeTempDir();
  try {
    const node = `${root}/js_runtime/node/24.1.0/bin/node`,
      manager = `${root}/package_manager/pnpm/10.1.0/pnpm`;
    await Deno.mkdir(node.slice(0, node.lastIndexOf("/")), { recursive: true });
    await Deno.mkdir(manager + "/bin", { recursive: true });
    await Deno.writeTextFile(node, "native-runtime", { mode: 0o755 });
    await Deno.writeTextFile(
      manager + "/package.json",
      JSON.stringify({ name: "pnpm", version: "10.1.0" }),
    );
    await Deno.writeTextFile(manager + "/bin/pnpm", "native-manager", {
      mode: 0o755,
    });
    const input = {
      environments: [{
        store: root,
        node_version: "24.1.0",
        package_manager: "pnpm",
        package_manager_version: "10.1.0",
        package_manager_hash: null,
      }],
    };
    const fixtureProgram =
      `import json,sys,types,os\nns={'__name__':'fixture'}\nexec(${
        JSON.stringify(runtimeToolProgram)
      },ns)\nhost=types.SimpleNamespace(account=types.SimpleNamespace(pw_uid=os.getuid()))\nprint(json.dumps(ns['doctor_store'](host,json.loads(sys.argv[1]))))`;
    assertEquals(await python(fixtureProgram, input), { installed: [true] });
    await Deno.writeTextFile(
      manager + "/package.json",
      JSON.stringify({ name: "pnpm", version: "9.0.0" }),
    );
    assertEquals(await python(fixtureProgram, input), { installed: [false] });
    assertEquals(await Deno.readTextFile(node), "native-runtime");
  } finally {
    await Deno.remove(root, { recursive: true });
  }
});
const systemdShow = [
  "/usr/bin/systemctl",
  "show",
  "--property=ActiveState,SubState,NRestarts,LoadState",
  "--",
  "orbit-process-7-web.service",
];
const nativeSample = (
  restarts: string,
  active = "active",
  sub = "running",
  load = "loaded",
) =>
  `ActiveState=${active}\nSubState=${sub}\nNRestarts=${restarts}\nLoadState=${load}\n`;
function processFixture(root: string, samples: string[]) {
  const hook = `samples=${
    JSON.stringify(samples)
  }\ndef process_show(argv):\n if argv!=${
    JSON.stringify(systemdShow)
  }:raise RuntimeError('Unexpected command')\n if not samples:raise RuntimeError('Unexpected extra sample')\n value=samples.pop(0)\n return (1,'') if value=='FAILED' else (0,value)\nns['run']=process_show\nns['time'].sleep=lambda seconds:None`;
  return fixture(root, {}, hook);
}
async function processRoot(): Promise<string> {
  const root = await Deno.makeTempDir();
  await Deno.mkdir(root + "/etc/systemd/system", { recursive: true });
  await Deno.writeTextFile(
    root + "/etc/systemd/system/orbit-process-7-web.service",
    "[Unit]\nX-Orbit-Process-ID=7\n[Service]\nExecStart=/usr/bin/false\n",
  );
  return root;
}
Deno.test("native systemd read gathers two bounded restart samples with frozen Gateway schema", async () => {
  const root = await processRoot();
  try {
    const result = await readRuntime(
      "doctor.inspect-process",
      process,
      processFixture(root, [nativeSample("3"), nativeSample("4")]),
      {
        freshness: "fresh",
        units: { "systemd:orbit-process-7-web": "active" },
        docker: "absent",
      },
    );
    assertEquals(result, {
      present: true,
      status: "active",
      systemd_observations: [{
        active_state: "active",
        sub_state: "running",
        restarts: 3,
      }, { active_state: "active", sub_state: "running", restarts: 4 }],
    });
    assertEquals(
      await Deno.readTextFile(
        root + "/etc/systemd/system/orbit-process-7-web.service",
      ),
      "[Unit]\nX-Orbit-Process-ID=7\n[Service]\nExecStart=/usr/bin/false\n",
    );
  } finally {
    await Deno.remove(root, { recursive: true });
  }
});
Deno.test("systemd uses fresh Gateway status while native evidence covers counters and missing observer fields", async () => {
  const root = await processRoot();
  try {
    const result = await readRuntime(
      "doctor.inspect-process",
      process,
      processFixture(root, [nativeSample("0"), nativeSample("0")]),
      {
        freshness: "fresh",
        units: { "systemd:orbit-process-7-web": "inactive" },
      },
    );
    assertEquals(result?.status, "inactive");
    assertEquals(
      (result?.systemd_observations as Row[])[1].active_state,
      "active",
    );
    const native = await readRuntime(
      "doctor.inspect-process",
      process,
      processFixture(root, [nativeSample("0"), nativeSample("0")]),
      { freshness: "stale", units: {} },
    );
    assertEquals(native?.status, "active");
  } finally {
    await Deno.remove(root, { recursive: true });
  }
});
Deno.test("systemd unknown restart counters remain unknown rather than a false crash loop", async () => {
  const root = await processRoot();
  try {
    const result = await readRuntime(
      "doctor.inspect-process",
      process,
      processFixture(root, [nativeSample(""), nativeSample("")]),
      { freshness: "missing", units: {} },
    );
    assertEquals(result?.status, "active");
    assertEquals(
      (result?.systemd_observations as Row[]).map((s) => s.restarts),
      [null, null],
    );
  } finally {
    await Deno.remove(root, { recursive: true });
  }
});
Deno.test("systemd auto restart and intended stop use one sample without a second read", async () => {
  const root = await processRoot();
  try {
    const restarting = await readRuntime(
      "doctor.inspect-process",
      process,
      processFixture(root, [nativeSample("8", "activating", "auto-restart")]),
      {},
    );
    assertEquals(restarting, {
      present: true,
      status: "other",
      systemd_observations: [{
        active_state: "activating",
        sub_state: "auto-restart",
        restarts: 8,
      }],
    });
    const stopped = await readRuntime(
      "doctor.inspect-process",
      { ...process, desired: "stopped" },
      processFixture(root, [nativeSample("", "inactive", "dead")]),
      {},
    );
    assertEquals(stopped?.status, "inactive");
    assertEquals((stopped?.systemd_observations as Row[]).length, 1);
  } finally {
    await Deno.remove(root, { recursive: true });
  }
});
Deno.test("missing systemd runtime differs from unreadable/foreign unit and failed second observation", async () => {
  const root = await processRoot(),
    path = root + "/etc/systemd/system/orbit-process-7-web.service";
  try {
    const gone = await readRuntime(
      "doctor.inspect-process",
      process,
      processFixture(root, [nativeSample("", "inactive", "dead", "not-found")]),
      {},
    );
    assertEquals(gone, {
      present: false,
      status: null,
      systemd_observations: [],
    });
    for (
      const samples of [[nativeSample("0"), "FAILED"], [nativeSample("01")], [
        nativeSample("4294967296"),
      ], ["private stderr not a typed observation"]]
    ) {
      try {
        await readRuntime(
          "doctor.inspect-process",
          process,
          processFixture(root, samples),
          {},
        );
        throw new Error("Expected unavailable");
      } catch (error) {
        assertEquals(error, {
          error_code: "observation.inspection_unavailable",
        });
      }
    }
    await Deno.writeTextFile(path, "X-Orbit-Process-ID=8\n");
    try {
      await readRuntime(
        "doctor.inspect-process",
        process,
        processFixture(root, []),
        {},
      );
      throw new Error("Expected foreign refusal");
    } catch (error) {
      assertEquals(error, { error_code: "observation.inspection_unavailable" });
    }
    await Deno.remove(path);
    assertEquals(
      await readRuntime(
        "doctor.inspect-process",
        process,
        processFixture(root, []),
        {},
      ),
      { present: false, status: null, systemd_observations: [] },
    );
    await Deno.mkdir(path);
    try {
      await readRuntime(
        "doctor.inspect-process",
        process,
        processFixture(root, []),
        {},
      );
      throw new Error("Expected unreadable refusal");
    } catch (error) {
      assertEquals(error, { error_code: "observation.inspection_unavailable" });
    }
  } finally {
    await Deno.remove(root, { recursive: true });
  }
});
