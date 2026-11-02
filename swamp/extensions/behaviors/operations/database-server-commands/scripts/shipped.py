"""Run one shipped database phase module on a Node from argv, with its shipped library modules.

Arguments: a JSON map of module name to source, the module to run, then that module's own
arguments. The sources are the repository's script files, read once on 209; nothing is copied
to the Node and no input enters a source.
"""

import importlib.abc
import importlib.util
import json
import runpy
import sys


class Shipped(importlib.abc.MetaPathFinder, importlib.abc.SourceLoader):
    def __init__(self, sources):
        self.sources = sources

    def find_spec(self, name, path, target=None):
        if name not in self.sources:
            return None
        return importlib.util.spec_from_loader(name, self, origin=name + ".py")

    def get_filename(self, fullname):
        return fullname + ".py"

    def get_data(self, path):
        return self.sources[path.removesuffix(".py")].encode()


sys.meta_path.insert(0, Shipped(json.loads(sys.argv[1])))
main = sys.argv[2]
sys.argv = [main, *sys.argv[3:]]
runpy.run_module(main, run_name="__main__")
