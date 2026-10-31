"""Remove only this native run's public candidate fragments."""

import shutil

from payload_common import metadata, stage_for

value = metadata()
stage = stage_for(value)
shutil.rmtree(stage)
print('{"cleaned":true}')
