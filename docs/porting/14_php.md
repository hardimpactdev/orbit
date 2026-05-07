# 14_php — PHP Workstream

Detail file for the PHP command family. Top-level command status lives in
[`PORTING.md`](PORTING.md). Doc authority: `docs/commands/14_php/`.

## Status

Complete for `php:list` and `php:use`. The focused vertical slice ports
registry-backed behavior with local gateway execution, typed gateway forwarding,
JSON/human renderers, API endpoints, in-memory Pest coverage, and Docker feature
E2E coverage.

Docker E2E evidence: `tests/E2E/PhpRuntimeCommandsTest.php` seeds gateway PHP
tool facts, reads `php:list`, changes app/workspace/node CLI intent with
`php:use`, and asserts no runtime installation occurs. Passing command:

```bash
composer test:e2e:docker -- --filter=PhpRuntimeCommandsTest
```

Activity logging is covered through the API controller `Loggable` contracts, but
no PHP-family E2E activity invariant has been added yet.

## Commands

- [x] `php:list` — in-memory evidence:
  `tests/Feature/Commands/Php/PhpListCommandTest.php`,
  `tests/Feature/Http/Api/PhpRuntimeControllerTest.php`,
  `tests/Unit/Services/Php/PhpRuntimeManagerTest.php`, and
  `tests/Unit/Http/Gateway/Requests/Php/PhpRuntimeRequestsTest.php`. Docker E2E:
  `tests/E2E/PhpRuntimeCommandsTest.php`; `composer test:e2e:docker -- --filter=PhpRuntimeCommandsTest`.
- [x] `php:use` — in-memory evidence:
  `tests/Feature/Commands/Php/PhpUseCommandTest.php`,
  `tests/Feature/Http/Api/PhpRuntimeControllerTest.php`,
  `tests/Unit/Services/Php/PhpRuntimeManagerTest.php`, and
  `tests/Unit/Http/Gateway/Requests/Php/PhpRuntimeRequestsTest.php`. Docker E2E:
  `tests/E2E/PhpRuntimeCommandsTest.php`; `composer test:e2e:docker -- --filter=PhpRuntimeCommandsTest`.
