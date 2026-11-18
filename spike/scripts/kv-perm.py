import re
p='/etc/nats/nats.conf'; s=open(p).read()
for n in (1,3,4):
    add='"$JS.API.DIRECT.GET.KV_CONTENT.$KV.CONTENT.n%d.>"' % n
    if add not in s:
        s=s.replace('"orbit.receipts.%d"]' % n, '"orbit.receipts.%d", %s]' % (n, add))
open(p,'w').write(s)
print(s.count('DIRECT.GET.KV_CONTENT'), 'content grants')
