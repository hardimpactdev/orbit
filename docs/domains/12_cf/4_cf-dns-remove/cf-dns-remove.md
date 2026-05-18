# `orbit cf-dns:remove`

Remove a Cloudflare address record that Orbit manages.

## Usage

```bash
orbit cf-dns:remove <record-id> --zone=<zone> [--force] [--json]
```

## Examples

```bash
orbit cf-dns:remove record-1 --zone=example.com
orbit cf-dns:remove record-1 --zone=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa --force --json
```

## Arguments and options

- `record-id`: Cloudflare DNS record ID to remove.
- `--zone=<zone>`: Cloudflare zone ID or domain name that contains the record.
- `--force`: Confirm removal without an interactive prompt.
- `--json`: Return the removal result in the JSON output.

## What Happens

Run `orbit cf-dns:remove` to delete one Cloudflare `A` or `AAAA` address record from the selected zone.

`cf-dns:remove` asks the gateway to delete one Cloudflare DNS address record
from the selected zone. The command is intentionally limited to `A` and `AAAA`
records because general DNS administration is outside Orbit's current scope.

Removal is destructive. Interactive use asks for confirmation unless `--force`
is supplied. Non-interactive use, including `--json`, requires `--force`.

## Output

You will see a confirmation of the removed record ID.

Human output confirms the removed record ID. Use `--json` for machine-readable
output.

## Requirements

- The caller can reach the Orbit gateway.
- The caller is authorized for Cloudflare provider administration.
- The gateway has a Cloudflare API token configured.
- The selected zone and DNS record exist in the Cloudflare account.

## Related Commands

Use these commands to review or add records before or after removal.

- [`orbit cf-dns:list`](../2_cf-dns-list/cf-dns-list.md)
- [`orbit cf-dns:add`](../3_cf-dns-add/cf-dns-add.md)
- [Technical contract](technical/1_cf-dns-remove.md)
