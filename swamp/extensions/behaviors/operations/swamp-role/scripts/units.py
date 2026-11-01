"""Load unit declarations without starting or restarting runtime processes."""

from common import control, ready

if __name__ == "__main__":
    control("daemon-reload")
    ready()
