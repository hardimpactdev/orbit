"""The presented machine names that every Node converge writes (instance_container_files.py `names` mode,
tasks/instance-container-names.yml): a renamed Project's running container gets its new name from an ordinary converge, and an
Instance that comes or goes writes nothing into the containers that stay."""

import contextlib
import importlib.util
import io
import json
import subprocess
import unittest
from pathlib import Path
from unittest.mock import patch

ANSIBLE = Path(__file__).resolve().parents[1] / "ansible"
SPEC = importlib.util.spec_from_file_location("instance_container_files", ANSIBLE / "instance_container_files.py")
FILES = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(FILES)
CODE = (ANSIBLE / "instance_machine_name.py").read_text()
REAL_RUN = subprocess.run

PROJECT = "orbit-instances"


class Incus:
    """Running containers with their name files, as the file API holds them; every push is recorded. Each kernel keeps the hostname
    it booted with."""

    def __init__(self, files):
        self.files, self.pushed = files, []
        self.kernels = {name: texts["/etc/hostname"].strip() for name, texts in files.items()}

    def __call__(self, argv, **kwargs):
        if argv[0] != "incus":
            return REAL_RUN(argv, **kwargs)
        args = argv[1:]
        if args[:2] == ["query", "/1.0/instances?all-projects=true&recursion=1"]:
            rows = [{"project": PROJECT, "name": name, "status": "Running"} for name in sorted(self.files)]
            return subprocess.CompletedProcess(argv, 0, json.dumps(rows), "")
        if args[0] == "exec":
            return subprocess.CompletedProcess(argv, 0, f"{self.kernels[args[3]]}\n", "")
        name, path = args[-1].split("/", 1) if args[1] == "push" else args[-2].split("/", 1)
        if args[1] == "pull":
            return subprocess.CompletedProcess(argv, 0, self.files[name]["/" + path], "")
        self.files[name]["/" + path] = kwargs["input"]
        self.pushed.append(f"{name}/{path}")
        return subprocess.CompletedProcess(argv, 0, "", "")


def presented(container, hostname):
    return {"project": PROJECT, "container": container, "hostname": hostname, "pretty": hostname}


def held(hostname, container):
    return {"/etc/hostname": f"{hostname}\n", "/etc/machine-info": f"PRETTY_HOSTNAME={hostname}\n",
            "/etc/hosts": f"127.0.0.1\tlocalhost\n127.0.1.1\t{hostname} {hostname.split('.')[0]} {container}\n"}


class InstanceContainerNamesTest(unittest.TestCase):
    def run_names(self, incus, names):
        out = io.StringIO()
        with patch.object(FILES.subprocess, "run", incus), contextlib.redirect_stdout(out):
            code = FILES.names([PROJECT], names, CODE)
        return code, json.loads(out.getvalue())

    def test_a_coming_or_going_instance_writes_nothing_into_the_containers_that_stay(self):
        incus = Incus({"instance-1": held("default.web", "instance-1"), "instance-3": held("b.web", "instance-3")})
        # Instance 2 went (still named by an older list) and Instance 4 is not created yet: neither is any write.
        code, summary = self.run_names(incus, [presented("instance-1", "default.web"), presented("instance-2", "a.web"),
                                               presented("instance-3", "b.web"), presented("instance-4", "c.web")])
        self.assertEqual((code, summary["written"], incus.pushed), (0, [], []))

    def test_a_renamed_project_gives_its_running_container_the_new_name(self):
        incus = Incus({"instance-1": held("default.web", "instance-1"), "instance-3": held("b.web", "instance-3")})
        code, summary = self.run_names(incus, [presented("instance-1", "default.shop"), presented("instance-3", "b.web")])
        self.assertEqual(code, 0)
        self.assertEqual(sorted(summary["written"]), [f"{PROJECT}/instance-1{path}" for path in ("/etc/hostname", "/etc/hosts", "/etc/machine-info")])
        files = incus.files["instance-1"]
        self.assertEqual((files["/etc/hostname"], files["/etc/machine-info"]), ("default.shop\n", "PRETTY_HOSTNAME=default.shop\n"))
        # The running kernel keeps the old hostname until its next start, so the hosts line still names it.
        self.assertEqual(files["/etc/hosts"], "127.0.0.1\tlocalhost\n127.0.1.1\tdefault.shop default default.web instance-1\n")
        self.assertEqual(incus.files["instance-3"], held("b.web", "instance-3"))
        # The next converge finds nothing to write.
        self.assertEqual(self.run_names(incus, [presented("instance-1", "default.shop"), presented("instance-3", "b.web")])[1]["written"], [])


class ConvergeRunsNamesTest(unittest.TestCase):
    def test_the_check_tag_of_every_converge_writes_the_names(self):
        playbook = (ANSIBLE / "operation-node-converge.yml").read_text()
        task = playbook[playbook.index("- name: Apply the presented machine names"):playbook.index("- name: Check the managed agent file")]
        self.assertIn("file: tasks/instance-container-names.yml", task)
        self.assertIn("apply: { tags: [check] }", task)
        self.assertIn("'mode': 'names'", (ANSIBLE / "tasks/instance-container-names.yml").read_text())


if __name__ == "__main__":
    unittest.main()
