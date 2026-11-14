# Conn held sessions

The Gateway holds one T3 client session per Node on a container Instance's T3 server ([Held sessions](../../../../../docs/reference/conn.md#held-sessions)). Two plain, bounded curl commands on the Swamp role worker make it: one mints a pairing link with the Gateway's admin session, the other redeems that link on T3's normal pairing endpoint, `POST /oauth/token`, as a device does. curl reads every secret from the native vault-backed step environment and writes each reply in a private directory.

This type only reads and shapes the private replies. `link` parses T3's pairing result (`id`, `credential`, `expiresAt`, the echoed `label`, which must match) and stores the link as a native sensitive field; its public `credential_key` lets the redeem step read it with `vault.get`. `hold` parses T3's access-token result (`access_token`, `token_type: Bearer`, `expires_in`, `scope`) and stores the session as a native sensitive field. Each resource instance is `pairing-<pairing_id>`, so concurrent holds never share a vault key. The public result holds only the pairing id, link id, the two expiries and the two vault locators.

Codes: a refused mint is `conn.session_rejected` (401) or `conn.request_refused`; a mint reply without T3's shape is `conn.response_invalid`; a refused redeem is `conn.redeem_refused`. A 2xx redeem reply without T3's shape is `conn.session_outcome_unknown`: a session may exist, so the Gateway revokes it by its label.

The official SSH, cfgmgmt and vault behaviors do not implement the T3 auth API, and the existing `@nckrtl/conn-pairing` type delivers a link to a device, not a session to the Gateway. No HTTP implementation, input callback, result callback or custom transport is in this type.
