# Swamp credential report

`@nckrtl/node-worker-assets` version `2026.11.21.3` has one method, `credential_report`. It classifies the four read-only outputs of the [credential expiry check](../../docs/reference/operations.md#swamp-credential-expiry-alerts): the access token list, the worker token list, the `swamp.orbit` certificate end date and the token names the Swamp Node consumers hold. It skips a listed generation only when a consumer holds a newer one of its family and principal. Earlier steps of `swamp-credential-monitor` read them as plain commands on Node209. The method reads nothing itself, performs no host effect and ships no file. Its `credential_report` resource holds `checked_at` and `alerts`.

`extensions/behaviors/node-worker/config.ts` owns the official runtime pin and workerUnit renderer. The exported Node209 unit includes the approved existing SSH identity through `LoadCredential=proxy-ssh-identity:/etc/orbit/node-proxy/id_ed25519`. Preserve that key and its worker-readable systemd credential when retiring target workers.

All target actions use official `@swamp/ssh` from209. Follow [the SSH guide](node-official-ssh.md) and [the retirement plan](single-swamp-node-retirement.md).
