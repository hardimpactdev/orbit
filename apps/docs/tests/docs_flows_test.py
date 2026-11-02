"""The docs build takes each chart a CLI page places, with the kind its chart names."""
import importlib.machinery
import importlib.util
from pathlib import Path
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[3]
loader = importlib.machinery.SourceFileLoader('docs_flows', str(ROOT / 'bin/docs-flows'))
flows = importlib.util.module_from_spec(importlib.util.spec_from_loader(loader.name, loader))
loader.exec_module(flows)


class DocsFlowsTest(unittest.TestCase):
    def test_only_placed_charts_are_written(self):
        with tempfile.TemporaryDirectory() as tmp:
            cli = Path(tmp)
            (cli / 'firewall').mkdir()
            chart = 'sequenceDiagram\n  %% command: firewall:allow\n  %% kind: firewall.allow\n'
            (cli / 'firewall/allow.mmd').write_text(chart)
            (cli / 'firewall/deny.mmd').write_text('sequenceDiagram\n  %% kind: firewall.deny\n')
            (cli / 'firewall/allow.mdx').write_text('## How it works\n\n<div className="flow-at" data-chart="firewall/allow"></div>\n')
            (cli / 'firewall/deny.mdx').write_text('No chart here.\n')

            self.assertEqual({'firewall/allow': {'kind': 'firewall.allow', 'src': chart}}, flows.placed(cli))


if __name__ == '__main__':
    unittest.main()
