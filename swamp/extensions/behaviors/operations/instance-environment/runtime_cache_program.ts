/** Application diagnostics never leave this fixed program. Only the outcome is public. */
export const configCacheProgram = String.raw`
import json,os,pathlib,pwd,re,shutil,stat,subprocess,sys,tempfile
P=pathlib.Path
class Refused(Exception):pass
def check(ok):
 if not ok:raise Refused()
def exists(path):return os.path.lexists(path)
def directory(path,uid):
 check(path.is_dir() and path.resolve(strict=True)==path and path.stat().st_uid==uid)
def regular(path,uid):
 check(exists(path) and stat.S_ISREG(path.lstat().st_mode) and path.lstat().st_uid==uid)
class Host:
 php=P('/usr/bin')
 def account(self,user):return pwd.getpwnam(user)
 def run(self,program,args,account,env,cwd):
  def drop():
   if os.geteuid()==0:
    os.initgroups(account.pw_name,account.pw_gid);os.setgid(account.pw_gid);os.setuid(account.pw_uid)
   else:check(os.geteuid()==account.pw_uid)
  return subprocess.run([str(program),*args],cwd=cwd,env=env,preexec_fn=drop,stdin=subprocess.DEVNULL,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,timeout=120).returncode==0
def refresh(data,host):
 user,version=data['user'],data['version']
 check(re.fullmatch('[a-z_][a-z0-9_-]{0,31}',user) and user!='root' and re.fullmatch('[0-9]+\\.[0-9]+',version))
 account=host.account(user);check(account.pw_uid!=0)
 home=P(data['home']);check(home==P(account.pw_dir));directory(home,account.pw_uid)
 current=home/'current';application=P(data['application'])
 check(application==current or application.is_relative_to(current))
 if not exists(current):return dict(outcome='no_current_release')
 check(current.is_symlink())
 release=current.resolve(strict=True);releases=home/'releases';directory(releases,account.pw_uid)
 check(release.parent==releases and re.fullmatch('[A-Za-z0-9][A-Za-z0-9._-]{0,127}',release.name))
 directory(release,account.pw_uid)
 app=release/application.relative_to(current);directory(app,account.pw_uid)
 cache=app/'bootstrap'/'cache'/'config.php'
 if not exists(cache):return dict(outcome='config_uncached')
 directory(app/'bootstrap',account.pw_uid);directory(cache.parent,account.pw_uid);regular(cache,account.pw_uid)
 temporary=P(tempfile.mkdtemp(prefix='.orbit-config-',dir=cache.parent))
 try:
  os.chown(temporary,account.pw_uid,account.pw_gid);temporary.chmod(0o700)
  candidate=temporary/'config.php'
  environment=dict(PATH='/usr/bin:/bin',HOME=str(home),LANG='C.UTF-8',APP_CONFIG_CACHE=str(candidate))
  php=host.php/('php'+version)
  check(host.run(php,['artisan','config:cache'],account,environment,app))
  regular(candidate,account.pw_uid)
  check(host.run(php,['artisan','--version','--no-interaction'],account,environment,app))
  regular(candidate,account.pw_uid)
  candidate.chmod(stat.S_IMODE(cache.lstat().st_mode))
  with candidate.open('rb') as file:os.fsync(file.fileno())
  os.replace(candidate,cache)
  fd=os.open(cache.parent,os.O_RDONLY|os.O_DIRECTORY)
  try:os.fsync(fd)
  finally:os.close(fd)
  return dict(outcome='config_cached')
 finally:shutil.rmtree(temporary)
def main():
 try:result=refresh(json.load(sys.stdin),Host())
 except:result=dict(error_code='env.config_cache_refresh_failed')
 print(json.dumps(result,separators=(',',':')))
if __name__=='__main__':main()
`;
