/** Fixed SQLite filesystem implementation bundled with the native behavior, never caller code. */
export const sqliteProgram = String.raw`
import contextlib, dataclasses, errno, fcntl, hashlib, json, os, pwd, re, secrets, signal, sqlite3, stat, sys, time
from pathlib import Path
from urllib.parse import quote

RESERVE = 1048576
FICLONE = 0x40049409
class Refused(Exception): pass
class Unconfirmed(Exception): pass
class QueryFailed(Exception): pass

def require(ok):
    if not ok: raise Refused()

def path(value):
    require(isinstance(value, str) and value.startswith('/') and len(value) > 1 and
            not value.endswith('/') and not re.search(r'[\x00\r\n]|(?:^|/)\.{1,2}(?:/|$)|//', value))
    return Path(value)

def inside(base, selected):
    base, selected = path(str(base)), path(str(selected))
    require(base in selected.parents)
    return selected

def lstat(selected):
    try: return os.lstat(selected)
    except FileNotFoundError: return None

def walk(selected, create_below=None):
    selected = path(str(selected))
    current = Path('/')
    for part in selected.parts[1:]:
        current /= part
        info = lstat(current)
        if info is None:
            require(create_below is not None and Path(create_below) in current.parents)
            current.mkdir(mode=0o755)
            info = os.lstat(current)
        require(stat.S_ISDIR(info.st_mode) and not stat.S_ISLNK(info.st_mode))
    return os.lstat(selected)

def regular(selected, uid=None, inode=None, mode=None, links=None):
    selected = path(str(selected)); walk(selected.parent)
    info = os.lstat(selected)
    require(stat.S_ISREG(info.st_mode) and not stat.S_ISLNK(info.st_mode))
    require(uid is None or info.st_uid == uid)
    require(inode is None or info.st_ino == inode)
    require(mode is None or stat.S_IMODE(info.st_mode) == mode)
    require(links is None or info.st_nlink == links)
    return info

def opened(selected, flags, *, uid=None, inode=None, mode=None, links=None):
    before = regular(selected, uid, inode, mode, links)
    fd = os.open(selected, flags | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        after = os.fstat(fd)
        require(before.st_ino == after.st_ino and before.st_dev == after.st_dev and stat.S_ISREG(after.st_mode))
        return fd
    except BaseException:
        os.close(fd); raise

def account(name):
    require(isinstance(name, str) and re.fullmatch(r'[a-z_][a-z0-9_-]{0,31}', name))
    value = pwd.getpwnam(name); require(value.pw_uid != 0)
    return value

@contextlib.contextmanager
def as_account(user):
    if os.geteuid() == user.pw_uid:
        yield; return
    require(os.geteuid() == 0)
    uid, gid, groups = os.geteuid(), os.getegid(), os.getgroups()
    try:
        os.initgroups(user.pw_name, user.pw_gid)
        os.setegid(user.pw_gid); os.seteuid(user.pw_uid)
        yield
    finally:
        os.seteuid(uid); os.setegid(gid); os.setgroups(groups)

def digest(selected):
    fd = opened(selected, os.O_RDONLY)
    with os.fdopen(fd, 'rb') as stream:
        sha = hashlib.sha256()
        while chunk := stream.read(1048576): sha.update(chunk)
        return sha.hexdigest()

def sync_dir(selected):
    fd = os.open(selected, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try: os.fsync(fd)
    finally: os.close(fd)

def sync_file(selected):
    fd = opened(selected, os.O_RDONLY)
    try: os.fsync(fd)
    finally: os.close(fd)

def available(selected):
    info = os.statvfs(selected)
    require(not info.f_flag & os.ST_RDONLY)
    return info.f_bavail * info.f_frsize

def connect(selected, *, readonly=False, immutable=False):
    uri = 'file:' + quote(str(selected), safe='/') + ('?mode=ro' if readonly else '?mode=rw')
    if immutable: uri += '&immutable=1'
    db = sqlite3.connect(uri, uri=True, timeout=5, isolation_level=None)
    db.set_progress_handler(lambda: time.monotonic() >= DEADLINE, 1000)
    if readonly: db.execute('PRAGMA query_only=ON')
    return db

def checked_payload(selected, size, sha, uid=None, inode=None, private=False):
    info = regular(selected, uid, inode, 0o600 if private else None)
    require(info.st_size == size and digest(selected) == sha)
    with contextlib.closing(connect(selected, readonly=True, immutable=True)) as db:
        require(db.execute('PRAGMA integrity_check').fetchone() == ('ok',))
    return info

def temporary(parent, prefix='.orbit-sqlite-', uid=None):
    selected = Path(parent) / (prefix + secrets.token_hex(8))
    fd = os.open(selected, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    if uid is not None: os.fchown(fd, uid.pw_uid, uid.pw_gid)
    return selected, fd

def snapshot(origin, destination, *, seed=False):
    # An existing private file was created with O_EXCL before SQLite opens it.
    with contextlib.closing(connect(origin, readonly=seed)) as source, contextlib.closing(connect(destination)) as target:
        if seed: require(source.execute('PRAGMA quick_check').fetchone() == ('ok',))
        pages = source.execute('PRAGMA page_count').fetchone()[0]
        page_size = source.execute('PRAGMA page_size').fetchone()[0]
        budget = available(Path(destination).parent)
        require(max(os.stat(origin).st_size, pages * page_size) > 0 and budget >= max(os.stat(origin).st_size, pages * page_size) + RESERVE)
        limit = min(DEADLINE, time.monotonic() + 30)
        def progress(status, remaining, total):
            require(time.monotonic() < limit and total * page_size <= budget - RESERVE)
        source.backup(target, pages=256, progress=progress, sleep=0.01)
        require(target.execute('PRAGMA integrity_check').fetchone() == ('ok',))
    sync_file(destination)

def remove_present(selected):
    try: Path(selected).unlink()
    except FileNotFoundError: pass

def clone_directory(selected, create=False):
    selected = path(str(selected))
    require(re.fullmatch(r'/tmp/orbit-sqlite-clone-[a-f0-9]{32}', str(selected)))
    if create: selected.mkdir(mode=0o700)
    if lstat(selected) is None: return selected, False
    info = walk(selected)
    require(info.st_uid == os.geteuid() and stat.S_IMODE(info.st_mode) == 0o700)
    return selected, True

def cleanup_clone(selected):
    selected, exists = clone_directory(selected)
    if not exists: return {}
    entries = list(selected.iterdir())
    for entry in entries:
        require(entry.name == 'snapshot.sqlite' or re.fullmatch(r'\.orbit-sqlite-[a-f0-9]{16}', entry.name))
        regular(entry, os.geteuid(), mode=0o600, links=1)
    for entry in entries: entry.unlink()
    selected.rmdir()
    return {}

def report(selected, mode, copied):
    return {'bytes': os.lstat(selected).st_size, 'sha256': digest(selected), 'mode': mode, 'copy': copied}

def place(database, wal, destination, mode):
    os.chmod(database, mode)
    remove_present(str(destination) + '-shm')
    if wal is None: remove_present(str(destination) + '-wal')
    else:
        os.chmod(wal, mode); os.replace(wal, str(destination) + '-wal')
    os.replace(database, destination); sync_dir(destination.parent)

def reflink(origin, parent):
    target, fd = temporary(parent)
    try:
        source = opened(origin, os.O_RDONLY, uid=os.geteuid())
        try: fcntl.ioctl(fd, FICLONE, source)
        finally: os.close(source)
        return target
    except BaseException:
        remove_present(target); raise
    finally: os.close(fd)

def copied_local(source, destination, mode):
    partials = []
    # Reflink is used only after proving support and while SQLite holds its write lock.
    use_backup = False
    try:
        probe = reflink(source, destination.parent); probe.unlink()
    except OSError as error:
        if error.errno not in (errno.EOPNOTSUPP, errno.ENOTTY, errno.EXDEV, errno.EINVAL): raise
        use_backup = True
    if not use_backup:
        with contextlib.closing(connect(source)) as db:
            db.execute('PRAGMA busy_timeout=2000')
            try: db.execute('BEGIN IMMEDIATE')
            except sqlite3.OperationalError as error:
                if getattr(error, 'sqlite_errorcode', None) not in (sqlite3.SQLITE_BUSY, sqlite3.SQLITE_LOCKED): raise
                use_backup = True
            if not use_backup:
                try:
                    database = reflink(source, destination.parent); partials.append(database)
                    wal_path = Path(str(source) + '-wal'); wal = None
                    if lstat(wal_path) is not None:
                        regular(wal_path, os.geteuid())
                        wal = reflink(wal_path, destination.parent); partials.append(wal)
                finally: db.execute('ROLLBACK')
    try:
        if use_backup:
            database, fd = temporary(destination.parent); os.close(fd); partials.append(database)
            snapshot(source, database)
            wal = None
        place(database, wal, destination, mode)
        return report(destination, mode, 'snapshot' if use_backup else 'reflink')
    finally:
        for partial in partials: remove_present(partial)

COPY_FIELDS = {
    'local': {'operation','checkout','source','target_checkout','target'},
    'export': {'operation','checkout','source','directory'},
    'prepare': {'operation','target_checkout','target'},
    'install': {'operation','target_checkout','target','incoming','bytes','sha256','mode'},
    'cleanup': {'operation','directory'},
    'remove': {'operation','checkout','path'},
}
def copy_database(data):
    operation = data.get('operation')
    require(operation in COPY_FIELDS and set(data) == COPY_FIELDS[operation] and os.geteuid() != 0)
    if operation == 'cleanup': return cleanup_clone(data['directory'])
    if operation == 'remove':
        selected = inside(data['checkout'], data['path'])
        try:
            regular(selected, os.geteuid()); selected.unlink()
        except FileNotFoundError: pass
        return {}
    if operation in ('prepare','local','install'):
        destination = inside(data['target_checkout'], data['target'])
        walk(path(data['target_checkout'])); walk(destination.parent, path(data['target_checkout']))
    if operation == 'prepare': return {}
    if operation == 'install':
        incoming = inside(data['target_checkout'], data['incoming'])
        require(incoming.parent == destination.parent and re.fullmatch(r'\.orbit-sqlite-[a-f0-9]{32}', incoming.name))
        require(type(data['bytes']) is int and data['bytes'] >= 16 and re.fullmatch(r'[a-f0-9]{64}', data['sha256']))
        checked_payload(incoming, data['bytes'], data['sha256'], os.geteuid(), private=True)
        require(type(data['mode']) is int and 0 <= data['mode'] <= 0o7777)
        os.chmod(incoming, data['mode'] & 0o666); os.replace(incoming, destination); sync_dir(destination.parent)
        return {}
    source = inside(data['checkout'], data['source']); info = regular(source, os.geteuid())
    mode = stat.S_IMODE(info.st_mode)
    if operation == 'local': return copied_local(source, destination, mode)
    directory, _ = clone_directory(data['directory'], create=True)
    temporary_path, fd = temporary(directory); os.close(fd)
    try:
        snapshot(source, temporary_path)
        selected = directory / 'snapshot.sqlite'; os.replace(temporary_path, selected)
        return report(selected, mode, 'snapshot')
    finally: remove_present(temporary_path)

@dataclasses.dataclass(frozen=True)
class Source:
    mode: str; base: str; source_path: str; source_user: str; transport_user: str
    operation: str; state_dir: str; snapshot_path: str
@dataclasses.dataclass(frozen=True)
class Target:
    mode: str; base: str; target_user: str; transport_user: str; operation: str
    state_dir: str; expected_size: int; expected_digest: str; environment: str; relative_path: str

def state_directory(selected, create=True):
    selected = path(str(selected))
    require(re.fullmatch(r'/var/lib/orbit/app-instance-sqlite-seeds/(?:source-[1-9][0-9]*-target-[1-9][0-9]*|target-[1-9][0-9]*)(?:-[a-f0-9]{64})?', str(selected)))
    require(os.geteuid() == 0)
    if lstat(selected) is None and not create: return False
    # The fixed shared parent is root-private, like the replaced seed implementation.
    parent = selected.parent
    if lstat(parent) is None:
        if lstat(parent.parent) is None:
            walk(parent.parent.parent); parent.parent.mkdir(mode=0o700)
        walk(parent.parent); parent.mkdir(mode=0o700)
    for directory in (parent, selected):
        if lstat(directory) is None: directory.mkdir(mode=0o700)
        info = walk(directory)
        require(info.st_uid == 0 and stat.S_IMODE(info.st_mode) == 0o700)
    return True

def load_state(directory):
    selected = Path(directory) / 'state.json'
    if lstat(selected) is None: return None
    fd = opened(selected, os.O_RDONLY, uid=0, mode=0o600, links=1)
    with os.fdopen(fd) as stream: value = json.load(stream)
    require(isinstance(value, dict)); return value

def write_state(directory, value):
    directory = Path(directory); selected, fd = temporary(directory, '.state-')
    try:
        with os.fdopen(fd, 'w') as stream:
            json.dump(value, stream, separators=(',',':')); stream.flush(); os.fsync(stream.fileno())
        os.replace(selected, directory / 'state.json'); sync_dir(directory)
    finally: remove_present(selected)

def valid_identity(value): require(isinstance(value, str) and re.fullmatch(r'[a-f0-9]{64}', value))

def source_seed(raw):
    value = Source(**raw); valid_identity(value.operation)
    require(re.fullmatch(r'/var/lib/orbit/app-instance-sqlite-seeds/source-[1-9][0-9]*-target-[1-9][0-9]*(?:-'+value.operation+r')?',value.state_dir))
    base, source, selected = path(value.base), inside(value.base, value.source_path), path(value.snapshot_path)
    require(str(selected) == '/tmp/orbit-sqlite-' + value.operation + '.sqlite')
    owner, transport = account(value.source_user), account(value.transport_user)
    require(value.mode in ('prepare','cleanup'))
    if value.mode == 'cleanup' and not state_directory(value.state_dir, False):
        require(lstat(selected) is None); return {}
    if value.mode == 'prepare' and lstat(value.state_dir) is not None: state_directory(value.state_dir)
    state = load_state(value.state_dir)
    if state is not None:
        for key in ('operation','base','source_path','snapshot_path'): require(state.get(key) == getattr(value,key))
    def remove_snapshot():
        info = lstat(selected)
        if info is not None:
            require(stat.S_ISREG(info.st_mode) and info.st_uid in (owner.pw_uid,transport.pw_uid))
            require('inode' not in state or state['inode'] == info.st_ino)
            selected.unlink()
    if value.mode == 'cleanup':
        if state is None: require(lstat(selected) is None)
        else: remove_snapshot(); (Path(value.state_dir)/'state.json').unlink()
        Path(value.state_dir).rmdir(); return {}
    if state is not None:
        if state.get('status') == 'ready' and lstat(selected) is not None:
            info = regular(selected, transport.pw_uid, state['inode'], 0o600, 1)
            require(info.st_size == state['size'] and digest(selected) == state['digest'])
            return {'bytes':info.st_size,'sha256':state['digest'],'snapshot':str(selected)}
        remove_snapshot(); (Path(value.state_dir)/'state.json').unlink()
    else: require(lstat(selected) is None)
    with as_account(owner):
        walk(base); regular(source)
        fd = opened(source, os.O_RDONLY); os.close(fd)
        with contextlib.closing(connect(source,readonly=True)) as origin:
            require(origin.execute('PRAGMA quick_check').fetchone() == ('ok',))
            required = max(os.stat(source).st_size, origin.execute('PRAGMA page_count').fetchone()[0] * origin.execute('PRAGMA page_size').fetchone()[0])
            require(required > 0 and available('/tmp') >= required + RESERVE)
    state_directory(value.state_dir)
    state = {key:getattr(value,key) for key in ('operation','base','source_path','snapshot_path')}
    state['status'] = 'preparing'; write_state(value.state_dir,state)
    with as_account(owner):
        fd = os.open(selected, os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600); os.close(fd)
        snapshot(source,selected,seed=True)
    os.chown(selected,transport.pw_uid,transport.pw_gid); os.chmod(selected,0o600)
    info = os.lstat(selected); sha = digest(selected)
    state.update(status='ready',inode=info.st_ino,size=info.st_size,digest=sha);write_state(value.state_dir,state)
    return {'bytes':info.st_size,'sha256':sha,'snapshot':str(selected)}

def target_seed(raw):
    value = Target(**raw); valid_identity(value.operation)
    require(re.fullmatch(r'/var/lib/orbit/app-instance-sqlite-seeds/target-[1-9][0-9]*(?:-'+value.operation+r')?',value.state_dir))
    base = path(value.base); destination = inside(base, str(base / value.relative_path))
    require(isinstance(value.relative_path,str) and not value.relative_path.startswith('/'))
    require(value.environment in ('development','production') and value.mode in ('prepare','install','cleanup'))
    owner,transport = account(value.target_user), account(value.transport_user)
    if value.environment == 'production': require(base == Path(owner.pw_dir) and value.relative_path == 'database.sqlite')
    directory = Path(value.state_dir); home = destination.parent
    def destination_info():
        if lstat(destination) is None: return None
        return regular(destination,owner.pw_uid,mode=0o600)
    def remove_owned(selected,inode,kind):
        selected = path(selected)
        if kind == 'incoming': require(re.fullmatch(r'/tmp/orbit-sqlite-'+value.operation[:16]+r'-[a-f0-9]{16}',str(selected)))
        else: require(selected.parent==home and re.fullmatch(r'\.database\.sqlite\.orbit-[a-f0-9]{16}',selected.name))
        if lstat(selected) is not None:
            regular(selected, inode=inode); selected.unlink()
    def abandon(state):
        for kind in ('candidate','incoming'):
            if kind in state: remove_owned(state[kind],state[kind+'_inode'],kind)
        (directory/'state.json').unlink();directory.rmdir()
    def state_identity(state): require(state.get('operation')==value.operation and state.get('destination')==str(destination))
    def complete(state):
        info = checked_payload(destination,state['expected_size'],state['expected_digest'],owner.pw_uid,private=True)
        for kind in ('candidate','incoming'):
            if kind in state: remove_owned(state[kind],state[kind+'_inode'],kind)
        state.update(status='complete',destination_inode=info.st_ino)
        for key in ('candidate','candidate_inode','incoming','incoming_inode'): state.pop(key,None)
        write_state(directory,state)
    if value.mode == 'cleanup':
        if not state_directory(directory,False): return {'state':'cleaned'}
        state=load_state(directory)
        if state is None: directory.rmdir()
        else: state_identity(state);abandon(state)
        return {'state':'cleaned'}
    require(type(value.expected_size) is int and value.expected_size>0);valid_identity(value.expected_digest)
    with as_account(owner):
        for selected in (base,home): require(walk(selected).st_uid==owner.pw_uid)
        require(os.access(home,os.W_OK|os.X_OK,effective_ids=True))
    if lstat(directory) is not None: state_directory(directory)
    state=load_state(directory)
    if state is not None:
        state_identity(state); require(type(state.get('expected_size')) is int and state['expected_size']>0);valid_identity(state.get('expected_digest'))
        if state.get('status')=='complete':
            checked_payload(destination,state['expected_size'],state['expected_digest'],owner.pw_uid,state['destination_inode'],private=True)
            return {'state':'complete'}
        require(state.get('status') in ('prepared','installing'))
        try:
            present=destination_info()
            if present is not None:
                require(state['status']=='installing')
                checked_payload(destination,state['expected_size'],state['expected_digest'],owner.pw_uid,private=True)
                complete(state); return {'state':'complete'}
        except Refused:
            abandon(state);raise
        selected = state['candidate'] if state['status']=='installing' else state['incoming']
        if lstat(selected) is None or state['expected_size']!=value.expected_size or state['expected_digest']!=value.expected_digest:
            abandon(state);state=None
    else: require(destination_info() is None)
    require(available(home)>=value.expected_size+RESERVE)
    if value.mode=='prepare':
        if state is None:
            state_directory(directory)
            selected,fd=temporary('/tmp','orbit-sqlite-'+value.operation[:16]+'-',transport)
            info=os.fstat(fd);os.close(fd)
            state={'operation':value.operation,'destination':str(destination),'expected_size':value.expected_size,'expected_digest':value.expected_digest,
                   'incoming':str(selected),'incoming_inode':info.st_ino,'status':'prepared'}
            write_state(directory,state);return {'state':'ready','incoming':str(selected)}
        if state['status']=='installing':
            try: checked_payload(state['candidate'],value.expected_size,value.expected_digest,owner.pw_uid,state['candidate_inode'],private=True)
            except Refused: abandon(state);raise
            return {'state':'resume'}
        with as_account(transport):
            fd=opened(state['incoming'],os.O_WRONLY,uid=transport.pw_uid,inode=state['incoming_inode'],mode=0o600,links=1)
            try: os.ftruncate(fd,0)
            finally: os.close(fd)
        return {'state':'ready','incoming':state['incoming']}
    require(state is not None)
    if state['status']=='prepared':
        checked_payload(state['incoming'],value.expected_size,value.expected_digest,transport.pw_uid,state['incoming_inode'],private=True)
        candidate,fd=temporary(home,'.database.sqlite.orbit-',owner)
        try:
            source=opened(state['incoming'],os.O_RDONLY,uid=transport.pw_uid,inode=state['incoming_inode'],mode=0o600,links=1)
            with os.fdopen(source,'rb') as origin, os.fdopen(fd,'wb') as target:
                while chunk:=origin.read(1048576): target.write(chunk)
                target.flush();os.fsync(target.fileno());inode=os.fstat(target.fileno()).st_ino
            state.update(status='installing',candidate=str(candidate),candidate_inode=inode)
            write_state(directory,state)
        except BaseException: remove_present(candidate);raise
    candidate=Path(state['candidate'])
    checked_payload(candidate,value.expected_size,value.expected_digest,owner.pw_uid,state['candidate_inode'],private=True)
    if destination_info() is not None: abandon(state);raise Refused()
    try: os.link(candidate,destination,follow_symlinks=False)
    except FileExistsError: abandon(state);raise Refused()
    try: sync_dir(home);complete(state)
    except BaseException: raise Unconfirmed()
    return {'state':'changed'}

def write_database(data):
    require(set(data)=={'owner','path','sql'})
    sql=data['sql']
    if not (isinstance(sql,str) and sql.strip() and len(sql.strip().encode())<=16384 and '\x00' not in sql): raise QueryFailed()
    selected=path(data['path']);require(len(str(selected))<=1024)
    user=account(data['owner'])
    with as_account(user):
        fd=opened(selected,os.O_RDWR);os.close(fd)
        with contextlib.closing(connect(selected)) as db:
            db.setlimit(sqlite3.SQLITE_LIMIT_ATTACHED,0)
            db.execute('PRAGMA busy_timeout=10000')
            # execute accepts exactly one statement. SQL is never returned or logged.
            cursor=db.execute(sql.strip())
            truncated=False
            if cursor.description is not None:
                truncated=len(cursor.fetchmany(501))>500
            count=db.execute('SELECT changes()').fetchone()[0]
            cursor.close()
            return {'row_count':max(0,count),'truncated':truncated}


def read_database(data):
    require(set(data)=={'owner','path','sql','mode','table'})
    mode=data['mode'];require(mode in ('query','tables','schema','describe'))
    sql=data['sql'];require(isinstance(sql,str) and len(sql.encode())<=16384 and '\x00' not in sql)
    selected=path(data['path']);require(len(str(selected))<=1024)
    user=account(data['owner'])
    with as_account(user):
        fd=opened(selected,os.O_RDONLY);os.close(fd)
        with contextlib.closing(sqlite3.connect('file:'+quote(str(selected),safe='/')+'?mode=ro',uri=True)) as db:
            db.execute('PRAGMA query_only=ON');db.execute('PRAGMA busy_timeout=10000')
            db.setlimit(sqlite3.SQLITE_LIMIT_ATTACHED,0)
            db.set_progress_handler(lambda: time.monotonic()>=DEADLINE,1000)
            pragmas={'collation_list','compile_options','database_list','foreign_key_list','function_list','index_info','index_list','index_xinfo','module_list','pragma_list','table_info','table_list','table_xinfo'}
            def authorize(action,one,two,unused_db,unused_trigger):
                if action==sqlite3.SQLITE_PRAGMA: return sqlite3.SQLITE_OK if (one or '').lower() in pragmas else sqlite3.SQLITE_DENY
                if action==sqlite3.SQLITE_FUNCTION and (two or '').lower() in ('load_extension','writefile','readfile'): return sqlite3.SQLITE_DENY
                return sqlite3.SQLITE_OK if action in (sqlite3.SQLITE_SELECT,sqlite3.SQLITE_READ,sqlite3.SQLITE_FUNCTION,sqlite3.SQLITE_RECURSIVE) else sqlite3.SQLITE_DENY
            db.set_authorizer(authorize)
            def query(statement):
                cursor=db.execute(statement);columns=[str(c[0]) for c in cursor.description or []]
                rows=[];truncated=False
                for record in cursor:
                    row={name:(value.decode('utf-8') if isinstance(value,bytes) else value) for name,value in zip(columns,record)}
                    if len(rows)>=500 or len(json.dumps([columns,rows,row],ensure_ascii=False).encode())>11000:
                        truncated=True;break
                    rows.append(row)
                cursor.close()
                return {'columns':columns,'rows':rows,'row_count':len(rows),'truncated':truncated}
            def names():
                return [row['name'] for row in query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")['rows']]
            def columns(table):
                require(isinstance(table,str) and table and len(table)<=255 and '\x00' not in table)
                result=query('PRAGMA table_info("'+table.replace('"','""')+'")')['rows']
                if not result: return None
                return [{'name':row['name'],'type':row['type'],'nullable':not bool(row['notnull']),'default':row['dflt_value'],'primary':bool(row['pk'])} for row in result]
            if mode=='query': return query(sql)
            if mode=='tables': result={'tables':names()}
            elif mode=='describe':
                value=columns(data['table'])
                if value is None: return {'error_code':'database.table_missing'}
                result={'table':data['table'],'columns':value}
            else: result={'tables':[{'name':name,'columns':columns(name)} for name in names()]}
            if len(json.dumps(result,ensure_ascii=False).encode())>12000: raise QueryFailed()
            return result

DEADLINE=time.monotonic()+float(sys.argv[2]) if len(sys.argv)>2 else time.monotonic()+120
if __name__=='__main__':
    action=sys.argv[1] if len(sys.argv)>1 else ''
    family='database.query' if action in ('write','read') else 'instance.sqlite_copy' if action=='copy' else 'instance.sqlite_seed'
    try:
        os.umask(0o077)
        if action=='read':
            def read_timeout(signum,frame): raise QueryFailed()
            signal.signal(signal.SIGALRM,read_timeout)
        signal.alarm(max(1,int(DEADLINE-time.monotonic())))
        raw=sys.stdin.buffer.read(65537);require(len(raw)<=65536)
        data=json.loads(raw);require(isinstance(data,dict))
        result={'copy':copy_database,'seedSource':source_seed,'seedTarget':target_seed,'write':write_database,'read':read_database}[action](data)
        sys.stdout.write(json.dumps({'ok':True,'data':result},separators=(',',':')))
    except Unconfirmed: sys.stdout.write('{"ok":false,"error_code":"instance.sqlite_seed_unconfirmed"}')
    except (Refused,PermissionError,FileNotFoundError,KeyError,TypeError,ValueError):
        code='database.sqlite_unreadable' if action in ('write','read') else family+'_refused'
        sys.stdout.write(json.dumps({'ok':False,'error_code':code},separators=(',',':')))
    except BaseException:
        code='database.query_failed' if action in ('write','read') else family+'_failed'
        sys.stdout.write(json.dumps({'ok':False,'error_code':code},separators=(',',':')))
`;
