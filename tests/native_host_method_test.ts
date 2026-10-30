import { assert, assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import { executeHostMethod } from "../extensions/behaviors/native_host_method.ts";

async function fixture(body: string) {
  const root = await Deno.makeTempDir({ prefix: "native-host-test-" });
  await Deno.mkdir(`${root}/bin`);
  await Deno.copyFile(
    new URL("../bin/native-host-start.py", import.meta.url),
    `${root}/bin/native-host-start.py`,
  );
  const executable = `${root}/command`;
  await Deno.writeTextFile(
    executable,
    `#!/usr/bin/python3\nimport os,sys,json,pathlib,time,signal,subprocess\nroot=pathlib.Path(${
      JSON.stringify(root)
    })\n${body}`,
  );
  await Deno.chmod(executable, 0o700);
  return {
    root,
    runtime: { repo: root, home: root, executable, graceMs: 100 },
  };
}

Deno.test("host method uses literal stdin and a private temporary tree without inheriting credentials", async () => {
  const f = await fixture(`
input=json.load(sys.stdin)
tmp=pathlib.Path(os.environ['TMPDIR']);(tmp/'nested').mkdir();(tmp/'nested'/'file').write_text('fixture')
(root/'proof').write_text(json.dumps({'args':sys.argv[1:],'input':input,'keys':list(os.environ),'tmp':str(tmp)}))
`);
  const previous = Deno.env.get("GH_TOKEN");
  Deno.env.set("GH_TOKEN", "ephemeral_sentinel");
  try {
    const input = { projectId: 20, runId: "@/must/not/be/read-${{ 1 + 1 }}" };
    await executeHostMethod("project_scan", input, undefined, f.runtime);
    const proof = JSON.parse(await Deno.readTextFile(`${f.root}/proof`));
    assertEquals(proof.input, input);
    assertEquals(proof.args.slice(0, 4), [
      "behavior",
      "run",
      "@nckrtl/dependency-scanner",
      "scan",
    ]);
    assert(proof.args.includes("dependency-scanner-20"));
    assert(proof.args.includes("--literal-inputs"));
    assert(!proof.args.includes("--server"));
    for (
      const key of [
        "GH_TOKEN",
        "GITHUB_TOKEN",
        "ORBIT_GIT_TOKEN",
        "SWAMP_SERVER_URL",
        "SWAMP_SERVE_URL",
        "SWAMP_SERVER_TOKEN",
        "SWAMP_SERVE_EXTRA_HEADERS",
      ]
    ) assert(!proof.keys.includes(key));
    await assertRejects(() => Deno.stat(proof.tmp), Deno.errors.NotFound);
  } finally {
    if (previous === undefined) Deno.env.delete("GH_TOKEN");
    else Deno.env.set("GH_TOKEN", previous);
    await Deno.remove(f.root, { recursive: true });
  }
});

Deno.test("native errors expose only known safe codes and remove the scratch tree", async () => {
  const f = await fixture(`
json.load(sys.stdin)
(root/'tmp').write_text(os.environ['TMPDIR'])
print(json.dumps({'code':'method_execution_failed','error':'ephemeral_sentinel'}),file=sys.stderr)
sys.exit(23)
`);
  try {
    const error = await assertRejects(
      () => executeHostMethod("project_work", {}, undefined, f.runtime),
      Error,
      "native_host_method_failed:method_execution_failed",
    );
    assert(!String(error).includes("ephemeral_sentinel"));
    await assertRejects(
      () => Deno.stat(Deno.readTextFileSync(`${f.root}/tmp`)),
      Deno.errors.NotFound,
    );
  } finally {
    await Deno.remove(f.root, { recursive: true });
  }
});

Deno.test("abort reaps a native process and its stubborn descendant before cleanup", async () => {
  const f = await fixture(`
json.load(sys.stdin)
signal.signal(signal.SIGTERM,signal.SIG_IGN)
p=subprocess.Popen([sys.executable,'-c','import os,signal,time;os.setsid();signal.signal(signal.SIGTERM,signal.SIG_IGN);time.sleep(100)'])
(root/'started').write_text(json.dumps({'pid':os.getpid(),'descendant':p.pid,'tmp':os.environ['TMPDIR']}))
time.sleep(100)
`);
  const controller = new AbortController();
  const pending = executeHostMethod(
    "project_work",
    {},
    controller.signal,
    f.runtime,
  );
  try {
    for (let n = 0; n < 100; n++) {
      try {
        await Deno.stat(`${f.root}/started`);
        break;
      } catch {
        await new Promise((r) => setTimeout(r, 10));
      }
    }
    const proof = JSON.parse(await Deno.readTextFile(`${f.root}/started`));
    controller.abort();
    await assertRejects(() => pending, Error, "native_host_method_cancelled");
    for (const pid of [proof.pid, proof.descendant]) {
      try {
        assertEquals(
          (await Deno.readTextFile(`/proc/${pid}/stat`)).split(" ")[2],
          "Z",
        );
      } catch (error) {
        if (!(error instanceof Deno.errors.NotFound)) throw error;
      }
    }
    await assertRejects(() => Deno.stat(proof.tmp), Deno.errors.NotFound);
  } finally {
    controller.abort();
    await pending.catch(() => {});
    await Deno.remove(f.root, { recursive: true });
  }
});

Deno.test("host adapter refuses inputs outside each fixed method contract", async () => {
  for (
    const [action, input] of [
      ["project_scan", { projectId: 0, runId: "run" }],
      ["project_work", { executable: "/bin/sh" }],
      ["package_admit", {
        workItem: "tg-1",
        targets: [],
        token: "must-not-forward",
      }],
    ] as const
  ) {
    await assertRejects(() => executeHostMethod(action, input));
  }
});

Deno.test("normal completion reaps a setsid descendant holding stderr", async () => {
  const f = await fixture(`
json.load(sys.stdin)
p=subprocess.Popen([sys.executable,'-c','import os,signal,time;os.setsid();signal.signal(signal.SIGTERM,signal.SIG_IGN);time.sleep(100)'])
(root/'started').write_text(json.dumps({'descendant':p.pid,'tmp':os.environ['TMPDIR']}))
`);
  const controller = new AbortController();
  const pending = executeHostMethod(
    "project_work",
    {},
    controller.signal,
    f.runtime,
  );
  try {
    for (let n = 0; n < 100; n++) {
      try {
        await Deno.stat(`${f.root}/started`);
        break;
      } catch {
        await new Promise((r) => setTimeout(r, 10));
      }
    }
    const proof = JSON.parse(await Deno.readTextFile(`${f.root}/started`));
    await pending;
    try {
      assertEquals(
        (await Deno.readTextFile(`/proc/${proof.descendant}/stat`)).split(
          " ",
        )[2],
        "Z",
      );
    } catch (error) {
      if (!(error instanceof Deno.errors.NotFound)) throw error;
    }
    await assertRejects(() => Deno.stat(proof.tmp), Deno.errors.NotFound);
  } finally {
    controller.abort();
    await pending.catch(() => {});
    await Deno.remove(f.root, { recursive: true });
  }
});
