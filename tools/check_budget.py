"""Allocate one worker budget between the concurrent lanes of repository checks."""
import threading


def lane_weight(root, project):
    tests = root / project / "tests"
    return max(1, sum(1 for _path in tests.rglob("*Test.php"))) if tests.is_dir() else 1


def lane_count(groups, processes):
    """How many lanes run at once. The heaviest lane keeps at least half the budget, so with a budget of 4 the
    Gateway runs two processes while two other lanes run one each. A budget of 2 or less runs one lane: the budget
    counts workers, not each tool's main process, so fewer lanes also reduce coordinator overhead."""
    if processes <= 2:
        return 1
    return max(1, min(len(groups), 1 + processes // 2))


class ProcessBudget:
    """Hands out processes to the commands of the running lanes, so the tools of all lanes together stay within the
    budget. A command gets its lane's weighted share of the lanes that are still running, so a lane gets the
    processes that finished lanes freed. A command waits when every process is in use."""

    def __init__(self, processes, weights):
        self.processes, self.weights = processes, weights
        self.held, self.running = {}, set()
        self.condition = threading.Condition()

    def start(self, lane):
        with self.condition:
            self.running.add(lane)

    def finish(self, lane):
        with self.condition:
            self.running.discard(lane)
            self.held.pop(lane, None)
            self.condition.notify_all()

    def acquire(self, lane):
        with self.condition:
            while True:
                others = [other for other in self.running if other != lane]
                free = self.processes - sum(self.held.get(other, 0) for other in others)
                # Keep one process for each running lane between commands, so it can continue.
                waiting = sum(1 for other in others if not self.held.get(other))
                total = sum(self.weights[other] for other in self.running | {lane})
                share = max(1, self.processes * self.weights[lane] // total)
                granted = min(share, free - waiting) if free - waiting >= 1 else min(1, free)
                if granted >= 1:
                    self.held[lane] = granted
                    return granted
                self.condition.wait()

    def release(self, lane):
        with self.condition:
            self.held[lane] = 0
            self.condition.notify_all()
