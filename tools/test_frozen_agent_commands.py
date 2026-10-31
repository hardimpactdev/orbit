"""Regression coverage for the static frozen agent check; no agent action is executed."""
import importlib.machinery
import importlib.util
from pathlib import Path
import unittest

path = Path(__file__).resolve().parents[1] / 'bin/check-frozen-agent-commands'
spec = importlib.util.spec_from_loader('guard', importlib.machinery.SourceFileLoader('guard', str(path)))
guard = importlib.util.module_from_spec(spec)
spec.loader.exec_module(guard)


class FrozenAgentReferences(unittest.TestCase):
    def names(self, source, php=False):
        return {name for _, name in guard.references(source, php)}

    def test_gateway_runner_and_step(self):
        source = '''AgentCommandRunner $agents;
        $this->agents->run(Node::find($id), 'caddy.publish', ['name' => 'ignore.payload']);
        AgentStep::run($this->agents, $node, 'caddy.connect', []);
        // $this->agents->run($node, 'ignore.comment');
        '''
        self.assertEqual(self.names(source, True), {'caddy.publish', 'caddy.connect'})

    def test_gateway_wrappers_constants_and_choices(self):
        source = '''AgentCommandRunner $agents;
        const string Install = 'agent.install';
        function changed(Node $node, string $command) {}
        $this->changed($node, 'unknown.new-command');
        $command = $read ? 'caddy.read-live' : 'caddy.reload';
        $this->agents->run($node, $command);
        $this->agents->run($node, self::Install);
        '''
        self.assertEqual(self.names(source, True), {'agent.install', 'unknown.new-command', 'caddy.read-live', 'caddy.reload'})

    def test_descriptors_distinguish_gateway_and_agent(self):
        source = '''
        const calls = [{transport: "agent", name: "caddy.publish", input: {}},
          {transport: "gateway", name: "gateway.primitive", input: {}},
          {name: "new.agent-command", input: {}, pin: digest, deadlineMs: 1000}];
        '''
        self.assertEqual(self.names(source), {'caddy.publish', 'new.agent-command'})

    def test_swamp_wrappers_argv_and_budgets(self):
        source = '''const binary = "/usr/local/bin/orbit-agent";
        await guest.command("image.new-command", {});
        await localAgent("process.new-command", {});
        const args = [binary, "command", "new.direct-call"];
        const Budgets = {"schedule.new-command": 1000};
        '''
        self.assertEqual(self.names(source), {'image.new-command', 'process.new-command', 'new.direct-call', 'schedule.new-command'})


if __name__ == '__main__':
    unittest.main()
