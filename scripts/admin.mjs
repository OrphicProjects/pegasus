#!/usr/bin/env node
/**
 * PEGASUS — Console admin (réservée à Sacha, sur le Mac qui détient les secrets).
 * Gère la livraison de la clé d'équipe aux collègues via le service Pegasus (Worker Cloudflare).
 *
 * Prérequis : ~/.config/orphic/pegasus.local.json (adresse + jeton de service) et la clé
 * privée de déchiffrement (voir scripts/_acces.mjs).
 *
 * Usage :
 *   node scripts/admin.mjs set-key            # (re)dépose la clé d'équipe dans le service
 *   node scripts/admin.mjs add "Prénom Nom"   # crée un code d'accès pour un collègue
 *   node scripts/admin.mjs list               # liste les codes (statut, dernière utilisation)
 *   node scripts/admin.mjs revoke "Prénom Nom"# révoque le(s) code(s) d'un collègue
 */
import { createHash, randomBytes } from "node:crypto";
import { acces, cleEquipe } from "./_acces.mjs";

const { url: URL, token: SERVICE } = acces();

const H = {
  Authorization: `Bearer ${SERVICE}`,
  "Content-Type": "application/json",
  Prefer: "return=representation",
};

const sha256 = (s) => createHash("sha256").update(s).digest("hex");

async function rest(path, opts = {}) {
  const res = await fetch(`${URL}/v1/${path}`, { ...opts, headers: { ...H, ...(opts.headers || {}) } });
  const text = await res.text();
  if (!res.ok) throw new Error(`${res.status} ${res.statusText} — ${text}`);
  return text ? JSON.parse(text) : null;
}

const buildTeamKey = cleEquipe;

// Code lisible : 4 groupes de 4 (ex : 7Q2K-9FMP-3XR8-VD5T)
function genCode() {
  const alpha = "ABCDEFGHJKLMNPQRSTUVWXYZ23456789"; // sans I/O/0/1
  const bytes = randomBytes(16);
  let out = "";
  for (let i = 0; i < 16; i++) {
    out += alpha[bytes[i] % alpha.length];
    if (i % 4 === 3 && i < 15) out += "-";
  }
  return out;
}

const cmd = process.argv[2];
const arg = process.argv.slice(3).join(" ").trim();

try {
  if (cmd === "set-key") {
    const team_key = buildTeamKey();
    // upsert id=1
    await rest("team_config?on_conflict=id", {
      method: "POST",
      headers: { Prefer: "resolution=merge-duplicates,return=representation" },
      body: JSON.stringify({ id: 1, team_key, updated_at: new Date().toISOString() }),
    });
    console.log(`\n✅ Clé d'équipe déposée dans le service Pegasus (${team_key.length} caractères).`);
    console.log("   Les collègues avec un code valide peuvent maintenant s'installer.\n");
  } else if (cmd === "add") {
    if (!arg) throw new Error('Précise un libellé : node scripts/admin.mjs add "Prénom Nom"');
    const code = genCode();
    await rest("access_codes", {
      method: "POST",
      body: JSON.stringify({ code_hash: sha256(code), label: arg }),
    });
    console.log(`\n✅ Code créé pour « ${arg} » :\n`);
    console.log(`        ${code}\n`);
    console.log("   Donne-lui ce code (il le saisit UNE fois dans l'app Pegasus).");
    console.log("   ⚠️  Il ne sera plus jamais réaffiché — note-le maintenant.\n");
  } else if (cmd === "list") {
    const rows = await rest("access_codes?select=label,revoked,created_at,last_used_at&order=created_at.desc");
    if (!rows?.length) { console.log("\n(aucun code)\n"); process.exit(0); }
    console.log("\nCodes d'accès Pegasus :\n");
    for (const r of rows) {
      const st = r.revoked ? "❌ révoqué" : "✅ actif  ";
      const used = r.last_used_at ? new Date(r.last_used_at).toLocaleString("fr-FR") : "jamais";
      console.log(`  ${st}  ${(r.label || "—").padEnd(24)} dernière utilisation : ${used}`);
    }
    console.log("");
  } else if (cmd === "revoke") {
    if (!arg) throw new Error('Précise le libellé : node scripts/admin.mjs revoke "Prénom Nom"');
    const updated = await rest(`access_codes?label=eq.${encodeURIComponent(arg)}`, {
      method: "PATCH",
      body: JSON.stringify({ revoked: true }),
    });
    console.log(`\n✅ ${updated?.length || 0} code(s) révoqué(s) pour « ${arg} ».\n`);
  } else {
    console.log(`
PEGASUS — Console admin

  node scripts/admin.mjs set-key             (re)dépose la clé d'équipe dans le service Pegasus
  node scripts/admin.mjs add "Prénom Nom"    crée un code d'accès pour un collègue
  node scripts/admin.mjs list                liste les codes (statut, dernière utilisation)
  node scripts/admin.mjs revoke "Prénom Nom" révoque le(s) code(s) d'un collègue
`);
  }
} catch (e) {
  console.error("\n❌ " + e.message + "\n");
  process.exit(1);
}
