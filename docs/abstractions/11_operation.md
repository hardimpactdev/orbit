# Operation Implementation Patterns

Read this with `docs/abstractions/cross-cutting.md` before implementing
operation command ports.

Product behavior remains owned by `docs/domains/11_operation/**` and the
top-level product docs.

## Domain Constraints

- Operation commands do not own durable operation-family intent.
- Local operation commands affect only the caller unless the command documents
  a gateway-mediated fleet path.
- `update:all` fleet target selection is product behavior owned by
  `docs/domains/11_operation/2_update-all/technical/1_update-all.md`; link to
  that contract instead of restating target selection rules here.
- Idempotent operation commands may be verified with focused in-memory Pest plus
  the correct ephemeral E2E gate decision; they do not use persistent live-node
  smoke.
- Doctor and activity commands may reference state families but must not invent
  operation-family drift keys.

## Evidence Pointers

- `docs/domains/11_operation/README.md`
- `app/Console/Commands/UpdateCommand.php`
- `app/Console/Commands/UpdateAllCommand.php`
- `tests/Feature/Commands/Operations/UpdateCommandTest.php`
- `tests/Feature/Commands/Operations/UpdateAllJsonRendererTest.php`
- `tests/Feature/Commands/Operations/UpdateAllHumanRendererTest.php`
