import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
const env = (key: string) => Deno.env.get(key)!;
assertEquals(Deno.args.slice(0, 3), ["--yes", "--package=@bitwarden/cli@2026.6.0", "bw"]);
const args = Deno.args.slice(3), path = env("ORBIT_BW_FIXTURE_STATE");
const state = JSON.parse(Deno.readTextFileSync(path));
(state.commands ??= []).push(args);
const save = () => Deno.writeTextFileSync(path, JSON.stringify(state));
const fail = () => {
  save();
  console.error(env("FIXTURE_ITEM_SECRET"));
  Deno.exit(1);
};
assert(!Deno.env.has("ORBIT_VAULT_CONFIGURATION"));
if (state.fail_command === args[0] || args[0] === "delete" && args[2] === state.fail_delete) fail();
let output: unknown = {};
if (args[0] === "login") assert(env("BW_CLIENTSECRET") === env("FIXTURE_CLIENT_SECRET"));
else if (args[0] === "unlock") {
  assertEquals(Deno.statSync(env("BITWARDENCLI_APPDATA_DIR") + "/session").mode! & 0o777, 0o600);
  assertEquals(args, ["unlock", "--passwordenv", "BW_PASSWORD", "--raw"]);
  assert(env("BW_PASSWORD") === env("FIXTURE_MASTER_PASSWORD"));
  save();
  console.log(env("FIXTURE_SESSION"));
  Deno.exit();
} else if (!["config", "sync", "logout"].includes(args[0])) {
  assert(env("BW_SESSION") === env("FIXTURE_SESSION"));
  if (args[0] === "list") {
    output = args[1] === "organizations"
      ? [{ id: env("FIXTURE_ORGANIZATION"), name: "Orbit" }]
      : state.ciphers.filter((row: { deleted: boolean }) => row.deleted === args.includes("--trash"))
        .map((row: Record<string, unknown>) => ({
          id: row.id,
          organizationId: row.organization_id,
          collectionIds: row.collection_ids,
          revisionDate: row.revision,
          deletedDate: row.deleted ? "deleted" : null,
          fields: [{ name: "orbit.source", value: row.source }, {
            name: "password",
            value: env("FIXTURE_ITEM_SECRET"),
          }],
        }));
  } else if (args[0] === "delete") {
    assert(args[1] === "item" && args[3] === "--permanent");
    state.deletes.push(args[2]);
    state.ciphers = state.ciphers.filter((row: { id: string }) => row.id !== args[2]);
  } else {
    assert(["create", "edit"].includes(args[0]) && args[1] === "item");
    const payload = JSON.parse(atob(await new Response(Deno.stdin.readable).text()));
    const fields = Object.fromEntries(payload.fields.map((field: { name: string }) => [field.name, field]));
    assertEquals(fields.password.type, 1);
    assert(fields.password.value === env("FIXTURE_ITEM_SECRET"));
    assertEquals([payload.type, payload.secureNote], [2, { type: 0 }]);
    const id = args[0] === "edit" ? args[2] : "new-cipher";
    state.writes.push({ source: fields["orbit.source"].value, id });
    state.password_sha256 = Array.from(
      new Uint8Array(
        await crypto.subtle.digest(
          "SHA-256",
          new TextEncoder().encode(fields.password.value),
        ),
      ),
      (b) => b.toString(16).padStart(2, "0"),
    ).join("");
    output = { id, revisionDate: "new-revision", ...payload };
    if (state.fail_write) fail();
  }
}
save();
console.log(JSON.stringify(output));
