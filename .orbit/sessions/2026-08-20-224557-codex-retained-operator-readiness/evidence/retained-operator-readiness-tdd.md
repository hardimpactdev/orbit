# Retained operator readiness TDD

## Red

Command:

`vendor/bin/pest --compact tests/Feature/E2ESupport/Commands/E2EDevTopologyCommandTest.php`

Pre-change result: 2 failed, 13 passed, 99 assertions. The source contract did
not contain the conditional readiness decision, and the focused behavior test
failed because `E2EDevTopologyCommand::requiresGatewayApiReadiness()` did not
exist. This is the expected regression state before implementation.

## Green

The same command passed after implementation: 15 tests passed with 102
assertions. The behavior check proves an operator-only lease skips gateway API
readiness and an operator-plus-gateway lease still requires it.
