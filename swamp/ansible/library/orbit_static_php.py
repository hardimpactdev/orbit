#!/usr/bin/python
"""Check that the static PHP CLI and FrankenPHP of one build load exactly the recipe's extensions, and the CLI its CLI-only ones."""
import os
import subprocess
import tempfile

DOCUMENTATION = r'''
module: orbit_static_php
short_description: Check the extensions of a static PHP build
version_added: "1.0.0"
description: >-
  Run the PHP CLI and FrankenPHP of one static build and compare their loaded extensions with the recipe's list. Run the CLI
  again with each CLI-only extension from the build's modules folder; it must load the static extensions and those.
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
    description: The build's static extensions, lower case as `php -m` lists them.
    type: list
    elements: str
    required: true
  modules:
    description: Absolute path of the build's modules folder, which holds `<name>.so` for each CLI-only extension.
    type: path
    required: true
  cli_extensions:
    description: The build's CLI-only extensions, which only the PHP CLI loads.
    type: list
    elements: str
    required: true
author: Orbit
'''

# Prints a marker, the PHP version, the ini files PHP read, and what `php -m` prints, so both binaries are read the same way.
# PHP prints its startup warnings, such as an extension it cannot load, before the marker. FrankenPHP before PHP 8.6 runs
# PHP only from a script.
MARKER = 'orbit-static-php-probe'
PROBE = ("<?php echo '" + MARKER + "', \"\\n\", PHP_VERSION, \"\\n\", implode(', ', array_filter(array_map('trim', explode(',', php_ini_loaded_file() . ','"
         " . php_ini_scanned_files())))), \"\\n\"; foreach (get_loaded_extensions() as $e) echo $e, \"\\n\";"
         " foreach (get_loaded_extensions(true) as $e) echo $e, \"\\n\";\n")


# Under `php-cli`, FrankenPHP registers its own SAPI module. It is FrankenPHP, not an extension.
FRANKENPHP_MODULE = 'frankenphp-cli'


class StaticPhpError(Exception):
    """A binary that does not run, or reports another PHP or other extensions."""


def loaded(argv):
    """What PHP printed before the probe, the PHP_VERSION, the ini files read, and the sorted, lower-case extension names that
    one binary reports for the probe.

    It runs in an empty folder: `frankenphp php-cli` also looks for its ini file in the working folder."""
    with tempfile.TemporaryDirectory() as folder:
        script = os.path.join(folder, 'probe.php')
        with open(script, 'w', encoding='utf-8') as handle:
            handle.write(PROBE)
        run = subprocess.run([*argv, script], capture_output=True, text=True, check=False, stdin=subprocess.DEVNULL, timeout=60, cwd=folder)
    if run.returncode != 0:
        raise StaticPhpError(f'{" ".join(argv)} exited {run.returncode}: {run.stderr.strip() or run.stdout.strip()}')
    before, marker, after = run.stdout.partition(MARKER + '\n')
    lines = after.splitlines()
    if not marker or len(lines) < 2:
        raise StaticPhpError(f'{" ".join(argv)} did not run the probe: {run.stdout.strip() or run.stderr.strip()}')
    return ' '.join(before.split()), lines[0].strip(), lines[1].strip(), sorted({line.strip().lower() for line in lines[2:] if line.strip()})


def problems(name, startup, version, ini, extensions, php_version, expected):
    found = []
    if startup:
        found.append(f'{name} printed at startup: {startup}')
    # `frankenphp php-cli` takes the SAPI name `cli`, so it reads `php-cli.ini` and the scan folder, and it has no `-n`.
    # Its list is the build's only where its config path does not exist, as on a Node.
    if ini:
        found.append(f'{name} read {ini}; run the check where the build\'s config path does not exist.')
    if version != php_version:
        found.append(f'{name} reports PHP {version}, expected {php_version}.')
    missing = sorted(set(expected) - set(extensions))
    extra = sorted(set(extensions) - set(expected))
    if missing:
        found.append(f'{name} lacks {", ".join(missing)}.')
    if extra:
        found.append(f'{name} also loads {", ".join(extra)}.')
    return found


def check(php, frankenphp, php_version, extensions, modules, cli_extensions):
    """Every difference between the two binaries and the recipe, as readable lines. Empty means the build matches.

    Both binaries load exactly the static extensions. The CLI, given each CLI-only extension from `modules`, loads exactly
    the static extensions and those: FrankenPHP never loads one, because only the CLI's ini names them."""
    expected = sorted({name.lower() for name in extensions})
    runs = [('php', [php, '-n'], expected), ('frankenphp', [frankenphp, 'php-cli'], expected)]
    if cli_extensions:
        files = [os.path.join(modules, f'{name}.so') for name in cli_extensions]
        runs.append((f'php with {", ".join(files)}', [php, '-n', *[arg for path in files for arg in ('-d', f'extension={path}')]],
                     sorted(set(expected) | {name.lower() for name in cli_extensions})))
    found = []
    for name, argv, wanted in runs:
        startup, version, ini, names = loaded(argv)
        if name == 'frankenphp':
            names = [module for module in names if module != FRANKENPHP_MODULE]
        found.extend(problems(name, startup, version, ini, names, php_version, wanted))
    return found


def main():
    from ansible.module_utils.basic import AnsibleModule

    module = AnsibleModule(argument_spec={
        'php': {'type': 'path', 'required': True},
        'frankenphp': {'type': 'path', 'required': True},
        'php_version': {'type': 'str', 'required': True},
        'extensions': {'type': 'list', 'elements': 'str', 'required': True},
        'modules': {'type': 'path', 'required': True},
        'cli_extensions': {'type': 'list', 'elements': 'str', 'required': True},
    }, supports_check_mode=True)
    params = module.params
    try:
        found = check(params['php'], params['frankenphp'], params['php_version'], params['extensions'], params['modules'],
                      params['cli_extensions'])
    except (StaticPhpError, OSError, subprocess.TimeoutExpired) as error:
        module.fail_json(msg=str(error))
    if found:
        module.fail_json(msg='The static PHP build does not match the recipe: ' + ' '.join(found), problems=found)
    module.exit_json(changed=False)


if __name__ == '__main__':
    main()
