"""The terminal driver against a small program that prompts like Laravel Prompts: a text question and a list with a highlighted line."""
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

    def test_plain_removes_escape_codes_and_returns(self):
        self.assertEqual(terminal.plain('\x1b[36mred\x1b[0m\r\n'), 'red\n')


if __name__ == '__main__':
    unittest.main()
