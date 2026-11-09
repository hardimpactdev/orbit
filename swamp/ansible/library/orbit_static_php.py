#!/usr/bin/python
"""Check that the static PHP CLI and FrankenPHP of one build load exactly the recipe's extensions."""
import os
import subprocess
import tempfile

DOCUMENTATION = r'''
module: orbit_static_php
short_description: Check the extensions of a static PHP build
version_added: "1.0.0"
description: Run the PHP CLI and FrankenPHP of one static build and compare their loaded extensions with the recipe's list.
options:
  php:
    description: Absolute path of the static PHP CLI.
    type: path
    required: true
  frankenphp:
    description: Absolute path of the FrankenPHP binary of the same build.
    type: path
    required: true
  php_version:
    description: The PHP_VERSION both binaries must report, such as 8.5.12.
    type: str
    required: true
  extensions:
    description: The recipe's extensions, lower case as `php -m` lists them.
    type: list
    elements: str
    required: true
author: Orbit
'''

# Prints what `php -m` prints, so both binaries are read the same way. FrankenPHP before PHP 8.6 runs PHP only from a script.
PROBE = ("<?php echo PHP_VERSION, \"\\n\"; foreach (get_loaded_extensions() as $e) echo $e, \"\\n\";"
         " foreach (get_loaded_extensions(true) as $e) echo $e, \"\\n\";\n")


class StaticPhpError(Exception):
    """A binary that does not run, or reports another PHP or other extensions."""


def loaded(argv):
    """The PHP_VERSION and the sorted, lower-case extension names that one binary reports for the probe."""
    with tempfile.TemporaryDirectory() as folder:
        script = os.path.join(folder, 'probe.php')
        with open(script, 'w', encoding='utf-8') as handle:
            handle.write(PROBE)
        run = subprocess.run([*argv, script], capture_output=True, text=True, check=False, stdin=subprocess.DEVNULL, timeout=60)
    if run.returncode != 0:
        raise StaticPhpError(f'{" ".join(argv)} exited {run.returncode}: {run.stderr.strip() or run.stdout.strip()}')
    lines = [line.strip() for line in run.stdout.splitlines() if line.strip()]
    if not lines:
        raise StaticPhpError(f'{" ".join(argv)} printed nothing.')
    return lines[0], sorted({line.lower() for line in lines[1:]})


def problems(name, version, extensions, php_version, expected):
    found = []
    if version != php_version:
        found.append(f'{name} reports PHP {version}, expected {php_version}.')
    missing = sorted(set(expected) - set(extensions))
    extra = sorted(set(extensions) - set(expected))
    if missing:
        found.append(f'{name} lacks {", ".join(missing)}.')
    if extra:
        found.append(f'{name} also loads {", ".join(extra)}.')
    return found


def check(php, frankenphp, php_version, extensions):
    """Every difference between the two binaries and the recipe, as readable lines. Empty means the build matches."""
    expected = sorted({name.lower() for name in extensions})
    found = []
    for name, argv in (('php', [php, '-n']), ('frankenphp', [frankenphp, 'php-cli'])):
        version, names = loaded(argv)
        found.extend(problems(name, version, names, php_version, expected))
    return found


def main():
    from ansible.module_utils.basic import AnsibleModule

    module = AnsibleModule(argument_spec={
        'php': {'type': 'path', 'required': True},
        'frankenphp': {'type': 'path', 'required': True},
        'php_version': {'type': 'str', 'required': True},
        'extensions': {'type': 'list', 'elements': 'str', 'required': True},
    }, supports_check_mode=True)
    params = module.params
    try:
        found = check(params['php'], params['frankenphp'], params['php_version'], params['extensions'])
    except (StaticPhpError, OSError, subprocess.TimeoutExpired) as error:
        module.fail_json(msg=str(error))
    if found:
        module.fail_json(msg='The static PHP build does not match the recipe: ' + ' '.join(found), problems=found)
    module.exit_json(changed=False)


if __name__ == '__main__':
    main()
