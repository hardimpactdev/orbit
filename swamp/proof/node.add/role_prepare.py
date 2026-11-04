"""Bind the stock machine enrolled by node.add.fresh, without changing its state."""
import json
from pathlib import Path

state = json.loads(Path('/home/orbit/.orbit/swamp-proof/node-add-stock.json').read_text())
print(json.dumps({'bindings': {'stock_node': state['node_id']}}))
