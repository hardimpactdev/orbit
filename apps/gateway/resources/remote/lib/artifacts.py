"""Candidate locations for external recipe artifacts. Transport failures may fall back; integrity failures stop."""


def artifact_candidates(name, artifact, mirror):
    url = artifact['url']
    digest = artifact.get('sha256') or artifact.get('sha512')
    mirrored = [mirror + '/' + name + '_' + digest + '.deb'] if mirror and url.endswith('.deb') else []
    return list(dict.fromkeys([*mirrored, url]))
