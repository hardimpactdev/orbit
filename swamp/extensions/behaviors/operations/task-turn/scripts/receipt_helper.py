#!/usr/bin/env python3
import argparse
import json
import os

p = argparse.ArgumentParser()
p.add_argument("--thread", type=int, required=True)
p.add_argument(
    "--outcome",
    required=True,
    choices=[
        "ready_for_review",
        "approved",
        "changes_requested",
        "blocked",
        "answered",
    ],
)
p.add_argument("--summary", required=True)
p.add_argument("--question")
p.add_argument("--deliverable", action="append", default=[])
a = p.parse_args()
if not a.summary.strip() or (a.outcome == "blocked" and not (a.question or "").strip()):
    p.error("A summary and blocked question are required")
directory = os.path.dirname(os.path.realpath(__file__))
with open(directory + "/turn.json") as f:
    expected = json.load(f)
if a.thread != expected["thread"]:
    p.error("This turn belongs to another thread")
result = {
    "thread": a.thread,
    "outcome": a.outcome,
    "summary": a.summary,
    "deliverables": dict(v.split("=", 1) for v in a.deliverable),
}
if a.question:
    result["question"] = a.question
with open(directory + "/receipt.json", "w") as f:
    json.dump(result, f)
