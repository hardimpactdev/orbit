# Gateway response fixtures

Copied without edits from `hardimpactdev/orbit` at delivered PR #1500 merge
`d6bce77673b00e4b5e60cf9260d5441a746c829a`:

- `default.json`: `packages/php-sdk/fixtures/tasks/tasks-group-comment-list/default.json`
- `requested.json`: `packages/php-sdk/fixtures/tasks/tasks-group-comment-create/requested.json`
- `resolved.json`: `packages/php-sdk/fixtures/tasks/tasks-group-comment-create/resolved.json`

The harness changes IDs, bodies, authors and history in memory. It uses no live
Gateway. Any unhandled HTTP call, including an effect dispatch, fails the test.
