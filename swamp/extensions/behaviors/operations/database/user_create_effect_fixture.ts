import { assertEquals } from "jsr:@std/assert@1.0.19";
export function registerUserEffect(driver: "mysql" | "pgsql") {
    Deno.test(`disposable ${driver} SQL user creation, login and recorded repair`, async () => {
        const script =
            new URL("./user_create_effect_fixture.py", import.meta.url)
                .pathname;
        const result = await new Deno.Command("python3", {
            args: [script, driver],
            stdout: "piped",
            stderr: "piped",
        }).output();
        assertEquals(
            result.success,
            true,
            "Disposable SQL user fixture must succeed",
        );
        assertEquals(JSON.parse(new TextDecoder().decode(result.stdout)), {
            driver,
            user_created: true,
            login: true,
            recorded_repair: true,
        });
    });
}
