/** Strict Project dotenv parsing shared by Instance and Tasks setup actions. */
export const projectEnvironmentProgram = String.raw`
def parse_project_environment(dotenv):
 if len(dotenv.encode())>1048576:raise ValueError('instance.step_not_admitted')
 env={}
 for line in dotenv.splitlines():
  if not line.strip() or line.lstrip().startswith('#'):continue
  match=re.fullmatch(r'([A-Za-z_][A-Za-z0-9_]{0,254})=(.*)',line)
  if not match or match[1].startswith(('LD_','BASH_')) or '\0' in match[2]:raise ValueError('instance.step_not_admitted')
  key,text=match.groups()
  if text.startswith('"'):
   if len(text)<2 or not text.endswith('"') or re.search(r'\\(?![nr\\"$])',text[1:-1]):raise ValueError('instance.step_not_admitted')
   text=re.sub(r'\\([nr\\"$])',lambda escape:{'n':'\n','r':'\r'}.get(escape[1],escape[1]),text[1:-1])
  elif '"' in text or "'" in text:raise ValueError('instance.step_not_admitted')
  if key in env or len(text.encode())>65536 or len(env)>=1024:raise ValueError('instance.step_not_admitted')
  env[key]=text
 return env
`;
