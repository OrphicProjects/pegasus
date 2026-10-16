// Migration : supprime les 15 fiches secteur×niveau-de-luxe (tiers) et pose 5 fiches secteur propres.
const SECTEURS = [
  { titre: "Restaurant", business: "restaurant", ingredients: "L'assiette et la photographie culinaire au centre ; le menu comme objet typographique (jamais un PDF) ; réservation / commande accessible en permanence. Registre selon le positionnement (sombre-dramatique pour la gastronomie, chaleureux pour la bistronomie). Pièges : PDF de menu, musique auto, immersion qui cache les infos pratiques." },
  { titre: "Bijou", business: "bijou", ingredients: "La pièce est l'objet héros : mono-focal, macro, écrin qui la sculpte ; pré-rendu ray-tracé ou caustiques / dispersion en shader selon le budget. Registre écrin (sombre) ou galerie claire. Pièges : pièce trop petite, plusieurs produits par écran, compression qui tue les reflets." },
  { titre: "Mode", business: "mode", ingredients: "Le vêtement porté en mouvement, éditorial et lookbook ; matière tissu / soie en shader pour le haut de gamme, e-commerce éditorialisé sinon. Typo display forte. Pièges : boutique générique qui casse l'aura, lenteur sur le tunnel d'achat." },
  { titre: "Corporate", business: "corporate", ingredients: "Sobriété, espace, typo institutionnelle, données mises en scène ; la confiance et la précision priment. Registre clair-épuré (ou sombre premium). CTA contact / devis toujours clair. Pièges : dégradés type Stripe = corporate tech pas luxe ; template sans idée singulière." },
  { titre: "Artistique", business: "artistique", ingredients: "Le site incarne l'œuvre : loader qui pose le ton, transitions qui racontent, détails qui incarnent ; motion scénique. Billetterie / agenda accessible. Registre selon l'univers de l'artiste. Réfs : Michael Gatt, Emotions Arts. Pièges : singer l'affiche au lieu de traduire le mouvement." },
];

const list = await (await fetch("http://127.0.0.1:9223/json")).json();
const ws = new WebSocket(list.find(t => t.type === "page").webSocketDebuggerUrl);
let id = 0; const pend = {};
const send = (m, p = {}) => new Promise(res => { const i = ++id; pend[i] = res; ws.send(JSON.stringify({ id: i, method: m, params: p })); });
ws.onmessage = (ev) => { const m = JSON.parse(ev.data); if (m.id && pend[m.id]) { pend[m.id](m.result || m.error); delete pend[m.id]; } };
await new Promise(r => ws.onopen = r); await send("Runtime.enable");
const js = async (e) => (await send("Runtime.evaluate", { expression: e, returnByValue: true, awaitPromise: true })).result?.value;

// 1. Supprime toutes les fiches kind=secteur existantes (les 15 à tiers)
const existing = await js(`(async()=>{const r=await window.olympus.pegasusRefs({kind:'secteur',statut:'tous',limit:500});return (r.refs||[]).map(x=>({id:x.id,titre:x.titre}));})()`) || [];
let deleted = 0;
for (const x of existing) { const r = await js(`(async()=>{const r=await window.olympus.pegasusRefDelete(${x.id});return r.ok;})()`); if (r) deleted++; }

// 2. Pose les 5 fiches secteur propres (sans niveau/registre — ils varient selon le positionnement)
let added = 0;
for (const s of SECTEURS) {
  const row = { kind: "secteur", titre: s.titre, business: s.business, ingredients: s.ingredients, statut: "valide", auteur: "Sacha" };
  const r = await js(`(async()=>{const r=await window.olympus.pegasusRefAdd(${JSON.stringify(row)});return r.ok?'ok':(r.error||'err');})()`);
  if (r === "ok") added++; else console.log("échec:", s.titre, "→", r);
}
console.log(`supprimées: ${deleted} | ajoutées: ${added}`);
process.exit(0);
