import { assertEquals } from "jsr:@std/assert@1.0.19";

export function registerServerCreateEffect(
    driver: "mysql" | "pgsql" | "redis",
) {
    Deno.test({
        name:
            `disposable ${driver} server provisions, scrubs secrets and replays`,
        fn: async () => {
            const result = await new Deno.Command("python3", {
                args: [
                    new URL(
                        "./server_create_effect_fixture.py",
                        import.meta.url,
                    ).pathname,
                    driver,
                ],
                stdout: "piped",
                stderr: "piped",
            }).output();
            assertEquals(
                result.success,
                true,
                new TextDecoder().decode(result.stderr),
            );
            const value = JSON.parse(
                new TextDecoder().decode(result.stdout).trim().split("\n").at(
                    -1,
                )!,
            );
            assertEquals(value, {
                driver,
                scrubbed: true,
                repaired: true,
            });
        },
    });
}
