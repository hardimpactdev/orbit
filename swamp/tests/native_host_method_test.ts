import { assert, assertEquals, assertRejects } from "jsr:@std/assert@1.0.19";
import {
  invokeNativeModel,
} from "../extensions/behaviors/native_host_method.ts";

const packageInput = {
  workItem: "tg-7",
  targets: [{
    path: ".",
    manager: "composer",
    package: "acme/example",
    version: "2.0.0",
    purpose: "major",
    advisories: [],
    advisoryUrls: [],
  }],
};

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

Deno.test("placed coordinator invokes nested models on its native worker with literal input", async () => {
  const f = await fixture(`
input=json.load(sys.stdin)
(root/'proof').write_text(json.dumps({'args':sys.argv[1:],'input':input,'keys':list(os.environ)}))
`);
  try {
    const input = {
      workItem: "tg-7",
      note: "@/unread-${{ vault.get(default, secret) }}",
    };
    assertEquals(
      await invokeNativeModel(
        {
          definition: "fixture-factory",
          method: "status",
          arguments: input,
        },
        undefined,
        f.runtime,
      ),
      { ok: true },
    );
    const proof = JSON.parse(await Deno.readTextFile(`${f.root}/proof`));
    assertEquals(proof.args.slice(0, 4), [
      "behavior",
      "run",
      "fixture-factory",
      "status",
    ]);
    assertEquals(proof.input, input);
    assert(proof.args.includes("--literal-inputs"));
    assert(!proof.args.includes("--server"));
    assert(!proof.keys.includes("SWAMP_REMOTE_ONLY"));
  } finally {
    await Deno.remove(f.root, { recursive: true });
  }
});

Deno.test("nested model invocation refuses CLI option injection before spawning", async () => {
  for (
    const request of [
      { definition: "--server", method: "status" },
      { definition: "factory", method: "--help" },
      { modelType: "@swamp/git", name: "--repo-dir", method: "status" },
    ]
  ) await assertRejects(() => invokeNativeModel(request));
});

