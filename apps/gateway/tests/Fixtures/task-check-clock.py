# Injected before the real program's main guard. Only this module sees the test clock;
# subprocess/selectors keep their real clocks and still run real command trees.
_real_time = time


class DeadlineClock:
    def __init__(self, trigger=None):
        self.trigger = trigger
        self.drain_ticks = 0
        self.draining = False

    def monotonic(self):
        if __ADVANCE_DRAIN__ and self.draining:
            self.drain_ticks += 10
        return _real_time.monotonic() + self.drain_ticks + (10000 if self.trigger and os.path.exists(self.trigger) else 0)

    def __getattr__(self, name):
        return getattr(_real_time, name)


class GraceClock(DeadlineClock):
    def __init__(self):
        self.now = 0

    def monotonic(self):
        return self.now

    def sleep(self, seconds):
        self.now += seconds
        _real_time.sleep(0)


time = DeadlineClock(__DEADLINE_TRIGGER__)
_stop_child_process = stop_child_process


def stop_child_process():
    global time
    deadline_clock = time
    deadline_clock.draining = CHILD_PROCESS is not None and CHILD_PROCESS.poll() is not None
    time = GraceClock()
    try:
        _stop_child_process()
    finally:
        time = deadline_clock


_auth_chunk_ack = __CHUNK_ACK__
if _auth_chunk_ack is not None:
    _read_auth_chunk = os.read

    def read_with_auth_ack(fd, size):
        chunk = _read_auth_chunk(fd, size)
        if b'ghs_set' in chunk:
            ack = os.open(_auth_chunk_ack, os.O_WRONLY | os.O_NONBLOCK)
            try:
                os.write(ack, b'1')
            finally:
                os.close(ack)
        return chunk

    os.read = read_with_auth_ack
