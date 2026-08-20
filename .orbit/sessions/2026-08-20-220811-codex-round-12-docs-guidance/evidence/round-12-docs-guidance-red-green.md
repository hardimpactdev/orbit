# Round 12 docs-guidance behavior proof

## RED

Solo process 2575 read the pre-change files. It classified `operator node` as
legacy, admitted Agent IDE as a command documentation domain, assigned future
operations to `redis:*` and `db:*`, and treated `cli` as a current activity
emission channel. It also found the authorization guidance internally
inconsistent. The old guidance therefore reproduced the five reconciled audit
findings.

## GREEN

Solo process 2576 read the changed files and answered all five questions with
high confidence:

1. Grants are the default. Gateway implicit authority is one of four named
   exceptions and is not caller-role leakage.
2. `operator node` is current identity wording.
3. Agent IDE is not a current command documentation domain.
4. Future Valkey-native operations belong to `valkey:*`; database backup and
   restore belong to `database:*`.
5. Current CLI commands do not emit activity. Read paths accept stored `cli`
   channel values only for compatibility.

The process ended with `CONSISTENT across all five`.

## Deterministic checks

- `git diff --check`: passed.
- `bin/orbit-docs-artisan librarian:lint --format=agent --path=domains`:
  passed with zero errors and 227 pre-existing warnings; the changed Activity
  concepts file has no finding.
- `apps/docs/vendor/bin/mago format --check`: passed.
- `composer quality-check`: passed all ten repository units on candidate
  `366d502324e56a5d1924c14d0a8d9f9d963c2d84`. Retained Pest evidence:
  `.orbit/quality-gates/profiles/2026-08-20T20-00-51Z-366d502324e5/gateway_pest.junit.xml`.
