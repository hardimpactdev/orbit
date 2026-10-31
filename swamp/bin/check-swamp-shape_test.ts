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
  workflowCoverage,
} from "./check-swamp-shape.ts";
const file = "extensions/behaviors/example.ts";
const inventory = {
  "agent.status": "observer",
  "cli.install": "action",
  "caddy.publish": "action",
};
const officialSshFixture = Deno.readTextFileSync(
  new URL("../tests/fixtures/shape/official-ssh-vault.yaml", import.meta.url),
);
Deno.test("official SSH transport identity vault fields are native sensitive inputs", () => {
  assertEquals(
    analyzeYaml("models/official-ssh.yaml", officialSshFixture).findings,
    [],
  );
  const step = `name: official-ssh
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
  `name: example\njobs:\n- name: action\n  steps:\n  - name: run\n    task:\n      type: behavior\n      behaviorType: command/shell\n      methodName: execute\n      inputs:\n${inputs}`;
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
