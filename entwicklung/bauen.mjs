// Baut die Dateien der beweglichen Karte nach public/interaktiv/.
//
//   cd entwicklung && npm ci && node bauen.mjs
//
// Alles, was die Karte im Browser braucht, liegt danach im Bundle und wird von der eigenen
// Website ausgeliefert: MapLibre GL (Darstellung), pmtiles (liest die Kartendatei per
// Range-Abruf), je Kartenstil eine Stildatei, die Schriften für die Beschriftungen und die
// Symbole. Beim Seitenaufruf wird kein fremder Server angefragt.
//
// Quellen: maplibre-gl, pmtiles, @protomaps/basemaps (npm, Versionen in package.json fest);
// Schriften und Symbole aus protomaps/basemaps-assets (github.io), einmalig beim Bauen geladen.

import { mkdir, writeFile, copyFile, readFile, rm } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { layers, namedFlavor } from '@protomaps/basemaps';

const ZIEL = new URL('../public/interaktiv/', import.meta.url);
const ASSETS = 'https://protomaps.github.io/basemaps-assets';

// Schriften: nur die Bereiche für lateinische Schrift samt Satzzeichen — deutsche Karten
// beschriften damit alles. Andere Bereiche fragt die Karte nur bei exotischen Namen an.
const SCHRIFTEN = ['Noto Sans Regular', 'Noto Sans Medium', 'Noto Sans Italic'];
const BEREICHE = [];
for (let s = 0; s < 8960; s += 256) BEREICHE.push(`${s}-${s + 255}`);

// Kartenstile des Bundles → Grundlage bei Protomaps, dazu eigene Farben.
const hell = namedFlavor('light');
const dunkel = namedFlavor('dark');
const STILE = {
    standard: { flavor: hell, sprite: 'light' },
    freundlich: {
        // angelehnt an die hellen, klaren Farben von Google Maps (wie das Kartenbild)
        flavor: {
            ...hell,
            background: '#f5f3ef', earth: '#f5f3ef', buildings: '#e9e6e1',
            water: '#aadaff', ocean_label: '#5b8fc7',
            park_a: '#d4ecd0', park_b: '#c3e5bd', wood_a: '#d4ecd0', wood_b: '#c3e5bd', scrub_a: '#dcefd7', scrub_b: '#cde8c7',
            industrial: '#ecebe8', school: '#efece6', hospital: '#f3e6e6', pedestrian: '#f1efea',
            highway: '#fde293', highway_casing_early: '#f1c96b', highway_casing_late: '#f1c96b',
            bridges_highway: '#fde293', bridges_highway_casing: '#f1c96b', tunnel_highway: '#fbe7b1',
            major: '#ffffff', major_casing_early: '#dadce0', major_casing_late: '#dadce0',
            minor_a: '#ffffff', minor_b: '#ffffff', minor_casing: '#e3e3e3', link: '#ffffff', link_casing: '#dadce0',
            other: '#ffffff', minor_service: '#ffffff', minor_service_casing: '#e3e3e3',
            railway: '#b5b5b5', boundaries: '#a9a9b8',
        },
        sprite: 'light',
    },
    gedaempft: { flavor: namedFlavor('white'), sprite: 'white' },
    hell: { flavor: namedFlavor('white'), sprite: 'white' },
    graustufen: { flavor: namedFlavor('grayscale'), sprite: 'grayscale' },
    retro: {
        // warme Papiertöne, blaugrünes Wasser (wie das Kartenbild „Retro“)
        flavor: {
            ...hell,
            background: '#ebe3cd', earth: '#ebe3cd', buildings: '#dfd2ae',
            water: '#b9d3c2', ocean_label: '#6f9ba5',
            park_a: '#d6dbb0', park_b: '#c5cf9c', wood_a: '#d6dbb0', wood_b: '#c5cf9c', scrub_a: '#dde0b9', scrub_b: '#cdd3a6',
            industrial: '#e3d9c0', school: '#e8dcc0', hospital: '#ead8c6', pedestrian: '#efe6cf',
            highway: '#f8c967', highway_casing_early: '#e9bc62', highway_casing_late: '#e9bc62',
            bridges_highway: '#f8c967', bridges_highway_casing: '#e9bc62',
            major: '#fdfcf8', minor_a: '#fdfcf8', minor_b: '#fdfcf8', link: '#fdfcf8', other: '#fdfcf8', minor_service: '#fdfcf8',
            railway: '#a99c80', boundaries: '#c9b2a6',
        },
        sprite: 'light',
    },
    dunkel: { flavor: dunkel, sprite: 'dark' },
    nacht: {
        flavor: {
            ...dunkel,
            background: '#1d2c4d', earth: '#1d2c4d', buildings: '#25365c',
            water: '#0e1626', park_a: '#1f3b3a', park_b: '#21423f', wood_a: '#1f3b3a', wood_b: '#21423f',
            scrub_a: '#203a40', scrub_b: '#223f44', industrial: '#22325a', school: '#24345a', hospital: '#2a3358', pedestrian: '#243560',
            highway: '#2c6675', highway_casing_early: '#255763', highway_casing_late: '#255763', bridges_highway: '#2c6675',
            major: '#304a7d', minor_a: '#2a4170', minor_b: '#2a4170', link: '#304a7d', other: '#2a4170', minor_service: '#2a4170',
            railway: '#3a4762', boundaries: '#4b6878',
        },
        sprite: 'dark',
    },
};

async function laden(url) {
    const antwort = await fetch(url);
    if (!antwort.ok) throw new Error(`${antwort.status} ${url}`);
    return Buffer.from(await antwort.arrayBuffer());
}

await rm(ZIEL, { recursive: true, force: true });
await mkdir(new URL('stile/', ZIEL), { recursive: true });

// 1. Bibliotheken
const mapl = new URL('./node_modules/maplibre-gl/dist/', import.meta.url);
for (const datei of ['maplibre-gl.mjs', 'maplibre-gl-worker.mjs', 'maplibre-gl.css']) {
    await copyFile(new URL(datei, mapl), new URL(datei, ZIEL));
}
// pmtiles kommt als Skript mit einer globalen Variable; als Modul angeboten, damit die
// Karte alles per import() nachladen kann.
const pm = await readFile(new URL('./node_modules/pmtiles/dist/pmtiles.js', import.meta.url), 'utf8');
await writeFile(new URL('pmtiles.mjs', ZIEL), `${pm}\nexport default pmtiles;\n`);

// 2. Stile: Platzhalter für Kartendatei, Schriften und Symbole setzt die Seite beim Laden ein.
for (const [name, { flavor, sprite }] of Object.entries(STILE)) {
    const stil = {
        version: 8,
        glyphs: '{GLYPHS}/{fontstack}/{range}.pbf',
        sprite: `{SPRITES}/${sprite}`,
        sources: {
            protomaps: {
                type: 'vector',
                url: '{PMTILES}',
                attribution: '<a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">© OpenStreetMap</a> · <a href="https://protomaps.com" target="_blank" rel="noopener">Protomaps</a>',
            },
        },
        layers: layers('protomaps', flavor, { lang: 'de' }),
    };
    await writeFile(new URL(`stile/${name}.json`, ZIEL), JSON.stringify(stil));
}

// 3. Schriften
let anzahl = 0;
for (const schrift of SCHRIFTEN) {
    const ordner = new URL(`schriften/${schrift}/`, ZIEL);
    await mkdir(ordner, { recursive: true });
    for (const bereich of BEREICHE) {
        await writeFile(new URL(`${bereich}.pbf`, ordner), await laden(`${ASSETS}/fonts/${encodeURIComponent(schrift)}/${bereich}.pbf`));
        anzahl++;
    }
}
await writeFile(new URL('schriften/LIZENZ.txt', ZIEL), 'Noto Sans — SIL Open Font License 1.1 (https://openfontlicense.org). Glyphen aus github.com/protomaps/basemaps-assets.\n');

// 4. Symbole (Sprites) in einfacher und doppelter Auflösung
await mkdir(new URL('symbole/', ZIEL), { recursive: true });
for (const sprite of new Set(Object.values(STILE).map((s) => s.sprite))) {
    for (const endung of ['.json', '.png', '@2x.json', '@2x.png']) {
        await writeFile(new URL(`symbole/${sprite}${endung}`, ZIEL), await laden(`${ASSETS}/sprites/v4/${sprite}${endung}`));
    }
}

const pkg = JSON.parse(await readFile(new URL('package.json', import.meta.url), 'utf8'));
await writeFile(new URL('VERSIONEN.txt', ZIEL), Object.entries(pkg.dependencies).map(([n, v]) => `${n} ${v}`).join('\n') + '\n');
console.log(`fertig: ${Object.keys(STILE).length} Stile, ${anzahl} Schriftdateien, Symbole: ${existsSync(new URL('symbole/light.png', ZIEL))}`);
