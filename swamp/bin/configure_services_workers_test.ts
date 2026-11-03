import { assert, assertEquals, assertFalse } from "jsr:@std/assert@1.0.19";
import { nativeRepo, ROOT } from "../tests/_support/native_harness.ts";
import { readCalls } from "../tests/_support/fake_tool.ts";
const TOOL = new URL("../tests/_support/fake_tool.ts", import.meta.url).href;
const SECRET = "worker-secret-SENTINEL";
Deno.test("worker reply accepts a banner and refuses extra content", async () => {
  for (
    const [source, success] of [['Remote wss://swamp.orbit\n{"id":17}\n', true], [
      '{"id":17}\nunexpected trailing output',
      false,
    ]] as const
  ) {
    const result = await new Deno.Command("python3", {
      args: [
        "-c",
        `import runpy,sys,json; print(json.dumps(runpy.run_path(sys.argv[1])["reply"](sys.argv[2])))`,
        `${ROOT}/bin/configure-services-workers`,
        source,
      ],
    }).output();
    assertEquals(result.success, success);
    if (success) assertEquals(JSON.parse(new TextDecoder().decode(result.stdout)), { id: 17 });
  }
});
async function fixture(failure = "") {
  const repo = await nativeRepo(),
    log = `${repo.dir}/calls`,
    journal = `${repo.dir}/journal`,
    created = `${repo.dir}/created`;
  await Deno.mkdir(`${repo.dir}/tools`);
  for (const name of ["orbit", "swamp", "ssh"]) {
    await repo.fakeBinary(
      `tools/${name}`,
      `import {logCall,readStdin} from ${JSON.stringify(TOOL)};
const a=Deno.args,name=${JSON.stringify(name)},source=await readStdin();logCall(${
        JSON.stringify(log)
      },{argv:[name,...a],source});
if(name==="ssh"){if(a.at(-1)!.includes("token reveal"))console.log(JSON.stringify({token:${JSON.stringify(SECRET)}}));}
else if(name==="orbit"){
 if(a[0]==="node:show")console.log(JSON.stringify({status:"active",platform:"linux",user:"nckrtl",wireguard_ip:"10.44.0.10",access:{can_access:[]}}));
 else if(a[0]==="process:list"){let present=false;try{Deno.statSync(${
        JSON.stringify(created)
      });present=true;}catch{}console.log(JSON.stringify({processes:present?[{id:900,name:"migration-sabre"}]:[]}));}
 else if(a[0]==="process:create"){Deno.writeTextFileSync(${
        JSON.stringify(created)
      },"created");console.log('{"id":900}');}else console.log('{}');
}else{
 if(a.slice(0,3).join(" ")==="worker token create"){if(${
        JSON.stringify(failure)
      }==="enrollment_timeout")Deno.exit(1);console.log(JSON.stringify({token:${
        JSON.stringify(SECRET)
      },expiresAt:"2026-11-04T00:00:00Z"}));}
 else if(a.slice(0,3).join(" ")==="access token mint"){if(${
        JSON.stringify(failure)
      }==="existing_server")Deno.exit(1);console.log('{"vaultRef":{"vaultName":"_token-secrets","secretKey":"fixture"}}');}
 else if(a.slice(0,2).join(" ")==="worker list")console.log('{"workers":[{"tokenName":"migration-sabre","status":"idle","labels":{"pool":"migration","node":"12"}}]}');else console.log('{}');
}`,
    );
  }
  const result = await new Deno.Command("python3", {
    args: [
      `${ROOT}/bin/configure-services-workers`,
      "--token-file",
      "/operator/credential",
      "--journal",
      journal,
      "--node",
      "12",
    ],
    env: { PATH: `${repo.dir}/tools:${Deno.env.get("PATH")}` },
    stdout: "piped",
    stderr: "piped",
  }).output();
  return {
    repo,
    result,
    journal: await Deno.readTextFile(journal),
    calls: readCalls<{ argv: string[]; source: string }>(log),
  };
}
Deno.test("worker plaintext crosses only SSH stdin and never Process spec or journal", async () => {
  const f = await fixture();
  await using _ = f.repo;
  assert(f.result.success, new TextDecoder().decode(f.result.stderr));
  assertFalse(f.journal.includes(SECRET));
  assertFalse(new TextDecoder().decode(f.result.stdout).includes(SECRET));
  assert(f.calls.every((c) => !JSON.stringify(c.argv).includes(SECRET)));
  const transfers = f.calls.filter((c) => c.source.includes(SECRET));
  assertEquals(transfers.length, 1);
  assertEquals(transfers[0].argv[0], "ssh");
  const entries = f.journal.trim().split("\n").map((l) => JSON.parse(l));
  assertEquals(entries.filter((e) => ["access_added", "enrollment_created"].includes(e.action)).map((e) => e.action), [
    "access_added",
    "access_added",
    "enrollment_created",
  ]);
  assertEquals(entries.at(-1).expires_at, "2026-11-04T00:00:00Z");
  const spec = f.calls.find((c) => c.argv[0] === "orbit" && c.argv[1] === "process:create")!.argv;
  for (const arg of ["--command=auto", "--runtime=systemd", "--command=--network", "--command=host"]) {
    assert(spec.includes(arg));
  }
  assertFalse(spec.some((a) => a.includes("ci-")));
});
Deno.test("failed worker mints record intent without revoking unowned credentials", async () => {
  for (const failure of ["enrollment_timeout", "existing_server"]) {
    const f = await fixture(failure);
    await using _ = f.repo;
    assertFalse(f.result.success);
    assertFalse(f.calls.some((c) => c.argv.slice(1, 4).join(" ") === "access token revoke"));
    const entries = f.journal.trim().split("\n").map((l) => JSON.parse(l));
    for (const action of ["enrollment_create_attempt", "setup_failed"]) {
      assert(entries.some((e) => e.action === action));
    }
    if (failure === "enrollment_timeout") {
      assertFalse(f.calls.some((c) => c.argv.slice(1, 4).join(" ") === "worker token revoke"));
    }
  }
});
