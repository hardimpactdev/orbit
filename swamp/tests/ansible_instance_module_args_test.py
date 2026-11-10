"""Check every module call of the Instance container task files against the real module's argument spec.

The fixture tests of these files replace modules that need Linux and root, such as `community.general.ufw`, so the real module
never checks the arguments there. This test reads each real module's spec at the pinned version and runs Ansible's own argument
validator over each call: an unknown parameter, a missing one, or a pair the module refuses fails here, not on a Node."""
import importlib.util
import unittest
from pathlib import Path
from unittest import mock

import yaml
from ansible.module_utils import basic
from ansible.module_utils.common.arg_spec import ModuleArgumentSpecValidator
from ansible.plugins.loader import init_plugin_loader, module_loader

ANSIBLE = Path(__file__).resolve().parents[1] / 'ansible'
# The Instance host branch also runs the two files it shares with a task container host.
FILES = sorted({*(ANSIBLE / 'tasks').glob('instance-*.yml'), ANSIBLE / 'tasks/task-container-host-system.yml',
                ANSIBLE / 'tasks/task-container-host-prepare.yml'})
# Not modules: the controller runs these, and no argument spec exists for them.
CONTROLLER = {'ansible.builtin.assert', 'ansible.builtin.debug', 'ansible.builtin.fail', 'ansible.builtin.include_tasks',
              'ansible.builtin.set_fact'}
# The shell action runs the command module, with its arguments.
RUNS_AS = {'ansible.builtin.shell': 'ansible.builtin.command'}
# A templated value has no value yet. Each stands in for one the spec accepts, so only the parameter names and literal values count.
STAND_INS = {'str': 'x', 'path': '/x', 'raw': 'x', 'int': 1, 'float': 1.0, 'bool': True, 'list': [], 'dict': {}, 'json': '{}',
             'jsonarg': '{}', 'bytes': '1', 'bits': '1'}


class Captured(Exception):
    pass


def spec_of(name):
    """What the module passes to `AnsibleModule`, read by running its `main` up to that call."""
    path = module_loader.find_plugin_with_context(RUNS_AS.get(name, name)).plugin_resolved_path
    if path is None:
        raise AssertionError(f'{name}: no such module in the pinned collections')
    captured = {}

    def capture(self, argument_spec=None, **options):
        captured.update(options, argument_spec=dict(argument_spec or {}))
        raise Captured

    spec = importlib.util.spec_from_file_location('orbit_args_' + name.replace('.', '_'), path)
    module = importlib.util.module_from_spec(spec)
    with mock.patch.object(basic.AnsibleModule, '__init__', capture):
        spec.loader.exec_module(module)
        try:
            module.main()
        except Captured:
            pass
    if 'argument_spec' not in captured:
        raise AssertionError(f'{name}: its main() made no AnsibleModule')
    if captured.get('add_file_common_args'):
        captured['argument_spec'] = {**basic.FILE_COMMON_ARGUMENTS, **captured['argument_spec']}
    return captured


def templated(value):
    if isinstance(value, str):
        return '{{' in value or '{%' in value
    if isinstance(value, dict):
        return any(templated(key) or templated(item) for key, item in value.items())
    return isinstance(value, list) and any(templated(item) for item in value)


def stand_in(option):
    if option.get('choices'):
        return option['choices'][0]
    kind = option.get('type') or 'str'
    return STAND_INS[kind] if isinstance(kind, str) else 'x'


def errors_of(name, arguments, specs):
    if name not in specs:
        specs[name] = spec_of(name)
    spec = specs[name]
    options = {alias: option for key, option in spec['argument_spec'].items() for alias in (key, *option.get('aliases', []))}
    parameters = {key: stand_in(options[key]) if templated(value) and key in options else value for key, value in arguments.items()}
    # `required_if` reads values, which a stand-in does not have.
    validator = ModuleArgumentSpecValidator(spec['argument_spec'], spec.get('mutually_exclusive'), spec.get('required_together'),
                                            spec.get('required_one_of'), None, spec.get('required_by'))
    return validator.validate(parameters).error_messages


def calls(tasks):
    """Each module call of a task list, through its blocks: the task's name, the module and its arguments."""
    for task in tasks:
        for part in ('block', 'rescue', 'always'):
            yield from calls(task.get(part) or [])
        for key, value in task.items():
            if key.count('.') == 2 and isinstance(value, dict):
                yield task.get('name', key), key, value


class InstanceModuleArgumentsTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        init_plugin_loader()
        module_loader.add_directory(str(ANSIBLE / 'library'))

    def test_every_module_call_passes_the_real_argument_spec(self):
        specs, checked, failures = {}, set(), []
        for file in FILES:
            for task, module, arguments in calls(yaml.safe_load(file.read_text())):
                if module in CONTROLLER:
                    continue
                checked.add(module)
                failures += [f'{file.name}: {task}: {module}: {error}' for error in errors_of(module, arguments, specs)]
        self.assertEqual(failures, [])
        # The modules that the fixture tests replace are among the checked ones.
        self.assertLessEqual({'community.general.ufw', 'ansible.posix.sysctl', 'ansible.builtin.apt', 'community.general.modprobe',
                              'ansible.builtin.systemd_service'}, checked)

    def test_the_check_refuses_what_the_real_module_refuses(self):
        specs = {}
        rule = {'rule': 'allow', 'interface_in': '{{ bridge }}', 'proto': 'tcp', 'to_port': '3128', 'comment': 'orbit:instances-egress'}

        self.assertEqual(errors_of('community.general.ufw', rule, specs), [])
        # `ufw` takes its direction from `interface_in`; the pinned module refuses both together.
        self.assertEqual(errors_of('community.general.ufw', {**rule, 'direction': '{{ direction }}'}, specs),
                         ['parameters are mutually exclusive: direction|interface_in'])
        self.assertIn('ports', errors_of('community.general.ufw', {**rule, 'ports': '3128'}, specs)[0])
        self.assertIn('value of proto must be one of', errors_of('community.general.ufw', {**rule, 'proto': 'sctp'}, specs)[0])


if __name__ == '__main__':
    unittest.main()
