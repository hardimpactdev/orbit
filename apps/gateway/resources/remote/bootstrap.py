# Starts a Python remote program: `python3 -I -c <this file> <program name>`. Standard input holds the assembled
# program, one NUL byte, and the program's JSON input. The program reads that input as its own standard input.
import io
import sys

source, _, payload = sys.stdin.buffer.read().partition(b'\0')
sys.stdin = io.TextIOWrapper(io.BytesIO(payload), encoding='utf-8')
exec(compile(source, sys.argv[1], 'exec'), {'__name__': '__main__'})
