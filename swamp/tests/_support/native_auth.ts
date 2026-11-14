/** Offline subprocess credential, following the pinned Swamp integration/auth_gate_fixture.ts. */
import { generateTestSigningKey, mintTestProof } from "https://raw.githubusercontent.com/swamp-club/swamp/db3f6373207cc41235d2334f19a7901b2b84620f/src/domain/auth/proof_test_helpers.ts";

export async function nativeAuth(folder: string): Promise<Pick<Deno.CommandOptions, "env" | "clearEnv">> {
    const config = `${folder}/fixture-auth`;
    await Deno.mkdir(config, { mode: 0o700 });
    const apiKey = "swamp_integration_fixture_key";
    await Deno.writeTextFile(`${config}/auth.json`, JSON.stringify({
        serverUrl: "https://swamp-club.com", apiKey, apiKeyId: "integration-fixture", username: "integration-fixture",
    }), { mode: 0o600 });
    const now = Math.floor(Date.now() / 1000);
    const minted = await mintTestProof(await generateTestSigningKey(), apiKey, { iat: now, exp: now + 14 * 86_400 });
    await Deno.writeTextFile(`${config}/auth_verified.json`, JSON.stringify({ ...minted, cachedAt: new Date().toISOString() }), { mode: 0o600 });
    const env = Deno.env.toObject();
    for (const name of ["SWAMP_API_KEY", "SWAMP_API_KEY_FILE", "SWAMP_SIGNIN_TOKEN", "SWAMP_CLUB_URL"]) delete env[name];
    return { clearEnv: true, env: { ...env, SWAMP_CONFIG_DIR: config, SWAMP_NO_INBOX_NOTICE: "1" } };
}
