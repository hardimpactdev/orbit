/** Direct UFW ownership and rollback, shared by Node network programs. */
export const FIREWALL_PYTHON = String.raw`
def endpoint(value):
    if value in ['any','Anywhere','0.0.0.0/0','::/0']:return 'any'
    try:
        if '/' in value:
            n=ipaddress.ip_network(value,strict=False)
            return str(n.network_address) if n.prefixlen==n.max_prefixlen else str(n)
        return str(ipaddress.ip_address(value))
    except ValueError:return None

def port_value(value):
    parts=value.split(':')
    if not 1<=len(parts)<=2 or any(not p.isdigit() or not 0<int(p)<65536 for p in parts):return None
    nums=[int(p) for p in parts]
    if len(nums)==2 and nums[0]>nums[1]:return None
    return ':'.join(str(p) for p in nums)

def shape_valid(s):
    fields=['comment','action','direction','source','destination','port','protocol','in_interface','out_interface','family']
    if not isinstance(s,dict) or set(s)!=set(fields):return False
    if not isinstance(s['comment'],str) or not re.fullmatch(r'[^\s#]{1,256}',s['comment']):return False
    if s['action'] not in ['allow','deny'] or s['direction'] not in ['in','out','fwd'] or s['protocol'] not in ['any','tcp','udp']:return False
    if s['family'] not in [None,'v4','v6']:return False
    for field in ['source','destination']:
        if endpoint(s[field])!=s[field]:return False
        if s[field]!='any' and s['family']!=('v6' if ':' in s[field] else 'v4'):return False
    if s['port']!='any' and port_value(s['port'])!=s['port']:return False
    for field in ['in_interface','out_interface']:
        if s[field] is not None and not re.fullmatch('[A-Za-z0-9_.:-]{1,64}',s[field]):return False
    return not (s['direction']=='in' and s['out_interface'] is not None or s['direction']=='out' and s['in_interface'] is not None)

def split_interface(value):
    if ' on ' in value:
        text,interface=value.rsplit(' on ',1)
        if re.fullmatch('[A-Za-z0-9_.:-]{1,64}',interface):return text.strip(),interface
    return value,None

def parse_live(line):
    comment=line.rsplit('#',1)[1].strip() if '#' in line else ''
    row={'number':None,'comment':comment,'shape':None}
    m=re.fullmatch(r'(?:\[\s*(\d+)\]\s*)?(.+?)\s+(ALLOW|DENY)\s+(IN|OUT|FWD)\s+(.+?)(?:\s+#\s*(.*))?',line.strip())
    if not m or (m[6] or '')!=comment:return row
    row['number']=int(m[1]) if m[1] else None
    family='v6' if '(v6)' in m[2]+m[5] else 'v4'
    target,ti=split_interface(m[2].replace('(v6)','').strip())
    source,si=split_interface(m[5].replace('(v6)','').strip())
    t=re.fullmatch(r'(?:(.+?)\s+)?(\d{1,5}(?::\d{1,5})?)(?:/(tcp|udp))?',target)
    destination=endpoint((t[1] or 'any') if t else target)
    port=port_value(t[2]) if t else 'any';source=endpoint(source)
    if destination is None or port is None or source is None:return row
    direction=m[4].lower()
    row['shape']={'comment':comment,'action':m[3].lower(),'direction':direction,'source':source,'destination':destination,'port':port,'protocol':(t[3] or 'any') if t else 'any','in_interface':si if direction=='fwd' else None if direction=='out' else ti or si,'out_interface':ti if direction=='fwd' else ti or si if direction=='out' else None,'family':family}
    return row

def firewall_status():
    text=command('ufw',['status','numbered']).stdout
    statuses=re.findall(r'(?im)^Status: (active|inactive|absent)\s*$',text)
    if len(statuses)!=1:refuse('firewall.status_unrecognized')
    return statuses[0],[r for r in map(parse_live,text.splitlines()) if r['comment'] or r['shape']]

def matches(expected,actual):
    if actual is None:return False
    return all(expected[k]==actual[k] for k in expected if k!='family') and (expected['family'] is None or expected['family']==actual['family'])

def ownership(rows,shape):
    owned=[r for r in rows if r['comment']==shape['comment']]
    if not owned:return 'missing'
    families=[r['shape']['family'] if r['shape'] else None for r in owned]
    required=[shape['family']] if shape['family'] else ['v4','v6']
    return 'exact' if all(matches(shape,r['shape']) for r in owned) and sorted(families)==sorted(required) else 'drift'

def ufw_args(s):
    args=['route',s['action']] if s['direction']=='fwd' else [s['action'],s['direction']]
    for direction,key in [('in','in_interface'),('out','out_interface')]:
        if s[key]:args+=([direction] if s['direction']=='fwd' else [])+['on',s[key]]
    if s['protocol']!='any':args+=['proto',s['protocol']]
    wildcard='0.0.0.0/0' if s['family']=='v4' else '::/0' if s['family']=='v6' else 'any'
    args+=['from',s['source'] if s['source']!='any' else wildcard,'to',s['destination'] if s['destination']!='any' else wildcard]
    if s['port']!='any':args+=['port',s['port']]
    return args+['comment',s['comment']]

def active_rows():
    status,rows=firewall_status()
    if status!='active':refuse('node.firewall_convergence_failed')
    return rows

def validate_shapes(shapes):
    comments=set()
    for s in shapes:
        if not shape_valid(s) or not (s['comment'].startswith('orbit:') or s['comment']=='orbit-ci-runners') or s['comment'] in comments or len(comments)>=4096:refuse('firewall.input_invalid')
        comments.add(s['comment'])

def delete_comments(comments):
    rows=active_rows();numbers=[]
    for row in rows:
        if row['comment'] in comments:
            if row['number'] is None:raise Uncertain()
            numbers.append(row['number'])
    for n in sorted(set(numbers),reverse=True):command('ufw',['--force','delete',str(n)])

def rules(data):
    desired=data['desired'];removing=data['remove'];validate_shapes([r['shape'] for r in desired]+removing)
    status,rows=firewall_status()
    if status!='active':
        if status!='inactive' or not data['enable'] or len(desired)!=1 or removing:refuse('node.firewall_convergence_failed')
        s=desired[0]['shape']
        if not (s['comment']=='orbit:public-ssh-recovery' and s['action']=='allow' and s['direction']=='in' and s['source']=='any' and s['destination']=='any' and s['protocol']=='tcp' and s['port'].isdigit() and s['family'] is None and s['in_interface'] is None and s['out_interface'] is None):refuse('firewall.input_invalid')
        command('ufw',ufw_args(s));command('ufw',['--force','enable']);rows=active_rows()
    replacing=set();missing=[]
    for r in desired:
        own=ownership(rows,r['shape'])
        if own=='missing':missing.append(r['shape'])
        if own=='drift':
            previous=next((row['shape'] for row in rows if row['comment']==r['shape']['comment']),None)
            expect=dict(r['shape']);expect['port']=previous['port'] if previous else ''
            if not r['replace_port'] or not previous or not previous['port'].isdigit() or not r['shape']['port'].isdigit() or ownership(rows,expect)!='exact':refuse('firewall.rule_collision')
            replacing.add(r['shape']['comment']);missing.append(r['shape'])
    if any(ownership(rows,s)=='drift' for s in removing):refuse('firewall.rule_collision')
    delete_comments(replacing)
    for s in missing:command('ufw',ufw_args(s))
    rows=active_rows()
    if any(ownership(rows,r['shape'])!='exact' for r in desired):raise Uncertain()
    delete_comments({s['comment'] for s in removing})
    if any(ownership(active_rows(),s)!='missing' for s in removing):raise Uncertain()
    return {'matches':True}

ROUTER_FAMILIES=['orbit:router-lan-https','orbit:router-lan-dns-udp','orbit:router-lan-dns-tcp']
def router_owned(comment):return any(comment==p or comment.startswith(p+':') for p in ROUTER_FAMILIES)
def router_snapshot():
    result=[]
    for row in active_rows():
        if router_owned(row['comment']):
            if row['shape'] is None:raise Uncertain()
            result.append(row['shape'])
    return result

def router(data):
    desired=data['desired'];validate_shapes(desired)
    if any(not router_owned(s['comment']) for s in desired):refuse('firewall.input_invalid')
    before=router_snapshot();rows=active_rows()
    if len({json.dumps(s,sort_keys=True) for s in before})!=len(before):refuse('firewall.rule_collision')
    try:
        if data['expand']:
            changed={s['comment'] for s in desired if ownership(rows,s)=='drift'};delete_comments(changed)
            for s in desired:
                if ownership(rows,s)!='exact':command('ufw',ufw_args(s))
        if data['prune']:delete_comments({s['comment'] for s in before if s['comment'] not in {d['comment'] for d in desired}})
        rows=active_rows()
        if data['expand'] and any(ownership(rows,s)!='exact' for s in desired):raise Uncertain()
        if data['prune'] and any(s['comment'] not in {d['comment'] for d in desired} for s in router_snapshot()):raise Uncertain()
    except Exception:
        try:
            observed=router_snapshot();comments={s['comment'] for s in before+observed}
            changed={c for c in comments if sorted(json.dumps(s,sort_keys=True) for s in before if s['comment']==c)!=sorted(json.dumps(s,sort_keys=True) for s in observed if s['comment']==c)}
            delete_comments(changed)
            for s in before:
                if s['comment'] in changed:command('ufw',ufw_args(s))
            if sorted(json.dumps(s,sort_keys=True) for s in router_snapshot())!=sorted(json.dumps(s,sort_keys=True) for s in before):raise Uncertain()
        except Exception:raise Uncertain()
        refuse('node.firewall_convergence_failed')
    return {'matches':True}
`;
