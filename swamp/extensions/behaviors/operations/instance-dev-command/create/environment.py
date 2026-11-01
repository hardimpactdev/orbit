"""Write only Gateway-owned values and committed public template defaults."""
import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'scripts'))
from write_environment import publish, validate_target


def environment(value):
    validate_target(value)
    validate_target(value, '.env.testing')
    publish(value)
    publish({**value, 'content': value['testing_content']}, '.env.testing')
    return {'ready': True, 'keys': value['keys']}


if __name__ == '__main__':
    print(json.dumps(environment(json.load(sys.stdin))))
