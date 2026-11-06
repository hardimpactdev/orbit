#!/usr/bin/env python3
"""The proof runner: `runner plan --file F` prints the batches and cases; `runner case ID --file F` runs one case.

Format and verdicts: docs/reference/verification-cases. A case ends with one JSON line of at most 1 KB; the receipt goes to
the evidence directory ($PROOF_EVIDENCE). Exit 0 passed, 1 failed, 2 unrun.
"""
import argparse
import concurrent.futures
import datetime
import json
import sys
import time
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import fleet as fleet_module
import spec as spec_module
import steps

BATCHES = Path(__file__).resolve().parents[1] / 'batches.yaml'
LINE_LIMIT = 1000


class Run:
    """The state of one case: bound names, the rules the case made, the baseline of `unchanged` and the evidence."""

    def __init__(self, fleet, spec, evidence):
        self.fleet, self.spec, self.evidence = fleet, spec, Path(evidence)
        self.names, self.rules, self.baseline, self.sources = {}, {}, {}, {}
        self.log, self.calls, self.acted, self.timings = [], 0, time.time(), {}
        self.shimmed, self.background = set(), []
        self.evidence.mkdir(parents=True, exist_ok=True)

    def cli(self, args, form='json', node=None):
        result = self.fleet.cli(args, form=form, **({'node': node} if node else {}))
        return self.keep(result, 'json' if form == 'json' else 'txt')

    def cli_tty(self, args, script):
        return self.keep(self.fleet.cli_tty(args, script), 'txt')

    def keep(self, result, suffix):
        self.calls += 1
        (self.evidence / f'cli-{self.calls}.{suffix}').write_text(result['stdout'])
        (self.evidence / f'cli-{self.calls}.stderr').write_text(result['stderr'])
        return result

    def sleep(self, seconds):
        """Wait on the target Node, so that the wait is one fleet call and not a loop of the runner."""
        if seconds > 0:
            self.fleet.sh(self.spec.target, f'sleep {int(seconds) + 1}', timeout=int(seconds) + 30)

    def evidence_text(self, name, text):
        (self.evidence / name).write_text(text)

    def value(self, name):
        if name in self.names:
            return self.names[name]
        found = spec_module.NODE_VALUE.match(name)
        if not found:
            raise steps.Infra(f'unbound placeholder ${{{name}}}')
        node, kind = found[1], found[2]
        if kind == 'id':
            return self.fleet.nodes[node]['node_id']
        key = (node, 6 if kind == 'src6' else 4)
        if key not in self.sources:
            self.sources[key] = self.source_of(*key)
        return self.sources[key]

    def source_of(self, node, family):
        """The address `node` really uses to reach the target, from the kernel route."""
        target = self.fleet.nodes[self.spec.target].get('bridge6' if family == 6 else 'bridge')
        if target is None:
            raise steps.Infra(f'${{{node}.src6}}: {self.spec.target} has no IPv6 address; the batch needs ipv6_addresses')
        words = self.fleet.sh(node, f'ip -{family} route get ' + target).stdout.split()
        if 'src' not in words:
            raise steps.Infra(f'no source address from {node} to {self.spec.target}')
        return words[words.index('src') + 1]

    def resolve(self, value):
        if isinstance(value, str):
            whole = spec_module.PLACEHOLDER.fullmatch(value)
            if whole:
                return self.value(whole[1])
            return spec_module.PLACEHOLDER.sub(lambda match: str(self.value(match[1])), value)
        if isinstance(value, dict):
            return {key: self.resolve(item) for key, item in value.items()}
        if isinstance(value, list):
            return [self.resolve(item) for item in value]
        return value


def run_steps(run, items, section, failures, deadline):
    """Run the steps of one section. A setup failure raises Unrun; an act or expect failure is listed."""
    began = time.time()
    try:
        _run_steps(run, items, section, failures, deadline)
    finally:
        run.timings[section] = round(run.timings.get(section, 0) + time.time() - began, 2)


def _run_steps(run, items, section, failures, deadline):
    for index, step in enumerate(items):
        if time.time() > deadline:
            raise steps.Infra('the case timeout passed')
        started = time.time()
        resolved = run.resolve(step.args)
        expanded = spec_module.expand_args(step.name, resolved)
        if step.name == 'probe' and len(expanded) > 1:  # each blocked probe waits for its timeout; the probes do not depend on each other
            with concurrent.futures.ThreadPoolExecutor(len(expanded)) as pool:
                results = list(pool.map(lambda args: steps.FUNCTIONS['probe'](run, args), expanded))
        else:
            results = [steps.FUNCTIONS[step.name](run, args) for args in expanded]
        for found in results:
            run.log.append({'step': f'{section}[{index}] {step.name}', 'ok': not found, 'failed': found, 'secs': round(time.time() - started, 2)})
            if found and section == 'setup':
                raise steps.Unrun(found[0])
            failures.extend(f'{section}[{index}] {message}' for message in found)


def execute(case, fleet, spec, evidence, negative=False):
    """Run one case and return its verdict and the receipt. With `negative` the case breaks its own effect after `act`
    and is proven when each expectation named in `must_fail` fails."""
    started = time.time()
    deadline = started + case.timeout
    run = Run(fleet, spec, evidence)
    failures, verdict, cause = [], 'passed', None
    try:
        if negative and (not case.negative or len(case.stages) > 1):
            raise steps.NotBuilt('negative needs a case with a negative key and one stage')
        run_steps(run, case.setup, 'setup', failures, deadline)
        for number, stage in enumerate(case.stages):
            steps.take_baseline(run, stage.expect)
            tag = f's{number}.' if len(case.stages) > 1 else ''
            run_steps(run, stage.act, tag + 'act', failures, deadline)
            run.acted = time.time()
            if negative:
                broken = []
                run_steps(run, case.negative['do'], 'negative', broken, deadline)
                if broken:
                    raise steps.Infra(broken[0])
            run_steps(run, stage.expect, tag + 'expect', failures, deadline)
        if negative:
            kept = [name for name in case.negative.get('must_fail') or [] if not any(f' {name}:' in message for message in failures)]
            if kept or not case.negative.get('must_fail'):
                verdict, cause, failures = 'failed', 'assertion', [f'negative: did not fail: {kept}' if kept else 'negative: must_fail names no expectation']
        elif failures:
            verdict, cause = 'failed', 'assertion'
    except steps.Unrun as error:
        verdict, cause, failures = 'unrun', 'precondition', [f'setup: {error}']
    except Exception as error:  # a step that could not run is a failed case, never a pass
        verdict, cause, failures = 'failed', 'infrastructure', failures + [f'{type(error).__name__}: {error}']
    try:
        steps.cleanup(run)
    except Exception as error:  # the next restore removes what is left
        failures.append(f'cleanup: {error}')
    result = {'id': case.id, 'verdict': verdict, 'secs': round(time.time() - started, 1)}
    if negative:
        result['negative'] = True
    if cause:
        result['cause'] = cause
        result['failed'] = failures
    receipt = {**result, 'summary': case.summary, 'steps': run.log, 'phase_secs': run.timings, 'names': run.names, 'sources': {f'{node}/v{family}': address for (node, family), address in run.sources.items()}, 'failed': failures}
    return result, receipt


def verdict_line(result):
    """One JSON line of at most 1 KB: long failure messages are cut and the failures that do not fit are counted."""
    failed = [message[:200] for message in result.get('failed', [])]
    for shown in range(len(failed), -1, -1):
        trimmed = failed[:shown] + ([f'+{len(failed) - shown} more'] if shown < len(failed) else [])
        line = json.dumps({**result, **({'failed': trimmed} if failed else {})}, separators=(', ', ': '))
        if len(line) <= LINE_LIMIT:
            return line
    return line[:LINE_LIMIT]


def plan_lines(spec):
    """One line a batch, for the workflow's forEach: `BATCH SETUP CASE...`, SETUP is 1 when the batch declares setup steps."""
    return [' '.join([batch['batch'], '1' if spec.batches[batch['batch']].get('setup') else '0', *batch['cases']]) for batch in spec_module.plan(spec)]


def command_plan(args):
    spec = spec_module.load(args.file, args.batches)
    if args.lines:
        print('\n'.join(plan_lines(spec)))
        return 0
    print(f'{spec.command}  {len(spec.cases)} cases')
    for batch in spec_module.plan(spec):
        print(f"batch {batch['batch']}: {batch['summary']}")
        for case_id in batch['cases']:
            case = spec.case(case_id)
            print(f'  {case_id}  covers {case.covers}')
            if args.steps:
                for step in case.setup + [item for stage in case.stages for item in stage.act + stage.expect]:
                    print(f'    {step.where.split(" ", 2)[-1]} {step.name} {json.dumps(step.args, sort_keys=True)}')
    return 0


def command_case(args):
    spec = spec_module.load(args.file, args.batches)
    case = spec.case(args.id)
    stamp = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')
    evidence = Path(args.evidence or fleet_module.evidence_root()) / case.id / stamp
    discovered = time.time()
    try:
        fleet = fleet_module.from_environment()
    except fleet_module.FleetError as error:
        result = {'id': case.id, 'verdict': 'failed', 'cause': 'infrastructure', 'secs': 0, 'failed': [str(error)]}
        print(verdict_line(result))
        return 1
    discovery = round(time.time() - discovered, 2)
    result, receipt = execute(case, fleet, spec, evidence, args.negative)
    receipt['phase_secs']['discover'] = discovery
    (evidence / 'receipt.json').write_text(json.dumps(receipt, indent=2, default=str))
    print(verdict_line(result))
    print('evidence ' + str(evidence), file=sys.stderr)
    return {'passed': 0, 'failed': 1, 'unrun': 2}[result['verdict']]


def command_setup(args):
    """Run the setup steps a batch declares, on the fleet. One JSON line; exit 1 when a step fails or is not built."""
    spec = spec_module.load(args.file, args.batches)
    if args.batch not in spec.batches:
        raise spec_module.SpecError('unknown batch: ' + args.batch)
    started = time.time()
    failures = []
    try:
        items = spec_module.expand_steps(spec.batches[args.batch].get('setup') or [], 'setup', f'batch {args.batch} setup', {}, {}, {})
        evidence = Path(args.evidence or fleet_module.evidence_root()) / ('batch-' + args.batch)
        run = Run(fleet_module.from_environment(), spec, evidence)
        run_steps(run, items, 'setup', failures, started + 120)
    except steps.Unrun as error:
        failures.append(str(error))
    except Exception as error:
        failures.append(f'{type(error).__name__}: {error}')
    print(json.dumps({'batch': args.batch, 'setup': 'failed' if failures else 'ok', 'secs': round(time.time() - started, 1), 'failed': [message[:200] for message in failures][:3]}))
    return 1 if failures else 0


def main(argv=None):
    parser = argparse.ArgumentParser(prog='runner')
    commands = parser.add_subparsers(dest='command', required=True)
    plan = commands.add_parser('plan', help='print the ordered batches and cases; no fleet')
    plan.add_argument('--steps', action='store_true', help='also print the resolved steps')
    plan.add_argument('--lines', action='store_true', help='print one line a batch, `BATCH SETUP CASE...`, for the workflow')
    case = commands.add_parser('case', help='run one case on the nested fleet')
    case.add_argument('id')
    case.add_argument('--negative', action='store_true')
    case.add_argument('--evidence')
    setup = commands.add_parser('setup', help='run the setup steps of one batch on the fleet')
    setup.add_argument('batch')
    setup.add_argument('--evidence')
    for command in (plan, case, setup):
        command.add_argument('--file', required=True)
        command.add_argument('--batches', default=str(BATCHES))
    args = parser.parse_args(argv)
    try:
        return {'plan': command_plan, 'case': command_case, 'setup': command_setup}[args.command](args)
    except spec_module.SpecError as error:
        print(f'cases file refused: {error}', file=sys.stderr)
        return 3


if __name__ == '__main__':
    sys.exit(main())
