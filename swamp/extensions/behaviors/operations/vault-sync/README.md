# Vault CLI primitives

Every action runs one pinned Bitwarden CLI command on a Swamp worker.
No script assets, shell programs, jq filters or commands reach a Node.
Typed data methods bind the frozen configuration, select the organization and
collection, check KDF support, encode cipher stdin and whitelist CLI responses.
They execute no CLI, HTTP or cryptographic protocol.

Native schema-sensitive fields put credentials and session keys in the vault.
Command environments resolve the generated key names with vault.get. Only the
needed credential reaches each CLI action. Private inventory, write responses
and cipher stdin stay in one mode-0700 scratch directory. Cipher input files
use mode 0600. No raw value enters history, logs or argv; CLI stderr is discarded.
The Gateway's bounded grants cover only the native credential and session fields.

Cleanup runs after success, failure or a skipped dependency, then removes the
private directory. Setup deletes its harmless write probe and logs out before
removal. Sync retains each public write receipt for native summary resume.
Native assertions check the final state. A new setup always verifies credentials.
