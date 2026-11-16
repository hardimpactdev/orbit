// SPIKE(signed-orders): a small helper process (~60 MB resident).
const keep = [];
for (let i = 0; i < 16; i++) keep.push(Buffer.alloc(1 << 20, i));
setInterval(() => keep.length, 1e9);
