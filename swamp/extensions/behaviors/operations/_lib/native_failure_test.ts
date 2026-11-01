Deno.test("shared failure wrapper preserves only redacted stderr on failure", async () => {
  const result = await new Deno.Command("python3", {
    args: ["-B", new URL("../../../../tests/native_failure_test.py", import.meta.url).pathname],
    stdout: "piped",
    stderr: "piped",
  }).output();
  if (!result.success) throw new Error(new TextDecoder().decode(result.stderr));
});
