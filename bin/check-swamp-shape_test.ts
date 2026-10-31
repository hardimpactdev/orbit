import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import {
  aggregate,
  analyze,
  analyzeTs,
  analyzeYaml,
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
    rules(`const p=${JSON.stringify(program(21))};`).includes("inline-script"),
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
      analyzeYaml("workflows/test.yaml", workflow(input)).findings.length > 0,
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
      ratchet([next], { schema: SCHEMA, entries: [next] }, baseline).length > 0,
    );
  }
  assertEquals(ratchet([], { schema: SCHEMA, entries: [] }, baseline), []);
});
Deno.test("workflow coverage separates clean, unknown and accepted debt", () => {
  const sources = new Map([
    ["workflows/clean.yaml", workflow("        run: true\n")],
    ["workflows/debt.yaml", workflow("        run: sleep 10\n")],
    ["workflows/unknown.yaml", "jobs:\n- steps:\n  - task: {type: unknown}\n"],
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
