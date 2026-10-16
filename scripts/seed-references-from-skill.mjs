// Sème la bibliothèque vivante avec les références du skill (references/sites.md),
// via l'IPC réel d'Olympus (window.olympus.pegasusRefAdd). Idempotent : saute les titres déjà présents.
const SEED = [
  { titre: "Igloo.inc", url: "https://igloo.inc", kind: "site", niveau: "N4", registre: "", intention: "univers", business: "studio / produit", technique: "Three.js, three-mesh-bvh, GSAP, Vite (Houdini + Blender)", ingredients: "Cristaux procéduraux + texte UI en shader + footer particules VDB ; caméra qui dérive entre objets = navigation ; sur du full-WebGL, la perf EST le design.", notes: "Abeto × Bureaux · palette #b6bac5 / #383e4e" },
  { titre: "Active Theory", url: "https://activetheory.net", kind: "site", niveau: "N4", registre: "sombre-dramatique", intention: "univers", business: "studio", technique: "WebGL, framework maison Hydra", ingredients: "Environnements 3D navigables, néons ; le temps réel exige un outillage industriel maison.", notes: "Venice Beach, pionniers WebGL depuis 2012" },
  { titre: "Musée", url: "https://musee.barvian.me", kind: "site", niveau: "N2", registre: "", intention: "vitrine", business: "démo / portfolio", technique: "React Three Fiber + Framer Motion + CSS scroll snapping", ingredients: "Finition R3F déclaratif, maintenable, intégrable Next — modèle réaliste pour 80% des projets 3D clients.", notes: "Maxwell Barvian · repo public github.com/barvian/musee" },
  { titre: "Organimo", url: "https://organimo.com", kind: "site", niveau: "N2", registre: "", intention: "produit", business: "e-commerce (complément)", technique: "WebGL + WooCommerce", ingredients: "Immersion AU SERVICE du tunnel de vente ; chemin d'achat toujours lisible — prouve que WebGL + WooCommerce est viable.", notes: "Unseen Studio, SOTD · #e7e6f0 / #2d2f36" },
  { titre: "Ryan Ritzenthaler", url: "https://ryanritzenthaler.com", kind: "site", niveau: "N2", registre: "", intention: "vitrine", business: "portfolio", technique: "Next.js + Three.js + R3F + GLSL + Prismic", ingredients: "Illusion d'un site 2D plat imprégné de matière shader sur plane geometries (pas un modèle 3D collé dans la page).", notes: "" },
  { titre: "Lusion", url: "https://lusion.co", kind: "site", niveau: "N2", registre: "sombre-dramatique", intention: "vitrine", business: "studio", technique: "WebGL (Three.js)", ingredients: "Assets custom obtenus quand design + dev travaillent ensemble ; refus des tendances (anti-template).", notes: "Edan Kwan · Porsche, Google, Max Mara · noir/blanc" },
  { titre: "Akaru", url: "https://akaru.fr", kind: "site", niveau: "N2", registre: "clair-epure", intention: "vitrine", business: "studio", technique: "Nuxt 3 + Sanity + Three.js + GLSL + Blender", ingredients: "WebGL discret au service d'un éditorial ; techniques justifiées par le projet, sans démonstration de force — la règle-mère incarnée.", notes: "Lyon, 35+ Awwwards" },
  { titre: "Amir Mohseni", url: "https://amirmohseni.com", kind: "site", niveau: "", registre: "", intention: "vitrine", business: "art director", technique: "", ingredients: "Le design prime, la technique sert : typo, espacement, hiérarchie = la fondation. Contrepoids au tout-WebGL.", notes: "Deveb, Dopegood — SOTD" },
  { titre: "Michael Gatt", url: "https://michaelgatt.com", kind: "site", niveau: "N2", registre: "sombre-dramatique", intention: "univers", business: "compositeur / artiste", technique: "Nuxt + Vue + WebGL", ingredients: "Loader qui pose le ton, transitions qui racontent, détails qui incarnent (Guitar & Backpack) — l'axe univers/récit.", notes: "Synchronized Studio × Zhenya Rynzhuk · modèle pour Orphic Production" },
  { titre: "Trionn", url: "https://trionn.com", kind: "site", niveau: "N2", registre: "chaleureux-ludique", intention: "univers", business: "studio", technique: "Next.js + GSAP + Three.js", ingredients: "Aucune zone morte (menu, footer, 404, hovers) ; un univers de marque peut être ludique et chaleureux.", notes: "Sunny Rathod · univers jungle/tigre" },
  { titre: "Emotions Arts", url: "https://emotions-arts.com", kind: "site", niveau: "N2", registre: "clair-conversion", intention: "vitrine", business: "spectacle vivant", technique: "WordPress + Elementor + Curtains.js + GSAP + ScrollTrigger + Theatre.js", ingredients: "N2 en registre clair : le motion sert la séduction et le CTA (devis), pas la démonstration technique.", notes: "Projet Orphic · Cormorant Garamond + Jost · FR/EN/IT" },
  { titre: "Kalinsky", url: "https://kalinsky.design", kind: "site", niveau: "N1", registre: "clair-epure", intention: "vitrine", business: "designer / portfolio", technique: "Framer", ingredients: "Minimalisme clair : équilibre, alignement, contraste ; la voie no-code élégante pour ce qui ne justifie pas du custom.", notes: "Max Kalinsky" },
  { titre: "Editorial New", url: "", kind: "site", niveau: "N1", registre: "sombre-dramatique", intention: "vitrine", business: "typographie", technique: "CSS/HTML5 + GSAP + PHP, zéro WebGL", ingredients: "Tout le site = une police variable mise en scène ; UNE idée typo poussée à fond suffit pour un SOTD.", notes: "Locomotive × Pangram Pangram · noir/blanc" },
  { titre: "Basement Foundry", url: "https://basement.studio", kind: "site", niveau: "N1", registre: "chaleureux-ludique", intention: "vitrine", business: "studio", technique: "Next.js", ingredients: "Typo comme spectacle ; élargit le vocabulaire hors des palettes givrées.", notes: "orange #FF4D00 / noir · brut-énergique" },
  { titre: "Locomotive", url: "https://locomotive.ca", kind: "site", niveau: "N1", registre: "sombre-dramatique", intention: "vitrine", business: "agence", technique: "GSAP", ingredients: "Navigation minimale + usage massif de la typo + concept bichrome = univers auto-suffisant ; le site d'agence intemporel.", notes: "Montréal, 100+ awards · case study Baillat" },
  { titre: "Julien Calot", url: "https://juliencalot.com", kind: "site", niveau: "N2", registre: "", intention: "vitrine", business: "artiste / portfolio", technique: "Webflow + WebGL", ingredients: "Luxe + WebGL-surface, registre feutré — la ref la plus proche du créneau ultra-luxe shader-surface (opportunité Orphic).", notes: "par FLOT NOIR" },
];

const list = await (await fetch("http://127.0.0.1:9223/json")).json();
const ws = new WebSocket(list.find(t => t.type === "page").webSocketDebuggerUrl);
let id = 0; const pend = {};
const send = (m, p = {}) => new Promise(res => { const i = ++id; pend[i] = res; ws.send(JSON.stringify({ id: i, method: m, params: p })); });
ws.onmessage = (ev) => { const m = JSON.parse(ev.data); if (m.id && pend[m.id]) { pend[m.id](m.result || m.error); delete pend[m.id]; } };
await new Promise(r => ws.onopen = r); await send("Runtime.enable");
const js = async (e) => (await send("Runtime.evaluate", { expression: e, returnByValue: true, awaitPromise: true })).result?.value;

// Titres déjà présents
const existing = await js(`(async()=>{const r=await window.olympus.pegasusRefs({statut:'tous',limit:500});return (r.refs||[]).map(x=>x.titre);})()`) || [];
const have = new Set(existing);
let added = 0, skipped = 0;
for (const ref of SEED) {
  if (have.has(ref.titre)) { skipped++; continue; }
  const row = { ...ref, statut: "valide", auteur: "Sacha" };
  const r = await js(`(async()=>{const r=await window.olympus.pegasusRefAdd(${JSON.stringify(row)});return r.ok?'ok':(r.error||'err');})()`);
  if (r === "ok") added++; else console.log("échec:", ref.titre, "→", r);
}
console.log(`ajoutées: ${added} | déjà présentes: ${skipped} | total seed: ${SEED.length}`);
process.exit(0);
