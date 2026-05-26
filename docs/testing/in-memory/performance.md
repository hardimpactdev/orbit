# In-memory performance

Keep the in-memory lane fast by keeping external boundaries fake unless the
behavior under test is the boundary itself.

## Fast feedback

Use a file or filter while developing:

```bash
php artisan test --compact tests/Feature/E2ESupport/VerificationScriptsTest.php
php artisan test --compact --filter='documents the supported verification lanes'
```

Use `composer test` when the touched surface crosses command families, shared
services, renderers, or support helpers.

## Suite shape

`composer test` clears config, gives Pest a 512 MB memory limit, excludes `e2e`
and `slow`, enables parallel mode, and uses compact output:

```bash
php -d memory_limit=512M vendor/pestphp/pest/bin/pest \
  --exclude-group=e2e \
  --exclude-group=slow \
  --parallel \
  --compact
```

If a deterministic test is slow because it shells out, talks to Docker, mutates
Incus, or waits on a network service, move that behavior behind a fake boundary
or into the E2E lane that owns that behavior.
