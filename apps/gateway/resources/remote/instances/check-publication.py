# Program: instances.check-publication
# Uses: lib/program.py lib/source-removal.py
# Outcomes: published unpublished failed
# Timeout: 900
# Input: {"origin": the checkout's origin, "commit": full commit ID, "git_read": Git read environment variables}
# Result: published or unpublished, with no data. failed: the origin refs could not be read.
# Checks whether a current branch or tag of the origin contains the commit. It reads the origin refs into a temporary
# repository and never changes the checkout.


def main(payload):
    if is_published(payload['origin'], payload['commit'], payload['git_read'], 'failed'):
        return 'published', {}
    return 'unpublished', {}


run_program(main)
