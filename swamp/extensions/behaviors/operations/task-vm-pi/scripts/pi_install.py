"""Publish the pinned cached Pi executable atomically."""
from pi_common import install, run

if __name__ == "__main__":
    run(install)
