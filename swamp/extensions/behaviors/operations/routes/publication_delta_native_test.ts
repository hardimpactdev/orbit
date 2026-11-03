import { assert, assertEquals } from "jsr:@std/assert@1.0.19";
import { exists, publicationFixture, sha256 } from "../../../../tests/_support/route_fixture.ts";
const HEADER = "# Managed by Orbit: Node Caddy build\n{ admin off }\n";
const block = (name: string, target: string) =>
  `# orbit: routes ${name}\nhttps://${name}.test { reverse_proxy ${target} }\n`;
const config = (...blocks: string[]) => HEADER + (blocks.length ? "\n" + blocks.join("\n") : "");
const pool = (id: number, value: number) =>
  `[orbit-instance-${id}]\nchdir = /srv/app-${id}\npm.max_children = ${value}\n`;
const caddy = async (content: string) => ({
  content,
  version: (await sha256(content)).slice(0, 32),
  listen_addresses: ["10.44.0.9"],
  unserved: false,
});

Deno.test("Caddy delta validation failure skips reload", async () => {
  await using f = await publicationFixture();
  f.repo.env.FIXTURE_FAIL = "validate";
  await Deno.writeTextFile(f.live, config());
  await f.delta("caddy", await caddy(config()), await caddy(config(block("new", "a"))), "forward", false);
  assertEquals(f.calls().map((c) => c[0]), ["caddy"]);
});
Deno.test("Caddy delta initial publication creates no staging files", async () => {
  await using f = await publicationFixture();
  const { history } = await f.delta("caddy", await caddy(config()), await caddy(config(block("new", "a"))));
  assertEquals(f.calls().map((c) => c[0]), ["caddy", "systemctl"]);
  const terminal = history.jobs.find((j) => j.name === "completion")!.steps.find((s) => s.name === "result")!;
  assert(JSON.parse(String(terminal.outputs?.stdout)).ready);
  assertEquals(await Deno.readTextFile(f.live), config(block("new", "a")));
  assertEquals([...Deno.readDirSync(f.host)].map((e) => e.name).sort(), ["Caddyfile", "php"]);
});

Deno.test("PHP final withdrawal removes only the managed pool file", async () => {
  await using f = await publicationFixture();
  const php = (text: string) => ({ account_user: f.user, configurations: { "8.4": text } });
  await Deno.writeTextFile(f.php, pool(1, 5));
  const local = f.php.replace("orbit-scopes.conf", "local.conf");
  await Deno.writeTextFile(local, "; local");
  await f.delta("php", php(pool(1, 5)), php(""));
  assert(!exists(f.php));
  assertEquals(await Deno.readTextFile(local), "; local");
});
Deno.test("PHP validation failure skips reload", async () => {
  await using f = await publicationFixture();
  const php = (text: string) => ({ account_user: f.user, configurations: { "8.4": text } });
  await Deno.writeTextFile(f.php, pool(1, 5));
  f.repo.env.FIXTURE_FAIL = "validate";
  await f.delta("php", php(pool(1, 5)), php(pool(1, 7)), "forward", false);
  assertEquals(f.calls().map((c) => c[0]), ["php-fpm8.4"]);
});
Deno.test("PHP versions validate concurrently without a shared behavior lock", async () => {
  await using f = await publicationFixture();
  const second = f.php.replace("8.4", "8.5");
  await Deno.mkdir(second.slice(0, second.lastIndexOf("/")), { recursive: true });
  const barrier = `${f.repo.dir}/validation-barrier`;
  await Deno.mkdir(barrier);
  f.repo.env.FIXTURE_PHP_BARRIER = barrier;
  for (const version of ["8.4", "8.5"]) {
    await Deno.writeTextFile(
      `${f.commands}/php-fpm${version}`,
      `
const barrier = Deno.env.get("FIXTURE_PHP_BARRIER");
Deno.writeTextFileSync(barrier+"/"+name,"");
const deadline = Date.now()+8000;
while ([...Deno.readDirSync(barrier)].length < 2) {
  if (Date.now()>deadline) throw new Error("parallel validation serialized");
  await new Promise(resolve=>setTimeout(resolve,20));
}
`,
      { append: true },
    );
  }
  await f.delta("php", { account_user: f.user, configurations: { "8.4": "", "8.5": "" } }, {
    account_user: f.user,
    configurations: { "8.4": pool(1, 5), "8.5": pool(2, 7) },
  });
  assertEquals(await Deno.readTextFile(f.php), pool(1, 5));
  assertEquals(await Deno.readTextFile(second), pool(2, 7));
  assertEquals(f.calls().filter((c) => c[0] === "systemctl").map((c) => c.at(-1)).sort(), ["php8.4-fpm", "php8.5-fpm"]);
  assertEquals([...Deno.readDirSync(barrier)].map((e) => e.name).sort(), ["php-fpm8.4", "php-fpm8.5"]);
});
