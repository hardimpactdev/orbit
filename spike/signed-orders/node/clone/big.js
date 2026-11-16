// SPIKE(signed-orders): ~420 MB resident, part JS heap (many small objects), part buffers.
const keep = [];
for (let i = 0; i < 700000; i++) keep.push({ i, s: 'item-' + i, a: [i, i + 1, i + 2] });
for (let i = 0; i < 300; i++) keep.push(Buffer.alloc(1 << 20, i % 251));
setInterval(() => keep.length, 1e9);
