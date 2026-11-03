import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
const env = (key: string) => Deno.env.get(key)!;
assertEquals(Deno.args.slice(0, 3), ["--yes", "--package=@bitwarden/cli@2026.6.0", "bw"]);
const args = Deno.args.slice(3), path = env("ORBIT_BW_FIXTURE_STATE");
assert(!Deno.env.has("ORBIT_VAULT_CONFIGURATION"));
// The workflow reads status, organizations and collections in parallel.
using lock = Deno.openSync(path + ".lock", { create: true, write: true });
lock.lockSync(true);
const state = JSON.parse(Deno.readTextFileSync(path));
state.commands.push(args);
Deno.writeTextFileSync(path, JSON.stringify(state));
if (state.fail === args[0] || state.readonly && args[0] === "create") {
  console.error(env("FIXTURE_CLIENT_SECRET"));
  Deno.exit(1);
}
let output: unknown = {};
if (args[0] === "login") assert(env("BW_CLIENTSECRET") === env("FIXTURE_CLIENT_SECRET"));
else if (args[0] === "unlock") {
  assertEquals(Deno.statSync(env("BITWARDENCLI_APPDATA_DIR") + "/session").mode! & 0o777, 0o600);
  assert(env("BW_PASSWORD") === env("FIXTURE_MASTER_PASSWORD"));
  Deno.writeTextFileSync(
    env("BITWARDENCLI_APPDATA_DIR") + "/data.json",
    JSON.stringify({
      user_account_kdfConfig_kdfConfig: { kdfType: state.kdf ?? 0, iterations: 600000 },
    }),
  );
  console.log(env("FIXTURE_SESSION"));
  Deno.exit();
} else if (args[0] === "list") {
  assert(env("BW_SESSION") === env("FIXTURE_SESSION"));
  output = state[args[1]].map((row: Record<string, unknown>) =>
    state.echo_secret === args[1] ? { ...row, name: env("FIXTURE_CLIENT_SECRET") } : row
  );
} else if (args[0] === "status") output = { userId: "account", userEmail: "setup@example.test" };
else if (args[0] === "create") {
  assert(!(await new Response(Deno.stdin.readable).text()).includes("setup-private"));
  output = { id: "setup-probe" };
}
console.log(JSON.stringify(output));
