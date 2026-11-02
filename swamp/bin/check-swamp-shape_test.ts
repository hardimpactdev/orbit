import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import {
  movedLimits,
  proveProxyHttpMove,
  PROXY_HTTP_MOVE,
} from "./swamp-shape-relocations.ts";
import {
  agentPythonFindings,
  agentShellFindings,
  aggregate,
  analyze,
  analyzeTs,
  analyzeYaml,
  GATEWAY_PRIMITIVES,
  gatewayShellFindings,
  isGatewayPrimitive,
  ratchet,
  SCHEMA,
  toolFindings,
  workflowCoverage,
} from "./check-swamp-shape.ts";
const file = "extensions/behaviors/example.ts";
const inventory = {
  "agent.status": "observer",
  "cli.install": "action",
  "caddy.publish": "action",
};
Deno.test("step inputs read one bounded data record, never a query or version history", () => {
  const file = "workflows/workflow-example.yaml";
  const workflow = (candidates: string) =>
    JSON.stringify({
      name: "example",
      jobs: [{
        name: "observe",
        target: "orbit-node-209",
        steps: [{
          name: "context",
          task: {
            type: "behavior",
            behaviorType: "@nckrtl/observation-schedule",
            behaviorName: "select",
            methodName: "select",
            inputs: { candidates },
          },
        }],
      }],
    });
  const rules = (candidates: string) =>
    analyzeYaml(file, workflow(candidates)).findings.map((f) => f.rule);
  for (
    const unbounded of [
      `\${{ data.query('specName == "context"').map(c, c.attributes) }}`,
      `\${{ data.findBySpec("m", "context") }}`,
      `\${{ data.findByTag("k", "v") }}`,
      `\${{ data.listVersions("m", "context") }}`,
    ]
  ) assert(rules(unbounded).includes("unbounded-data-input"), unbounded);
  assertEquals(
    rules(
      `\${{ [data.latest("observation-context-fleet", "context").?attributes.orValue(null)].filter(c, c != null) }}`,
    ).includes("unbounded-data-input"),
    false,
  );
});
const pollFile = "scripts/poll.py";
const externalPoll = `import time
import json
import urllib.request

ORIGIN = 'https://api.provider.example/v1/'

def api(method, path):
    request = urllib.request.Request(ORIGIN + path, method=method)
    with urllib.request.urlopen(request, timeout=5) as response:
        raw = response.read()
        return json.loads(raw)

def wait_started():
    while True:
        state = api('GET', 'server/example')['state']
        if state == 'started':
            return
        if state not in ('stopped', 'maintenance'):
            raise RuntimeError('provider entered a bad terminal state')
        time.sleep(3)
`;
const phaseWorkflow = (runs: string[], extraSteps: unknown[] = []) =>
  JSON.stringify({
    name: "example",
    jobs: [{
      name: "phase",
      target: "orbit-node-209",
      steps: [
        ...extraSteps,
        ...runs.map((run, i) => ({
          name: `phase-${i}`,
          task: {
            type: "behavior",
            behaviorType: "command/shell",
            behaviorName: `phase-${i}`,
            methodName: "execute",
            inputs: {
              run,
              env: {
                ORBIT_PROVIDER_SCRIPT: "${{ steps.input.outputs.script }}",
              },
            },
          },
        })),
      ],
    }],
  });
async function phaseControls(files: Map<string, string>) {
  return [...analyze(files, inventory).values()].flatMap((a) => a.findings)
    .concat(await toolFindings(files)).filter((f) =>
      f.rule === "custom-control"
    );
}
// Frozen provider shape 2ee89ab0c2ad4b6abc37fe83485229ba062a15cf; source analysis only.
const incusPoll =
  "\"\"\"Observe one admitted Incus VM until started; the caller supplies a literal timeout.\"\"\"\nimport json\nimport subprocess\nimport sys\nimport time\n\n\ndef observe(project, name):\n    rows = json.loads(subprocess.check_output([\n        '/usr/bin/sudo', '-n', '--', '/usr/bin/incus', '--project', project,\n        'list', '--format', 'json', '--', name,\n    ], text=True, stderr=subprocess.PIPE))\n    if not isinstance(rows, list) or len(rows) != 1:\n        raise SystemExit('task_vm.provider_identity_invalid: expected exactly one Incus VM')\n    row = rows[0]\n    if row.get('name') != name or row.get('type') != 'virtual-machine':\n        raise SystemExit('task_vm.provider_identity_invalid: Incus returned another instance')\n    return row\n\n\ndef wait_started(project, name):\n    while True:\n        row = observe(project, name)\n        state = row['status']\n        if state == 'Running':\n            return [{'name': row['name'], 'type': row['type'], 'status': state}]\n        if state not in ('Stopped', 'Starting'):\n            raise SystemExit('task_vm.not_settled: Incus reached an unsupported start state')\n        time.sleep(1)\n\n\ndef main():\n    try:\n        print(json.dumps(wait_started(sys.argv[1], sys.argv[2])))\n    except (subprocess.CalledProcessError, OSError):\n        print('task_vm.provider_observation_failed: Incus state read failed', file=sys.stderr)\n        return 1\n    except (ValueError, KeyError, IndexError, TypeError):\n        print('task_vm.provider_observation_invalid: invalid Incus state receipt', file=sys.stderr)\n        return 1\n    return 0\n\n\nif __name__ == '__main__':\n    raise SystemExit(main())\n";
const incusFile = "extensions/behaviors/provider/scripts/incus_wait.py";
const incusArgv =
  '${{ inputs.action == "start" ? ["timeout", "120", "/usr/bin/python3", "-I", "-c", steps.input.outputs.start_script, inputs.intent.vm.project, inputs.intent.name] : steps.input.outputs.observe_argv }}';
function incusFiles(
  source = incusPoll,
  argv: unknown = incusArgv,
  second?: unknown,
) {
  const exec = (value: unknown, name: string) => ({
    name,
    task: {
      type: "behavior",
      behaviorType: "@swamp/ssh",
      behaviorName: name,
      methodName: "exec",
      inputs: { argv: value, timeoutSec: 180 },
    },
  });
  return new Map([
    [incusFile, source],
    [
      "extensions/behaviors/provider/metadata.ts",
      `export const behavior = {type: '@example/provider', methods: {read: {async execute(_args, ctx) {return {dataHandles: [await ctx.writeResource('input', 'input', {start_script: await Deno.readTextFile(ctx.extensionFile('scripts/incus_wait.py'))})]};}}}};`,
    ],
    [
      "workflows/incus.yaml",
      JSON.stringify({
        name: "example",
        queueTimeout: 10,
        jobs: [{
          name: "power",
          target: "orbit-node-209",
          steps: [
            {
              name: "input",
              task: {
                type: "behavior",
                behaviorType: "@example/provider",
                behaviorName: "input",
                methodName: "read",
                inputs: {},
              },
            },
            exec(argv, "incus-after"),
            ...(second === undefined ? [] : [exec(second, "another")]),
          ],
        }],
      }),
    ],
  ]);
}
Deno.test("official SSH proves the shipped Incus list poll under a literal argv timeout", async () => {
  assertEquals(await phaseControls(incusFiles()), []);
  assertEquals(
    await phaseControls(
      incusFiles(incusPoll, [
        "timeout",
        "120",
        "/usr/bin/python3",
        "-I",
        "-c",
        "${{ steps.input.outputs.start_script }}",
        "default",
        "vm",
      ]),
    ),
    [],
  );
});
for (
  const [name, source] of [
    [
      "retried mutation",
      incusPoll.replace(
        "'list', '--format', 'json'",
        "'start', '--format', 'json'",
      ),
    ],
    [
      "extra mutation",
      incusPoll.replace(
        "row = observe(project, name)",
        "subprocess.check_call(['incus', 'start', name])\n        row = observe(project, name)",
      ),
    ],
    [
      "shell command",
      incusPoll.replace("text=True,", "shell=True, text=True,"),
    ],
    [
      "unchecked command",
      incusPoll.replace(
        "subprocess.check_output",
        "subprocess.getoutput",
      ),
    ],
    [
      "suppressed read failure",
      incusPoll.replace(
        "        row = observe(project, name)",
        "        try:\n            row = observe(project, name)\n        except Exception:\n            row = {'status': 'Starting'}",
      ),
    ],
    [
      "computed interval",
      incusPoll.replace("time.sleep(1)", "time.sleep(interval)"),
    ],
  ]
) {
  Deno.test(`Incus poll rejects ${name}`, async () => {
    assert((await phaseControls(incusFiles(source))).length > 0);
  });
}
for (
  const [name, argv, second] of [
    [
      "missing timeout",
      incusArgv.replace('["timeout", "120", ', "["),
      undefined,
    ],
    [
      "computed timeout",
      incusArgv.replace('"120"', "inputs.budget"),
      undefined,
    ],
    [
      "second unbounded invocation",
      incusArgv,
      incusArgv.replace('["timeout", "120", ', "["),
    ],
    [
      "unbounded conditional branch",
      incusArgv.replace(
        "steps.input.outputs.observe_argv",
        '["/usr/bin/python3", "-I", "-c", steps.input.outputs.start_script]',
      ),
      undefined,
    ],
  ]
) {
  Deno.test(`Incus argv rejects ${name}`, async () => {
    assert(
      (await phaseControls(incusFiles(incusPoll, argv, second))).length >
        0,
    );
  });
}
Deno.test("a concatenated conditional branch cannot hide an unbounded body invocation", async () => {
  const argv = incusArgv.replace(
    "steps.input.outputs.observe_argv",
    '["/usr/bin/python3", "-I", "-c", steps.input.outputs.start_script] + []',
  );
  assert((await phaseControls(incusFiles(incusPoll, argv))).length > 0);
});
for (const builtin of ["isinstance", "len"]) Deno.test(`Incus poll rejects a shadowed ${builtin} guard`, async () => {
  const source = incusPoll.replace("def observe(", `def ${builtin}(*args):\n    subprocess.check_call(['/usr/bin/incus', 'start', 'vm'])\n    return True\n\ndef observe(`);
  assert((await phaseControls(incusFiles(source))).length > 0);
});
for (const [name, value, ssh] of [
  ["bracket step", '${{ steps["input"].outputs.start_script }}', false],
  ["history record", '${{ data.latest("input", "input").attributes.start_script }}', false],
  ["dynamic producer output", '${{ steps.input.outputs[inputs.field] }}', false],
  ["dynamic producer and field", '${{ ["/usr/bin/python3", "-I", "-c", steps[inputs.producer].outputs[inputs.key]] }}', true],
  ["fully dynamic step selectors", '${{ steps[inputs.producer][inputs.outputs][inputs.key] }}', false],
  ["fully dynamic history selectors", '${{ data.latest("input", "input")[inputs.attributes][inputs.key] }}', false],
  ["dynamic history field", '${{ data.latest("input", "input").attributes[inputs.field] }}', false],
  ["bracket SSH argv", '${{ ["/usr/bin/python3", "-c", steps["input"].outputs.start_script] }}', true],
  ["bracket output", '${{ steps.input.outputs["start_script"] }}', false],
  ["escaped bracket output", '${{ steps.input.outputs["start_\\u0073cript"] }}', false],
  ["joined bracket output", '${{ steps.input.outputs["start_" + "script"] }}', false],
  ["concatenation", "${{ steps.input.outputs.start_script + '' }}", false],
] as const) Deno.test(`body-key accounting rejects an additional ${name} use`, async () => {
  const files = incusFiles();
  const workflow = JSON.parse(files.get("workflows/incus.yaml")!);
  workflow.jobs[0].steps.push({name: "hidden", task: {
    type: "behavior", behaviorType: ssh ? "@swamp/ssh" : "command/shell",
    behaviorName: "hidden", methodName: ssh ? "exec" : "execute",
    inputs: ssh ? {argv: value} : {run: 'python3 -c "$SCRIPT"', env: {SCRIPT: value}},
  }});
  files.set("workflows/incus.yaml", JSON.stringify(workflow));
  assert((await phaseControls(files)).length > 0);
});
Deno.test("Incus interval equal to or longer than the timeout is refused", async () => {
  for (const seconds of [120, 121]) {
    assert((await phaseControls(incusFiles(incusPoll.replace("time.sleep(1)", `time.sleep(${seconds})`)))).length > 0);
  }
});
Deno.test("an additional shell use of an SSH script body invalidates its bound", async () => {
  const files = incusFiles();
  const workflow = JSON.parse(files.get("workflows/incus.yaml")!);
  workflow.jobs[0].steps.push({
    name: "unbounded",
    task: {
      type: "behavior",
      behaviorType: "command/shell",
      behaviorName: "unbounded",
      methodName: "execute",
      inputs: {
        run: 'python3 -c "$SCRIPT"',
        env: { SCRIPT: "${{ steps.input.outputs.start_script }}" },
      },
    },
  });
  files.set("workflows/incus.yaml", JSON.stringify(workflow));
  assert((await phaseControls(files)).length > 0);
});
Deno.test("Incus reads are not admitted by a local shell timeout or another transport", async () => {
  const files = incusFiles();
  files.set(
    "workflows/incus.yaml",
    phaseWorkflow([`timeout 120 python3 ${incusFile}`]),
  );
  assert((await phaseControls(files)).length > 0);
  const other = incusFiles();
  other.set(
    "workflows/incus.yaml",
    other.get("workflows/incus.yaml")!.replaceAll(
      '"@swamp/ssh"',
      '"@example/ssh"',
    ),
  );
  assert((await phaseControls(other)).length > 0);
});

Deno.test("phase script accepts a literal-bounded external-state poll and a shorter single wait", async () => {
  const files = new Map([
    [
      "workflows/phase.yaml",
      phaseWorkflow([
        "exec timeout 30 /usr/bin/python3 -B scripts/poll.py",
        "bash scripts/grace.sh",
      ]),
    ],
    [pollFile, externalPoll],
    ["scripts/grace.sh", "#!/bin/bash\ntimeout 35 /usr/bin/sleep 31\n"],
  ]);
  assertEquals(await phaseControls(files), []);
});
Deno.test("Python polls prove external origins and propagate HTTP errors before reading state", async () => {
  for (const client of ["requests", "httpx"]) {
    const checked = externalPoll.slice(
      externalPoll.indexOf("def wait_started"),
    )
      .replace(
        "state = api('GET', 'server/example')['state']",
        `response = ${client}.get('https://api.provider.example/state', timeout=5)
        response.raise_for_status()
        state = response.json()['state']`,
      );
    const source = `import time\nimport ${client}\n${checked}`;
    const findings = (script: string) =>
      phaseControls(
        new Map([
          [
            "workflows/phase.yaml",
            phaseWorkflow(["timeout 30 python3 scripts/poll.py"]),
          ],
          [pollFile, script],
        ]),
      );
    assertEquals(await findings(source), []);
    for (
      const rejected of [
        source.replace("        response.raise_for_status()\n", ""),
        source.replace("response.json()['state']", "response.text")
          .replace("        response.raise_for_status()\n", ""),
        source.replace(
          "        response.raise_for_status()",
          "        if response.status_code == 200:\n            response.raise_for_status()",
        ),
        source.replace(
          "        response.raise_for_status()",
          "        try:\n            response.raise_for_status()\n        except Exception:\n            pass",
        ),
        source.replace("api.provider.example", "gw.orbit"),
        source.replace("api.provider.example", "api.gateway.example"),
        source.replace("api.provider.example", "10.7.0.14"),
        source.replace("api.provider.example", "localhost"),
        source.replace(
          "https://api.provider.example",
          "http://api.provider.example",
        ),
        source.replace("import " + client, ""),
      ]
    ) assert((await findings(rejected)).length > 0, rejected);
  }
});
for (const [name, read] of [
  ["httpx Gateway state", "httpx.get('https://gw.orbit/api/v1/operations/5').text"],
  ["requests unchecked HTTP error body", "requests.get('https://api.provider.example/state').text"],
]) {
  Deno.test(`phase poll rejects ${name}`, async () => {
    const source = "import time\nimport httpx\nimport requests\n" +
      externalPoll.slice(externalPoll.indexOf("def wait_started"))
        .replace("api('GET', 'server/example')['state']", read);
    assert((await phaseControls(new Map([
      ["workflows/phase.yaml", phaseWorkflow(["timeout 30 python3 scripts/poll.py"])],
      [pollFile, source],
    ]))).length > 0);
  });
}
Deno.test("poll proofs follow only sibling shipped modules and reject tolerant helpers", async () => {
  const helpers = externalPoll.slice(0, externalPoll.indexOf("def wait_started"));
  const waiting = externalPoll.slice(externalPoll.indexOf("def wait_started"));
  const findings = (source: string, helper: string, path = "scripts/provider_http.py") => phaseControls(new Map([
    ["workflows/phase.yaml", phaseWorkflow(["timeout 30 python3 scripts/poll.py"])],
    [pollFile, source],
    [path, helper],
  ]));
  for (const imported of ["from provider_http import api", "from .provider_http import api", "import provider_http"]) {
    const call = imported === "import provider_http" ? waiting.replace("api(", "provider_http.api(") : waiting;
    assertEquals(await findings(`import time\n${imported}\n${call}`, helpers), []);
  }
  const source = "import time\nfrom provider_http import api\n" + waiting;
  assert((await findings(source, helpers, "other/provider_http.py")).length > 0);
  assert((await findings(source, helpers.replace("api.provider.example", "gw.orbit"))).length > 0);
  const tolerant = helpers.replace("    with urllib.request.urlopen", "    try:\n        with urllib.request.urlopen")
    .replace("        raw = response.read()", "            raw = response.read()")
    .replace("        return json.loads(raw)", "            return json.loads(raw)\n    except Exception:\n        return {'state': 'maintenance'}");
  assert((await findings(source, tolerant)).length > 0);
});
Deno.test("the shipped UpCloud strict sibling read passes; its tolerant API cannot poll", async () => {
  const helper = Deno.readTextFileSync(new URL("../extensions/behaviors/operations/task-vm-lifecycle/scripts/upcloud.py", import.meta.url));
  const waiting = externalPoll.slice(externalPoll.indexOf("def wait_started"));
  const findings = (method: string) => phaseControls(new Map([
    ["workflows/phase.yaml", phaseWorkflow(["timeout 30 python3 scripts/poll.py"])],
    ["scripts/upcloud.py", helper],
    [pollFile, `import time\nfrom upcloud import ${method}\n` + waiting.replace("api('GET', 'server/example')['state']", method === "poll_read" ? "poll_read('server/example')['server']['state']" : "api('GET', 'server/example')['server']['state']")],
  ]));
  assertEquals(await findings("poll_read"), []);
  assert((await findings("api")).length > 0);
});
Deno.test("an enclosing context manager cannot suppress a failed poll", async () => {
  const start = externalPoll.indexOf("def wait_started():");
  const header = externalPoll.slice(0, start) + "from contextlib import suppress\n\n";
  const body = externalPoll.slice(start).split("\n");
  const suppressed = header + body[0] + "\n    with suppress(Exception):\n" +
    body.slice(1).map((line) => "    " + line).join("\n");
  assert((await phaseControls(new Map([
    ["workflows/phase.yaml", phaseWorkflow(["timeout 30 python3 scripts/poll.py"])],
    [pollFile, suppressed],
  ]))).length > 0);
});
Deno.test("provider wrappers are proved from source, never from their name", async () => {
  const findings = (source: string) =>
    phaseControls(
      new Map([
        [
          "workflows/phase.yaml",
          phaseWorkflow(["timeout 30 python3 scripts/poll.py"]),
        ],
        [pollFile, source],
      ]),
    );
  assertEquals(await findings(externalPoll), []);
  for (
    const rejected of [
      externalPoll.replace(
        "def api(method, path):",
        "@retry\ndef api(method, path):",
      ),
      externalPoll.replace("api.provider.example", "gw.orbit"),
      externalPoll.replace("ORIGIN + path", "path + ORIGIN"),
      externalPoll.replace(
        "with urllib.request.urlopen(request, timeout=5) as response:\n        raw = response.read()\n        return json.loads(raw)",
        "try:\n        with urllib.request.urlopen(request, timeout=5) as response:\n            raw = response.read()\n            return json.loads(raw)\n    except Exception:\n        return {'state': 'maintenance'}",
      ),
      externalPoll.replace(
        "request = urllib.request.Request(ORIGIN + path, method=method)",
        "request = urllib.request.Request(ORIGIN + path, method='POST')",
      ),
      externalPoll.replace(
        "return json.loads(raw)",
        "return {'state': 'started'}",
      ),
      externalPoll.replace(
        "state = api('GET', 'server/example')['state']",
        "state = other.get(api('GET', 'server/example'))",
      ),
      externalPoll.replace(
        "def api(method, path):",
        "def api(method, path):\n    return {'state': 'maintenance'}",
      ),
    ]
  ) assert((await findings(rejected)).length > 0, rejected);
});
Deno.test("shell polls use the same parsed external origin and real errexit flags", async () => {
  const findings = (source: string) =>
    phaseControls(
      new Map([
        [
          "workflows/phase.yaml",
          phaseWorkflow(["bash scripts/poll.sh"]),
        ],
        ["scripts/poll.sh", source],
      ]),
    );
  const body = shellPoll.replace("#!/bin/bash\nset -e\n", "");
  assertEquals(await findings(`timeout 30 bash -e -c '${body}'`), []);
  assertEquals(
    await findings(
      `timeout 30 bash -e -c '${body.replace("/state", "/gateway-state")}'`,
    ),
    [],
  );
  for (const option of ["--noprofile", "--restricted", "--rcfile"]) {
    assert(
      (await findings(`timeout 30 bash ${option} -c '${body}'`)).length >
        0,
      option,
    );
  }
  for (
    const origin of [
      "gw.orbit",
      "10.7.0.14",
      "127.0.0.2",
      "[::1]",
      "localhost",
      "api.internal",
      "provider.example@gw.orbit",
      "provider.example:444",
    ]
  ) {
    assert(
      (await findings(
        `timeout 30 bash -e -c '${body.replace("provider.example", origin)}'`,
      )).length > 0,
      origin,
    );
  }
});
Deno.test("phase script resolves the shipped metadata asset through the step environment", async () => {
  const files = new Map([
    [
      "extensions/behaviors/provider/assets.ts",
      `export const behavior = {
      type: "@example/provider", methods: {input: {execute(args, ctx) {
        return {script: ctx.extensionFile("scripts/upcloud.py")};
      }}}};`,
    ],
    ["extensions/behaviors/provider/scripts/upcloud.py", externalPoll],
    [
      "workflows/phase.yaml",
      phaseWorkflow([
        'exec timeout 30 /usr/bin/python3 -B "$ORBIT_PROVIDER_SCRIPT" wait-started',
      ], [
        {
          name: "input",
          task: {
            type: "behavior",
            behaviorType: "@example/provider",
            methodName: "input",
          },
        },
      ]),
    ],
  ]);
  assertEquals(await phaseControls(files), []);
  files.set(
    "workflows/phase.yaml",
    files.get("workflows/phase.yaml")!.replace("timeout 30 ", ""),
  );
  assert((await phaseControls(files)).length > 0);
});
const shellPoll = `#!/bin/bash
set -e
while true; do
    state=$(curl --fail --silent https://provider.example/state)
    case "$state" in
        started) exit 0;;
        failed) echo "provider entered a bad terminal state" >&2; exit 1;;
    esac
    sleep 3
done
`;
Deno.test("phase script accepts shell state polling and a Python one-shot wait under a step timeout", async () => {
  assertEquals(
    await phaseControls(
      new Map([
        [
          "workflows/phase.yaml",
          phaseWorkflow([
            "timeout 30 bash scripts/poll.sh",
            "timeout 35 python3 scripts/grace.py",
          ]),
        ],
        ["scripts/poll.sh", shellPoll],
        ["scripts/grace.py", "import time\ntime.sleep(31)\n"],
      ]),
    ),
    [],
  );
});
Deno.test("phase script does not allow action retries, swallowed request failures or a disconnected state selector", async () => {
  for (
    const source of [
      shellPoll.replace("state=$(curl", "other=$(curl"),
      shellPoll.replace("--fail --silent", "--silent"),
      shellPoll.replace("--fail --silent", "--fail --retry 3"),
      shellPoll.replace("sleep 3", 'sleep "$INTERVAL"'),
      shellPoll.replace("set -e", "set +e"),
      shellPoll.replace("set -e", "set -e\nset +e"),
      shellPoll.replace("set -e", "") + "set -e\n",
      shellPoll.replace("--fail --silent", "--fail --form key=value"),
      shellPoll.replace("--fail --silent", "--fail --json '{}'"),
      shellPoll.replace("--fail --silent", "--fail -sXPOST"),
      shellPoll.replace("set -e", "set -e\ncommand set +e"),
      shellPoll.replace("/state)", "/state & )"),
      shellPoll.replace(
        'echo "provider entered a bad terminal state" >&2; ',
        "",
      ),
      shellPoll.replace("    sleep 3", "    deploy\n    sleep 3"),
      shellPoll.replace("    state=", "    state=").replace(
        "/state)",
        "/state) || true",
      ),
    ]
  ) {
    assert(
      (await phaseControls(
        new Map([
          [
            "workflows/phase.yaml",
            phaseWorkflow(["timeout 30 bash scripts/poll.sh"]),
          ],
          ["scripts/poll.sh", source],
        ]),
      )).length > 0,
      source,
    );
  }
});
Deno.test("phase controls reject inline waits and files without a literal bound on every invocation", async () => {
  for (
    const runs of [
      [],
      ["python3 scripts/poll.py"],
      ["timeout $BUDGET python3 scripts/poll.py"],
      ["timeout 30 python3 scripts/poll.py", "python3 scripts/poll.py"],
      ["timeout 30 python3 scripts/poll.py; echo done"],
      ["timeout 30 python3 scripts/poll.py $(python3 scripts/poll.py)"],
    ]
  ) {
    assert(
      (await phaseControls(
        new Map([
          ["workflows/phase.yaml", phaseWorkflow(runs)],
          [pollFile, externalPoll],
        ]),
      )).length > 0,
      JSON.stringify(runs),
    );
  }
  for (
    const run of [
      "timeout 35 sleep 31",
      "timeout 30 bash -c 'while true; do sleep 3; done'",
    ]
  ) {
    assert(
      (await phaseControls(
        new Map([["workflows/phase.yaml", phaseWorkflow([run])]]),
      )).length > 0,
      run,
    );
  }
});
Deno.test("phase file bounds do not bless another script's unbounded call", async () => {
  for (
    const wrapper of [
      "#!/bin/bash\npython3 scripts/poll.py\n",
      "import poll\npoll.wait_started()\n",
      "from scripts.poll import wait_started\nwait_started()\n",
      "from .poll import wait_started\nwait_started()\n",
      "from scripts import (poll,)\npoll.wait_started()\n",
    ]
  ) {
    const name = wrapper.startsWith("#!")
      ? "scripts/wrapper.sh"
      : "scripts/wrapper.py";
    assert(
      (await phaseControls(
        new Map([
          [
            "workflows/phase.yaml",
            phaseWorkflow([
              "timeout 30 python3 scripts/poll.py",
              `bash ${name}`,
            ]),
          ],
          [pollFile, externalPoll],
          [name, wrapper],
        ]),
      )).length > 0,
      wrapper,
    );
  }
});
Deno.test("phase budgets follow bounded script imports but not unused test harness imports", async () => {
  const wrapper = "from scripts.poll import wait_started\nwait_started()\n";
  assertEquals(
    await phaseControls(
      new Map([
        [
          "workflows/phase.yaml",
          phaseWorkflow(["timeout 30 python3 scripts/wrapper.py"]),
        ],
        [pollFile, externalPoll],
        ["scripts/wrapper.py", wrapper],
        ["tests/test_provider.py", "import poll\n"],
      ]),
    ),
    [],
  );
  assert(
    (await phaseControls(
      new Map([
        [
          "workflows/phase.yaml",
          phaseWorkflow([
            "timeout 30 python3 scripts/poll.py",
            "python3 scripts/wrapper.py",
          ]),
        ],
        [pollFile, externalPoll],
        ["scripts/wrapper.py", "import intermediate\n"],
        ["scripts/intermediate.py", wrapper],
      ]),
    )).length > 0,
  );
});
Deno.test("phase controls reject computed intervals and failed-command retries", async () => {
  for (
    const source of [
      externalPoll.replace("time.sleep(3)", "time.sleep(interval)"),
      externalPoll.replace("time.sleep(3)", "time.sleep(1 + 2)"),
      externalPoll.replace("time.sleep(3)", "time.sleep(30)"),
      externalPoll.replace("time.sleep(3)", "time.sleep(0)"),
      externalPoll.replace("import time", "import asyncio").replace(
        "time.sleep(3)",
        "asyncio.sleep(3)",
      ),
      externalPoll.replace(
        "time.sleep(3)",
        "if False:\n            time.sleep(3)",
      ),
      externalPoll.replace(
        "        if state ==",
        "        state = 'maintenance'\n        if state ==",
      ),
      externalPoll.replace(
        "state = api('GET', 'server/example')['state']",
        "state = api('POST', 'server/example')['state']",
      ),
      externalPoll.replace(
        "state = api('GET', 'server/example')['state']",
        "try:\n            state = api('GET', 'server/example')['state']\n        except OSError:\n            continue",
      ),
    ]
  ) {
    assert(
      (await phaseControls(
        new Map([
          [
            "workflows/phase.yaml",
            phaseWorkflow(["timeout 30 python3 scripts/poll.py"]),
          ],
          [pollFile, source],
        ]),
      )).length > 0,
      source,
    );
  }
  for (
    const source of [
      "#!/bin/bash\nsleep 31\n",
      '#!/bin/bash\ntimeout 35 sleep "$INTERVAL"\n',
      "#!/bin/bash\ntimeout 35 bash -c 'until curl --fail https://provider.example; do sleep 3; done'\n",
      "#!/bin/bash\ntimeout 35 sleep 31\nflock /tmp/workflow.lock true\n",
      "#!/bin/bash\ntimeout 35 sleep 31\npython3 -c 'import fcntl; fcntl.flock(0, fcntl.LOCK_EX)'\n",
      "#!/bin/bash\ntimeout 35 sleep 31\npython3 -c 'import time; time.sleep(99)'\n",
    ]
  ) {
    assert(
      (await phaseControls(
        new Map([
          [
            "workflows/phase.yaml",
            phaseWorkflow(["bash scripts/grace.sh"]),
          ],
          ["scripts/grace.sh", source],
        ]),
      )).length > 0,
      source,
    );
  }
});
Deno.test("Route private DNS grace allows only the approved file, step and literal command", () => {
  const file = "workflows/workflow-route-dns-grace.yaml";
  const workflow = {
    name: "route-dns-grace",
    jobs: [{
      name: "grace",
      steps: [{
        name: "wait-private-dns-ttl",
        labels: { role: "swamp" },
        task: {
          type: "behavior",
          behaviorType: "command/shell",
          behaviorName: "route-dns-grace",
          methodName: "execute",
          inputs: { run: "/usr/bin/sleep 31" },
        },
      }],
    }],
  };
  const source = JSON.stringify(workflow);
  assertEquals(analyzeYaml(file, source).findings, []);
  const labelsOnly = JSON.parse(source);
  const targetOnly = JSON.parse(source);
  delete targetOnly.jobs[0].steps[0].labels;
  targetOnly.jobs[0].steps[0].target = "orbit-node-209";
  assertEquals(analyzeYaml(file, JSON.stringify(targetOnly)).findings, []);
  const rejected = (path: string, source: string) =>
    assert(
      analyzeYaml(path, source).findings.some((f) =>
        f.rule === "custom-control"
      ),
      `${path}: ${source}`,
    );
  const unplaced = structuredClone(labelsOnly);
  delete unplaced.jobs[0].steps[0].labels;
  rejected(file, JSON.stringify(unplaced));
  for (const labels of [{ node: "18" }, { node: 209 }, { node: "209" }, { role: "swamp", node: "209" }, {}]) {
    const changed = structuredClone(labelsOnly);
    changed.jobs[0].steps[0].labels = labels;
    rejected(file, JSON.stringify(changed));
  }
  const foreignTarget = structuredClone(targetOnly);
  foreignTarget.jobs[0].steps[0].target = "orbit-node-18";
  rejected(file, JSON.stringify(foreignTarget));
  for (const scope of ["workflow", "job"]) {
    const changed = structuredClone(labelsOnly);
    const placement = scope === "workflow" ? changed : changed.jobs[0];
    placement.target = "orbit-node-18";
    rejected(file, JSON.stringify(changed));
    placement.target = "orbit-node-209";
    rejected(file, JSON.stringify(changed));
  }
  for (const path of [
    "workflows/other.yaml",
    "models/workflow-route-dns-grace.yaml",
    "workflows/nested/workflow-route-dns-grace.yaml",
  ]) rejected(path, source);
  for (const [from, to] of [
    ['"name":"grace"', '"name":"other"'],
    ['"name":"wait-private-dns-ttl"', '"name":"other"'],
    ['"role":"swamp"', '"role":"other"'],
    ['"role":"swamp"', '"role":"swamp","other":"value"'],
    ['"labels":{"role":"swamp"}', '"target":"orbit-node-210"'],
    ['"type":"behavior"', '"type":"model_method"'],
    ['"behaviorType":"command/shell"', '"behaviorType":"@example/shell"'],
    ['"behaviorName":"route-dns-grace"', '"behaviorName":"other"'],
    ['"methodName":"execute"', '"methodName":"other"'],
    ['"inputs":{', '"globalArgs":{},"inputs":{'],
    ['"run":', '"env":{},"run":'],
  ]) rejected(file, source.replace(from, to));
  for (const run of [
    "/usr/bin/sleep 30",
    "sleep 31",
    "/usr/bin/sleep -- 31",
    "sudo /usr/bin/sleep 31",
    "/usr/bin/sleep 31; echo done",
    "/usr/bin/sleep 31\necho done",
    "/usr/bin/sleep 31 # comment",
    "/usr/bin/sleep ${{ inputs.seconds }}",
    "sh -c '/usr/bin/sleep 31'",
  ]) {
    const changed = structuredClone(workflow);
    changed.jobs[0].steps[0].task.inputs.run = run;
    rejected(file, JSON.stringify(changed));
  }
  const duplicateStep = structuredClone(workflow);
  duplicateStep.jobs[0].steps.push(duplicateStep.jobs[0].steps[0]);
  rejected(file, JSON.stringify(duplicateStep));
  const duplicateJob = structuredClone(workflow);
  duplicateJob.jobs.push(duplicateJob.jobs[0]);
  rejected(file, JSON.stringify(duplicateJob));
});
const officialSshFixture = Deno.readTextFileSync(
  new URL("../tests/fixtures/shape/official-ssh-vault.yaml", import.meta.url),
);
Deno.test("official SSH vault stdin and env require a 209 worker and closed execution descriptors", () => {
  const vault =
    "${{ vault.get(inputs.execution_secrets.material.vault, inputs.execution_secrets.material.key) }}";
  const workflow = {
    name: "route-environment-write",
    vaults: ["orbit-control-inputs"],
    inputs: {
      type: "object",
      additionalProperties: false,
      required: ["execution_secrets"],
      properties: {
        execution_secrets: {
          type: "object",
          additionalProperties: false,
          required: ["material"],
          properties: {
            material: {
              type: "object",
              additionalProperties: false,
              required: ["vault", "key"],
              properties: {
                vault: { type: "string", enum: ["orbit-control-inputs"] },
                key: { type: "string", pattern: "^material-[1-9][0-9]*$" },
              },
            },
          },
        },
      },
    },
    jobs: [{
      name: "environment",
      target: "orbit-node-209",
      steps: [{
        name: "write",
        task: {
          type: "behavior",
          behaviorType: "@swamp/ssh",
          behaviorName: "route-environment",
          methodName: "exec",
          inputs: {
            hosts: "name:target",
            argv: ["python3", "write_environment.py"],
            sudo: true,
            stdin: vault,
          },
        },
      }],
    }],
  };
  const file = "workflows/workflow-route-environment-write.yaml";
  const source = JSON.stringify(workflow);
  assertEquals(analyzeYaml(file, source).findings, []);
  assertEquals(
    analyzeYaml(
      file,
      source.replace('"type":"behavior"', '"type":"model_method"')
        .replace('"behaviorType":', '"modelType":'),
    ).findings,
    [],
  );
  assertEquals(
    analyzeYaml(file, source.replace(vault, "public input")).findings,
    [],
  );
  const labelled = JSON.parse(source);
  delete labelled.jobs[0].target;
  labelled.jobs[0].labels = { node: "209" };
  assertEquals(analyzeYaml(file, JSON.stringify(labelled)).findings, []);
  const inherited = JSON.parse(source);
  delete inherited.jobs[0].target;
  inherited.target = "orbit-node-209";
  assertEquals(analyzeYaml(file, JSON.stringify(inherited)).findings, []);
  for (const scope of ["workflow", "job", "step"]) {
    const changed = JSON.parse(source);
    delete changed.jobs[0].target;
    const placement = scope === "workflow"
      ? changed
      : scope === "job"
      ? changed.jobs[0]
      : changed.jobs[0].steps[0];
    placement.target = "orbit-node-209";
    placement.labels = { node: "209" };
    assertEquals(analyzeYaml(file, JSON.stringify(changed)).findings, []);
    placement.labels.node = "18";
    assert(
      analyzeYaml(file, JSON.stringify(changed)).findings.some((
        finding,
      ) => finding.rule === "vault-placement"),
      `${scope}: conflicting selectors cannot prove Node 209`,
    );
  }
  const scriptEnv = JSON.parse(source);
  scriptEnv.jobs[0].steps[0].task.methodName = "script";
  scriptEnv.jobs[0].steps[0].task.inputs = {
    hosts: "name:target",
    script: "print('public')",
    interpreter: "python3",
    env: { MATERIAL: vault },
  };
  assertEquals(analyzeYaml(file, JSON.stringify(scriptEnv)).findings, []);
  for (const method of ["copy", "resolve"]) {
    const changed = structuredClone(scriptEnv);
    changed.jobs[0].steps[0].task.methodName = method;
    assert(
      analyzeYaml(file, JSON.stringify(changed)).findings.some((f) =>
        f.rule === "vault-placement"
      ),
      method,
    );
  }
  for (const target of [undefined, "orbit-node-1", "orbit-node-18", "${{ inputs.worker }}"]) {
    for (const channel of ["stdin", "env"]) {
      const changed = JSON.parse(source);
      if (target === undefined) delete changed.jobs[0].target;
      else changed.jobs[0].target = target;
      if (channel === "env") {
        delete changed.jobs[0].steps[0].task.inputs.stdin;
        changed.jobs[0].steps[0].task.inputs.env = { MATERIAL: vault };
      }
      assert(
        analyzeYaml(file, JSON.stringify(changed)).findings.some((f) =>
          f.rule === "vault-placement"
        ),
        `${channel}: ${target}`,
      );
    }
  }
  const overridden = JSON.parse(source);
  overridden.jobs[0].steps[0].target = "orbit-node-18";
  assert(
    analyzeYaml(file, JSON.stringify(overridden)).findings.some((f) =>
      f.rule === "vault-placement"
    ),
  );
  overridden.jobs[0].labels = { node: "209" };
  assert(
    analyzeYaml(file, JSON.stringify(overridden)).findings.some((f) =>
      f.rule === "vault-placement"
    ),
  );
  overridden.jobs[0].steps[0].target = "orbit-node-209";
  overridden.jobs[0].labels = { node: "18" };
  assert(
    analyzeYaml(file, JSON.stringify(overridden)).findings.some((finding) =>
      finding.rule === "vault-placement"
    ),
  );
  labelled.jobs[0].steps[0].labels = {};
  assert(
    analyzeYaml(file, JSON.stringify(labelled)).findings.some((f) =>
      f.rule === "vault-placement"
    ),
  );
  for (const [from, to] of [
    ['"@swamp/ssh"', '"@example/ssh"'],
    ['"@swamp/ssh"', '"command/shell"'],
    ['"@swamp/ssh"', '"@swamp/ssh-extra"'],
    ['"type":"behavior"', '"type":"workflow"'],
    ['"methodName":"exec"', '"methodName":"script"'],
    ['"methodName":"exec"', '"methodName":"copy"'],
    ['"methodName":"exec"', '"methodName":"${{ inputs.method }}"'],
    ['"stdin":', '"script":'],
    ['"stdin":', '"command":'],
    ['"stdin":', '"other":'],
  ]) {
    assert(
      analyzeYaml(file, source.replace(from, to)).findings.some((f) =>
        f.rule === "vault-placement"
      ),
      `${from} -> ${to}`,
    );
  }
  for (const stdin of [
    `prefix ${vault}`,
    `${vault} suffix`,
    `${vault}${vault}`,
    "${{ vault.get('vault', 'key') + 'suffix' }}",
    "${{ vault.get('vault', 'key') || identity() }}",
    [vault],
    { value: vault },
  ]) {
    const changed = JSON.parse(source);
    changed.jobs[0].steps[0].task.inputs.stdin = stdin;
    assert(
      analyzeYaml(file, JSON.stringify(changed)).findings.some((f) =>
        f.rule === "vault-placement"
      ),
      JSON.stringify(stdin),
    );
  }
  const argv = JSON.parse(source);
  argv.jobs[0].steps[0].task.inputs.argv.push(vault);
  const findings = analyzeYaml(file, JSON.stringify(argv)).findings;
  assert(findings.some((f) => f.rule === "secret-argv"));
  assert(findings.some((f) => f.rule === "vault-placement"));
  const globalStdin = JSON.parse(source);
  globalStdin.jobs[0].steps[0].task.globalArgs = { stdin: vault };
  assert(
    analyzeYaml(file, JSON.stringify(globalStdin)).findings.some((f) =>
      f.rule === "vault-placement"
    ),
  );
  const stepStdin = JSON.parse(source);
  stepStdin.jobs[0].steps[0].stdin = vault;
  assert(
    analyzeYaml(file, JSON.stringify(stepStdin)).findings.some((f) =>
      f.rule === "vault-placement"
    ),
  );
  assert(
    analyzeYaml("models/official-ssh.yaml", source).findings.some((f) =>
      f.rule === "vault-placement"
    ),
  );
  const open = JSON.parse(source);
  open.inputs.properties.execution_secrets.additionalProperties = true;
  assert(
    analyzeYaml(file, JSON.stringify(open)).findings.some((f) =>
      f.rule === "secret-input"
    ),
  );
});
Deno.test("official SSH transport identity vault fields are native sensitive inputs", () => {
  assertEquals(
    analyzeYaml("models/official-ssh.yaml", officialSshFixture).findings,
    [],
  );
  const step = `name: official-ssh
vaults: [orbit-control-inputs]
jobs:
- name: action
  steps:
  - name: run
    task:
      type: behavior
      behaviorType: '@swamp/ssh'
      behaviorName: '\${{ "wireguard-hub-" + string(inputs.hub_id) }}'
      methodName: script
      globalArgs:
        name: wireguard-hub
        transport:
          kind: ssh
          identityContent: \${{ vault.get('orbit-control-inputs', inputs.execution_secrets.ssh.key) }}
        hosts:
        - name: hub
          address: example.invalid
          transport:
            identityContent: \${{ vault.get('orbit-control-inputs', 'ssh-hub') }}
      inputs:
        hosts: name:hub
        interpreter: python3
        sudo: true
        script: '\${{ steps.assets.outputs.script }}'
`;
  assertEquals(analyzeYaml("workflows/official-ssh.yaml", step).findings, []);
  assertEquals(
    analyzeYaml(
      "workflows/official-ssh.yaml",
      step.replace("type: behavior", "type: model_method").replace(
        "behaviorType:",
        "modelType:",
      ),
    ).findings,
    [],
  );
  assert(
    analyzeYaml(
      "models/official-ssh.yaml",
      officialSshFixture.replace(
        "${{ vault.get('orbit-control-inputs', 'ssh-hub') }}",
        "plaintext-private-key",
      ),
    ).findings.some((entry) => entry.rule === "secret-native-source"),
  );
  for (
    const source of [
      officialSshFixture.replace("type: '@swamp/ssh'", "type: '@example/ssh'"),
      officialSshFixture.replaceAll("identityContent:", "identityFile:"),
      officialSshFixture.replace("globalArguments:", "inputs:"),
      step.replace(
        "behaviorType: '@swamp/ssh'",
        "behaviorType: '@example/ssh'",
      ),
      step.replace("globalArgs:", "inputs:").replace(
        "      inputs:\n        hosts:",
        "      other:\n        hosts:",
      ),
    ]
  ) {
    const findings = analyzeYaml(
      source.startsWith("name: official-ssh\n")
        ? "workflows/official-ssh.yaml"
        : "models/official-ssh.yaml",
      source,
    ).findings;
    assert(
      findings.some((entry) => entry.rule === "vault-placement"),
      source,
    );
  }
});
Deno.test("official SSH identity content requires one whole vault value, never raw or composite secrets", () => {
  const original =
    "${{ vault.get('orbit-control-inputs', inputs.execution_secrets.ssh.key) }}";
  for (
    const value of [
      "plaintext-private-key",
      "${{ inputs.private_key }}",
      "${{ env.SSH_IDENTITY }}",
      "prefix " + original,
      original + " suffix",
      "${{ vault.get('vault','key') + string('suffix') }}",
      "${{ vault.get('vault','key') || identity() }}",
      "${{ vault.get('vault','key') }}${{ vault.get('other','key') }}",
      "{not: a string}",
    ]
  ) {
    const findings = analyzeYaml(
      "models/official-ssh.yaml",
      officialSshFixture.replace(original, value),
    ).findings;
    assert(
      findings.some((entry) => entry.rule === "secret-native-source"),
      value,
    );
  }
  for (
    const value of [
      "${{ vault.get('vault', inputs.execution_secrets.ssh.key) }}",
      "${{ vault.get('vault', 'ssh-node-' + string(inputs.node_id)) }}",
    ]
  ) {
    assertEquals(
      analyzeYaml(
        "models/official-ssh.yaml",
        officialSshFixture.replace(original, value),
      ).findings,
      [],
    );
  }
  const argv =
    "name: official-ssh\njobs:\n- steps:\n  - task:\n      type: behavior\n      behaviorType: '@swamp/ssh'\n      inputs:\n        argv:\n        - \"${{ vault.get('vault','key') }}\"\n";
  assert(
    analyzeYaml("workflows/official-ssh.yaml", argv).findings.some((entry) =>
      entry.rule === "secret-argv"
    ),
  );
});
const rules = (source: string) =>
  analyzeTs(file, source, inventory).findings.map((f) => f.rule);
const program = (n: number) =>
  "import json\n" +
  Array.from({ length: n - 1 }, (_, i) => `print(${i})`).join("\n");
Deno.test("embedded threshold is per program, not sum of independent short programs", () => {
  assert(
    !rules(
      `const a=${JSON.stringify(program(19))}; const b=${
        JSON.stringify(program(4))
      };const c=${JSON.stringify(program(14))};`,
    ).includes("inline-script"),
  );
  assert(
    rules(`const p=${JSON.stringify(program(21))};`).includes(
      "inline-script",
    ),
  );
});
Deno.test("script metrics count source once across a tagged template, concatenation and subprocess sink", () => {
  const source = `const first=String.raw\`${program(21)}\`; const second=${
    JSON.stringify(program(24))
  }; const joined=first+second;new Deno.Command('python3',{args:['-c',joined]});`;
  const a = analyzeTs(file, source, inventory);
  assertEquals(a.inlineLines, 45);
  assertEquals(
    a.findings.filter((f) => f.rule === "inline-script").reduce(
      (n, f) => n + f.count,
      0,
    ),
    45,
  );
});
Deno.test("joins and aliases cannot hide an oversized operational string", () => {
  assert(
    rules(
      `const parts=${
        JSON.stringify(program(24).split("\n"))
      };const p=parts.join('\\n'); const alias=p;new Deno.Command('python3',{args:['-c',alias]});`,
    ).includes("inline-script"),
  );
});
Deno.test("systemd unit text is declarative and absent from script metrics", () => {
  const unit = "[Unit]\nDescription=worker\n[Service]\n" +
    "Environment=PATH=/usr/bin\n".repeat(27) +
    "ExecStart=/usr/bin/python3 worker.py";
  const a = analyzeTs(file, `const unit=${JSON.stringify(unit)};`, inventory);
  assertEquals(a.inlineLines, 0);
});
Deno.test("nested subprocess binaries are evidence for multi-action, not a claim of business action count", () => {
  const a = analyzeTs(
    file,
    `const p=${
      JSON.stringify(
        "import subprocess\nsubprocess.run(['git','status'])\nsubprocess.run(['systemctl','restart','caddy'])",
      )
    };`,
    inventory,
  );
  assertEquals(a.findings.find((f) => f.rule === "multi-action")?.evidence, [
    "git",
    "systemctl",
  ]);
});
Deno.test("bounded polling, timer aliases and lock calls are not native controls", () => {
  for (
    const source of [
      "for(let attempt=0;attempt<3;attempt++){await delay(20)}",
      "while(true){await check()}",
      "setTimeout(done,100)",
      "invoke('/usr/bin/flock',['/tmp/runtime.lock'])",
    ]
  ) assert(rules(source).includes("custom-control"), source);
});
Deno.test("pure finite data iteration is not polling", () => {
  assert(
    !rules("for(const item of rows){result.push(item.id)}").includes(
      "custom-control",
    ),
  );
});
Deno.test("private-material clients and Gateway HTTP step imports are grandfathered rules", () => {
  assert(
    rules("gateway.withPrivateMaterial(async material=>material)").includes(
      "private-material",
    ),
  );
  assert(
    rules(
      "import {GatewayOperation as Client} from './direct.ts';new Client(http,args).read();",
    ).includes("gateway-http"),
  );
});
Deno.test("agent aliases, wrappers, conditional commands and array invocations are classified", () => {
  for (
    const source of [
      "import {runNodeAgent as act} from './node-agent.ts';act({name:'cli.install'})",
      "const act=runNodeAgent;act({name:flag?'agent.status':'cli.install'});",
      "function wrapper(c){return runNodeAgent(c)}wrapper({name:'cli.install'});",
      "invoke(['/usr/local/bin/orbit-agent','command','cli.install']);",
    ]
  ) assert(rules(source).includes("agent-action"), source);
  assert(
    !rules("runNodeAgent({name:'agent.status'})").includes("agent-action"),
  );
  assert(rules("runNodeAgent(request)").includes("agent-dynamic"));
});
Deno.test("fixture rename or missing local dependency is unknown, never clean", () => {
  const a = analyze(
    new Map([[
      file,
      "import {act} from './hidden_test.ts';export const behavior={type:'@example/test'};",
    ]]),
    inventory,
  );
  assert(a.get(file)?.findings.some((f) => f.rule === "unknown-dependency"));
});
const workflow = (inputs: string) =>
  `name: example\nvaults: [control]\njobs:\n- name: action\n  steps:\n  - name: run\n    task:\n      type: behavior\n      behaviorType: command/shell\n      methodName: execute\n      inputs:\n${inputs}`;
Deno.test("vault values are permitted only as whole command environment values", () => {
  const good = workflow(
    "        run: python3 scripts/bootstrap.py\n        env:\n          ORBIT_BOOTSTRAP_IDENTITY: \"${{ vault.get('control',steps.intent.outputs.identity_key) }}\"\n",
  );
  assertEquals(analyzeYaml("workflows/test.yaml", good).findings, []);
  for (
    const input of [
      "        run: \"echo ${{ vault.get('control','key') }}\"\n",
      "        run: echo $ORBIT_SECRET\n",
      "        env:\n          IDENTITY: plaintext\n",
      "        env:\n          IDENTITY: \"prefix ${{ vault.get('control','key') }}\"\n",
      "        run: set -x\n",
    ]
  ) {
    assert(
      analyzeYaml("workflows/test.yaml", workflow(input)).findings
        .length > 0,
      input,
    );
  }
});
Deno.test("secret workflow inputs are rejected; vault key names are public references", () => {
  const bad = "inputs:\n  properties:\n    password: {type: string}\n" +
    workflow("        run: echo ok\n");
  assert(
    analyzeYaml("workflows/test.yaml", bad).findings.some((f) =>
      f.rule === "secret-input"
    ),
  );
  const good = "inputs:\n  properties:\n    identity_key: {type: string}\n" +
    workflow("        run: echo ok\n");
  assert(
    !analyzeYaml("workflows/test.yaml", good).findings.some((f) =>
      f.rule === "secret-input"
    ),
  );
});
Deno.test("baseline cannot grow, add a rule, move a file or admit a different agent action", () => {
  const entry = {
    file,
    rule: "agent-action",
    count: 1,
    evidence: ["cli.install"],
  };
  const baseline = { schema: SCHEMA, entries: [entry] };
  assertEquals(ratchet([entry], baseline, baseline), []);
  for (
    const next of [
      { ...entry, count: 2 },
      { ...entry, file: "extensions/behaviors/new.ts" },
      { ...entry, rule: "private-material" },
      { ...entry, evidence: ["caddy.publish"] },
    ]
  ) {
    assert(
      ratchet([next], { schema: SCHEMA, entries: [next] }, baseline)
        .length > 0,
    );
  }
  assertEquals(ratchet([], { schema: SCHEMA, entries: [] }, baseline), []);
});
Deno.test("workflow coverage separates clean, unknown and accepted debt", () => {
  const sources = new Map([
    ["workflows/clean.yaml", workflow("        run: true\n")],
    ["workflows/debt.yaml", workflow("        run: sleep 10\n")],
    [
      "workflows/unknown.yaml",
      "jobs:\n- steps:\n  - task: {type: unknown}\n",
    ],
  ]);
  assertEquals(workflowCoverage(analyze(sources, inventory)), {
    total: 3,
    clean: 1,
    unknown: 1,
    baseline_accepted: 1,
  });
});
Deno.test("aggregation preserves call sites separately from file-rule rows", () => {
  assertEquals(
    aggregate([{
      file,
      rule: "agent-action",
      count: 1,
      line: 1,
      evidence: ["cli.install"],
    }, {
      file,
      rule: "agent-action",
      count: 1,
      line: 9,
      evidence: ["cli.install"],
    }]),
    [{ file, rule: "agent-action", count: 2, evidence: ["cli.install"] }],
  );
});

Deno.test("Gateway failure reporters count as rule F even without private material", () => {
  const sources = new Map([
    [
      "workflows/root.yaml",
      workflow("        run: echo ok\n").replace(
        "behaviorType: command/shell",
        "behaviorType: '@example/root'",
      ),
    ],
    [
      "extensions/behaviors/root.ts",
      "import {nativeFailure} from './failure.ts'; export const behavior={type:'@example/root'};",
    ],
    [
      "extensions/behaviors/failure.ts",
      "import {GatewayOperation} from './client.ts'; const g=new GatewayOperation(http,args);g.call('POST','/failure');",
    ],
    ["extensions/behaviors/client.ts", "export class GatewayOperation {}"],
  ]);
  const result = analyze(sources, inventory);
  assert(
    result.get("extensions/behaviors/failure.ts")?.findings.some((f) =>
      f.rule === "gateway-http"
    ),
  );
  assertEquals(workflowCoverage(result).clean, 0);
});
Deno.test("internal credential arguments are not secret-flow proof; env and private material are", () => {
  assert(
    !rules(
      "function write(WorkerCredentials){host.run('/usr/bin/tool',['--identity',WorkerCredentials])}",
    ).includes("secret-argv"),
  );
  assert(
    rules(
      "const s=Deno.env.get('BOOTSTRAP_SECRET'); host.run('/usr/bin/tool',['--identity',s]);",
    ).includes("secret-argv"),
  );
  assert(
    rules(
      "gateway.withPrivateMaterial(async material=>host.run('/usr/bin/tool',[material.password]));",
    ).includes("secret-argv"),
  );
});
Deno.test("native stdin and protected environment do not become argv findings", () => {
  assert(
    !rules(
      "const s=Deno.env.get('BOOTSTRAP_SECRET');invoke({command:'/usr/bin/tool',args:[],stdin:s,env:{SECRET:s}});",
    ).includes("secret-argv"),
  );
});

Deno.test("dynamic execution descriptor maps keep every value closed without a cardinality cap", () => {
  const descriptor = {
    type: "object",
    additionalProperties: false,
    properties: {
      vault: { type: "string", enum: ["orbit-control-inputs"] },
      key: {
        type: "string",
        pattern: "^[A-Za-z0-9][A-Za-z0-9_.:-]{0,255}$",
      },
    },
    required: ["vault", "key"],
  };
  const map = { type: "object", additionalProperties: descriptor };
  const findings = (schema: unknown) =>
    analyzeYaml(
      "workflows/route-descriptor-map.yaml",
      "inputs:\n  properties:\n    execution_secrets: " +
        JSON.stringify(schema) +
        "\n" + workflow("        run: true\n"),
    ).findings.filter((finding) => finding.rule === "secret-input");
  for (
    const valid of [
      map,
      { ...map, properties: {}, required: [] },
      {
        type: "object",
        additionalProperties: false,
        properties: { routes: map },
        required: ["routes"],
      },
    ]
  ) assertEquals(findings(valid), []);
  for (
    const invalid of [
      { ...map, additionalProperties: true },
      { ...map, additionalProperties: {} },
      { ...map, additionalProperties: { type: "string" } },
      {
        ...map,
        additionalProperties: {
          ...descriptor,
          additionalProperties: true,
        },
      },
      {
        ...map,
        additionalProperties: { ...descriptor, required: ["vault"] },
      },
      {
        ...map,
        additionalProperties: {
          ...descriptor,
          properties: {
            ...descriptor.properties,
            value: { type: "string" },
          },
        },
      },
      { ...map, properties: { password: { type: "string" } } },
      { ...map, properties: [] },
      { ...map, required: ["material"] },
      { ...map, patternProperties: { ".*": { type: "string" } } },
      { ...map, additionalProperties: map },
    ]
  ) assert(findings(invalid).length > 0, JSON.stringify(invalid));
});

Deno.test("closed execution_secrets containers carry only public vault-key references", () => {
  const ref = {
    type: "object",
    properties: {
      vault: { type: "string", enum: ["orbit-control-inputs"] },
      key: {
        type: "string",
        pattern: "^bootstrap-identity-node-[1-9][0-9]*$",
      },
    },
    required: ["vault", "key"],
    additionalProperties: false,
  };
  const container = {
    type: "object",
    properties: { bootstrap_identity: ref },
    required: ["bootstrap_identity"],
    additionalProperties: false,
  };
  const yaml = (schema: unknown) =>
    "inputs:\n  properties:\n    execution_secrets: " +
    JSON.stringify(schema) +
    "\n" + workflow("        run: true\n");
  assert(
    !analyzeYaml("workflows/pilot.yaml", yaml(container)).findings.some((
      f,
    ) => f.rule === "secret-input"),
  );
  for (
    const bad of [{ ...container, additionalProperties: true }, {
      ...container,
      properties: { password: { type: "string" } },
    }, {
      ...container,
      properties: {
        bootstrap_identity: {
          ...ref,
          properties: {
            ...ref.properties,
            value: { type: "string" },
          },
        },
      },
    }]
  ) {
    assert(
      analyzeYaml("workflows/pilot.yaml", yaml(bad)).findings.some((f) =>
        f.rule === "secret-input"
      ),
    );
  }
});

Deno.test("agent array, bind and default-parameter wrappers cannot hide new actions", () => {
  for (
    const source of [
      "const calls=[runNodeAgent];calls[0]({name:'cli.install'});",
      "const act=runNodeAgent.bind(null);act({name:'cli.install'});",
      "function install(call,invoke=runNodeAgent){return invoke({name:'cli.install'});}",
    ]
  ) assert(rules(source).includes("agent-action"), source);
});
Deno.test("a new registered step cannot reuse an old Gateway HTTP helper without rule F", () => {
  const result = analyze(
    new Map([[
      "extensions/behaviors/new.ts",
      "import {read} from './old.ts';export const behavior={type:'@example/new',execute:()=>read()};",
    ], [
      "extensions/behaviors/old.ts",
      "export function read(){return gatewayHttp(url).get('/intent')} backup();",
    ]]),
    inventory,
  );
  assert(
    result.get("extensions/behaviors/new.ts")?.findings.some((f) =>
      f.rule === "gateway-http"
    ),
  );
});

Deno.test("Gateway primitive exceptions require exact POST routes, never endpoint prefixes", () => {
  assertEquals(GATEWAY_PRIMITIVES, [
    "/api/v1/direct-operations/{operation_id}/github-token",
    "/api/v1/direct-operations/{operation_id}/certificates/sign",
  ]);
  for (const route of GATEWAY_PRIMITIVES) {
    const path = route.replace("{operation_id}", "19");
    assert(isGatewayPrimitive("POST", path));
    for (const invalid of ["0", "-1", "node", "19/result", "19%2Fresult"]) {
      assert(
        !isGatewayPrimitive(
          "POST",
          route.replace("{operation_id}", invalid),
        ),
      );
    }
    for (const method of ["GET", "PUT", "DELETE", undefined]) {
      assert(!isGatewayPrimitive(method, path));
    }
    for (
      const suffix of [
        "/intent",
        "/result",
        "?endpoint=/primitive",
        "/../private-material",
      ]
    ) assert(!isGatewayPrimitive("POST", path + suffix));
    assert(
      !rules(`gatewayHttp('POST',${JSON.stringify(path)});`).includes(
        "gateway-http",
      ),
    );
    assert(
      rules(`gatewayHttp('GET',${JSON.stringify(path)});`).includes(
        "gateway-http",
      ),
    );
    assert(
      rules(
        `gatewayHttp('POST',${
          JSON.stringify(path)
        });gatewayHttp('GET','/api/v1/operations/19');`,
      ).includes("gateway-http"),
    );
  }
  assert(
    !isGatewayPrimitive("POST", "/api/v1/direct-operations/19/primitive"),
  );
  assert(
    !isGatewayPrimitive(
      "POST",
      "/api/v1/direct-operations/19/private-material",
    ),
  );
});
Deno.test("Gateway primitive fetch aliases and options must prove their exact method and path", () => {
  for (const route of GATEWAY_PRIMITIVES) {
    const path = route.replace("{operation_id}", "19");
    const url = JSON.stringify("https://gateway.orbit" + path);
    assert(
      !rules(
        `const request=fetch;const url=${url};request(url,{method:'POST'});`,
      ).includes("gateway-http"),
    );
    assert(rules(`fetch(${url});`).includes("gateway-http"));
    assert(rules(`fetch(${url},{method:verb});`).includes("gateway-http"));
    assert(
      rules(`fetch(${url},{method:'POST',...options});`).includes(
        "gateway-http",
      ),
    );
    assert(
      rules(
        `fetch(${url},{method:'POST'});fetch(gatewayUrl+'/api/v1/operations/'+id);`,
      ).includes("gateway-http"),
    );
    assert(
      rules(
        `import {GatewayOperation} from './direct.ts';fetch(${url},{method:'POST'});new GatewayOperation().read();`,
      ).includes("gateway-http"),
    );
  }
});
Deno.test("Gateway release-runner wait exception does not allow polling in Swamp steps", () => {
  assert(
    rules("while(!healthy){await delay(15000)}").includes("custom-control"),
  );
});

Deno.test("only concrete primitives are clean through shared local step dependencies", () => {
  const path = GATEWAY_PRIMITIVES[0].replace("{operation_id}", "19");
  const files = new Map([
    [
      "extensions/behaviors/step.ts",
      "import {mint} from './mint.ts';export const behavior={type:'@example/mint',execute:mint};",
    ],
    [
      "extensions/behaviors/mint.ts",
      `export function mint(){return fetch('https://gateway.orbit${path}',{method:'POST'});}`,
    ],
    [
      "workflows/mint.yaml",
      "jobs:\n- steps:\n  - task: {type: behavior, behaviorType: '@example/mint'}\n",
    ],
  ]);
  assertEquals(workflowCoverage(analyze(files, inventory)).clean, 1);
  files.set(
    "extensions/behaviors/mint.ts",
    "export function mint(){return fetch(gatewayUrl+endpoint,{method:'POST'});}",
  );
  const blocked = analyze(files, inventory);
  assert(
    blocked.get("extensions/behaviors/step.ts")?.findings.some((f) =>
      f.rule === "gateway-http"
    ),
  );
  assertEquals(workflowCoverage(blocked).clean, 0);
});
Deno.test("sign and mint names grant no Gateway exception", () => {
  for (const name of ["sign", "mint", "signCertificate", "mintGitHubToken"]) {
    assert(
      rules(
        `function ${name}(){return gatewayHttp('POST','/api/v1/operations/19/result');}`,
      ).includes("gateway-http"),
    );
  }
  assert(
    rules("gatewayHttp('POST',selectedEndpoint);").includes("gateway-http"),
  );
});

Deno.test("native shell Gateway requests use the same two exact POST routes", () => {
  for (const route of GATEWAY_PRIMITIVES) {
    const url = "https://gateway.orbit" +
      route.replace("{operation_id}", "19");
    assertEquals(
      gatewayShellFindings(
        "scripts/request.sh",
        `curl --request POST '${url}'`,
      ),
      [],
    );
    assert(
      gatewayShellFindings("scripts/request.sh", `curl '${url}'`).some((
        f,
      ) => f.rule === "gateway-http"),
    );
    assert(
      gatewayShellFindings(
        "scripts/request.sh",
        `curl --request POST '${url}/result'`,
      ).some((f) => f.rule === "gateway-http"),
    );
    assert(
      gatewayShellFindings(
        "scripts/request.sh",
        `curl --request POST '${url}'\ncurl https://gateway.orbit/api/v1/operations/19`,
      ).some((f) => f.rule === "gateway-http"),
    );
  }
});

Deno.test("external HTTP calls are not Gateway primitives or callbacks", () => {
  assert(
    !rules(
      "fetch('https://api.github.com/installation/token',{method:'DELETE'});",
    ).includes("gateway-http"),
  );
  assertEquals(
    gatewayShellFindings(
      "scripts/download.sh",
      "curl https://example.com/archive.tar.gz",
    ),
    [],
  );
});

Deno.test("agent service and artifact operands are data, never agent action calls", () => {
  for (
    const source of [
      "systemctl restart orbit-agent.service",
      "sudo -n systemctl is-active orbit-agent.service",
      "sha256sum /usr/local/bin/orbit-agent",
      "cp /tmp/agent /usr/local/bin/orbit-agent",
      "rm -f /usr/local/bin/orbit-agent /etc/orbit/agent/config.toml",
      "echo orbit-agent command cli.install",
      "ssh -o StrictHostKeyChecking=yes -- node 'sudo systemctl stop orbit-agent.service'",
      "python3 - <<'PY'\nfrom pathlib import Path\nprint(Path('/usr/local/bin/orbit-agent').stat())\nPY",
    ]
  ) {
    assertEquals(agentShellFindings(file, source, inventory), [], source);
    assert(
      !analyzeYaml(
        "workflows/native.yaml",
        workflow("        run: " + JSON.stringify(source) + "\n"),
        inventory,
      ).findings.some((f) => f.rule.startsWith("agent-")),
      source,
    );
  }
  assert(
    !rules("Deno.readFile('/usr/local/bin/orbit-agent');").some((r) =>
      r.startsWith("agent-")
    ),
  );
  assert(
    !rules(
      "new Deno.Command('sha256sum',{args:['/usr/local/bin/orbit-agent']});",
    ).some((r) => r.startsWith("agent-")),
  );
});
Deno.test("real shell agent invocations remain forbidden through executable wrappers", () => {
  for (
    const source of [
      "/usr/local/bin/orbit-agent command cli.install",
      "sudo -n -- /usr/local/bin/orbit-agent command cli.install",
      "env HOME=/tmp /usr/local/bin/orbit-agent command cli.install",
      "exec /usr/local/bin/orbit-agent command cli.install",
      "bash -c 'sudo orbit-agent command cli.install'",
      "ssh -o StrictHostKeyChecking=yes -- node 'sudo orbit-agent command cli.install'",
      'agent=/usr/local/bin/orbit-agent; "$agent" command cli.install',
      'echo "$(orbit-agent command cli.install)"',
    ]
  ) {
    assert(
      agentShellFindings(file, source, inventory).some((f) =>
        f.rule === "agent-action"
      ),
      source,
    );
  }
  assertEquals(
    agentShellFindings(file, "orbit-agent command agent.status", inventory),
    [],
  );
  assert(
    agentShellFindings(file, 'orbit-agent command "$command"', inventory)
      .some((f) => f.rule === "agent-dynamic"),
  );
});

Deno.test("closed empty execution secrets admits no private values", () => {
  for (
    const schema of [
      { type: "object", additionalProperties: false },
      { type: "object", properties: {}, additionalProperties: false },
      {
        type: "object",
        properties: {},
        additionalProperties: false,
        maxProperties: 0,
      },
    ]
  ) {
    const source = "inputs:\n  properties:\n    execution_secrets: " +
      JSON.stringify(schema) + "\n" + workflow("        run: 'true'\n");
    assert(
      !analyzeYaml("workflows/empty.yaml", source).findings.some((f) =>
        f.rule === "secret-input"
      ),
    );
  }
  for (
    const schema of [
      { type: "object" },
      { type: "object", additionalProperties: true },
      {
        type: "object",
        additionalProperties: false,
        patternProperties: { ".*": { type: "string" } },
      },
      {
        type: "object",
        additionalProperties: false,
        properties: { password: { type: "string" } },
      },
    ]
  ) {
    const source = "inputs:\n  properties:\n    execution_secrets: " +
      JSON.stringify(schema) + "\n";
    assert(
      analyzeYaml("workflows/unsafe.yaml", source).findings.some((f) =>
        f.rule === "secret-input"
      ),
    );
  }
});
Deno.test("normalized repository identity is public while authentication identities remain private", () => {
  const source = (name: string) =>
    "inputs:\n  properties:\n    intent:\n      type: object\n      properties:\n        " +
    name + ": {type: string, minLength: 1, maxLength: 4096}\n";
  assert(
    !analyzeYaml("workflows/transfer.yaml", source("repository_identity"))
      .findings.some((f) => f.rule === "secret-input"),
  );
  for (
    const field of [
      "bootstrap_identity",
      "ssh_identity",
      "api_token",
      "private_key",
    ]
  ) {
    assert(
      analyzeYaml("workflows/private.yaml", source(field)).findings.some((f) =>
        f.rule === "secret-input"
      ),
    );
  }
});

Deno.test("official cfgmgmt identity file workflow input is a public string path", () => {
  const source = (name: string, schema: unknown) =>
    "inputs:\n  properties:\n    connection:\n      type: object\n      properties:\n        " +
    name + ": " + JSON.stringify(schema) + "\n";
  assert(
    !analyzeYaml(
      "workflows/cfgmgmt.yaml",
      source("nodeIdentityFile", { type: "string" }),
    ).findings.some((f) => f.rule === "secret-input"),
  );
  for (const schema of [{}, { type: "object" }, { type: "array" }]) {
    assert(
      analyzeYaml("workflows/cfgmgmt.yaml", source("nodeIdentityFile", schema))
        .findings.some((f) => f.rule === "secret-input"),
    );
  }
  for (
    const name of ["identityContent", "nodeIdentityContent", "ssh_identity"]
  ) {
    assert(
      analyzeYaml("workflows/cfgmgmt.yaml", source(name, { type: "string" }))
        .findings.some((f) => f.rule === "secret-input"),
    );
  }
  const vault = "${{ vault.get('execution', 'ssh') }}";
  assert(
    analyzeYaml(
      "models/cfgmgmt.yaml",
      "globalArguments:\n  nodeIdentityFile: " + vault,
    )
      .findings.some((f) => f.rule === "vault-placement"),
  );
  assert(
    analyzeYaml(
      "workflows/cfgmgmt.yaml",
      workflow(
        "        run: 'tool $NODE_IDENTITY_FILE'\n",
      ),
    ).findings.some((f) => f.rule === "secret-argv"),
  );
});

Deno.test("agent invocations inside shell substitutions and interpreter bodies are actions", () => {
  for (
    const source of [
      "sudo -S orbit-agent command cli.install",
      "env -S 'sudo -S orbit-agent command cli.install'",
      "printf hello `orbit-agent command cli.install`",
      "bash <<EOF\norbit-agent command cli.install\nEOF",
      'python3 -c \'import subprocess; subprocess.run(["orbit-agent","command","cli.install"])\'',
      "python3 - <<'PY'\nimport subprocess\nsubprocess.run(['orbit-agent','command','cli.install'])\nPY",
    ]
  ) {
    assertEquals(
      agentShellFindings(file, source, inventory).filter((f) =>
        f.rule === "agent-action"
      ).length,
      1,
      source,
    );
  }
  assertEquals(
    agentPythonFindings(
      file,
      "import subprocess\nsubprocess.run(['printf','$(orbit-agent command cli.install)'])",
      inventory,
    ),
    [],
  );
  assertEquals(
    agentShellFindings(
      file,
      "cat <<'EOF'\norbit-agent command cli.install\nEOF",
      inventory,
    ),
    [],
  );
});

Deno.test("static native child workflows are scanned through their local source graph", () => {
  const child = (run = "true") =>
    `id: 11111111-1111-4111-8111-111111111111\n${
      workflow("        run: " + JSON.stringify(run) + "\n").replace(
        "name: example",
        "name: child",
      )
    }`;
  const parent = (reference: string) =>
    `name: parent\njobs:\n- steps:\n  - task:\n      type: workflow\n      workflowIdOrName: ${
      JSON.stringify(reference)
    }\n`;
  for (const reference of ["child", "11111111-1111-4111-8111-111111111111"]) {
    const sources = new Map([
      ["workflows/parent.yaml", parent(reference)],
      ["workflows/child.yaml", child()],
    ]);
    const clean = analyze(sources, inventory);
    assertEquals(clean.get("workflows/parent.yaml")!.findings, []);
    assertEquals(workflowCoverage(clean), {
      total: 2,
      clean: 2,
      unknown: 0,
      baseline_accepted: 0,
    });
    sources.set(
      "workflows/child.yaml",
      child("orbit-agent command cli.install"),
    );
    assertEquals(workflowCoverage(analyze(sources, inventory)), {
      total: 2,
      clean: 0,
      unknown: 0,
      baseline_accepted: 2,
    });
  }
  for (const reference of ["missing", "${{ inputs.child }}"]) {
    const analyses = analyze(
      new Map([["workflows/parent.yaml", parent(reference)], [
        "workflows/child.yaml",
        child(),
      ]]),
      inventory,
    );
    assert(analyses.get("workflows/parent.yaml")!.unknown);
    assert(
      analyses.get("workflows/parent.yaml")!.findings.some((f) =>
        f.rule === "unknown-nested-workflow"
      ),
    );
    assertEquals(workflowCoverage(analyses).unknown, 1);
  }
  const ambiguous = analyze(
    new Map([["workflows/parent.yaml", parent("child")], [
      "workflows/child.yaml",
      child(),
    ], ["workflows/duplicate.yaml", child()]]),
    inventory,
  );
  assert(ambiguous.get("workflows/parent.yaml")!.unknown);
  const unresolvedBehavior = analyze(
    new Map([["workflows/parent.yaml", parent("child")], [
      "workflows/child.yaml",
      "name: child\njobs:\n- steps:\n  - task: {type: behavior, behaviorType: '@missing/behavior'}\n",
    ]]),
    inventory,
  );
  assertEquals(workflowCoverage(unresolvedBehavior).unknown, 2);
});

Deno.test("named native models resolve by name or id and retain their source findings", () => {
  const id = "2175a31b-0f01-4715-bada-683a46696388";
  const model = (run = "true") =>
    `id: ${id}\nname: cleanup\ntype: 'command/shell'\nversion: 1\nglobalArguments:\n  run: ${
      JSON.stringify(run)
    }\n`;
  const parent = (reference: string) =>
    `name: parent\njobs:\n- steps:\n  - task:\n      type: model_method\n      modelIdOrName: ${
      JSON.stringify(reference)
    }\n      methodName: execute\n`;
  for (const reference of ["cleanup", id]) {
    const sources = new Map([
      ["workflows/parent.yaml", parent(reference)],
      ["models/command/shell/cleanup.yaml", model()],
    ]);
    const clean = analyze(sources, inventory);
    assertEquals(clean.get("workflows/parent.yaml")!.findings, []);
    assertEquals(workflowCoverage(clean).clean, 1);
    sources.set(
      "models/command/shell/cleanup.yaml",
      model("orbit-agent command cli.install"),
    );
    assertEquals(
      workflowCoverage(analyze(sources, inventory)).baseline_accepted,
      1,
    );
    sources.set(
      "models/command/shell/cleanup.yaml",
      model().replace("command/shell", "@swamp/cfgmgmt/file"),
    );
    const official = analyze(sources, inventory);
    assertEquals(official.get("workflows/parent.yaml")!.findings, []);
    assertEquals(official.get("models/command/shell/cleanup.yaml")!.types, [
      "@swamp/cfgmgmt/file",
    ]);
  }
  for (const reference of ["missing", "${{ inputs.model }}", "cleanup"]) {
    const sources = new Map([
      ["workflows/parent.yaml", parent(reference)],
      ["models/command/shell/cleanup.yaml", model()],
      ["models/command/shell/duplicate.yaml", model()],
    ]);
    const result = analyze(sources, inventory).get(
      "workflows/parent.yaml",
    )!;
    assert(result.unknown);
    assert(
      result.findings.some((finding) => finding.rule === "unknown-behavior"),
    );
  }
  for (const type of [undefined, "unsupported/custom", "${{ inputs.kind }}"]) {
    const definition = type === undefined
      ? model().replace("type: 'command/shell'\n", "")
      : model().replace("'command/shell'", JSON.stringify(type));
    const result = analyze(new Map([
      ["workflows/parent.yaml", parent("cleanup")],
      ["models/command/shell/cleanup.yaml", definition],
    ]), inventory);
    assert(result.get("models/command/shell/cleanup.yaml")!.unknown);
    assertEquals(workflowCoverage(result).unknown, 1);
    assertEquals(workflowCoverage(result).clean, 0);
  }
});

Deno.test("Python subprocess wrappers cannot hide sleep or workflow-lock commands", () => {
  for (
    const program of [
      "import subprocess\nsubprocess.run(['flock','/tmp/workflow.lock','true'])",
      "import os\nos.system('sleep 30')",
      "from subprocess import run as execute\nexecute(['bash','-c','sudo -S sleep 30'])",
      "import subprocess\nsubprocess.run(['ssh','host','flock /tmp/workflow.lock true'])",
    ]
  ) {
    assertEquals(
      agentPythonFindings(file, program, inventory).filter((f) =>
        f.rule === "custom-control"
      ).length,
      1,
      program,
    );
    const heredoc = `python3 <<'PY'\n${program}\nPY`;
    assertEquals(
      agentShellFindings(file, heredoc, inventory).filter((f) =>
        f.rule === "custom-control"
      ).length,
      1,
      heredoc,
    );
  }
});

Deno.test("Python command bindings use execution order and immutable lexical captures", () => {
  for (
    const source of [
      "import subprocess\ncmd=['sleep','30']\nsubprocess.run(cmd)\ncmd=['echo','done']\n",
      "import subprocess\nbase=['sleep','30']\ncmd=base\nbase=['echo','done']\nsubprocess.run(cmd)\n",
    ]
  ) {
    assert(
      agentPythonFindings(file, source, inventory).some((f) =>
        f.rule === "custom-control"
      ),
      source,
    );
    assert(
      agentShellFindings(file, `python3 <<'PY'\n${source}PY`, inventory).some(
        (f) => f.rule === "custom-control",
      ),
      source,
    );
  }
  for (
    const source of [
      "import subprocess\ncmd=['echo','safe']\ndef execute(cmd):\n    subprocess.run(cmd)\nexecute(['sleep','30'])\n",
      "import subprocess\ncmd=['echo','safe']\ndef execute():\n    subprocess.run(cmd)\nfor cmd in [['sleep','30']]:\n    pass\nexecute()\n",
      "import subprocess\ncmd=['echo','safe']\ndef execute():\n    subprocess.run(cmd)\ncmd,other=['sleep','30'],None\nexecute()\n",
    ]
  ) {
    assert(
      agentPythonFindings(file, source, inventory).some((f) =>
        f.rule === "unknown-command"
      ),
      source,
    );
  }
});

Deno.test("Python cross-scope and walrus writes invalidate captured command provenance", () => {
  for (
    const source of [
      "import subprocess\ncmd=['echo','safe']\ndef execute():\n    subprocess.run(cmd)\ndef change():\n    global cmd\n    cmd=['sleep','30']\nchange()\nexecute()\n",
      "import subprocess\ncmd=['echo','safe']\ndef execute():\n    subprocess.run(cmd)\nif (cmd := ['sleep','30']):\n    execute()\n",
      "import subprocess\ncmd=['echo','safe']\ndef change():\n    global cmd\n    cmd=['sleep','30']\nchange()\nsubprocess.run(cmd)\n",
      "import subprocess\ndef outer():\n    cmd=['echo','safe']\n    def execute():\n        subprocess.run(cmd)\n    def change():\n        nonlocal cmd\n        cmd=['sleep','30']\n    change()\n    execute()\nouter()\n",
    ]
  ) {
    for (
      const findings of [
        agentPythonFindings(file, source, inventory),
        agentShellFindings(file, `python3 <<'PY'\n${source}PY`, inventory),
      ]
    ) {
      assert(
        findings.some((finding) =>
          finding.rule === "unknown-command" ||
          finding.rule === "custom-control"
        ),
        source,
      );
    }
  }
});

const moveFixture = JSON.parse(
  Deno.readTextFileSync(
    new URL(
      "../tests/fixtures/shape/proxy-http-source-move.json",
      import.meta.url,
    ),
  ),
);
const moveRow = (file: string, count = 1) => ({
  file,
  rule: "gateway-http",
  count,
  evidence: [] as string[],
});
const moveBaseline = (entries: ReturnType<typeof moveRow>[]) => ({
  schema: SCHEMA,
  entries,
});
function moveSource() {
  const { from, to, caller } = PROXY_HTTP_MOVE;
  const origin = new Map<string, string>([[from, moveFixture.from as string], [
    caller,
    moveFixture.caller as string,
  ]]);
  const head = new Map<string, string>([[
    from,
    "export const proxyLaunch = () => {};",
  ], [
    to,
    moveFixture.target as string,
  ], [
    caller,
    (moveFixture.caller as string).replace(
      'import { proxyGatewayRequest, runtimeNodeProxy } from "../operations/_lib/node-proxy.ts";',
      'import { proxyGatewayRequest } from "../operations/_lib/node-proxy-http.ts";\nimport { runtimeNodeProxy } from "../operations/_lib/node-proxy.ts";',
    ),
  ]]);
  const originBaseline = moveBaseline([moveRow(from)]);
  const proof = (
    candidate = head,
    base = origin,
    previous = originBaseline,
    before = [moveRow(from)],
    after = [moveRow(to)],
  ) =>
    proveProxyHttpMove(
      origin,
      base,
      candidate,
      originBaseline,
      previous,
      before,
      after,
    );
  return { origin, head, originBaseline, proof };
}
Deno.test("the exact approved HTTP extraction conserves one cap while the physical ledger only shrinks", () => {
  const { head, proof, originBaseline } = moveSource();
  assertEquals(moveFixture.origin, PROXY_HTTP_MOVE.origin);
  const move = proof();
  assert(move);
  const actual = [moveRow(PROXY_HTTP_MOVE.to)];
  const raw = moveBaseline([]);
  assert(ratchet(actual, raw, originBaseline).length > 0);
  assertEquals(ratchet([], raw, originBaseline), []);
  assertEquals(ratchet(actual, movedLimits(raw, [move])), []);
  assertEquals(ratchet(actual, movedLimits(originBaseline, [move])), []);
  assertEquals(
    raw.entries,
    [],
    "Derived limits never mutate or write the checked-in baseline",
  );
  assert(
    ratchet([], movedLimits(raw, [move]), originBaseline).length > 0,
    "A physical new row is still forbidden",
  );
  assert(
    proof(head, head, raw, actual, actual),
    "A retained proved move remains accounted with no ledger row",
  );
});
Deno.test("HTTP source relocation rejects changed definitions, imports, retained debt and new callers", () => {
  const { head, proof, origin } = moveSource();
  const { from, to, caller } = PROXY_HTTP_MOVE;
  const mutate = (file: string, source: string) =>
    new Map([...head, [file, source]]);
  for (
    const candidate of [
      mutate(to, head.get(to)!.replace("timeoutMs + 1000", "timeoutMs + 2000")),
      mutate(to, head.get(to)! + "\nexport const another = 1;"),
      mutate(to, head.get(to)! + '\nimport { helper } from "./other.ts";'),
      mutate(
        to,
        head.get(to)!.replace("commandOutput }", "commandOutput as output }"),
      ),
      mutate(from, origin.get(from)!),
      mutate(
        "extensions/behaviors/new.ts",
        'import {proxyGatewayRequest} from "./operations/_lib/node-proxy-http.ts"; proxyGatewayRequest();',
      ),
      mutate(
        "extensions/behaviors/new.ts",
        'import * as http from "./operations/_lib/node-proxy-http.ts"; http.proxyGatewayRequest();',
      ),
      mutate(
        "extensions/behaviors/new.ts",
        'export * from "./operations/_lib/node-proxy-http.ts";',
      ),
      mutate(
        "extensions/behaviors/new.ts",
        'const load=import("./operations/_lib/"+"node-proxy-http.ts");',
      ),
      mutate(caller, head.get(caller)! + "\nproxyGatewayRequest();"),
      mutate(caller, head.get(caller)! + "\nconst alias=proxyGatewayRequest;"),
      mutate(
        caller,
        head.get(caller)!.replace("        timeoutMs,", "        timeoutMs+1,"),
      ),
    ]
  ) assertEquals(proof(candidate), undefined);
  assertEquals(
    proof(head, origin, moveBaseline([])),
    undefined,
    "A deleted old cap cannot be revived",
  );
  assertEquals(
    proof(head, origin, undefined, []),
    undefined,
    "No historical cap without actual live debt",
  );
  assertEquals(
    proof(head, origin, undefined, undefined, [moveRow(to, 2)]),
    undefined,
    "No count growth",
  );
  assertEquals(
    proof(head, origin, undefined, undefined, [moveRow(from), moveRow(to)]),
    undefined,
    "No retained second site",
  );
  const changedBase = new Map(origin);
  changedBase.set(
    from,
    origin.get(from)!.replace("timeoutMs + 1000", "timeoutMs + 2000"),
  );
  assertEquals(
    proof(head, changedBase),
    undefined,
    "The actual base must preserve the immutable declarations",
  );
});

Deno.test("HTTP move proof follows the unchanged caller body and distinguishes local assertions from module loaders", () => {
  const { head, proof } = moveSource();
  const { caller } = PROXY_HTTP_MOVE;
  const added = "extensions/behaviors/assertions.ts";
  for (
    const source of [
      'const require=(ok:unknown)=>{if(!ok)throw new Error("refused")}; require(condition);',
      "function require(ok:unknown): asserts ok {if(!ok)failure();} require(condition);",
    ]
  ) assert(proof(new Map([...head, [added, source]])));
  for (
    const source of [
      "const require=(path:string)=>import(path); require(name);",
      'import {createRequire} from "node:module"; const load=createRequire(import.meta.url); load(name);',
      'const load=await import("node:module");',
      'require("./operations/_lib/"+"node-proxy-http.ts");',
    ]
  ) assertEquals(proof(new Map([...head, [added, source]])), undefined);
  const changedCaller = head.get(caller)!.replace("if (proxy)", "if (true)");
  assert(changedCaller !== head.get(caller));
  assertEquals(proof(new Map([...head, [caller, changedCaller]])), undefined);
});

Deno.test("forEach model identities use the item or index, with no grandfather growth", () => {
  const findings = (behaviorName: string, type = "behavior", stepName = "step") =>
    analyzeYaml(
      "workflows/loop.yaml",
      JSON.stringify({
        jobs: [{
          name: "job",
          steps: [{
            name: stepName,
            forEach: { item: "node", in: "${{ inputs.nodes }}" },
            task: {
              type,
              ...(type === "model_method"
                ? { modelType: "command/shell", modelIdOrName: behaviorName }
                : { behaviorType: "command/shell", behaviorName }),
              methodName: "execute",
              inputs: { run: "true" },
            },
          }],
        }],
      }),
    ).findings.filter((f) => f.rule === "foreach-shared-identity");
  for (
    const name of [
      "shared",
      '${{ "shared-" + run.id }}',
      '${{ "self.node" }}',
      '${{ "shared" // self.node\n }}',
      "literal-self._index",
      "${{ self.other }}",
      "${{ inputs.self.node }}",
    ]
  ) {
    assertEquals(findings(name).length, 1);
  }
  for (
    const name of ['${{ "node-" + string(self._index) }}', '${{ "node-" + self.node.id }}', '${{ self["node"].id }}']
  ) {
    assertEquals(findings(name).length, 0);
  }
  assertEquals(findings("shared", "workflow").length, 0);
  assertEquals(findings("shared", "model_method").length, 1);
  assertEquals(findings("${{ self.node.id }}", "model_method").length, 0);
  const entries = aggregate(findings("shared"));
  assert(ratchet(aggregate(findings("shared", "behavior", "other")), { schema: 1, entries }).length > 0);
  assertEquals(ratchet(entries, { schema: 1, entries }), []);
  assert(ratchet(aggregate([...findings("shared"), ...findings("shared")]), { schema: 1, entries }).length > 0);
});
Deno.test("workflows declare vaults read directly or forwarded as bounded execution-copy descriptors", () => {
  const descriptor = { type: "object", additionalProperties: false, properties: {
    vault: { type: "string", enum: ["copies"] }, key: { type: "string", pattern: "^key$" },
  }, required: ["vault", "key"] };
  const findings = (value: unknown, vaults?: string[], schema: unknown = {}) => analyzeYaml(
    "workflows/child.yaml", JSON.stringify({ vaults, inputs: { type: "object", properties: { execution_secrets: schema } },
      jobs: [{ name: "job", steps: [{ name: "step", task: { type: "workflow", workflowIdOrName: "child", inputs: value } }] }],
    }),
  ).findings.filter((f) => f.rule === "workflow-vault-declaration");
  for (const vaults of [undefined, [], ["other"]]) {
    assertEquals(findings('${{ vault.get("copies", "key") }}', vaults).map((f) => f.evidence), [["copies"]]);
  }
  assertEquals(findings("${{ vault.get(copies, key) }}", ["copies"]), []);
  assertEquals(findings("${{ vault.get(inputs.selected_vault, key) }}", []).length, 1);
  assertEquals(findings({ execution_secrets: "${{ steps.capture.outputs.execution_secrets }}" }, []).length, 1);
  assertEquals(findings("${{ vault.get(inputs.execution_secrets, key) }}", [], { type: "string", enum: ["copies"] }).map((f) => f.evidence), [["copies"]]);
  const named = { type: "object", additionalProperties: false, properties: { material: descriptor }, required: ["material"] };
  const map = { type: "object", additionalProperties: descriptor };
  for (const schema of [named, map]) {
    for (const value of ['${{ vault.get(inputs.execution_secrets.material.vault, inputs.execution_secrets.material.key) }}',
      { execution_secrets: "${{ inputs.execution_secrets }}" }]) {
      assertEquals(findings(value, [], schema).map((f) => f.evidence), [["copies"]]);
      assertEquals(findings(value, ["copies"], schema), []);
    }
  }
  assertEquals(findings({ material: { vault: "copies", key: "key" } }, []).map((f) => f.evidence), [["copies"]]);
  assertEquals(findings({ execution_secrets: {} }, [], named), []);
  assertEquals(findings({ execution_secrets: "${{ inputs.execution_secrets }}" }, [], { type: "object", additionalProperties: false }), []);
  const source = (name: string, task: unknown, vaults?: string[]) => JSON.stringify({ name, vaults, jobs: [{ name: "job", steps: [{ name: "step", task }] }] });
  for (const dynamic of [false, true]) {
    const nested = analyze(new Map([["workflows/child.yaml", source("child", { type: "behavior", inputs: { value: "${{ vault.get(copies, key) }}" + (dynamic ? " ${{ vault.get(inputs.selected_vault, key) }}" : "") } }, ["copies", "other"])],
      ["workflows/parent.yaml", source("parent", { type: "workflow", workflowIdOrName: "child" }, ["copies"])],
      ["workflows/root.yaml", source("root", { type: "workflow", workflowIdOrName: "parent" })]]), {});
    for (const [file, a] of nested) assertEquals(a.findings.filter((f) => f.rule === "workflow-vault-declaration").map((f) => f.evidence), file.endsWith("/child.yaml") ? [] : file.endsWith("/parent.yaml") ? dynamic ? [["other"]] : [] : dynamic ? [["copies"], ["other"]] : [["copies"]]);
  }
  const entries = aggregate(findings('${{ vault.get("copies", "key") }}'));
  assertEquals(ratchet(entries, { schema: 1, entries }), []);
  assert(ratchet(aggregate(findings('${{ vault.get("other", "key") }}')), { schema: 1, entries }).length > 0);
  assert(ratchet(aggregate(findings('${{ vault.get("copies", "key") }} ${{ vault.get("other", "key") }}')), { schema: 1, entries }).length > 0);
});
