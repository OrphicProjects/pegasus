#!/usr/bin/env node
/**
 * Pegasus MCP — pont entre Claude et l'API Pegasus des sites WordPress d'Orphic Agency.
 *
 * Les identifiants (une Application Password par site) vivent dans clients.json,
 * fichier local jamais versionné. Claude ne voit jamais les mots de passe :
 * il nomme un client ("robuchon"), le serveur va chercher les identifiants et
 * signe la requête lui-même.
 */
import { readFileSync } from "node:fs";
import { readFile } from "node:fs/promises";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";
import { privateDecrypt, constants } from "node:crypto";
import { homedir } from "node:os";

const __dir = dirname(fileURLToPath(import.meta.url));

/* Clé d'équipe : base64 d'un JSON {api_url, api_token, private_key} —
   api_url : le service Pegasus (Worker Cloudflare) ; api_token : son jeton de service.
   Sources, dans l'ordre :
     1. variable d'env PEGASUS_TEAM_KEY (userConfig du plugin, si l'app la supporte)
     2. fichier ~/.pegasus/team-key (déposé par la commande /pegasus:installer) ← cas app desktop
     3. repli : fichiers locaux pegasus.config.json + pegasus-private.pem (poste de dev admin)
   Jamais dans le dépôt. */
let _team;
function loadTeamKey() {
  if (_team !== undefined) return _team;
  let tk = process.env.PEGASUS_TEAM_KEY;
  if (tk && tk.includes("${")) tk = "";                 // gabarit non substitué → ignorer
  if (!tk) {
    try { tk = readFileSync(join(homedir(), ".pegasus", "team-key"), "utf8").trim(); } catch {}
  }
  if (!tk) return (_team = null);
  try { _team = JSON.parse(Buffer.from(tk.trim(), "base64").toString("utf8")); }
  catch { _team = null; }
  return _team;
}
function loadConfig() {
  const t = loadTeamKey();
  if (t && t.api_url && t.api_token) return { api_url: t.api_url, api_token: t.api_token };
  try { return JSON.parse(readFileSync(join(__dir, "pegasus.config.json"), "utf8")); }
  catch { return {}; }
}
function loadPrivateKey() {
  const t = loadTeamKey();
  if (t && t.private_key) return t.private_key;
  try { return readFileSync(join(__dir, "pegasus-private.pem"), "utf8"); }
  catch { return null; }
}

/* ————— Sites depuis le registre Pegasus (déchiffrés) + clients.json local en secours ————— */
let _cache = { at: 0, clients: {} };
async function loadClientsFromRegistry() {
  const cfg = loadConfig();
  const priv = loadPrivateKey();
  if (!cfg.api_url || !cfg.api_token || !priv) return {};
  const url = cfg.api_url.replace(/\/$/, "");
  const res = await fetch(`${url}/v1/sites?select=*&order=id.desc`, {
    headers: { Authorization: `Bearer ${cfg.api_token}` },
  });
  if (!res.ok) throw new Error(`Registre Pegasus ${res.status}`);
  const rows = await res.json();
  const clients = {};
  for (const row of rows) {
    const host = new URL(row.site_url).hostname.replace(/^www\./, "");
    const key = host.split(".")[0].toLowerCase();
    if (clients[key]) continue; // déjà la version la plus récente (tri id desc)
    let pass;
    try {
      pass = privateDecrypt(
        { key: priv, padding: constants.RSA_PKCS1_OAEP_PADDING },
        Buffer.from(row.app_password_enc, "base64")
      ).toString("utf8");
    } catch { continue; }
    clients[key] = { label: row.label || host, base_url: row.site_url, username: row.username, app_password: pass };
  }
  return clients;
}

async function loadClients() {
  let local = {};
  try { local = JSON.parse(readFileSync(join(__dir, "clients.json"), "utf8")).clients || {}; } catch {}
  if (Date.now() - _cache.at < 30000) return { ...local, ..._cache.clients };
  try {
    const remote = await loadClientsFromRegistry();
    _cache = { at: Date.now(), clients: remote };
    return { ...local, ...remote };
  } catch {
    return { ...local, ..._cache.clients };
  }
}

async function clientAuth(key) {
  const clients = await loadClients();
  const c = clients[key];
  if (!c) {
    const dispo = Object.keys(clients).join(", ") || "aucun";
    throw new Error(`Client inconnu : "${key}". Clients configurés : ${dispo}.`);
  }
  const basic = Buffer.from(`${c.username}:${c.app_password.replace(/\s+/g, "")}`).toString("base64");
  return { base: c.base_url.replace(/\/$/, ""), basic, label: c.label };
}

/* URL via ?rest_route= : fonctionne quels que soient les permaliens du site */
function restUrl(base, path) {
  const [p, qs] = path.split("?");
  return `${base}/?rest_route=/pegasus/v1${p}` + (qs ? `&${qs}` : "");
}

/* ————— Appel de l'API Pegasus d'un site ————— */
async function pegasus(key, method, path, body) {
  const { base, basic } = await clientAuth(key);
  const res = await fetch(restUrl(base, path), {
    method,
    headers: {
      "Authorization": `Basic ${basic}`,   // en-tête standard (hôtes qui le laissent passer)
      "X-Pegasus-Auth": basic,             // en-tête de secours (hôtes qui filtrent Authorization)
      "Content-Type": "application/json",
    },
    body: body ? JSON.stringify(body) : undefined,
  });
  const text = await res.text();
  let data;
  try { data = JSON.parse(text); } catch { data = { raw: text.slice(0, 500) }; }
  if (!res.ok) {
    throw new Error(`Pegasus ${res.status} : ${data?.message || data?.code || text.slice(0, 200)}`);
  }
  return data;
}

/* ————— Bibliothèque Orphic (références vivantes) — table references_library —————
   Architecture « méthode stable / données vivantes » : le skill orphic-web-design
   porte la méthode, cette table porte les données (refs, animations, secteurs).
   Flux : proposer (candidat) → valider (humain) → vivant pour toute l'agence. */
async function registry(path, opts = {}) {
  const cfg = loadConfig();
  if (!cfg.api_url || !cfg.api_token) {
    throw new Error("Service Pegasus non configuré (clé d'équipe manquante — /pegasus:installer).");
  }
  const url = cfg.api_url.replace(/\/$/, "");
  const res = await fetch(`${url}/v1${path}`, {
    ...opts,
    headers: {
      Authorization: `Bearer ${cfg.api_token}`,
      "Content-Type": "application/json",
      ...(opts.headers || {}),
    },
  });
  const text = await res.text();
  if (!res.ok) throw new Error(`Service Pegasus ${res.status} : ${text.slice(0, 200)}`);
  try { return text ? JSON.parse(text) : null; } catch { return { raw: text.slice(0, 300) }; }
}

const REF_FIELDS = ["kind", "titre", "url", "niveau", "technique", "intention", "registre", "business", "ingredients", "notes"];

const ok = (obj) => ({ content: [{ type: "text", text: JSON.stringify(obj, null, 2) }] });
const ko = (e) => ({ content: [{ type: "text", text: `Erreur : ${e.message}` }], isError: true });

/* ————— Définition des outils MCP ————— */
const TOOLS = [
  {
    name: "pegasus_list_clients",
    description: "Liste les sites WordPress configurés dans clients.json (labels et clés).",
    inputSchema: { type: "object", properties: {} },
  },
  {
    name: "pegasus_health",
    description: "Vérifie la connexion à un site et renvoie ses infos (WP, PHP, thème, utilisateur).",
    inputSchema: { type: "object", properties: { client: { type: "string" } }, required: ["client"] },
  },
  {
    name: "pegasus_inspect",
    description: "Structure exacte d'un site : thème, plugins (actifs/inactifs + versions), constructeur de page (Elementor ?), permaliens, compteurs de contenus.",
    inputSchema: { type: "object", properties: { client: { type: "string" } }, required: ["client"] },
  },
  {
    name: "pegasus_diagnostic",
    description: "Diagnostic complet d'un site : thème actif (+ enfant/parent), tous les thèmes et plugins installés, constructeur de page, multilingue, pages existantes (collisions de slugs), verrous serveur (DISALLOW_FILE_MODS/EDIT), et ce que Pegasus peut toucher ou pas.",
    inputSchema: { type: "object", properties: { client: { type: "string" } }, required: ["client"] },
  },
  {
    name: "pegasus_list_themes",
    description: "Liste les thèmes installés sur un site et indique lequel est actif.",
    inputSchema: { type: "object", properties: { client: { type: "string" } }, required: ["client"] },
  },
  {
    name: "pegasus_install_theme",
    description: "Installe (ou met à jour) un thème sur un site depuis un fichier .zip local du Mac, via l'installeur natif de WordPress. N'active PAS le thème — utiliser pegasus_activate_theme ensuite.",
    inputSchema: {
      type: "object",
      properties: { client: { type: "string" }, zip_path: { type: "string", description: "Chemin absolu du .zip sur le Mac" } },
      required: ["client", "zip_path"],
    },
  },
  {
    name: "pegasus_activate_theme",
    description: "Active un thème déjà installé (par son stylesheet, ex: 'emotionsarts'). L'ancien thème reste installé comme filet de secours.",
    inputSchema: {
      type: "object",
      properties: { client: { type: "string" }, stylesheet: { type: "string" } },
      required: ["client", "stylesheet"],
    },
  },
  {
    name: "pegasus_seo_audit",
    description: "Audit SEO d'un site : lit le HTML rendu de chaque page (title, meta description, H1, canonical, Open Graph, images sans alt, langue), détecte le plugin SEO, le sitemap et robots.txt, et résume les problèmes. Paramètre limit optionnel (défaut 12).",
    inputSchema: {
      type: "object",
      properties: { client: { type: "string" }, limit: { type: "number" } },
      required: ["client"],
    },
  },
  {
    name: "pegasus_install_plugin",
    description: "Installe un plugin sur un site : soit depuis le dépôt WordPress.org par 'slug' (ex 'polylang' pour ajouter le multilingue), soit depuis un .zip local (nos plugins de fonctionnalité). N'active pas — utiliser pegasus_activate_plugin ensuite.",
    inputSchema: {
      type: "object",
      properties: {
        client: { type: "string" },
        slug: { type: "string", description: "slug WordPress.org, ex 'polylang'" },
        zip_path: { type: "string", description: "chemin d'un .zip local (alternatif au slug)" },
      },
      required: ["client"],
    },
  },
  {
    name: "pegasus_activate_plugin",
    description: "Active un plugin déjà installé, par son fichier (ex 'polylang/polylang.php').",
    inputSchema: {
      type: "object",
      properties: { client: { type: "string" }, plugin: { type: "string" } },
      required: ["client", "plugin"],
    },
  },
  {
    name: "pegasus_upload_media",
    description: "Ajoute un média (photo/vidéo) à la bibliothèque d'un site : soit un fichier local (file_path, encodé base64, ≤ 64 Mo), soit depuis une URL (source_url, idéal pour les grosses vidéos). alt et title optionnels. Renvoie l'ID et l'URL du média.",
    inputSchema: {
      type: "object",
      properties: {
        client: { type: "string" },
        file_path: { type: "string", description: "chemin d'un fichier local sur le Mac" },
        source_url: { type: "string", description: "URL d'un média à importer (alternatif)" },
        alt: { type: "string" },
        title: { type: "string" },
      },
      required: ["client"],
    },
  },
  {
    name: "pegasus_seo_set",
    description: "Applique un title SEO et/ou une meta description à une page (post_id). Écrit dans le plugin SEO actif (Yoast/Rank Math) ou, à défaut, active la couche SEO de secours de Pegasus.",
    inputSchema: {
      type: "object",
      properties: { client: { type: "string" }, post_id: { type: "number" }, seo_title: { type: "string" }, seo_description: { type: "string" } },
      required: ["client", "post_id"],
    },
  },
  {
    name: "pegasus_seo_site",
    description: "Règle des paramètres SEO au niveau du site : locale (ex 'fr_FR', télécharge le pack de langue) et/ou tagline (slogan).",
    inputSchema: {
      type: "object",
      properties: { client: { type: "string" }, locale: { type: "string" }, tagline: { type: "string" } },
      required: ["client"],
    },
  },
  {
    name: "pegasus_list_content",
    description: "Liste les contenus d'un site (pages par défaut). Indique lesquels sont en Elementor.",
    inputSchema: {
      type: "object",
      properties: { client: { type: "string" }, type: { type: "string", description: "post type, défaut 'page'" } },
      required: ["client"],
    },
  },
  {
    name: "pegasus_get_content",
    description: "Lit un contenu précis par ID, avec son contenu et son JSON Elementor brut (_elementor_data) s'il existe.",
    inputSchema: {
      type: "object",
      properties: { client: { type: "string" }, id: { type: "number" } },
      required: ["client", "id"],
    },
  },
  {
    name: "pegasus_update_content",
    description: "Modifie un contenu : titre, contenu, statut (publish/draft/private) et/ou JSON Elementor (elementor_data, validé avant écriture). Ne fournir que les champs à changer.",
    inputSchema: {
      type: "object",
      properties: {
        client: { type: "string" },
        id: { type: "number" },
        title: { type: "string" },
        content: { type: "string" },
        status: { type: "string" },
        elementor_data: { type: "string", description: "JSON Elementor complet (chaîne)" },
      },
      required: ["client", "id"],
    },
  },
  {
    name: "pegasus_get_references",
    description: "Bibliothèque Orphic (service Pegasus) : cherche des références de design vivantes — sites, animations, matières, fiches secteur — enrichies par la veille de l'équipe. Utilisée par le skill orphic-web-design au cadrage et en recherche de DA. Par défaut, ne renvoie que les références validées.",
    inputSchema: {
      type: "object",
      properties: {
        kind: { type: "string", description: "site | animation | matiere | secteur | autre" },
        niveau: { type: "string", description: "N1 | N2 | N3 | N4" },
        registre: { type: "string", description: "sombre-dramatique | clair-epure | chaleureux-ludique | clair-conversion" },
        business: { type: "string", description: "secteur / type de client (recherche partielle, ex 'yachting')" },
        intention: { type: "string", description: "vitrine | produit | univers" },
        q: { type: "string", description: "texte libre (titre, ingrédients, technique, notes)" },
        statut: { type: "string", description: "'valide' (défaut), 'candidat' ou 'tous'" },
        limit: { type: "number", description: "défaut 30" },
      },
    },
  },
  {
    name: "pegasus_add_reference",
    description: "Propose une référence dans la bibliothèque Orphic, en statut 'candidat' (flux veille : proposer → valider par un humain → vivant pour toute l'agence). Une référence = des ingrédients à recombiner, jamais un modèle. Ne passer statut='valide' QUE sur validation humaine explicite dans la conversation.",
    inputSchema: {
      type: "object",
      properties: {
        titre: { type: "string" },
        kind: { type: "string", description: "site | animation | matiere | secteur | autre (défaut 'site')" },
        url: { type: "string" },
        niveau: { type: "string", description: "N1-N4" },
        technique: { type: "string", description: "libs/techniques, ex 'Curtains.js + GSAP'" },
        intention: { type: "string", description: "vitrine | produit | univers" },
        registre: { type: "string" },
        business: { type: "string", description: "secteur client, ex 'yachting'" },
        ingredients: { type: "string", description: "ce qu'on en extrait et recombine" },
        notes: { type: "string" },
        auteur: { type: "string", description: "qui propose (prénom)" },
        statut: { type: "string", description: "'candidat' (défaut) ; 'valide' uniquement sur validation humaine explicite" },
      },
      required: ["titre"],
    },
  },
  {
    name: "pegasus_validate_reference",
    description: "Passe une référence candidate de la bibliothèque Orphic en 'valide' (ou 'rejete'). Réservé à une décision humaine explicite (Sacha ou le dev qui valide) — jamais à l'initiative de Claude.",
    inputSchema: {
      type: "object",
      properties: {
        id: { type: "number" },
        statut: { type: "string", description: "'valide' (défaut) ou 'rejete'" },
      },
      required: ["id"],
    },
  },
];

async function callTool(name, a = {}) {
  try {
    switch (name) {
      case "pegasus_list_clients": {
        const c = await loadClients();
        return ok(Object.entries(c).map(([key, v]) => ({ key, label: v.label, base_url: v.base_url })));
      }
      case "pegasus_health":
        return ok(await pegasus(a.client, "GET", "/health"));
      case "pegasus_inspect":
        return ok(await pegasus(a.client, "GET", "/inspect"));
      case "pegasus_diagnostic":
        return ok(await pegasus(a.client, "GET", "/diagnostic"));
      case "pegasus_list_themes":
        return ok(await pegasus(a.client, "GET", "/themes"));
      case "pegasus_install_theme": {
        const zip = await readFile(a.zip_path);
        return ok(await pegasus(a.client, "POST", "/theme/install", { zip_b64: zip.toString("base64") }));
      }
      case "pegasus_activate_theme":
        return ok(await pegasus(a.client, "POST", "/theme/activate", { stylesheet: a.stylesheet }));
      case "pegasus_seo_audit":
        return ok(await pegasus(a.client, "GET", `/seo-audit${a.limit ? `?limit=${a.limit}` : ""}`));
      case "pegasus_install_plugin": {
        const body = {};
        if (a.slug) body.slug = a.slug;
        else if (a.zip_path) body.zip_b64 = (await readFile(a.zip_path)).toString("base64");
        else throw new Error("Fournir slug ou zip_path.");
        return ok(await pegasus(a.client, "POST", "/plugin/install", body));
      }
      case "pegasus_activate_plugin":
        return ok(await pegasus(a.client, "POST", "/plugin/activate", { plugin: a.plugin }));
      case "pegasus_upload_media": {
        const body = { alt: a.alt, title: a.title };
        if (a.file_path) {
          body.file_b64 = (await readFile(a.file_path)).toString("base64");
          body.filename = a.file_path.split("/").pop();
        } else if (a.source_url) {
          body.source_url = a.source_url;
        } else throw new Error("Fournir file_path ou source_url.");
        return ok(await pegasus(a.client, "POST", "/media/upload", body));
      }
      case "pegasus_seo_set":
        return ok(await pegasus(a.client, "POST", "/seo/set", { post_id: a.post_id, seo_title: a.seo_title, seo_description: a.seo_description }));
      case "pegasus_seo_site":
        return ok(await pegasus(a.client, "POST", "/seo/site", { locale: a.locale, tagline: a.tagline }));
      case "pegasus_list_content":
        return ok(await pegasus(a.client, "GET", `/content${a.type ? `?type=${encodeURIComponent(a.type)}` : ""}`));
      case "pegasus_get_content":
        return ok(await pegasus(a.client, "GET", `/content/${a.id}`));
      case "pegasus_update_content": {
        const body = {};
        for (const k of ["title", "content", "status", "elementor_data"]) if (a[k] !== undefined) body[k] = a[k];
        return ok(await pegasus(a.client, "POST", `/content/${a.id}`, body));
      }
      case "pegasus_get_references": {
        const p = new URLSearchParams();
        p.set("select", "*");
        p.set("order", "created_at.desc");
        if (a.kind) p.set("kind", `eq.${a.kind}`);
        if (a.niveau) p.set("niveau", `eq.${a.niveau}`);
        if (a.registre) p.set("registre", `eq.${a.registre}`);
        if (a.intention) p.set("intention", `eq.${a.intention}`);
        if (a.business) p.set("business", `ilike.*${a.business}*`);
        const statut = a.statut || "valide";
        if (statut !== "tous") p.set("statut", `eq.${statut}`);
        if (a.q) p.set("or", `(titre.ilike.*${a.q}*,ingredients.ilike.*${a.q}*,technique.ilike.*${a.q}*,notes.ilike.*${a.q}*)`);
        p.set("limit", String(a.limit || 30));
        return ok(await registry(`/references_library?${p.toString()}`));
      }
      case "pegasus_add_reference": {
        if (!a.titre) throw new Error("titre obligatoire.");
        const row = { statut: a.statut === "valide" ? "valide" : "candidat" };
        if (a.auteur) row.auteur = a.auteur;
        for (const k of REF_FIELDS) if (a[k] !== undefined) row[k] = a[k];
        const r = await registry("/references_library", {
          method: "POST",
          body: JSON.stringify(row),
          headers: { Prefer: "return=representation" },
        });
        return ok({ enregistré: r, rappel: row.statut === "candidat" ? "Statut candidat — validation humaine requise (pegasus_validate_reference)." : "Enregistrée comme validée." });
      }
      case "pegasus_validate_reference": {
        if (!a.id) throw new Error("id obligatoire.");
        const statut = a.statut === "rejete" ? "rejete" : "valide";
        const r = await registry(`/references_library?id=eq.${a.id}`, {
          method: "PATCH",
          body: JSON.stringify({ statut }),
          headers: { Prefer: "return=representation" },
        });
        if (!r || !r.length) throw new Error(`Référence ${a.id} introuvable.`);
        return ok(r);
      }
      default:
        return ko(new Error(`Outil inconnu : ${name}`));
    }
  } catch (e) {
    return ko(e);
  }
}

/* ————— Transport MCP stdio (JSON-RPC 2.0, sans aucune dépendance) —————
   Spec MCP stdio : messages JSON délimités par des nouvelles lignes. */
const PROTO = "2024-11-05";
const send = (msg) => process.stdout.write(JSON.stringify(msg) + "\n");
const reply = (id, result) => send({ jsonrpc: "2.0", id, result });
const replyErr = (id, code, message) => send({ jsonrpc: "2.0", id, error: { code, message } });

async function handle(msg) {
  const { id, method, params } = msg;
  switch (method) {
    case "initialize":
      return reply(id, {
        protocolVersion: (params && params.protocolVersion) || PROTO,
        capabilities: { tools: {} },
        serverInfo: { name: "pegasus", version: "0.6.0" },
      });
    case "notifications/initialized":
    case "notifications/cancelled":
      return; // notifications : pas de réponse
    case "ping":
      return reply(id, {});
    case "tools/list":
      return reply(id, { tools: TOOLS });
    case "tools/call": {
      const res = await callTool(params && params.name, (params && params.arguments) || {});
      return reply(id, res);
    }
    default:
      if (id !== undefined && id !== null) replyErr(id, -32601, `Méthode inconnue : ${method}`);
  }
}

let buf = "";
process.stdin.setEncoding("utf8");
process.stdin.on("data", (chunk) => {
  buf += chunk;
  let nl;
  while ((nl = buf.indexOf("\n")) >= 0) {
    const line = buf.slice(0, nl).trim();
    buf = buf.slice(nl + 1);
    if (!line) continue;
    let msg;
    try { msg = JSON.parse(line); } catch { continue; }
    Promise.resolve(handle(msg)).catch((e) => {
      if (msg && msg.id != null) replyErr(msg.id, -32603, String((e && e.message) || e));
    });
  }
});
process.stdin.on("end", () => process.exit(0));
console.error("Pegasus MCP prêt (sans dépendance).");
