# In-memory performance

Keep the in-memory lane fast by keeping external boundaries fake unless the
behavior under test is the boundary itself.

## Fast feedback

Use a file or filter while developing:

```bash
bin/orbit-gateway-pest --compact tests/Feature/E2ESupport/VerificationScriptsTest.php
bin/orbit-gateway-pest --compact --filter='documents the supported verification lanes'
```

Use `composer test` when the touched surface crosses command families, shared
services, renderers, or support helpers.

## Suite shape

`composer test` clears config and gives Pest a 512 MB memory limit. It excludes
`e2e` and `slow` from the gateway suite. It excludes `slow` from the CLI suite,
enables parallel mode for the gateway, and uses compact output:

```bash
bin/orbit-gateway-pest \
  --exclude-group=e2e \
  --exclude-group=slow \
  --parallel \
  --compact
bin/orbit-cli-pest --exclude-group=slow --compact
```

If a deterministic test is slow because it shells out, move that behavior
behind a fake boundary. Do the same for Docker calls, Incus mutation, real
pseudo-terminals, real transport timing, or network waits. If the real boundary
is the behavior under test, move it into the `slow` group or the E2E lane that
owns that behavior.
