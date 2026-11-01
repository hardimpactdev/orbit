from brew_query import brew_facts
from support import finish

if __name__ == "__main__":
    finish(lambda: brew_facts(False))
