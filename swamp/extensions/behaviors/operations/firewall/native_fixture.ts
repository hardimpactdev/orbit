/** Python boundary double for the shipped Python firewall program. */
export const ufwDouble = String.raw`import copy
import importlib.util
import os
import stat
import tempfile
from pathlib import Path

SCRIPT = Path(SCRIPT_PATH)
spec = importlib.util.spec_from_file_location('firewall', SCRIPT)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
TRUST = {'to': 'Anywhere on orbit', 'action': 'allow', 'source': '10.44.0.0/24', 'comment': 'orbit:wireguard-members'}
TRUST6 = {'to': '22/tcp (v6)', 'action': 'allow', 'source': 'Anywhere (v6)', 'comment': 'orbit:public-ssh-recovery'}


class Ufw:
    """UFW numbered status, add, insert and delete; any has IPv4 and IPv6 lines."""

    def __init__(self, rows=()):
        self.rows = copy.deepcopy(list(rows))
        self.effects = []
        self.active = True
        self.fail = False
        self.misplace = False

    def __call__(self, args):
        if args == ['status', 'numbered']:
            return 'Status: ' + ('active' if self.active else 'inactive') + '\n\n     To  Action  From\n' + '\n'.join(
                f"[{i + 1}] {row['to']} {row['action'].upper()} IN {row['source']}" + (f" # {row['comment']}" if row['comment'] else '')
                for i, row in enumerate(self.rows))
        if self.fail:
            raise RuntimeError('firewall.apply_failed')
        self.effects.append(args)
        remove = args[:2] == ['--force', 'delete']
        line = int(args[1]) if args[0] == 'insert' and not self.misplace else None
        rule = args[2:] if remove or args[0] == 'insert' else args
        action, protocol, source, port = rule[0], rule[3], rule[5], rule[9]
        comment = rule[rule.index('comment') + 1] if 'comment' in rule else ''
        families = {'any': [False, True], '0.0.0.0/0': [False], '::/0': [True]}.get(source, [':' in source])
        for v6 in families:
            shown = 'Anywhere' if source in ('any', '0.0.0.0/0', '::/0') else source
            row = {'to': port + '/' + protocol + (' (v6)' if v6 else ''), 'action': action,
                   'source': shown + (' (v6)' if v6 and shown == 'Anywhere' else ''), 'comment': comment}
            same = [old for old in self.rows if (old['to'], old['source']) == (row['to'], row['source'])]
            if remove:
                self.rows = [old for old in self.rows if (old['to'], old['source'], old['action']) != (row['to'], row['source'], action)]
            elif same:
                same[0].update(action=action, comment=comment)
            else:
                first6 = next((i for i, old in enumerate(self.rows) if '(v6)' in old['to'] + old['source']), len(self.rows))
                self.rows.insert(line - 1 if line else len(self.rows) if v6 else first6, row)
        return ''

    def tuples(self, port=None):
        return [(row['to'], row['action'], row['source']) for row in self.rows if port is None or row['to'].split('/')[0] == port]


`;
export const scriptPath = new URL("./scripts/firewall.py", import.meta.url).pathname;
export async function python(source: string, input: unknown = {}) {
  const child = new Deno.Command("python3", {
    args: ["-I", "-c", source],
    stdin: "piped",
    stdout: "piped",
    stderr: "piped",
    signal: AbortSignal.timeout(5000),
  }).spawn();
  const writer = child.stdin.getWriter();
  await writer.write(new TextEncoder().encode(JSON.stringify(input)));
  await writer.close();
  const result = await child.output();
  if (!result.success) {
    throw new Error(new TextDecoder().decode(result.stderr));
  }
  return JSON.parse(new TextDecoder().decode(result.stdout));
}
export const setup = `import json,sys\nSCRIPT_PATH=${JSON.stringify(scriptPath)}\n` + ufwDouble +
  String.raw`
request=json.load(sys.stdin)
ufw=Ufw([TRUST,TRUST6]);module.ufw=ufw
def converge(ensure='present',**changes):
 return module.converge({'rule':{'node_id':9,'action':'allow','port':'443','protocol':'tcp','source':'192.0.2.1','comment':'orbit web',**changes},'ensure':ensure})
def error(fn):
 try:fn()
 except (RuntimeError,ValueError) as e:return str(e)
 raise AssertionError('Expected refusal')
`;
