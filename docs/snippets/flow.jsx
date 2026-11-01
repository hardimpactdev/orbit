// Renders one docs/flows/<kind>.mmd chart with its step-detail panel.
// renderFlow is the migration dashboard's renderer, unchanged; the panel ports its FlowPage.
// Mintlify snippets cannot import each other, so pages pass the chart source:
//   <Flow kind="firewall.allow" src={flows['firewall.allow']} />
export const Flow = ({ kind, src }) => {
  // Renders a Mermaid sequenceDiagram subset as a static lane chart in the pr-lens style.
  // Lane options are Mermaid comments, so the source stays valid Mermaid:
  //   %% lane GW icon=gateway pill=intent tone=blue
  const renderFlow=(src, el)=>{
    const esc=s=>String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    const lanes=[],rows=[],frames=[],stack=[],meta={};let numbered=false;
    for(const raw of src.split('\n')){
      const l=raw.trim(); let m;
      if(m=l.match(/^%%\s*lane\s+(\w+)\s+(.*)$/)){meta[m[1]]=Object.fromEntries(m[2].split(/\s+/).map(kv=>kv.split('=')));continue}
      if(l==='autonumber'){numbered=true;continue}
      // `%%> text` lines explain the step above them; Mermaid treats them as comments.
      if(m=l.match(/^%%>\s?(.*)$/)){if(rows.length){const r=rows.at(-1);r.info=(r.info?r.info+'\n':'')+m[1]}continue}
      if(!l||l.startsWith('%%')||l==='sequenceDiagram')continue;
      if(m=l.match(/^participant\s+(\w+)(?:\s+as\s+(.+))?$/)){const[t,s]=(m[2]||m[1]).split('<br/>');lanes.push({id:m[1],title:t,sub:s||''});continue}
      if(m=l.match(/^(alt|loop|opt)\s*(.*)$/)){stack.push({type:m[1],label:m[2],start:rows.length,sections:[],depth:stack.length});continue}
      if(m=l.match(/^else\s*(.*)$/)){stack.at(-1).sections.push({at:rows.length,label:m[1]});continue}
      if(l==='end'){const f=stack.pop();f.end=rows.length;frames.push(f);continue}
      if(m=l.match(/^Note\s+over\s+([\w,\s]+):\s*(.+)$/)){rows.push({kind:'note',lanes:m[1].split(',').map(s=>s.trim()),text:m[2]});continue}
      if(m=l.match(/^(\w+)\s*(-->>|->>|--x|-x)\s*(\w+)\s*:\s*(.+)$/)){rows.push({kind:'msg',from:m[1],to:m[3],arrow:m[2],text:m[4]});continue}
    }
    lanes.forEach(ln=>Object.assign(ln,{tone:'gray'},meta[ln.id]||{}));
    const W=Math.max(900,lanes.length*220),P=14,LW=(W-2*P)/lanes.length,idx=id=>lanes.findIndex(x=>x.id===id);
    const cx=i=>P+i*LW+LW/2,tone=i=>`var(--${lanes[i].tone})`;
    const tw=(s,px,mono)=>s.length*px*(mono?0.6:0.56);
    // vertical layout
    let y=112;const starts=[...frames].sort((a,b)=>a.depth-b.depth);
    const pass=i=>{
      for(const f of frames)if(f.end===i){y+=4;f.y2=y;y+=14}
      for(const f of frames)for(const s of f.sections)if(s.at===i){s.y=y+2;y+=30}
      for(const f of starts)if(f.start===i){f.y1=y;y+=32}
    };
    rows.forEach((r,i)=>{pass(i);[r.main,r.detail]=r.text.split('<br/>');r.h=r.kind==='msg'?(r.detail?52:40):(r.detail?54:42);r.y=y;y+=r.h});
    pass(rows.length);const H=y+20;
    const out=[`<rect class="canvas" width="${W}" height="${H}" rx="14"/><rect width="${W}" height="${H}" rx="14" fill="url(#dots)"/>`];
    // lanes, lifelines, cards
    lanes.forEach((ln,i)=>{const x=P+i*LW,c=tone(i),cw=LW-40,cxr=x+20+cw;
      out.push(`<rect class="lane" x="${x+10}" y="12" width="${LW-20}" height="${H-24}" rx="20"/>`,
        `<line class="life" x1="${cx(i)}" y1="84" x2="${cx(i)}" y2="${H-22}"/>`,
        `<rect class="card" x="${x+20}" y="28" width="${cw}" height="56" rx="10"/>`,
        `<rect class="chip" x="${x+30}" y="42" width="28" height="28" rx="7"/>`,icon(ln.icon,x+44,56,ln.title),
        `<text class="ttl" x="${x+68}" y="52">${esc(ln.title)}</text>`,
        `<text class="sub" x="${x+68}" y="69">${esc(ln.sub)}</text>`);
      if(ln.pill){const t=ln.pill.toUpperCase(),w=tw(t,9,false)+t.length*0.6+16;
        out.push(`<rect x="${cxr-12-w}" y="19" width="${w}" height="18" rx="9" style="fill:color-mix(in srgb,${c} 16%,var(--card));stroke:color-mix(in srgb,${c} 50%,transparent)"/>`,
          `<text class="pill" x="${cxr-12-w/2}" y="31.5" text-anchor="middle" style="fill:${c}">${t}</text>`)}});
    // activation bars: a call stays open on the receiver until its reply
    const open=[];
    rows.forEach(r=>{if(r.kind!=='msg')return;const ay=r.y+18;
      if(!r.arrow.startsWith('--'))open.push({from:r.from,to:r.to,y:ay});
      else{const k=open.findLastIndex(o=>o.from===r.to&&o.to===r.from);if(k>=0){const o=open.splice(k,1)[0],i=idx(o.to);
        out.push(`<rect class="act" x="${cx(i)-5}" y="${o.y-8}" width="10" height="${ay-o.y+16}" rx="3" style="fill:color-mix(in srgb,${tone(i)} 22%,var(--card));stroke:color-mix(in srgb,${tone(i)} 60%,transparent)"/>`)}}});
    // frames
    for(const f of frames){
      const inside=rows.slice(f.start,f.end).flatMap(r=>r.kind==='msg'?[idx(r.from),idx(r.to)]:r.lanes.map(idx));
      const a=Math.min(...inside),b=Math.max(...inside),x1=P+a*LW+10,x2=P+(b+1)*LW-10;
      out.push(`<rect class="frame" x="${x1}" y="${f.y1}" width="${x2-x1}" height="${f.y2-f.y1}" rx="10"/>`);
      const tag=(yy,t,lbl)=>{const w=tw(t,9.5)+t.length*1;out.push(`<text class="ftag" x="${x1+12}" y="${yy+19}">${t}</text><text class="flbl" x="${x1+20+w}" y="${yy+19}">${esc(lbl)}</text>`)};
      tag(f.y1,f.type.toUpperCase(),f.label);
      for(const s of f.sections){out.push(`<line class="frame" x1="${x1}" y1="${s.y}" x2="${x2}" y2="${s.y}"/>`);tag(s.y,'ELSE',s.label)}
    }
    // rows
    rows.forEach((r,i)=>{const n=numbered?`${i+1}`:'';out.push(`<g class="step" data-step="${i}">`);
      if(r.kind==='msg'){const a=idx(r.from),b=idx(r.to),dir=Math.sign(b-a)||1,fail=r.arrow.endsWith('x'),ret=r.arrow.startsWith('--');
        const c=fail?'var(--red)':tone(a),ay=r.y+18,x1=cx(a)+dir*6,x2=cx(b)-dir*6,mid=(x1+x2)/2;
        out.push(`<line class="edge${ret?' ret':''}" x1="${x1}" y1="${ay}" x2="${x2-dir*(fail?0:8)}" y2="${ay}" style="stroke:${c}"/>`);
        if(!ret)out.push(`<circle cx="${x1}" cy="${ay}" r="2.6" style="fill:${c}"/>`);
        out.push(fail?`<path class="x" d="M${x2-4.5},${ay-4.5} l9,9 m0,-9 l-9,9" style="stroke:${c}"/>`
                     :`<path d="M${x2-dir*9},${ay-5} L${x2},${ay} L${x2-dir*9},${ay+5} z" style="fill:${c}"/>`);
        out.push(pill(mid,ay,r.main,n,c));
        if(r.detail)out.push(`<text class="mono" x="${mid}" y="${ay+24}" text-anchor="middle">${esc(r.detail)}</text>`);
      }else{const ls=r.lanes.map(idx),c=tone(ls[0]),c0=(cx(Math.min(...ls))+cx(Math.max(...ls)))/2;
        const w=Math.max(tw(r.main,12),r.detail?tw(r.detail,10.5,true):0)+28,bx=Math.max(P+6,Math.min(W-P-6-w,c0-w/2)),by=r.y+5,bh=r.h-10;
        out.push(`<rect class="note" x="${bx}" y="${by}" width="${w}" height="${bh}" rx="9"/>`,
          `<text class="lbl" x="${bx+w/2}" y="${by+(r.detail?19:bh/2+4)}" text-anchor="middle">${esc(r.main)}</text>`);
        if(n)out.push(badge(bx,by+bh/2,n,c));
        if(r.detail)out.push(`<text class="mono" x="${bx+w/2}" y="${by+35}" text-anchor="middle">${esc(r.detail)}</text>`);
      }
      out.push('</g>')});
    const steps=rows.map((r,i)=>({index:i,number:numbered?i+1:null,kind:r.kind,main:r.main,detail:r.detail??null,info:r.info??null,from:r.from??null,to:r.to??null,lanes:r.lanes??null}));
    el.innerHTML=`<svg viewBox="0 0 ${W} ${H}" role="img"><defs><pattern id="dots" width="18" height="18" patternUnits="userSpaceOnUse"><circle cx="1.5" cy="1.5" r="1" class="dot"/></pattern></defs>${out.join('')}</svg>`;
    return steps;

    function pill(x,y,text,n,c){const w=tw(text,11)+(n?24:18),tx=x+(n?3:0);
      return `<rect x="${x-w/2}" y="${y-10}" width="${w}" height="20" rx="10" class="epill" style="stroke:color-mix(in srgb,${c} 55%,transparent)"/>`+
        `<text class="elbl" x="${tx}" y="${y+3.8}" text-anchor="middle" style="fill:${c}">${esc(text)}</text>`+(n?badge(x-w/2,y,n,c):'')}
    // Small numbered circle on a block's left edge, centered vertically.
    function badge(x,y,n,c){
      return `<circle cx="${x}" cy="${y}" r="6.5" style="fill:color-mix(in srgb,${c} 14%,var(--card));stroke:color-mix(in srgb,${c} 60%,transparent)"/>`+
        `<text class="num" x="${x}" y="${y+2.8}" text-anchor="middle" style="fill:${c}">${n}</text>`}
    function icon(name,x,y,title){
      const p={
        terminal:`M${x-6},${y-4} l4,4 l-4,4 M${x},${y+4} h6`,
        gateway:`M${x},${y-7} l6,2.5 v4 c0,4 -3,6.5 -6,7.5 c-3,-1 -6,-3.5 -6,-7.5 v-4 z`,
        workflow:`M${x-3},${y-4} h6 M${x-5},${y-2} l3.6,5.2 M${x+5},${y-2} l-3.6,5.2`,
        server:`M${x-7},${y-7} h14 v6 h-14 z M${x-7},${y+1} h14 v6 h-14 z`,
      }[name];
      if(!p)return `<text class="glyph-t" x="${x}" y="${y+4.5}" text-anchor="middle">${esc(title[0])}</text>`;
      const dots=name==='workflow'?[[x-5,y-4],[x+5,y-4],[x,y+5]].map(([a,b])=>`<circle cx="${a}" cy="${b}" r="2.2" class="glyph"/>`).join('')
                :name==='server'?`<circle cx="${x+4}" cy="${y-4}" r=".9" class="glyph-f"/><circle cx="${x+4}" cy="${y+4}" r=".9" class="glyph-f"/>`:'';
      return `<path d="${p}" class="glyph"/>${dots}`;
    }
  }

  // Renders `code` spans and "- " list lines in a step explanation.
  const Info = ({ text }) => {
    const inline = (line) => line.split(/(`[^`]+`)/).map((part, i) => (part.startsWith('`') ? <code key={i}>{part.slice(1, -1)}</code> : part))
    const blocks = []
    let list = []
    const flush = () => { if (list.length) blocks.push(<ul key={blocks.length}>{list.map((l, i) => <li key={i}>{inline(l)}</li>)}</ul>); list = [] }
    for (const line of text.split('\n')) {
      if (line.startsWith('- ')) { list.push(line.slice(2)); continue }
      flush()
      if (/^[^\s`][^:`]{0,40}:$/.test(line.trim())) blocks.push(<div key={blocks.length} className="info-head">{line.trim().slice(0, -1)}</div>)
      else if (line.trim()) blocks.push(<p key={blocks.length}>{inline(line)}</p>)
    }
    flush()
    return <>{blocks}</>
  }

  const host = useRef(null)
  const [steps, setSteps] = useState([])
  const [selected, setSelected] = useState(null)
  useEffect(() => { if (src && host.current) { setSteps(renderFlow(src, host.current)); setSelected(null) } }, [src])
  // Mark the selected step in the chart.
  useEffect(() => {
    host.current?.querySelectorAll('.step').forEach((g) => g.classList.toggle('selected', Number(g.dataset.step) === selected))
  }, [selected, steps])
  if (!src) return <p>No chart for <code>{kind}</code> yet.</p>
  const lanes = Object.fromEntries([...src.matchAll(/participant\s+(\w+)\s+as\s+([^<\n]+)/g)].map((m) => [m[1], m[2].trim()]))
  const status = /%%\s*status:\s*reviewed/.test(src) ? 'reviewed' : 'draft'
  const step = selected === null ? null : steps[selected]
  const who = step ? (step.kind === 'msg' ? `${lanes[step.from] ?? step.from} → ${lanes[step.to] ?? step.to}` : (step.lanes ?? []).map((l) => lanes[l] ?? l).join(', ')) : ''
  const move = (d) => setSelected((s) => (s === null ? 0 : Math.max(0, Math.min(steps.length - 1, s + d))))
  const onClick = (e) => { const g = e.target.closest('.step'); setSelected(g ? Number(g.dataset.step) : null) }
  const onKey = (e) => {
    if (e.key === 'ArrowDown' || e.key === 'ArrowRight') { e.preventDefault(); move(1) }
    if (e.key === 'ArrowUp' || e.key === 'ArrowLeft') { e.preventDefault(); move(-1) }
    if (e.key === 'Escape') setSelected(null)
  }
  return (
    <div className="flow-doc not-prose" tabIndex={0} onKeyDown={onKey}>
      <div className="flow-head">
        <strong>Flow</strong> <code>{kind}</code> <span className={`flow-status ${status}`}>{status}</span>
        <span className="flow-hint">Click a step for details · ↑ ↓ move</span>
      </div>
      <div className="flow flow-inline" ref={host} onClick={onClick} />
      <div className="flow-panel">
        {step ? (
          <>
            <div className="flow-panel-num">Step {step.number ?? step.index + 1} of {steps.length} · {who}</div>
            <div className="flow-panel-title">{step.main}</div>
            {step.detail && <div className="flow-panel-detail">{step.detail}</div>}
            {step.info ? <div className="flow-panel-info"><Info text={step.info} /></div> : <p className="flow-muted">No explanation written for this step yet.</p>}
          </>
        ) : (
          <p className="flow-muted">Click a step in the chart to see what it does and why. Use the arrow keys to move through the steps.</p>
        )}
      </div>
    </div>
  )
}
