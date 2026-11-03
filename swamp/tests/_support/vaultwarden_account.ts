/** Account setup data for the opt-in real Vaultwarden protocol test. */
import {
  constants,
  createCipheriv,
  createHmac,
  generateKeyPairSync,
  pbkdf2Sync,
  publicEncrypt,
  randomBytes,
} from "node:crypto";
import { Buffer } from "node:buffer";
export async function provision(url: string, password: string, caFile: string) {
  const encode = (value: Uint8Array) => Buffer.from(value).toString("base64");
  const hmac = (key: Uint8Array, value: Uint8Array) => createHmac("sha256", key).update(value).digest();
  function encrypt(value: Uint8Array, key: Uint8Array) {
    const iv = randomBytes(16), engine = createCipheriv("aes-256-cbc", key.slice(0, 32), iv);
    const cipher = Buffer.concat([engine.update(value), engine.final()]);
    return "2." + [iv, cipher, hmac(key.slice(32), Buffer.concat([iv, cipher]))].map(encode).join("|");
  }
  let token = "";
  using client = Deno.createHttpClient({ caCerts: [await Deno.readTextFile(caFile)] });
  // deno-lint-ignore no-explicit-any
  async function request(path: string, value?: Record<string, any>, form = false): Promise<any> {
    const headers: Record<string, string> = {
      Accept: "application/json",
      "Content-Type": form ? "application/x-www-form-urlencoded" : "application/json",
    };
    if (token) headers.Authorization = "Bearer " + token;
    const response = await fetch(url + path, {
      client,
      headers,
      method: value ? "POST" : "GET",
      body: value
        ? form ? new URLSearchParams(Object.entries(value).map(([key, v]) => [key, String(v)])) : JSON.stringify(value)
        : undefined,
      signal: AbortSignal.timeout(15_000),
    });
    if (!response.ok) {
      await response.body?.cancel();
      throw new Error(`Vaultwarden fixture HTTP ${response.status}`);
    }
    return await response.json();
  }
  const email = `native-sync-${crypto.randomUUID().replaceAll("-", "")}@example.test`;
  const master = pbkdf2Sync(password, email, 600000, 32, "sha256");
  const passwordHash = encode(pbkdf2Sync(master, password, 1, 32, "sha256"));
  const stretched = Buffer.concat([hmac(master, Buffer.from("enc\x01")), hmac(master, Buffer.from("mac\x01"))]);
  const userKey = randomBytes(64), keys = generateKeyPairSync("rsa", { modulusLength: 2048, publicExponent: 65537 });
  const verification = await request("/identity/accounts/register/send-verification-email", {
    email,
    name: "Native sync fixture",
  });
  await request("/identity/accounts/register/finish", {
    email,
    emailVerificationToken: verification,
    name: "Native sync fixture",
    masterPasswordHash: passwordHash,
    key: encrypt(userKey, stretched),
    kdf: 0,
    kdfIterations: 600000,
    keys: {
      publicKey: encode(keys.publicKey.export({ type: "spki", format: "der" })),
      encryptedPrivateKey: encrypt(keys.privateKey.export({ type: "pkcs8", format: "der" }), userKey),
    },
  });
  token = (await request("/identity/connect/token", {
    grant_type: "password",
    username: email,
    password: passwordHash,
    scope: "api offline_access",
    client_id: "cli",
    deviceType: 25,
    deviceIdentifier: "lane23-disposable-native-sync",
    deviceName: "Native sync test",
  }, true)).access_token;
  const orgKey = randomBytes(64);
  const organization = await request("/api/organizations", {
    name: "Native sync fixture",
    billingEmail: email,
    planType: 0,
    collectionName: encrypt(Buffer.from("Disposable"), orgKey),
    key: "4." +
      encode(
        publicEncrypt({ key: keys.publicKey, padding: constants.RSA_PKCS1_OAEP_PADDING, oaepHash: "sha1" }, orgKey),
      ),
  });
  const snapshot = await request("/api/sync?excludeDomains=true");
  const apiKey = await request("/api/accounts/api-key", { masterPasswordHash: passwordHash });
  return {
    url,
    client_id: "user." + snapshot.profile.id,
    client_secret: apiKey.apiKey,
    master_password: password,
    organization_id: organization.id,
    collection_id: snapshot.collections[0].id,
  };
}
