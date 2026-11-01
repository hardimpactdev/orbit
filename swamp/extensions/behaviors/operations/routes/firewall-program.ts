// Fixed local UFW system program. Public inputs are typed rule data.
export const FirewallProgram = String
  .raw`"""Typed UFW ownership and exact Route comment reconciliation, executed only by native Swamp."""
import ipaddress
import contextlib
import fcntl
import os
import stat
import json
import re
import subprocess
import sys
from pathlib import Path

class Refusal(Exception):
    def __init__(self, code): self.code = code

LOCK = Path('/run/lock/orbit-firewall.lock')
LOCK_UID = 0
@contextlib.contextmanager
def transaction_lock():
    try:
        parent = LOCK.parent.lstat()
        if not stat.S_ISDIR(parent.st_mode) or parent.st_uid != LOCK_UID:
            raise Refusal('firewall.lock_failed')
        descriptor = os.open(LOCK, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600)
    except OSError:
        raise Refusal('firewall.lock_failed')
    try:
        info = os.fstat(descriptor)
        if not stat.S_ISREG(info.st_mode) or info.st_uid != LOCK_UID or stat.S_IMODE(info.st_mode) != 0o600:
            raise Refusal('firewall.lock_failed')
        try: fcntl.flock(descriptor, fcntl.LOCK_EX)
        except OSError: raise Refusal('firewall.lock_failed')
        yield
    finally:
        os.close(descriptor)

def endpoint(value):
    if value in ('any', 'Anywhere', '0.0.0.0/0', '::/0'): return 'any'
    try:
        if '/' in value:
            network=ipaddress.ip_network(value, strict=False)
            return str(network.network_address) if network.prefixlen == network.max_prefixlen else str(network)
        return str(ipaddress.ip_address(value))
    except ValueError: return None

def port(value):
    if value == 'any': return value
    if not re.fullmatch(r'[0-9]{1,5}(?::[0-9]{1,5})?', value): return None
    numbers=list(map(int,value.split(':')))
    if any(n<1 or n>65535 for n in numbers) or len(numbers)==2 and numbers[0]>numbers[1]: return None
    return ':'.join(map(str,numbers))

def valid(shape):
    fields={'comment','action','direction','source','destination','port','protocol','in_interface','out_interface','family'}
    if set(shape) != fields: return False
    comment=shape['comment']
    return (isinstance(comment,str) and len(comment)<=256 and bool(comment) and all(33<=ord(c)<=126 and c!='#' for c in comment)
        and shape['action'] in ('allow','deny') and shape['direction'] in ('in','out','fwd') and shape['protocol'] in ('any','tcp','udp')
        and endpoint(shape['source'])==shape['source'] and endpoint(shape['destination'])==shape['destination'] and port(shape['port'])==shape['port']
        and shape['family'] in (None,'v4','v6')
        and all(value is None or isinstance(value,str) and re.fullmatch(r'[A-Za-z0-9_.:-]{1,64}',value) for value in (shape['in_interface'],shape['out_interface']))
        and (shape['direction']!='in' or shape['out_interface'] is None) and (shape['direction']!='out' or shape['in_interface'] is None)
        and all(value=='any' or shape['family']==('v6' if ':' in value else 'v4') for value in (shape['source'],shape['destination'])))

def normalized_shape(raw):
    shape=dict(raw)
    for key in ('in_interface','out_interface','family'): shape.setdefault(key,None)
    if not valid(shape): raise Refusal('firewall.input_invalid')
    return shape

def ownership(rows, expected):
    owned=[row for row in rows if row['comment']==expected['comment']]
    if not owned: return 'missing'
    families=set()
    for row in owned:
        shape=row['shape']
        if shape is None: return 'drift'
        match=dict(expected)
        if match['family'] is None: match['family']=shape['family']
        if match!=shape or shape['family'] in families: return 'drift'
        families.add(shape['family'])
    return 'exact' if families==({expected['family']} if expected['family'] else {'v4','v6'}) else 'drift'

def interface(value):
    if ' on ' in value:
        address,device=value.rsplit(' on ',1)
        if re.fullmatch(r'[A-Za-z0-9_.:-]{1,64}',device): return address.strip(),device
    return value,None

def live_row(line):
    line=line.strip();comment=line.rsplit('#',1)[1].strip() if '#' in line else ''
    row=dict(number=None,comment=comment,shape=None)
    match=re.fullmatch(r'(?:\[\s*(\d+)\]\s*)?(.+?)\s+(ALLOW|DENY)\s+(IN|OUT|FWD)\s+(.+?)(?:\s+#\s*(.*))?',line)
    if not match or (match[6] or '')!=comment: return row
    row['number']=int(match[1]) if match[1] and int(match[1])>0 else None
    family='v6' if '(v6)' in match[2] or '(v6)' in match[5] else 'v4'
    target, target_if=interface(match[2].replace('(v6)','').strip());source,source_if=interface(match[5].replace('(v6)','').strip())
    numbered=re.fullmatch(r'(?:(.+?)\s+)?(\d{1,5}(?::\d{1,5})?)(?:/(tcp|udp))?',target)
    destination=endpoint(numbered[1] or 'any') if numbered else endpoint(target)
    p=port(numbered[2]) if numbered else 'any';protocol=(numbered[3] or 'any') if numbered else 'any'
    source=endpoint(source)
    if destination is None or source is None or p is None: return row
    direction=match[4].lower()
    incoming,outgoing=(source_if,target_if) if direction=='fwd' else ((None,target_if or source_if) if direction=='out' else (target_if or source_if,None))
    row['shape']=dict(comment=comment,action=match[3].lower(),direction=direction,source=source,destination=destination,port=p,protocol=protocol,in_interface=incoming,out_interface=outgoing,family=family)
    return row

def live(text):
    statuses=[line.strip().lower()[8:] for line in text.splitlines() if line.strip().lower() in ('status: active','status: inactive','status: absent')]
    if len(statuses)!=1: raise Refusal('firewall.status_unrecognized')
    rows=[live_row(line) for line in text.splitlines()]
    return statuses[0],[row for row in rows if row['shape'] or row['comment']]

def stored():
    rows=[]
    for filename,family in [('/etc/ufw/user.rules','v4'),('/etc/ufw/user6.rules','v6')]:
        text=Path(filename).read_text()
        if len(text)>1048576: raise Refusal('firewall.status_failed')
        for line in text.splitlines():
            if ' comment=' not in line: continue
            body,encoded=line.strip().rsplit(' comment=',1)
            try: comment=bytes.fromhex(encoded).decode()
            except (ValueError,UnicodeError): continue
            row=dict(number=None,comment=comment,shape=None);rows.append(row)
            if not body.startswith('### tuple ### '): continue
            tokens=body[len('### tuple ### '):].split()
            if len(tokens)!=7 or tokens[4]!='any': continue
            action,protocol,p,destination,_,source,interfaces=tokens
            incoming=outgoing=None
            if action.startswith('route:'):
                action=action[6:];direction='fwd'
                if not interfaces.startswith('in_') or '!out_' not in interfaces: continue
                incoming,outgoing=interfaces[3:].split('!out_',1)
            else:
                direction,_,device=interfaces.partition('_')
                if direction=='in': incoming=device or None
                elif direction=='out': outgoing=device or None
                else: continue
            shape=dict(comment=comment,action=action,direction=direction,source=endpoint(source),destination=endpoint(destination),port=port(p),protocol=protocol,in_interface=incoming,out_interface=outgoing,family=family)
            if valid(shape): row['shape']=shape
    return rows

def ufw(args):
    try: reply=subprocess.run(['/usr/sbin/ufw',*args],stdin=subprocess.DEVNULL,stdout=subprocess.PIPE,stderr=subprocess.PIPE,timeout=110)
    except (OSError,subprocess.TimeoutExpired): raise Refusal('firewall.status_failed')
    if len(reply.stdout)>1048576 or len(reply.stderr)>65536: raise Refusal('firewall.status_failed')
    return reply.returncode,reply.stdout.decode()

def status():
    code,text=ufw(['status','numbered'])
    if code!=0: raise Refusal('firewall.status_failed')
    return live(text)
def change(args):
    if ufw(args)[0]!=0: raise Refusal('node.firewall_convergence_failed')
def active():
    backend,rows=status()
    if backend!='active': raise Refusal('node.firewall_convergence_failed')
    return rows

def arguments(shape):
    args=['route',shape['action']] if shape['direction']=='fwd' else [shape['action'],shape['direction']]
    for direction,key in [('in','in_interface'),('out','out_interface')]:
        if shape[key] is not None:
            if shape['direction']=='fwd': args.append(direction)
            args.extend(['on',shape[key]])
    if shape['protocol']!='any': args.extend(['proto',shape['protocol']])
    wildcard={'v4':'0.0.0.0/0','v6':'::/0'}.get(shape['family'],'any')
    args.extend(['from',wildcard if shape['source']=='any' else shape['source'],'to',wildcard if shape['destination']=='any' else shape['destination']])
    if shape['port']!='any': args.extend(['port',shape['port']])
    return [*args,'comment',shape['comment']]
def delete_comments(comments, rows=None):
    if not comments: return
    rows=active() if rows is None else rows
    selected=[row for row in rows if row['comment'] in comments]
    if any(row['number'] is None for row in selected): raise Refusal('firewall.rule_collision')
    for number in sorted({row['number'] for row in selected},reverse=True): change(['--force','delete',str(number)])

def rules(payload):
    desired=[dict(shape=normalized_shape(item['shape']),replace_port=item['replace_port']) for item in payload['desired']]
    remove=[normalized_shape(raw) for raw in payload['remove']]
    comments=set()
    for shape in [*(item['shape'] for item in desired),*remove]:
        comment=shape['comment']
        if not (comment.startswith('orbit:') or comment=='orbit-ci-runners') or comment in comments or len(comments)>=4096: raise Refusal('firewall.input_invalid')
        comments.add(comment)
    if payload['enable']:
        if len(desired)!=1 or remove: raise Refusal('firewall.input_invalid')
        shape=desired[0]['shape']
        if not (shape['comment']=='orbit:public-ssh-recovery' and shape['action']=='allow' and shape['direction']=='in' and shape['source']==shape['destination']=='any' and shape['protocol']=='tcp' and shape['port'].isdigit() and shape['in_interface'] is None and shape['out_interface'] is None and shape['family'] is None): raise Refusal('firewall.input_invalid')
    backend,rows=status()
    if backend!='active':
        if backend!='inactive' or not payload['enable']: raise Refusal('node.firewall_convergence_failed')
        recovery=desired[0]['shape'];observed=ownership(stored(),recovery)
        if observed=='drift': raise Refusal('node.firewall_convergence_failed')
        if observed=='missing': change(arguments(recovery))
        if ownership(stored(),recovery)!='exact': raise Refusal('node.firewall_convergence_failed')
        change(['--force','enable']);rows=active()
    replacing,missing=set(),[]
    for item in desired:
        shape=item['shape'];observed=ownership(rows,shape)
        if observed=='exact': continue
        if observed=='missing': missing.append(shape);continue
        prior=next((row['shape'] for row in rows if row['comment']==shape['comment']),None)
        previous=dict(shape)
        if prior: previous['port']=prior['port']
        if not (item['replace_port'] and shape['comment'].startswith('orbit:') and shape['port'].isdigit() and prior and prior['port'].isdigit() and ownership(rows,previous)=='exact'): raise Refusal('node.firewall_convergence_failed')
        replacing.add(shape['comment']);missing.append(shape)
    if any(ownership(rows,shape)=='drift' for shape in remove): raise Refusal('node.firewall_convergence_failed')
    delete_comments(replacing)
    for shape in missing: change(arguments(shape))
    rows=active()
    if any(ownership(rows,item['shape'])!='exact' for item in desired): raise Refusal('node.firewall_convergence_failed')
    delete_comments({shape['comment'] for shape in remove})
    if remove and any(ownership(active(),shape)!='missing' for shape in remove): raise Refusal('node.firewall_convergence_failed')
    return dict(matches=True)

def route(payload):
    route_id=payload['route_id']
    if not isinstance(route_id,int) or isinstance(route_id,bool) or route_id<1: raise Refusal('firewall.input_invalid')
    comment=f'orbit:route-{route_id}-lan';backend,rows=status()
    if backend=='absent': raise Refusal('firewall.status_failed')
    if payload['operation']=='remove':
        delete_comments({comment},rows)
        if any(row['comment']==comment for row in status()[1]): raise Refusal('firewall.remove_verify_failed')
    elif payload['operation']=='retire':
        keep=payload['keep']
        if not isinstance(keep,list) or len(keep)>256: raise Refusal('firewall.input_invalid')
        expected=[]
        for pair in keep:
            router,workload=str(ipaddress.IPv4Address(pair['router'])),str(ipaddress.IPv4Address(pair['workload']))
            expected.append(dict(comment=comment,action='allow',direction='in',source=router,destination=workload,port='443',protocol='tcp',in_interface=None,out_interface=None,family='v4'))
        if backend!='active': raise Refusal('firewall.status_failed')
        owned=[row for row in rows if row['comment']==comment]
        if any(row.get('shape') is None or row['number'] is None for row in owned): raise Refusal('firewall.rule_collision')
        if any(not any(row['shape']==shape for row in owned) for shape in expected): raise Refusal('firewall.verify_failed')
        obsolete=[row for row in owned if row['shape'] not in expected]
        for row in sorted(obsolete,key=lambda row:row['number'],reverse=True):
            change(['--force','delete',str(row['number'])])
        observed,after=status()
        remaining=[row for row in after if row['comment']==comment]
        if observed!='active' or any(row.get('shape') not in expected for row in remaining) or any(not any(row.get('shape')==shape for row in remaining) for shape in expected): raise Refusal('firewall.remove_verify_failed')
    elif payload['operation']=='converge':
        router,workload=str(ipaddress.IPv4Address(payload['router'])),str(ipaddress.IPv4Address(payload['workload']))
        expected=dict(comment=comment,action='allow',direction='in',source=router,destination=workload,port='443',protocol='tcp',in_interface=None,out_interface=None,family='v4')
        if backend=='inactive': rows=stored()
        if not any(row['shape']==expected for row in rows):
            change(arguments(expected));observed,rows=status()
            if observed=='inactive': rows=stored()
            if observed!=backend or not any(row['shape']==expected for row in rows): raise Refusal('firewall.verify_failed')
    else: raise Refusal('firewall.input_invalid')
    return dict(matches=True)
def main(payload):
    try:
        with transaction_lock():
            if payload['mode']=='rules': return rules(payload)
            if payload['mode']=='route': return route(payload)
            raise Refusal('firewall.input_invalid')
    except Refusal as error: return dict(error_code=error.code)
    except (ValueError,TypeError,KeyError,OSError,UnicodeError): return dict(error_code='firewall.input_invalid')
if __name__=='__main__': print(json.dumps(main(json.load(sys.stdin))))
`;
