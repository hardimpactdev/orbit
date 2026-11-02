import { assertEquals } from "jsr:@std/assert@1";
import {
  capturedArgv,
  publicTail,
  readPublicTail,
  redactText,
  redactValue,
} from "../../_lib/public_output.ts";

const encoder = new TextEncoder();
const corpus = [
  "plain output\n",
  "https://user:pw@example.test/x token=abc",
  "-----BEGIN PRIVATE KEY-----\nabc\n-----END PRIVATE KEY-----\nok",
  "-----BEGIN RSA KEY-----\nunterminated",
  "trailing -----END PRIVATE KEY----- after",
  "ghp_" + "a".repeat(20) + " github_pat_" + "b".repeat(22),
  "MODEL_KEY=" + "a1".repeat(32) + " next",
  "head " + "c".repeat(64) + "x and " + "d".repeat(64),
  "\x1b[31mred\x1b[0m Authorization: Basic abc\nProxy-Authorization: x",
  "Bearer abc.def-ghi and bearer zzz",
  '{"api_key": "value", "name": "public", \'SECRET\'=\'q\'}',
  "DB_PASSWORD=hunter2 APP_KEYS: base64:xyz PATH=/usr/bin",
  "café TOKEN=ünïcode 漢字",
];

Deno.test("the text rule matches task-turn public_output.py exactly", async () => {
  const scripts = new URL("../task-turn/scripts/", import.meta.url).pathname;
  const result = await new Deno.Command("python3", {
    args: [
      "-I",
      "-c",
      "import json,sys\nsys.path.insert(0, sys.argv[1])\nfrom public_output import redact_text\nprint(json.dumps([redact_text(x) for x in json.load(sys.stdin)]))",
      scripts,
    ],
    stdin: "piped",
    stdout: "piped",
  }).spawn();
  const writer = result.stdin.getWriter();
  await writer.write(encoder.encode(JSON.stringify(corpus)));
  await writer.close();
  const python = JSON.parse(
    new TextDecoder().decode((await result.output()).stdout),
  );
  assertEquals(corpus.map((text) => redactText(text)), python);
});

Deno.test("known values are replaced whole in one pass, longest first", () => {
  assertEquals(
    redactText("a private-value-long b private-value", {
      values: ["private-value", "private-value-long", "short"],
    }),
    "a [REDACTED] b [REDACTED]",
  );
  // A value shorter than eight characters would blank ordinary words.
  assertEquals(redactText("short", { values: ["short"] }), "short");
});

Deno.test("structured evidence can keep SHA-256 identities", () => {
  const sha = "e".repeat(64);
  assertEquals(redactText(sha, { credentialHex: false }), sha);
  assertEquals(redactText(sha), "[REDACTED]");
  assertEquals(redactValue({ a: [sha, 1] }), { a: ["[REDACTED]", 1] });
});

Deno.test("a tail keeps whole lines within its bound and drops a cut first line", () => {
  const tail = publicTail("x".repeat(100) + "\nsecond\nthird\n", 16);
  assertEquals(tail, { output: "second\nthird\n", truncated: true });
  assertEquals(publicTail("short\n", 16), {
    output: "short\n",
    truncated: false,
  });
  // Discarded older bytes drop the first line even within the bound.
  assertEquals(publicTail("partial\nkept\n", 64, { discarded: true }), {
    output: "kept\n",
    truncated: true,
  });
});

Deno.test("a known value split at the tail boundary never appears even partly", () => {
  const secret = "straddle-secret-0123456789abcdef";
  for (let offset = 0; offset < secret.length + 12; offset++) {
    const raw = secret + "0".repeat(4096 - offset) + "\n" + secret + "\n";
    const { output } = publicTail(raw, 4096, { values: [secret] });
    assertEquals(encoder.encode(output).length <= 4096, true);
    for (let start = 0; start + 8 <= secret.length; start++) {
      assertEquals(
        output.includes(secret.slice(start, start + 8)),
        false,
      );
    }
  }
});

Deno.test("lines holding a fragment of a known value are dropped, not replaced", () => {
  const secret = "unique-private-fragment-0123456789";
  const raw = ("x" + secret.slice(3, 11) + "\n").repeat(512) + "last\n";
  const { output } = publicTail(raw, 4096, { values: [secret] });
  assertEquals(output, "last\n");
});

Deno.test("invalid UTF-8 never joins pieces into a known value", () => {
  const secret = "unique-private-fragment-0123456789";
  const pieces: number[] = [];
  for (let n = 0; n < secret.length; n += 7) {
    if (n) pieces.push(0xff);
    pieces.push(...encoder.encode(secret.slice(n, n + 7)));
  }
  const { output } = publicTail(new Uint8Array(pieces), 4096, {
    values: [secret],
  });
  assertEquals(output.includes(secret), false);
});

Deno.test("a downloaded file is read from its end, redacted and deleted", async () => {
  const directory = await Deno.makeTempDir();
  const path = `${directory}/output`;
  await Deno.writeTextFile(
    path,
    "0".repeat(1048576) + "\nTOKEN=abc\nvisible\n",
  );
  assertEquals(await readPublicTail(path, 4096), {
    output: "TOKEN=[REDACTED]\nvisible\n",
    truncated: true,
  });
  assertEquals([...Deno.readDirSync(directory)], []);
  assertEquals(await readPublicTail(path, 4096), null);
});

Deno.test("the capture wrapper keeps the command literal and its exit code", async () => {
  const directory = await Deno.makeTempDir();
  const argv = capturedArgv(directory + "/out", "step", [
    "sh",
    "-c",
    'printf "%s\\n" "$1"; echo err >&2; exit 7',
    "_",
    "$(touch never) 'quoted'",
  ]);
  const result = await new Deno.Command(argv[0], {
    args: argv.slice(1),
    cwd: directory,
    stdout: "piped",
    stderr: "piped",
  }).output();
  assertEquals(result.code, 7);
  assertEquals(result.stdout.length + result.stderr.length, 0);
  assertEquals(
    await Deno.readTextFile(directory + "/out/step"),
    "$(touch never) 'quoted'\nerr\n",
  );
  assertEquals((await Deno.stat(directory + "/out")).mode! & 0o777, 0o700);
  assertEquals(
    [...Deno.readDirSync(directory)].map((entry) => entry.name),
    ["out"],
  );
});
