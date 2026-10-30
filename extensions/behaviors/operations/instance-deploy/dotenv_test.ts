import { assertEquals, assertThrows } from "jsr:@std/assert@1.0.19";
import { mergeManagedTesting, renderAccountEnvironment } from "./dotenv.ts";

Deno.test("account env rendering matches dotenv escaping, sorted keys and admitted references", () => {
  assertEquals(
    renderAccountEnvironment(
      {
        Z: 'a\\b"$c\n\r',
        A: "https://{{instance.domain}}/{{instance.environment}}",
      },
      "development",
      "fixture.test",
    ),
    'A="https://fixture.test/development"\nZ="a\\\\b\\"\\$c\\n\\r"\n',
  );
  assertEquals(
    renderAccountEnvironment(
      { A: "{{instance.environment}}" },
      "production",
      null,
    ),
    'A="production"\n',
  );
});
Deno.test("account env refuses invalid or unavailable values without exposing their content", () => {
  const invalid: Record<string, string>[] = [{ A: "{{instance.domain}}" }, {
    A: "{{unknown}}",
  }, {
    A: "private-fixture\0",
  }, { "bad key": "private-fixture" }];
  for (const values of invalid) {
    const error = assertThrows(
      () => renderAccountEnvironment(values, "development", null),
      Error,
    );
    assertEquals(error.message.includes("private-fixture"), false);
  }
  assertThrows(
    () =>
      renderAccountEnvironment({ A: "a".repeat(65_537) }, "development", null),
    Error,
    "env.configuration_invalid",
  );
  assertThrows(
    () =>
      renderAccountEnvironment(
        Object.fromEntries(
          Array.from({ length: 17 }, (_, i) => [`A${i}`, "a".repeat(65_536)]),
        ),
        "development",
        null,
      ),
    Error,
    "env.configuration_invalid",
  );
});
Deno.test("managed testing merge preserves unmanaged lines and replaces each owned key once", () => {
  assertEquals(
    mergeManagedTesting(
      '# local\n export DB_HOST = old\nDB_HOST=duplicate\nDB_PORT=old\nLOCAL="keep"',
      'DB_HOST="new"\nDB_NAME="test"\n',
      ["DB_HOST", "DB_PORT", "DB_NAME"],
    ),
    '# local\nDB_HOST="new"\nLOCAL="keep"\nDB_NAME="test"\n',
  );
});
Deno.test("managed testing merge refuses duplicate or incomplete supplied updates", () => {
  for (
    const update of [
      'A="one"\nA="two"\n',
      'A="incomplete"',
      'OTHER="ignored"\n',
    ]
  ) {
    assertThrows(
      () => mergeManagedTesting('A="old"\n', update, ["A"]),
      Error,
      "env.configuration_invalid",
    );
  }
});
