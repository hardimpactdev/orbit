"""The terminal driver against small programs that prompt like Laravel Prompts: a text question, a list with a highlighted line and a searchable table."""
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
sys.path.insert(0, str(Path(__file__).resolve().parents[4] / 'tools/swamp-proof-guest'))
import terminal  # noqa: E402

PROGRAM = r'''
import os, sys, termios, tty
def frame(options, at):
    sys.stdout.write("\x1b[2J\x1b[H ┌ \x1b[36mWhich Node?\x1b[0m ───┐\r\n")
    for number, label in enumerate(options):
        sys.stdout.write(" │ %s %s %s │\r\n" % ("\x1b[36m›\x1b[0m" if number == at else " ", "●" if number == at else "○", label))
    sys.stdout.flush()
port = input("Which port? ")
while not port.isdigit():
    port = input("Which port? (a number) ")
options = ["gateway", "app-dev", "app-prod"]
at = 0
fd = sys.stdin.fileno()
saved = termios.tcgetattr(fd)
tty.setraw(fd)
try:
    frame(options, at)
    while True:
        key = os.read(fd, 8)
        if key == b"\x03":
            termios.tcsetattr(fd, termios.TCSADRAIN, saved)
            print("cancelled")
            sys.exit(1)
        if key == b"\x1b[B":
            at = (at + 1) % len(options)
            frame(options, at)
        if key == b"\r":
            break
finally:
    termios.tcsetattr(fd, termios.TCSADRAIN, saved)
print("port %s on %s" % (port, options[at]))
'''
ARGV = [sys.executable, '-c', PROGRAM]
ENV = {'PATH': '/usr/bin:/bin:/usr/local/bin', 'TERM': 'xterm'}

# The searchable table of the CLI (SearchableDataTablePrompt): a `/ Search` line, a header, the rows; typing filters at once, Down moves the
# highlight (a colour, so the text does not show it), Enter selects. The Node prompt draws it, with the rows ID | Name.
TABLE = r'''
import os, sys, termios, tty
ROWS = [(row.split(":")[0], row.split(":")[1]) for row in os.environ["ROWS"].split(",")]
def frame(query, at):
    shown = [row for row in ROWS if query in row[0] or query in row[1]]
    out = "\x1b[2J\x1b[H ┌ \x1b[36mNode\x1b[0m ─────────┐\r\n"
    out += " │ %s │\r\n" % (("/ " + query) if query else "\x1b[2m/ Search\x1b[0m")
    out += " ├────┬──────────┤\r\n │ ID │ Name     │\r\n ├────┼──────────┤\r\n"
    for number, (ident, name) in enumerate(shown):
        out += " │ %s%-2s\x1b[0m │ %-8s │\r\n" % ("\x1b[7m" if number == at else "", ident, name)
    if not shown:
        out += " │ No results found. │\r\n"
    out += " └────┴──────────┘\r\n"
    sys.stdout.write(out)
    sys.stdout.flush()
    return shown
fd = sys.stdin.fileno()
saved = termios.tcgetattr(fd)
tty.setraw(fd)
query, at = "", 0
try:
    shown = frame(query, at)
    while True:
        key = os.read(fd, 64)
        if key == b"\x03":
            break
        if key == b"\x1b[B":
            at = min(at + 1, max(len(shown) - 1, 0))
        elif key == b"\r":
            if shown:
                termios.tcsetattr(fd, termios.TCSADRAIN, saved)
                print("selected %s" % shown[at][1])
                sys.exit(0)
        elif not key.startswith(b"\x1b"):
            query += key.decode()
            at = 0
        shown = frame(query, at)
finally:
    termios.tcsetattr(fd, termios.TCSADRAIN, saved)
sys.exit(1)
'''


def table(rows='1:gateway,2:app-dev,3:app-prod'):
    return [sys.executable, '-c', TABLE], {**ENV, 'ROWS': rows}


class TerminalTest(unittest.TestCase):
    def test_answers_a_question_asks_again_and_chooses_the_third_item(self):
        script = [{'see': 'port', 'send': 'abc'}, {'see': 'a number', 'send': '8080'}, {'see': 'Node', 'choose': 'app-prod'}]
        done = terminal.drive(ARGV, script, ENV, 20)
        self.assertEqual(done['rc'], 0)
        self.assertIn('port 8080 on app-prod', done['text'])

    def test_chooses_the_first_item_without_moving(self):
        done = terminal.drive(ARGV, [{'see': 'port', 'send': '1'}, {'see': 'Node', 'choose': 'gateway'}], ENV, 20)
        self.assertIn('port 1 on gateway', done['text'])

    def test_ctrl_c_ends_the_prompt_and_the_exit_code_is_kept(self):
        done = terminal.drive(ARGV, [{'see': 'port', 'send': '5'}, {'see': 'Node', 'send': '^C'}], ENV, 20)
        self.assertEqual(done['rc'], 1)
        self.assertIn('cancelled', done['text'])

    def test_a_label_that_is_not_in_the_list_is_named(self):
        with self.assertRaises(terminal.TerminalError) as caught:
            terminal.drive(ARGV, [{'see': 'port', 'send': '5'}, {'see': 'Node', 'choose': 'nowhere'}], ENV, 20)
        self.assertIn("no option 'nowhere' in ['gateway', 'app-dev', 'app-prod']", str(caught.exception))

    def test_a_program_that_never_prompts_is_reported(self):
        with self.assertRaises(terminal.TerminalError) as caught:
            terminal.drive([sys.executable, '-c', 'print("Node ID must be a positive integer.")'], [{'see': 'port', 'send': '1'}], ENV, 20)
        self.assertIn("ended before 'port'", str(caught.exception))
        self.assertIn('Node ID must be', str(caught.exception))

    def test_a_program_that_does_not_end_is_killed_at_the_deadline(self):
        with self.assertRaises(terminal.TerminalError) as caught:
            terminal.drive([sys.executable, '-c', 'import time; print("ready", flush=True); time.sleep(30)'], [{'see': 'ready', 'send': 'x'}], ENV, 3)
        self.assertIn('did not end', str(caught.exception))

    def test_chooses_a_row_of_a_searchable_table_by_typing_its_name(self):
        argv, env = table()
        done = terminal.drive(argv, [{'see': 'Node', 'choose': 'app-dev'}], env, 20)
        self.assertEqual(done['rc'], 0)
        self.assertIn('selected app-dev', done['text'])

    def test_moves_down_to_the_row_that_equals_the_name_when_a_longer_name_also_matches(self):
        argv, env = table('1:gateway,2:app-dev-old,3:app-dev')
        done = terminal.drive(argv, [{'see': 'Node', 'choose': 'app-dev'}], env, 20)
        self.assertIn('selected app-dev\n', done['text'])

    def test_a_name_that_no_row_has_is_named_with_the_rows_that_the_search_left(self):
        argv, env = table('1:gateway,2:app-dev-old')
        with self.assertRaises(terminal.TerminalError) as caught:
            terminal.drive(argv, [{'see': 'Node', 'choose': 'app-dev'}], env, 20)
        self.assertIn("no option 'app-dev'", str(caught.exception))
        self.assertIn('app-dev-old', str(caught.exception))

    def test_see_steps_without_send_wait_in_the_same_screen_and_the_last_one_chooses(self):
        argv, env = table()
        script = [{'see': 'Node'}, {'see': '/ Search'}, {'see': 'ID'}, {'see': 'Name', 'choose': 'app-prod'}]
        done = terminal.drive(argv, script, env, 20)
        self.assertIn('selected app-prod', done['text'])

    def test_a_see_step_alone_does_not_type_anything(self):
        argv, env = table()
        done = terminal.drive(argv, [{'see': 'Node'}, {'see': 'ID', 'send': '^C'}], env, 20)
        self.assertEqual(done['rc'], 1)
        self.assertNotIn('selected', done['text'])

    def test_a_see_step_for_text_that_never_comes_is_reported(self):
        argv, env = table()
        with self.assertRaises(terminal.TerminalError) as caught:
            terminal.drive(argv, [{'see': 'Node'}, {'see': 'Press / to search'}], env, 3)
        self.assertIn("'Press / to search'", str(caught.exception))

    def test_a_table_is_read_from_its_last_frame(self):
        text = ' ┌ Node ┐\n │ / Search │\n ├──┬──┤\n │ ID │ Name │\n ├──┼──┤\n │ 1 │ old │\n └──┴──┘\n ┌ Node ┐\n │ / ap │\n ├──┬──┤\n │ ID │ Name │\n ├──┼──┤\n │ 2 │ app-dev │\n └──┴──┘\n'
        self.assertEqual(terminal.table_rows(text), [['2', 'app-dev']])

    def test_plain_removes_escape_codes_and_returns(self):
        self.assertEqual(terminal.plain('\x1b[36mred\x1b[0m\r\n'), 'red\n')


if __name__ == '__main__':
    unittest.main()
