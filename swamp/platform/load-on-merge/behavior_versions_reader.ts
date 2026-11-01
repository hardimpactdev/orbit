// Metadata only: reuse one authenticated connection instead of one handshake per type.
const [tokenFile, namesJson] = Deno.args;
const token = (await Deno.readTextFile(tokenFile)).trim();
const names: string[] = JSON.parse(namesJson);
const socket = new WebSocket('ws://127.0.0.1:9418', {
  headers: { Authorization: `Bearer ${token}` },
});
await new Promise<void>((resolve, reject) => {
  socket.addEventListener('open', () => resolve(), { once: true });
  socket.addEventListener('error', () => reject(new Error('metadata_connect_failed')), { once: true });
});

async function describe(name: string): Promise<string> {
  const id = crypto.randomUUID();
  return await new Promise((resolve, reject) => {
    const cleanup = () => {
      clearTimeout(timer);
      socket.removeEventListener('message', onMessage);
      socket.removeEventListener('close', onClose);
    };
    const onClose = () => {
      cleanup();
      reject(new Error('metadata_connection_closed'));
    };
    const onMessage = (event: MessageEvent) => {
      const response = JSON.parse(event.data);
      if (response.id !== id) return;
      cleanup();
      if (response.type === 'error') {
        reject(new Error(response.payload?.code ?? 'metadata_read_failed'));
      } else {
        resolve(name === '__registry__' ? JSON.stringify(response.payload?.data?.results?.map((x: { normalized: string }) => x.normalized) ?? []) : response.payload?.data?.version);
      }
    };
    const timer = setTimeout(() => {
      cleanup();
      reject(new Error('metadata_read_timeout'));
    }, 30000);
    socket.addEventListener('message', onMessage);
    socket.addEventListener('close', onClose, { once: true });
    socket.send(JSON.stringify({ type: name === '__registry__' ? 'model.type.search' : 'model.type.describe', id, payload: name === '__registry__' ? {} : { typeArg: name } }));
  });
}

const results = [];
for (const name of names) {
  try {
    results.push({ type: name, version: await describe(name) });
  } catch (error) {
    results.push({ type: name, error: error instanceof Error ? error.message : 'metadata_read_failed' });
  }
}
socket.close();
console.log(JSON.stringify(results));
