"""Load, check and resolve a cases file and the batch list. No fleet is needed.

Format: docs/reference/verification-cases. `load` returns a Spec whose cases already have their fixtures
inserted, their `"@name"` lists spliced and their static placeholders (`${proto}`, `${sources}`) replaced.
The placeholders that need the fleet (`${port}`, `${gateway.src}`) stay in the steps for the runner.
"""
import copy
import re
from dataclasses import dataclass, field
from pathlib import Path

import yaml

NODES = ('gateway', 'app-dev', 'app-prod')
SECTIONS = ('setup', 'act', 'expect', 'negative')
PLACEHOLDER = re.compile(r'\$\{([^}]+)\}')
NODE_VALUE = re.compile(r'(%s)\.(id|src|src6)$' % '|'.join(NODES))
# Step keys whose value is a Node name, or a list of them.
NODE_KEYS = {'on', 'from', 'to', 'node', 'nodes', 'consumer', 'serving', 'choose'}
NODE_PLACEHOLDER = re.compile(r'\$\{(%s)\.(id|src|src6)\}' % '|'.join(NODES))
# step -> (sections it may stand in, required keys, optional keys)
STEPS = {
    'listener': (('setup',), {'on'}, {'proto', 'family', 'ports', 'block'}),
    'ufw_rule': (('setup',), {'on', 'action', 'proto', 'port'}, {'as', 'from', 'above', 'below', 'family', 'comment'}),
    'fault': (('setup', 'act'), set(), {'on', 'command', 'fail_after', 'clear'}),
    'busy_node': (('setup',), {'on'}, {'command', 'seconds'}),
    'ipv6_addresses': (('setup',), {'nodes'}, set()),
    'cli': (('setup', 'act'), {'run'}, {'forms', 'tty', 'expect', 'as', 'save'}),
    'probe': (SECTIONS, {'from', 'to', 'port', 'expect'}, {'id', 'proto', 'family'}),
    'ufw': (('expect',), {'on', 'rows'}, {'family', 'absent'}),
    'record': (('expect',), {'list'}, {'node', 'where', 'count', 'min', 'order', 'args', 'section'}),
    'doctor': (('expect',), {'node', 'drift'}, set()),
    'unchanged': (('expect',), {'since'}, {'except', 'what', 'request'}),
    'ufw_delete': (('setup', 'negative'), {'on', 'to'}, {'from'}),
    'agent_report': (('setup',), {'node'}, set()),
    'node_down': (('setup',), {'node'}, set()),
    'node_access_remove': (('setup',), {'consumer', 'serving'}, set()),
    'node_fixture': (('setup',), {'kind', 'as'}, {'consumer', 'serving'}),
    'recorded_request': (('act',), {'node', 'caller', 'expect'}, {'agent', 'age'}),
}
FILE_KEYS = {'command', 'chart', 'target', 'sources', 'baseline', 'vars', 'fixtures', 'uncovered', 'cases', 'spread', 'parallel'}
CASE_KEYS = {'id', 'batch', 'covers', 'summary', 'setup', 'act', 'expect', 'stages', 'variants', 'fails_today', 'negative', 'timeout', 'node', 'exclusive'}
DEFAULT_PARALLEL = 4
# Steps that change something every case shares (a Node's `ufw`, the Gateway lock, a Node's agent, the addresses): the case runs alone.
GLOBAL_STEPS = {'fault', 'busy_node', 'node_down', 'agent_report', 'node_access_remove', 'ipv6_addresses', 'node_fixture', 'recorded_request'}
# Steps that compare or wait on the whole state of their Node: no other case may work on that Node meanwhile.
# Steps that name the Node they work on under `on` or `node`.
NODE_WORK = {'listener', 'ufw_rule', 'ufw_delete', 'ufw', 'record', 'doctor'}
# Step keys whose list value means one step per item.
EXPAND = {'probe': ('from', 'proto', 'port', 'family'), 'ufw_rule': ('proto', 'family')}


class SpecError(Exception):
    pass


@dataclass
class Step:
    name: str
    args: dict
    where: str = ''


@dataclass
class Stage:
    act: list
    expect: list


@dataclass
class Case:
    id: str
    batch: str
    covers: list
    summary: str
    setup: list
    stages: list
    negative: dict | None
    timeout: int
    fails_today: str | None = None
    node: str | None = None   # the Node that plays the target of the file; set by `load`
    lock: str = 'node'        # `all` runs alone, `node` is the only case on its Node (see `lock_of`)
    exclusive: bool = False   # the case asked to run alone


@dataclass
class Spec:
    command: str
    chart: str
    target: str
    sources: list
    baseline: list
    cases: list
    batches: dict = field(default_factory=dict)
    spread: list = field(default_factory=list)
    parallel: int = DEFAULT_PARALLEL

    def case(self, case_id):
        for case in self.cases:
            if case.id == case_id:
                return case
        raise SpecError('unknown case: ' + case_id)


class Loader(yaml.SafeLoader):
    """YAML 1.2 booleans: only true and false. Plain `on` is a key of the steps, not a boolean."""


Loader.yaml_implicit_resolvers = {key: [(tag, regexp) for tag, regexp in resolvers if tag != 'tag:yaml.org,2002:bool']
                                  for key, resolvers in yaml.SafeLoader.yaml_implicit_resolvers.items()}
Loader.add_implicit_resolver('tag:yaml.org,2002:bool', re.compile(r'^(?:true|false)$'), list('tf'))


def read_yaml(path):
    try:
        data = yaml.load(Path(path).read_text(), Loader=Loader)
    except (OSError, yaml.YAMLError) as error:
        raise SpecError(f'{path}: {error}') from error
    if not isinstance(data, dict):
        raise SpecError(f'{path}: not a mapping')
    return data


class Each(list):
    """The results of one placeholder that holds a list: the row or step that uses it stands once per item."""


def subst(value, context):
    """Replace `${name}` for the names in `context`. A string that is one placeholder takes the value as it is."""
    if isinstance(value, str):
        whole = PLACEHOLDER.fullmatch(value)
        if whole:
            return copy.deepcopy(context[whole[1]]) if whole[1] in context else value
        lists = {name for name in PLACEHOLDER.findall(value) if isinstance(context.get(name), list)}
        if len(lists) > 1:
            raise SpecError(f'one list placeholder per string: {value}')
        if lists:
            name = lists.pop()
            return Each(subst(value.replace('${' + name + '}', str(item)), context) for item in context[name])
        return PLACEHOLDER.sub(lambda match: str(context[match[1]]) if match[1] in context else match[0], value)
    if isinstance(value, dict):
        items = {key: subst(item, context) for key, item in value.items()}
        results = [{}]
        for key, item in items.items():
            results = [{**result, key: choice} for result in results for choice in (item if isinstance(item, Each) else [item])]
        return Each(results) if any(isinstance(item, Each) for item in items.values()) else items
    if isinstance(value, list):
        results = []
        for item in value:
            found = subst(item, context)
            results.extend(found if isinstance(found, Each) else [found])
        return results
    return value


def splice(items, variables, where, seen=()):
    """Replace each `"@name"` item by the list `vars` holds under that name."""
    result = []
    for item in items:
        if isinstance(item, str) and item.startswith('@'):
            name = item[1:]
            if name not in variables:
                raise SpecError(f'{where}: unknown var @{name}')
            if name in seen:
                raise SpecError(f'{where}: var @{name} refers to itself')
            result.extend(splice(variables[name], variables, where, seen + (name,)))
        else:
            result.append(item)
    return result


def parse_step(raw, section, where):
    if not isinstance(raw, dict) or len(raw) != 1:
        raise SpecError(f'{where}: a step is a mapping with one key')
    name, args = next(iter(raw.items()))
    if name == 'use':
        return name, args
    if name not in STEPS:
        raise SpecError(f'{where}: unknown step {name}')
    allowed, required, optional = STEPS[name]
    if section not in allowed:
        raise SpecError(f'{where}: step {name} is not allowed in {section}')
    if not isinstance(args, dict):
        raise SpecError(f'{where}: step {name} takes a mapping')
    missing = required - set(args)
    if missing:
        raise SpecError(f'{where}: step {name} lacks {sorted(missing)}')
    extra = set(args) - required - optional
    if extra:
        raise SpecError(f'{where}: step {name} has unknown keys {sorted(extra)}')
    if name == 'record' and not {'count', 'min', 'order'} & set(args):
        raise SpecError(f'{where}: step record needs count, min or order')
    return name, args


def expand_steps(raw_steps, section, where, file_vars, fixtures, static, depth=0):
    if not isinstance(raw_steps, list):
        raise SpecError(f'{where}: must be a list of steps')
    steps = []
    for index, raw in enumerate(raw_steps):
        label = f'{where}[{index}]'
        name, args = parse_step(raw, section, label)
        if name == 'use':
            steps.extend(use_fixture(args, section, label, file_vars, fixtures, static, depth))
            continue
        replaced = subst(args, static)
        for item in replaced if isinstance(replaced, Each) else [replaced]:
            if name == 'cli':
                if not isinstance(item['run'], list):
                    raise SpecError(f'{label}: cli run is a list')
                item['run'] = splice(item['run'], file_vars, label)
            steps.append(Step(name, item, label))
    return steps


def use_fixture(args, section, label, file_vars, fixtures, static, depth):
    if depth > 3:
        raise SpecError(f'{label}: fixtures nest too deep')
    params = {'fixture': args} if isinstance(args, str) else subst(dict(args or {}), static)
    name = params.pop('fixture', None)
    if name not in fixtures:
        raise SpecError(f'{label}: unknown fixture {name}')
    fixture = fixtures[name]
    declared = fixture.get('params') or {}
    unknown = set(params) - set(declared)
    if unknown:
        raise SpecError(f'{label}: fixture {name} has no params {sorted(unknown)}')
    context = {**static, **declared, **params}
    return expand_steps(subst(fixture.get('steps') or [], context), section, f'{label}:{name}', file_vars, fixtures, context, depth + 1)


def placeholders(value):
    if isinstance(value, str):
        return set(PLACEHOLDER.findall(value))
    if isinstance(value, dict):
        return set().union(*(placeholders(item) for item in value.values())) if value else set()
    if isinstance(value, list):
        return set().union(*(placeholders(item) for item in value)) if value else set()
    return set()


def bound_names(steps, extra):
    """Names the listener steps bind, plus the names the case brings."""
    names = set(extra)
    for step in steps:
        if step.name == 'node_fixture':
            names.update(f"{step.args['as']}.{part}" for part in ('id', 'name', 'cluster_name', 'tld'))
        if step.name == 'cli':
            names.update((step.args.get('save') or {}).values())
        if step.name == 'listener':
            names.update(step.args.get('ports') or [])
            if step.args.get('block'):
                names.update(f"{step.args['block']['as']}.{part}" for part in ('lo', 'mid', 'hi', 'spec'))
    return names


def check_names(case_id, steps, variant_keys):
    bound = bound_names(steps, variant_keys)
    for step in steps:
        for name in placeholders(step.args):
            if name not in bound and not NODE_VALUE.match(name):
                raise SpecError(f'case {case_id} {step.where}: unknown placeholder ${{{name}}}')


def build_cases(raw, file_vars, fixtures, static, batches):
    """One case, or one case for each variant: the keys of a variant are static placeholders and `@name` lists."""
    variants = raw.get('variants') if isinstance(raw, dict) else None
    if variants is None:
        return [build_case(raw, file_vars, fixtures, static, batches)]
    if not (isinstance(variants, list) and variants and all(isinstance(item, dict) and 'name' in item for item in variants)):
        raise SpecError(f"case {raw.get('id')}: variants is a list of mappings that each have a name")
    cases = []
    for variant in variants:
        values = {key: item for key, item in variant.items() if key != 'name'}
        case = build_case({key: item for key, item in raw.items() if key != 'variants'}, {**file_vars, **values}, fixtures, {**static, **values}, batches)
        case.id = f"{case.id}[{variant['name']}]"
        cases.append(case)
    return cases


def build_case(raw, file_vars, fixtures, static, batches):
    if not isinstance(raw, dict):
        raise SpecError('a case is a mapping')
    case_id = raw.get('id')
    where = f'case {case_id}'
    unknown = set(raw) - CASE_KEYS
    if unknown:
        raise SpecError(f'{where}: unknown keys {sorted(unknown)}')
    for key in ('id', 'batch', 'covers', 'summary'):
        if key not in raw:
            raise SpecError(f'{where}: missing {key}')
    if raw['batch'] not in batches:
        raise SpecError(f"{where}: unknown batch {raw['batch']}")
    if not isinstance(raw['covers'], list):
        raise SpecError(f'{where}: covers is a list')
    setup = expand_steps(raw.get('setup') or [], 'setup', f'{where} setup', file_vars, fixtures, static)
    if 'stages' in raw:
        if 'act' in raw or 'expect' in raw:
            raise SpecError(f'{where}: stages replaces act and expect')
        pairs = raw['stages']
        if not isinstance(pairs, list) or not pairs:
            raise SpecError(f'{where}: stages is a list')
    elif 'act' in raw and 'expect' in raw:
        pairs = [{'act': raw['act'], 'expect': raw['expect']}]
    else:
        raise SpecError(f'{where}: needs act and expect, or stages')
    stages = []
    for number, pair in enumerate(pairs):
        label = f'{where} stage {number}' if 'stages' in raw else where
        if not isinstance(pair, dict) or set(pair) - {'act', 'expect'} or 'act' not in pair:
            raise SpecError(f'{label}: a stage has act and expect')
        stages.append(Stage(expand_steps(pair['act'], 'act', f'{label} act', file_vars, fixtures, static),
                            expand_steps(pair.get('expect') or [], 'expect', f'{label} expect', file_vars, fixtures, static)))
    negative = raw.get('negative')
    if negative is not None:
        if not isinstance(negative, dict) or set(negative) - {'after', 'do', 'must_fail'}:
            raise SpecError(f'{where}: negative has after, do and must_fail')
        negative = dict(negative, do=expand_steps(negative.get('do') or [], 'negative', f'{where} negative', file_vars, fixtures, static))
    all_steps = setup + [step for stage in stages for step in stage.act + stage.expect] + (negative['do'] if negative else [])
    check_names(case_id, all_steps, set())
    if raw.get('node') is not None and raw['node'] not in NODES:
        raise SpecError(f"{where}: node is one of {NODES}")
    if not isinstance(raw.get('exclusive', False), bool):
        raise SpecError(f'{where}: exclusive is true or false')
    return Case(case_id, raw['batch'], raw['covers'], raw['summary'], setup, stages, negative, int(raw.get('timeout', 180)), raw.get('fails_today'),
                raw.get('node'), exclusive=raw.get('exclusive', False))


def case_steps(case):
    return case.setup + [step for stage in case.stages for step in stage.act + stage.expect] + (case.negative['do'] if case.negative else [])


def lock_of(case, target):
    """What the case needs of the fleet while it runs. `all`: it alone (it changes or compares what every case shares: the Operation
    list, the records of every Node, a fault, a stalled lock, the addresses, a Node other than its own). `node`: it alone on its Node (it
    compares or waits on the whole state of that Node, and the firewall write lock of the Gateway lets one write run on a Node at a time)."""
    steps = case_steps(case)
    names = {step.name for step in steps}
    compares_operations = any(step.name == 'unchanged' and 'operations' in (step.args.get('what') or []) for step in steps)
    no_request = any(step.name == 'cli' and (step.args.get('expect') or {}).get('request') == 'none' for step in steps)
    reads_every_node = any(step.name == 'record' and 'node' not in step.args for step in steps)
    other_node = any(step.args.get(key) not in (None, target) for step in steps if step.name in NODE_WORK for key in ('on', 'node'))
    if case.exclusive or names & GLOBAL_STEPS or compares_operations or no_request or reads_every_node or other_node:
        return 'all'
    return 'node'


def swap_roles(value, first, second, key=None):
    """Exchange two Node roles in resolved step arguments: a value under a key that names a Node (`NODE_KEYS`), and the `${role.id}`,
    `${role.src}` and `${role.src6}` placeholders in any string. Names a case binds (`as`, `ports`, `id`) stay."""
    pair = {first: second, second: first}
    if isinstance(value, str):
        value = pair.get(value) if key in NODE_KEYS and value in pair else value
        return NODE_PLACEHOLDER.sub(lambda found: '${%s.%s}' % (pair.get(found[1], found[1]), found[2]), value)
    if isinstance(value, dict):
        return {name: swap_roles(item, first, second, name) for name, item in value.items()}
    if isinstance(value, list):
        return [swap_roles(item, first, second, key) for item in value]
    return value


def exchange(items, first, second):
    """The steps with the two roles traded. `as` names a Node only in `cli`; in `ufw_rule` and `listener` it names what the step binds."""
    def args_of(step):
        if step.name != 'cli':
            return swap_roles(step.args, first, second)
        return {name: swap_roles(item, first, second, 'node' if name == 'as' else name) for name, item in step.args.items()}
    return [Step(step.name, args_of(step), step.where) for step in items]


def place(cases, target, spread):
    """Give each case its Node and its lock. A case that names a Node keeps it. Cases that run alone stay on the file's target; the others
    take the Nodes of `spread` in turn, in file order. A case placed on another Node than the target plays the same story there: the
    two roles trade places in every step."""
    turn = 0
    for case in cases:
        case.lock = lock_of(case, target)
        if case.node is None:
            case.node = target
            if case.lock != 'all' and spread:
                case.node = spread[turn % len(spread)]
                turn += 1
        if case.node != target:
            case.setup = exchange(case.setup, target, case.node)
            case.stages = [Stage(exchange(stage.act, target, case.node), exchange(stage.expect, target, case.node)) for stage in case.stages]
            if case.negative:
                case.negative = dict(case.negative, do=exchange(case.negative['do'], target, case.node))


def load(cases_file, batches_file):
    batches = read_yaml(batches_file).get('batches')
    if not isinstance(batches, dict) or not batches:
        raise SpecError(f'{batches_file}: no batches')
    data = read_yaml(cases_file)
    unknown = set(data) - FILE_KEYS
    if unknown:
        raise SpecError(f'{cases_file}: unknown keys {sorted(unknown)}')
    for key in ('command', 'chart', 'target', 'sources', 'cases'):
        if key not in data:
            raise SpecError(f'{cases_file}: missing {key}')
    if data['target'] not in NODES or not set(data['sources']) <= set(NODES):
        raise SpecError(f'{cases_file}: target and sources must be of {NODES}')
    file_vars = data.get('vars') or {}
    fixtures = data.get('fixtures') or {}
    static = {'sources': list(data['sources'])}
    cases, seen = [], set()
    for raw in data['cases']:
        for case in build_cases(raw, file_vars, fixtures, static, batches):
            if case.id in seen:
                raise SpecError(f'case {case.id} appears twice')
            seen.add(case.id)
            cases.append(case)
    spread = data.get('spread') or []
    if not isinstance(spread, list) or not set(spread) <= set(NODES):
        raise SpecError(f'{cases_file}: spread is a list of {NODES}')
    parallel = data.get('parallel', DEFAULT_PARALLEL)
    if type(parallel) is not int or not 1 <= parallel <= 16:
        raise SpecError(f'{cases_file}: parallel is a whole number from 1 to 16')
    place(cases, data['target'], spread)
    return Spec(data['command'], data['chart'], data['target'], list(data['sources']), data.get('baseline') or [], cases, batches, spread, parallel)


def plan(spec):
    """The batches that hold cases, in the order of the batch file, with their cases in file order."""
    return [{'batch': name, 'summary': definition.get('summary', ''), 'cases': [case.id for case in spec.cases if case.batch == name]}
            for name, definition in spec.batches.items() if any(case.batch == name for case in spec.cases)]


def expand_args(name, args):
    """One argument set for each combination of the list values of the step's expandable keys."""
    results = [dict(args)]
    for key in EXPAND.get(name, ()):
        if any(isinstance(result.get(key), list) for result in results):
            results = [{**result, key: item} for result in results for item in (result[key] if isinstance(result.get(key), list) else [result.get(key)])]
    return results
