"""Replay the last 200 main commits against the frozen pre-table selector."""
from functools import lru_cache
import json
from pathlib import Path
import unittest

from test_ci_build_plan import plan

ROOT = plan.ROOT
JOBS = ('php', 'gateway-static', 'gateway-tests', 'cli', 'cli-static', 'cli-tests', 'sdk-static', 'e2e-static', 'docs', 'api-reference', 'web', 'pi', 'agent', 'swamp', 'proof')
PROSE = ('.md', '.mdx', '.mmd')
SELECTION = plan.SELECTION
proof_run = plan.proof_run
SWAMP_GATEWAY_INPUTS = ('apps/gateway/app/Infrastructure/GatewayReleases/SwampGrantRenderer.php', 'apps/gateway/app/Support/ValidatedData.php', 'apps/gateway/bin/render-swamp-grants', 'apps/gateway/app/Domain/Tasks/direct-operations.php')
FROZEN_SCANNED = ('.ts', '.js', '.py', '.yaml', '.yml')
import re

# Known source roots are explicit even for the desktop app, whose checks remain paused.
APPS = {'agent', 'cli', 'desktop', 'docs', 'e2e', 'gateway', 'pi-server', 'web'}
SOURCE_ROOTS = {'docs', '.github', 'bin', 'tools', '.agents', '.codex', '.githooks', 'scripts', 'swamp'}
ROOT_INPUTS = {'.editorconfig', '.gitattributes', '.gitignore', '.node-version', 'composer.json', 'composer.lock', 'vet.json', 'LICENSE'}
PROSE = ('.md', '.mdx', '.mmd')


def check_source_roots(paths):
    for path in paths:
        parts = path.split('/')
        known = (parts[0] == 'apps' and len(parts) > 2 and parts[1] in APPS
                 or parts[0] == 'packages' and len(parts) > 2 and parts[1] == 'php-sdk'
                 or parts[0] in SOURCE_ROOTS and len(parts) > 1
                 or len(parts) == 1 and (path in ROOT_INPUTS or path.endswith(PROSE)))
        if not known:
            raise ValueError(f'Unknown CI source root: {path}. Add its check inputs to bin/ci-build-plan.')


def swamp_inputs(path):
    """Check inputs, including the files read by fixtures. Prose is handled by Docs instead."""
    checks = set()
    if path.startswith('swamp/') and not path.endswith(PROSE):
        checks.add('shape')
        relative = path.removeprefix('swamp/')
        if (relative.startswith(('workflows/', 'extensions/', 'models/'))
                or relative.startswith('config/') and not relative.startswith('config/swamp-shape')
                or relative.startswith(('bin/validate-swamp-workflows', 'bin/check-grant-patterns', 'bin/lint-workflow-placement'))
                or relative == 'tests/node_add_bootstrap_test.ts'):
            checks.add('validate')
        # Operation tests parse workflows, grants, manifests and Ansible assets; they also import
        # maintenance helpers, native test support and the YAML analyser. Keep those transitive inputs.
        if relative.startswith(('workflows/', 'extensions/', 'models/', 'ansible/', 'scripts/', 'tests/',
                                'bin/check-swamp-shape', 'bin/gate-test-support', 'bin/check-grant-patterns',
                                'bin/bounded-phase-scripts.ts', 'bin/workflow-placement.ts', 'bin/swamp-shape-relocations.ts')):
            checks.add('tests')
        if relative.startswith('config/'):
            checks.add('tests')
        if '/' not in relative:
            checks.update(('shape', 'validate', 'tests'))
    if path in SWAMP_GATEWAY_INPUTS:
        checks.update(('shape', 'tests'))
        if path != 'apps/gateway/app/Domain/Tasks/direct-operations.php':
            checks.add('validate')
    if path.startswith('apps/gateway/resources/agent/'):
        checks.update(('shape', 'tests'))
    if path.startswith(('apps/gateway/app/', 'apps/gateway/extensions/')) and path.endswith(FROZEN_SCANNED):
        checks.add('shape')
    return checks


def tooling_jobs(path):
    """Narrow known tooling; shared configuration and unclassified tools keep the broad check set."""
    if path.startswith(('.agents/', '.codex/', '.githooks/')):
        return {'docs'}
    if path == 'bin/docs-navigation':
        return {'docs', 'gateway-tests', 'api-reference', 'web'}
    if path in ('bin/build-api', 'bin/docs-openapi', 'bin/mcp-tools'):
        return {'gateway-tests', 'api-reference', 'web'}
    if path.startswith(('bin/docs-', 'bin/check-flows')) and path != 'bin/docs-openapi':
        return {'docs'}
    if path in ('bin/api-fixtures', 'bin/check-operation-kinds'):
        return {'api-reference', 'web'}
    if path == 'bin/swamp-shape-gate':
        return set()
    if path == 'bin/check-frozen-agent-commands':
        return {'php'}
    if path == 'bin/ci-proof-run' or path.startswith('tools/swamp-proof-guest/'):
        return {'proof'}
    if path.startswith(('tools/test_ci_', 'tools/test_swamp_import_base', 'tools/test_frozen_agent_commands')):
        return set()  # Select build inputs always runs these fixtures.
    if path.startswith('tools/phpstan/'):
        return {'gateway-static', 'cli-static', 'sdk-static', 'e2e-static'}
    if path in ('bin/orbit-build-cli-binary', 'bin/orbit-version', 'bin/orbit-cli-release-version', 'bin/orbit-cli-release-assets'):
        return {'cli', 'cli-tests'}
    return set(JOBS)


def select(paths, main=False, selection=None):
    """`selection` is the text of the selection list; omitted, the list of this checkout is read."""
    paths = [path for path in paths if path]
    check_source_roots(paths)
    swamp_only = bool(paths) and all(path.startswith('swamp/') for path in paths)
    jobs = dict.fromkeys(JOBS, False)
    swamp_checks = dict.fromkeys(('shape', 'validate', 'tests'), False)
    agent = False
    for path in paths:
        # Gateway tests invoke the CLI consent fixture and native firewall behavior, and read
        # proof cases, operation schemas, workflow/Ansible contracts and chart-backed metadata.
        if (path.startswith(('apps/gateway/', 'apps/cli/', 'packages/php-sdk/'))
                or not path.endswith(PROSE) and path.startswith(('swamp/workflows/', 'swamp/ansible/', 'swamp/proof/', 'swamp/config/',
                             'swamp/extensions/behaviors/operations/', 'swamp/extensions/behaviors/_lib/'))
                or path in ('swamp/deno.lock', 'swamp/deno.json', 'swamp/deno.jsonc', 'swamp/.swamp.yaml',
                            'tools/swamp-proof-guest/ansible-recaps.php', 'docs/site.json')
                or path.startswith('docs/navigation/') and path.endswith('.json')
                or path.startswith('docs/cli/') and path.endswith('.mmd')):
            jobs['gateway-tests'] = True
        # CLI tests replay SDK fixtures, audit command docs and Gateway route names, and
        # exercise the release helpers. Select these before the prose/test-only shortcuts.
        if (path.startswith(('apps/cli/', 'packages/php-sdk/'))
                or path.startswith('docs/cli/') and path.endswith('.mdx')
                or path == '.codex/config.toml'
                or path == 'docs/reference/cli-command-vocabulary.md'
                or path.startswith('apps/gateway/routes/api/') and path.endswith('.php')
                or path == 'apps/agent/scripts/source-commit'):
            jobs['cli-tests'] = True
        for name in swamp_inputs(path):
            swamp_checks[name] = True
        # Skills and Swamp prose never install application or Swamp toolchains.
        if path.startswith(('.agents/', '.codex/', '.githooks/')) or path.endswith(PROSE) and not path.startswith(('apps/', 'packages/')):
            jobs['docs'] = True
            if path.startswith('docs/cli/') and path.endswith('.mmd'):
                jobs['api-reference'] = True
            continue
        if path.startswith('swamp/'):
            if path.startswith('swamp/proof/'):
                jobs['proof'] = True
            if path == 'swamp/config/gateway-workflow-grants.json':
                jobs['api-reference'] = True
            if path.startswith('swamp/workflows/'):
                jobs['docs'] = True
            continue
        if path.startswith(('.github/', 'bin/', 'tools/', 'scripts/')) or '/' not in path:
            selected = tooling_jobs(path)
            for name in selected:
                jobs[name] = True
            if 'swamp' in selected:
                swamp_checks = dict.fromkeys(swamp_checks, True)
            if path == 'bin/swamp-shape-gate':
                swamp_checks['shape'] = swamp_checks['validate'] = True
            if path == 'bin/check-frozen-agent-commands':
                swamp_checks['shape'] = True
        if path.startswith('apps/gateway/'):
            jobs['gateway-static'] = True
        if path.startswith(('apps/cli/', 'packages/php-sdk/')):
            jobs['cli-static'] = True
        if path.startswith('packages/php-sdk/'):
            jobs['sdk-static'] = True
        if path.startswith('apps/e2e/'):
            jobs['e2e-static'] = True
        # The PHP job verifies the pin against the published Linux binary's contract.
        if path.startswith('apps/gateway/resources/agent/'):
            jobs['php'] = True
        if path.endswith('.php') or path.endswith(('composer.json', 'composer.lock')):
            jobs['php'] = True
        if path.startswith(('apps/cli/tests/', 'apps/gateway/tests/', 'packages/php-sdk/tests/')):
            continue
        if path.startswith(('apps/cli/', 'packages/php-sdk/')):
            jobs['cli'] = jobs['api-reference'] = jobs['web'] = True
        if path.startswith(('docs/', 'apps/docs/')):
            jobs['docs'] = True
        if path == 'docs/cli/flows-pending.txt':
            jobs['api-reference'] = True
        if path.startswith('apps/gateway/'):
            jobs['api-reference'] = jobs['web'] = True
        if path.startswith('apps/web/'):
            jobs['web'] = True
            if path.startswith(('apps/web/tools/api-types/', 'apps/web/fixtures/')):
                jobs['api-reference'] = True
        if path.startswith('apps/pi-server/') and not path.endswith(('.md', '.mdx')):
            jobs['pi'] = True
        if (path.startswith('apps/agent/') or re.match(r'apps/gateway/extensions/[^/]+/agent/', path)) and not path.endswith(('.md', '.mdx')):
            agent = jobs['agent'] = True
    jobs['swamp'] = any(swamp_checks.values())
    jobs['web'] |= main
    if selection is None and (ROOT / SELECTION).is_file():
        selection = (ROOT / SELECTION).read_text()
    return {'jobs': jobs, 'swamp_checks': swamp_checks, 'agent': agent, 'gateway_release': main or not swamp_only,
            'proof_run': proof_run(paths, selection)}


# Point 3: name every changed tracked or replayed tooling input, with its new checks.
# Keep these explicit: a new path must not silently join the exception set.
DIFFERENCES = {
    '.editorconfig': {'php'},
    '.gitattributes': {'php'},
    '.github/ISSUE_TEMPLATE/bug_report.yml': {'php'},
    '.github/actionlint.yaml': {'php'},
    '.github/dependabot.yml': {'php'},
    '.github/workflows/dependency-audit.yml': {'php'},
    '.github/workflows/git-build-watch.yml': {'php'},
    '.github/workflows/php-build-mirror.yml': {'php'},
    '.github/workflows/static-php.yml': {'php', 'gateway-tests'},
    '.gitignore': {'php'},
    '.node-version': {'php'},
    'LICENSE': {'php'},
    'bin/bootstrap': {'php'},
    'bin/check-processes': {'php'},
    'bin/ci-cache-ref': {'php'},
    'bin/ci-release-inputs': {'php'},
    'bin/cli-contract': {'php'},
    'bin/cli-flow': {'php'},
    'bin/dependency-audit': {'php'},
    'bin/e2e-topology': {'php'},
    'bin/e2e-topology-host-setup': {'php'},
    'bin/gateway-app-key': {'php'},
    'bin/gateway-rollback': {'php'},
    'bin/git-build-watch': {'php'},
    'bin/pest-plain': {'php'},
    'bin/php-build': {'php'},
    'bin/recipe-artifacts': {'php'},
    'bin/review-check': {'php'},
    'bin/static-php-build': {'php'},
    'bin/swamp-real-engine-checks': {'php'},
    'bin/test': {'php'},
    'bin/web-deploy': {'php'},
    'bin/web-verify': {'php'},
    'bin/worktree-create': {'php'},
    'bin/worktree-remove': {'php'},
    'composer.json': {'php'},
    'composer.lock': {'php'},
    'scripts/firewall-allow-trace': {'php'},
    'scripts/molecule-verify-spike': {'php'},
    'tools/agent_command.py': {'php'},
    'tools/boost/GuidelineSections.php': {'php'},
    'tools/check-processes.php': {'php'},
    'tools/check_budget.py': {'php'},
    'tools/check_ci_workflow.py': {'php'},
    'tools/ci-runners/javascript.py': {'php'},
    'tools/ci-runners/job-started-hook.sh': {'php'},
    'tools/ci-runners/setup.sh': {'php'},
    'tools/ci-runners/test_actions_cache.py': {'php'},
    'tools/ci-runners/test_job_started_hook.py': {'php'},
    'tools/ci-runners/test_setup.py': {'php'},
    'tools/ci-runners/test_tools.py': {'php'},
    'tools/combine_agent_delivery.py': {'php'},
    'tools/fixtures/proof-case-selection.json': {'php'},
    'tools/static-php/craft.yml': {'php'},
    'tools/test_runtime.py': {'php'},
    'vet.json': {'php'},
    'tools/fixtures/ci-history.json': {'php'},
    '.github/workflows/docs-site.yml': {'docs', 'api-reference'},
    '.github/workflows/orbit-cli-binary.yml': {'cli'},
    '.github/workflows/orbit-cli-release.yml': {'cli', 'agent'},
    '.github/workflows/proof-outside.yml': {'proof'},
    '.github/workflows/swamp-shape.yml': {'swamp'},
}


@lru_cache(maxsize=None)
def frozen_path(path):
    return select([path])


class HistoryReplayTest(unittest.TestCase):
    def test_last_200_main_path_lists_preserve_selection_except_named_tooling(self):
        fixture = json.loads((ROOT / 'tools/fixtures/ci-history.json').read_text())
        self.assertEqual(200, len(fixture['commits']))
        self.assertEqual(200, len({row['commit'] for row in fixture['commits']}))
        equal = 0
        for row in fixture['commits']:
            paths = row['paths']
            for main in (False, True):
                with self.subTest(commit=row['commit'], main=main):
                    before = select(paths, main)
                    after = plan.select(paths, main)
                    unchanged = [path for path in paths if path not in DIFFERENCES]
                    self.assertEqual(select(unchanged, main), plan.select(unchanged, main))
                    if main is False:
                        equal += before == after
                    expected = dict(before)
                    per_path = [DIFFERENCES[path] if path in DIFFERENCES else
                                {name for name, value in frozen_path(path)['jobs'].items() if value}
                                for path in paths]
                    expected['jobs'] = {name: any(name in names for names in per_path) or (main and name == 'web')
                                        for name in JOBS}
                    expected['swamp_checks'] = {
                        name: any((path == '.github/workflows/swamp-shape.yml') if path in DIFFERENCES else
                                  frozen_path(path)['swamp_checks'][name] for path in paths)
                        for name in ('shape', 'validate', 'tests')}
                    self.assertEqual(expected, after)
        print(f'Replay: 200 path lists; {equal} equal; {200 - equal} narrowed by named tooling inputs')

    def test_named_differences_are_only_the_old_broad_tooling_branch(self):
        for path, expected in DIFFERENCES.items():
            with self.subTest(path=path):
                before = select([path])
                after = plan.select([path])
                self.assertEqual(set(JOBS), {name for name, value in before['jobs'].items() if value})
                self.assertNotEqual(set(JOBS), expected)
                self.assertEqual(expected, {name for name, value in after['jobs'].items() if value})
                for key in ('agent', 'gateway_release', 'proof_run'):
                    self.assertEqual(before[key], after[key])


if __name__ == '__main__':
    unittest.main()
