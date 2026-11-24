/*
 * Bewegliche Karte für das Karten-Element (contao-osm-map-bundle).
 *
 * Das Kartenbild steht zuerst da. Kommt die Karte in die Nähe des sichtbaren Bereichs, lädt
 * dieses Skript MapLibre GL und legt darüber eine Karte zum Verschieben und Zoomen. Alles
 * kommt von der eigenen Website: Bibliothek, Stil, Schriften und Symbole aus dem Bundle,
 * die Kartendaten aus einer PMTiles-Datei im Dateimanager (davon nur die Teile, die gerade
 * zu sehen sind). Ohne JavaScript, beim Drucken, im Datensparmodus oder bei einem Fehler
 * bleibt es beim Kartenbild.
 *
 * Orte auf der Seite verknüpfen: Ein beliebiges Element mit der CSS-Klasse
 * „osm-ort-<id>-<n>“ wird anklickbar — <id> ist die CSS-ID des Karten-Elements, <n> die
 * Nummer der Markierung, gezählt wie im Balken ab 1 ({osm-2} = zweite Zeile). Die Klasse
 * lässt sich im Backend bei jedem Inhaltselement eintragen (Experten-Einstellungen →
 * CSS-ID/Klasse); im eigenen HTML geht auch data-osm-ort="<id>:<n>". Ein Klick darauf zeigt
 * den Ort auf der Karte; ein Klick auf eine Markierung gibt dem Element die Klasse
 * osm-ort--aktiv. Zusätzlich meldet die Karte jede Auswahl mit dem Ereignis „osm-map:ort“
 * (detail: karte, nummer ab 1, name).
 */

const geladen = {};
const NEUER_TAB = '<svg class="osm-map__extern" viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" focusable="false"><path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>';

function bibliothek(basis) {
    if (!geladen[basis]) {
        const css = document.createElement('link');
        css.rel = 'stylesheet';
        css.href = `${basis}/maplibre-gl.css`;
        document.head.appendChild(css);

        geladen[basis] = Promise.all([import(`${basis}/maplibre-gl.mjs`), import(`${basis}/pmtiles.mjs`)]).then(([maplibre, pm]) => {
            const ml = maplibre.default ?? maplibre;
            const pmtiles = pm.default ?? pm;
            ml.addProtocol('pmtiles', new pmtiles.Protocol().tile);

            return ml;
        });
    }

    return geladen[basis];
}

/** Pin wie im Kartenbild: Kopf mit Loch, Spitze auf dem Ort. Loch hell auf dunkler Farbe. */
function pin(farbe, name) {
    const knopf = document.createElement('button');
    knopf.type = 'button';
    knopf.className = 'osm-map__pin';
    knopf.setAttribute('aria-label', name);
    knopf.title = name;
    const r = parseInt(farbe.slice(1, 3), 16), g = parseInt(farbe.slice(3, 5), 16), b = parseInt(farbe.slice(5, 7), 16);
    const loch = (0.299 * r + 0.587 * g + 0.114 * b) > 170 ? '#3c3c3c' : '#ffffff';
    knopf.innerHTML = `<svg viewBox="0 0 32 42" width="32" height="42" aria-hidden="true" focusable="false"><path d="M16 1C7.7 1 1 7.6 1 15.8 1 26.9 16 41 16 41s15-14.1 15-25.2C31 7.6 24.3 1 16 1z" fill="${farbe}" stroke="rgba(0,0,0,.25)" stroke-width="1"/><circle cx="16" cy="15.5" r="5.5" fill="${loch}"/></svg>`;

    return knopf;
}

function sichtbar(el) {
    const r = el.getBoundingClientRect();

    return r.top >= 0 && r.bottom <= window.innerHeight;
}

function einrichten(wurzel) {
    let cfg;

    try {
        cfg = JSON.parse(wurzel.dataset.osmInteraktiv);
    } catch {
        return;
    }

    const schluessel = wurzel.parentElement?.closest('[id]')?.id || '';
    // Verknüpfte Elemente: Klasse osm-ort-<id>-<n> oder data-osm-ort="<id>:<n>" (n ab 1)
    const eintraege = [];

    if (schluessel) {
        const muster = new RegExp(`(?:^|\\s)osm-ort-${schluessel.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}-(\\d+)(?:\\s|$)`);
        document.querySelectorAll('[data-osm-ort], [class*="osm-ort-"]').forEach((el) => {
            const daten = (el.dataset.osmOrt || '').split(':');
            const treffer = daten.length === 2 && daten[0] === schluessel ? daten[1] : (el.getAttribute('class') || '').match(muster)?.[1];

            if (treffer && Number(treffer) >= 1 && Number(treffer) <= cfg.orte.length) {
                el.classList.add('osm-ort--verknuepft');
                eintraege.push({ el, i: Number(treffer) - 1 });
            }
        });
    }
    const bewegung = !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    let karte = null;
    let start = null;
    const marker = [];
    let popup = null;

    function markieren(i) {
        eintraege.forEach((e) => e.el.classList.toggle('osm-ort--aktiv', e.i === i));
    }

    function oeffnen(i, fliegen) {
        const ort = cfg.orte[i];

        if (!ort || !karte) {
            return;
        }

        if (fliegen) {
            const ziel = { center: [ort.lon, ort.lat], zoom: Math.max(karte.getZoom(), Math.min(16, cfg.maxZoom)) };
            // Ruhiger Flug: MapLibre zoomt auf dem Weg hinaus und wieder hinein; mit 3 s bleibt
            // das gut zu verfolgen. Feste Dauer statt speed/maxDuration: Wird maxDuration
            // überschritten, springt MapLibre ohne Flug ans Ziel (gemessen bei weiten Strecken).
            bewegung ? karte.flyTo({ ...ziel, duration: 3000, curve: 1.42 }) : karte.jumpTo(ziel);
        }

        const inhalt = document.createElement('div');
        inhalt.className = 'osm-map__blase';
        const titel = document.createElement('strong');
        titel.textContent = ort.name;
        inhalt.appendChild(titel);

        if (ort.link && cfg.linkText) {
            const a = document.createElement('a');
            a.href = ort.link;
            a.target = '_blank';
            a.rel = 'noopener';
            a.className = 'osm-map__link';
            a.innerHTML = `<span></span>${NEUER_TAB}<span class="osm-map__unsichtbar"></span>`;
            a.firstChild.textContent = cfg.linkText;
            a.lastChild.textContent = ` ${cfg.texte.neuerTab || ''}`;
            inhalt.appendChild(a);
        }

        popup?.remove();
        popup = new start.Popup({ offset: [0, -40], maxWidth: '300px', focusAfterOpen: false })
            .setLngLat([ort.lon, ort.lat])
            .setDOMContent(inhalt)
            .addTo(karte);
        markieren(i);
        wurzel.dispatchEvent(new CustomEvent('osm-map:ort', { bubbles: true, detail: { karte: schluessel, nummer: i + 1, name: ort.name } }));
    }

    async function laden() {
        if (karte) {
            return karte;
        }

        start = await bibliothek(cfg.basis);
        const abs = (pfad) => new URL(pfad, document.baseURI).href;
        const antwort = await fetch(`${cfg.basis}/stile/${cfg.stil}.json`);
        const stil = JSON.parse((await antwort.text())
            .replaceAll('{PMTILES}', `pmtiles://${abs(cfg.daten)}`)
            .replaceAll('{GLYPHS}', abs(`${cfg.basis}/schriften`))
            .replaceAll('{SPRITES}', abs(`${cfg.basis}/symbole`)));

        // Eigener Rahmen um die Karte: MapLibre setzt am Kartencontainer position: relative,
        // der Rahmen liegt deshalb absolut über dem Bild und die Karte füllt ihn.
        const rahmen = document.createElement('div');
        rahmen.className = 'osm-map__karte';
        const flaeche = document.createElement('div');
        flaeche.className = 'osm-map__leinwand';
        rahmen.appendChild(flaeche);
        wurzel.appendChild(rahmen);

        // Grenzen: Bis kurz vor der Detailstufe darf man im weiten Umfeld unterwegs sein, darüber
        // nur im Gebiet um die Markierungen — dort, wo es die genauen Kartendaten gibt.
        const umfeld = cfg.umfeld || cfg.gebiet;
        const grenzen = (z) => {
            const [w, s, o, n] = z < cfg.detailAb - 0.5 ? umfeld : cfg.gebiet;

            return [[w, s], [o, n]];
        };
        const t = cfg.texte || {};
        const optionen = {
            container: flaeche,
            style: stil,
            maxBounds: grenzen(0),
            maxZoom: cfg.maxZoom,
            dragRotate: false,
            pitchWithRotate: false,
            touchPitch: false,
            cooperativeGestures: true,
            attributionControl: { compact: true },
            locale: {
                'NavigationControl.ZoomIn': t.zoomIn,
                'NavigationControl.ZoomOut': t.zoomOut,
                'NavigationControl.ResetBearing': t.kompass,
                'AttributionControl.ToggleAttribution': t.namensnennung,
                'CooperativeGesturesHandler.WindowsHelpText': t.gestenWindows,
                'CooperativeGesturesHandler.MacHelpText': t.gestenMac,
                'CooperativeGesturesHandler.MobileHelpText': t.gestenMobil,
                'Map.Title': t.karte,
            },
        };

        if (cfg.orte.length > 1) {
            const lons = cfg.orte.map((p) => p.lon), lats = cfg.orte.map((p) => p.lat);
            optionen.bounds = [[Math.min(...lons), Math.min(...lats)], [Math.max(...lons), Math.max(...lats)]];
            optionen.fitBoundsOptions = { padding: { top: 60, bottom: 30, left: 40, right: 40 } };
        } else {
            optionen.center = [cfg.orte[0].lon, cfg.orte[0].lat];
            optionen.zoom = Math.min(cfg.zoom, 17);
        }

        karte = new start.Map(optionen);
        karte.addControl(new start.NavigationControl({ showCompass: false }), 'top-right');
        karte.on('error', (e) => {
            if (!wurzel.classList.contains('osm-map--bereit')) {
                console.warn('Bewegliche Karte nicht verfügbar, es bleibt beim Kartenbild.', e.error);
            }
        });
        karte.once('load', () => wurzel.classList.add('osm-map--bereit'));
        let detail = false;
        karte.on('zoom', () => {
            const jetzt = karte.getZoom() >= cfg.detailAb - 0.5;

            if (jetzt !== detail) {
                detail = jetzt;
                karte.setMaxBounds(grenzen(karte.getZoom()));
            }
        });

        cfg.orte.forEach((ort, i) => {
            const element = pin(cfg.farbe, ort.name);
            element.addEventListener('click', (e) => {
                e.stopPropagation();
                oeffnen(i, false);
            });
            marker.push(new start.Marker({ element, anchor: 'bottom' }).setLngLat([ort.lon, ort.lat]).addTo(karte));
        });

        return karte;
    }

    eintraege.forEach(({ el: eintrag, i }) => {
        eintrag.addEventListener('click', async (e) => {
            if (e.target.closest('a')) {
                return;
            }

            await laden();

            // Liegt die Karte außerhalb des Bildschirms, erst hinrollen, dann fliegen — sonst
            // ist der Flug schon halb vorbei, wenn die Karte ins Bild kommt.
            let warten = 0;

            if (!sichtbar(wurzel)) {
                wurzel.scrollIntoView({ behavior: bewegung ? 'smooth' : 'auto', block: 'center' });
                warten = bewegung ? 600 : 0;
            }

            // Nicht karte.loaded(): Das ist auch nach dem ersten Zeichnen falsch, solange Kacheln
            // nachladen (etwa direkt nach einem Flug) — und „load“ kommt kein zweites Mal.
            const los = () => setTimeout(() => oeffnen(i, true), warten);
            wurzel.classList.contains('osm-map--bereit') ? los() : karte.once('load', los);
        });
    });

    if ('IntersectionObserver' in window) {
        const beobachter = new IntersectionObserver((treffer) => {
            if (treffer.some((t) => t.isIntersecting)) {
                beobachter.disconnect();
                laden().catch((e) => console.warn('Bewegliche Karte nicht verfügbar, es bleibt beim Kartenbild.', e));
            }
        }, { rootMargin: '400px 0px' });
        beobachter.observe(wurzel);
    } else {
        laden().catch(() => {});
    }
}

if (!navigator.connection?.saveData) {
    document.querySelectorAll('.osm-map[data-osm-interaktiv]').forEach(einrichten);
}
