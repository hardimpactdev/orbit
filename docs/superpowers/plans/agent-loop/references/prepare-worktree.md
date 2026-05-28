# Prepare Worktree

Stage 1 command sequence the mechanical orchestrator runs to prepare one todo's
isolated checkout before implementation. Run every step from the worktree path.
This sequence is verified to run green from a fresh worktree with no `.env`
setup: `phpunit.xml` supplies the test `APP_KEY` and a `:memory:` database, and
the gateway `artisan` boots without an `.env` file.

1. Create the worktree off current `main`:

   ```bash
   git worktree add .worktrees/agent-loop-<todo_id> -b agent-loop-<todo_id> main
   ```

2. Install dependencies for every app and package. Root `composer install`
   installs nothing on its own, and the gateway/docs/cli apps have no functional
   frontend assets yet, so skip the npm build that `composer setup` runs.
   Install PHP dependencies per project instead:

   ```bash
   composer install
   for dir in packages/core apps/gateway apps/docs apps/cli; do
       (cd "$dir" && composer install)
   done
   ```

3. Verify the suite is green and the E2E lane is ready from a clean checkout:

   ```bash
   composer test
   composer e2e:preflight
   ```

4. If every step succeeds, the worktree is prepared. If any step fails, leave
   the worktree in place for inspection and report the failing step rather than
   dispatching an implementer onto a broken checkout.
