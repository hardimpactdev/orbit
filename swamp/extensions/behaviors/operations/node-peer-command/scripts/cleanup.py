import shutil

from common import finish, saved_directory


def cleanup(data):
    shutil.rmtree(saved_directory(data))
    return {"cleaned": True}


finish(cleanup)
