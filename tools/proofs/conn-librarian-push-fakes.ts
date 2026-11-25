// The two fakes of the Conn librarian push proof (conn-librarian-push.sh): a host Conn Server and ai-router.
// Everything else in the proof is the real Gateway: its HTTP route, its queue worker and its librarian.
const connPort = Number(process.argv[2]);
const routerPort = Number(process.argv[3]);
const projectId = Number(process.argv[4]);

type Thread = Record<string, unknown> & { id: string };
const threads = new Map<string, Thread>();
// Projection reads of a thread before its first user message is readable.
const hiddenReads = new Map<string, number>();
const log: string[] = [];
let modelCalls = 0;
let fileCommands = 0;

const firstMessage = (id: string) => ({ role: 'user', createdBy: 'user', createdAt: String(threads.get(id)?.createdAt), text: `Fix the Orbit Gateway thread index (${id}).` });

Bun.serve({
  port: connPort,
  hostname: '127.0.0.1',
  fetch(request, server) {
    const url = new URL(request.url);
    if (url.pathname === '/ws') {
      return server.upgrade(request) ? undefined : new Response('no upgrade', { status: 400 });
    }
    if (url.pathname === '/__thread' && request.method === 'POST') {
      return request.json().then((body: { id: string; hiddenReads?: number }) => {
        threads.set(body.id, { id: body.id, projectId: 'scratch-id', title: `Proof ${body.id}`, status: 'idle', lineage: { parentThreadId: null },
          createdAt: new Date().toISOString(), latestUserAuthoredMessageAt: new Date().toISOString() });
        hiddenReads.set(body.id, body.hiddenReads ?? 0);
        return Response.json({ ok: true });
      });
    }
    if (url.pathname === '/__stats') {
      return Response.json({ modelCalls, fileCommands, log });
    }
    if (url.pathname === '/health') {
      return Response.json({ server: 'conn-server', version: '0.5.3' });
    }
    if (url.pathname === '/api/orchestration/shell') {
      log.push('GET shell');
      return Response.json({ projects: [{ id: 'scratch-id', workspaceRoot: '/home/agent/scratch' }], threads: [...threads.values()] });
    }
    const match = url.pathname.match(/^\/api\/orchestration\/threads\/(.+)$/);
    if (match) {
      const id = decodeURIComponent(match[1]);
      const thread = threads.get(id);
      if (!thread) return Response.json({ error: 'not found' }, { status: 404 });
      const hidden = hiddenReads.get(id) ?? 0;
      hiddenReads.set(id, Math.max(0, hidden - 1));
      log.push(`GET thread ${id}: first message ${hidden > 0 ? 'not readable yet' : 'readable'}`);
      return Response.json({ snapshotSequence: 1, projection: { thread, messages: hidden > 0 ? [] : [firstMessage(id)] } });
    }
    return new Response('not found', { status: 404 });
  },
  websocket: {
    message(socket, raw) {
      const frame = JSON.parse(String(raw));
      let value: unknown = {};
      if (frame.tag === 'server.getConfig') {
        value = { scratchWorkspaceRoot: '/home/agent/scratch' };
      } else if (frame.tag === 'orchestration.dispatchCommand') {
        fileCommands++;
        const thread = threads.get(frame.payload.threadId);
        if (thread) thread.orbitProject = frame.payload.orbitProject;
        log.push(`WS file ${frame.payload.threadId} into Project ${frame.payload.orbitProject.id}`);
        value = { sequence: fileCommands };
      }
      socket.send(JSON.stringify({ _tag: 'Exit', requestId: frame.id, exit: { _tag: 'Success', value } }));
    },
  },
});

Bun.serve({
  port: routerPort,
  hostname: '127.0.0.1',
  async fetch(request) {
    const body = await request.json();
    modelCalls++;
    log.push(`POST ai-router /v1/messages model=${body.model}`);
    return Response.json({ model: body.model, content: [{ type: 'text', text: JSON.stringify({ result: 'existing', project_id: projectId, reason: 'Orbit Gateway work.' }) }] });
  },
});
