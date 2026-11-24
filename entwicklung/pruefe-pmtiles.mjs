// Gegenprobe: liest eine ausgeschnittene Datei mit der offiziellen Bibliothek
import { PMTiles, FileSource } from 'pmtiles';
import { open } from 'node:fs/promises';
class Datei { constructor(p){this.p=p;} getKey(){return this.p;} async getBytes(o,l){const f=await open(this.p);const b=Buffer.alloc(l);await f.read(b,0,l,o);await f.close();return {data:b.buffer.slice(b.byteOffset,b.byteOffset+l)};} }
const p = new PMTiles(new Datei(process.argv[2]));
const h = await p.getHeader(); console.log(JSON.stringify(h));
const m = await p.getMetadata(); console.log('Metadaten:', Object.keys(m), (m.vector_layers||[]).map(l=>l.id).join(','));
const proben = JSON.parse(process.argv[3]);
for (const [z,x,y] of proben) { const t = await p.getZxy(z,x,y); console.log(z,x,y, t ? t.data.byteLength+' B' : 'FEHLT'); }
