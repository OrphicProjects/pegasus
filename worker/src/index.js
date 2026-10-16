/**
 * PEGASUS — le Worker (Cloudflare Workers + D1).
 *
 * Il rend les services que rendait la base d'avant, avec les mêmes droits :
 *   • le plugin WordPress inscrit un site (POST /v1/sites) et pousse son rapport
 *     du jour (POST /v1/reports) avec le JETON PUBLIC — ajout seul, aucune lecture ;
 *   • l'application de bureau échange un code d'accès contre la clé d'équipe
 *     (POST /v1/rpc/get_team_key, jeton public) ;
 *   • le serveur MCP et les outils d'admin lisent et écrivent tout avec le
 *     JETON DE SERVICE (porté par la clé d'équipe).
 * Sans jeton : rien (401).
 *
 * Les requêtes gardent la forme REST d'avant (filtres « col=eq.x », « ilike »,
 * « or=(…) », « order », « limit », en-tête Prefer), pour que les clients
 * n'aient à changer qu'une adresse et un jeton. Les chemins /rest/v1/… sont
 * acceptés comme alias de /v1/….
 *
 * Secrets (posés chez Cloudflare, jamais dans le dépôt) :
 *   PEGASUS_PUBLIC_TOKEN, PEGASUS_SERVICE_TOKEN
 */

const TABLES = {
  sites: {
    cols: ["id", "site_url", "username", "app_password_enc", "label", "created_at"],
    generatedId: true, publicInsert: true,
  },
  reports: {
    cols: ["id", "site_url", "day", "seo", "perf", "secu", "audience", "created_at"],
    json: ["seo", "perf", "secu", "audience"],
    generatedId: true, publicInsert: true,
  },
  references_library: {
    cols: ["id", "kind", "titre", "url", "niveau", "technique", "intention", "registre", "business", "ingredients", "notes", "statut", "auteur", "created_at"],
    generatedId: true,
  },
  site_backups: {
    cols: ["id", "site_key", "label", "kind", "structure", "content", "home_html", "note", "created_at"],
    json: ["structure", "content"],
    generatedId: true,
  },
  team_config: { cols: ["id", "team_key", "updated_at"] },
  access_codes: {
    cols: ["code_hash", "label", "revoked", "created_at", "last_used_at"],
    bool: ["revoked"],
  },
};

/* ————— Réponses ————— */
const json = (data, status = 200, extra = {}) =>
  new Response(data === undefined ? null : JSON.stringify(data), {
    status,
    headers: { "Content-Type": "application/json; charset=utf-8", "Cache-Control": "no-store", ...extra },
  });
const fail = (status, message, code) => json({ code: code || String(status), message }, status);
class HttpError extends Error { constructor(status, message) { super(message); this.status = status; } }

/* ————— Jetons ————— */
async function sha256Hex(s) {
  const d = await crypto.subtle.digest("SHA-256", new TextEncoder().encode(s));
  return [...new Uint8Array(d)].map((b) => b.toString(16).padStart(2, "0")).join("");
}
async function same(a, b) {
  if (!a || !b) return false;
  const [x, y] = await Promise.all([sha256Hex(a), sha256Hex(b)]);
  const ex = new TextEncoder().encode(x), ey = new TextEncoder().encode(y);
  return crypto.subtle.timingSafeEqual(ex, ey);
}
async function role(request, env) {
  const auth = request.headers.get("Authorization") || "";
  const token = (auth.match(/^Bearer\s+(.+)$/i) || [])[1] || request.headers.get("apikey") || "";
  if (!token) return null;
  if (await same(token.trim(), env.PEGASUS_SERVICE_TOKEN)) return "service";
  if (await same(token.trim(), env.PEGASUS_PUBLIC_TOKEN)) return "public";
  return null;
}

/* ————— Lignes : D1 ⇄ JSON ————— */
function toRow(t, row) {
  const out = { ...row };
  for (const c of t.json || []) if (out[c] != null) { try { out[c] = JSON.parse(out[c]); } catch {} }
  for (const c of t.bool || []) if (out[c] != null) out[c] = !!out[c];
  return out;
}
function toDb(t, col, v) {
  if (v === undefined) return null;
  if ((t.json || []).includes(col)) return v === null ? null : JSON.stringify(v);
  if ((t.bool || []).includes(col)) return v === null ? null : (v === true || v === "true" || v === 1) ? 1 : 0;
  if (v !== null && typeof v === "object") return JSON.stringify(v);
  return v;
}

/* ————— Filtres (col=op.valeur) ————— */
const RESERVED = new Set(["select", "order", "limit", "offset", "or", "on_conflict"]);
function checkCol(t, c) {
  if (!t.cols.includes(c)) throw new HttpError(400, `Colonne inconnue : ${c}`);
  return c;
}
function condition(t, col, expr, binds) {
  checkCol(t, col);
  let neg = false;
  if (expr.startsWith("not.")) { neg = true; expr = expr.slice(4); }
  const dot = expr.indexOf(".");
  if (dot < 0) throw new HttpError(400, `Filtre illisible : ${col}=${expr}`);
  const op = expr.slice(0, dot), raw = expr.slice(dot + 1);
  const val = (v) => toDb(t, col, (t.bool || []).includes(col) ? v === "true" : v);
  let sql;
  switch (op) {
    case "eq": sql = `${col} = ?`; binds.push(val(raw)); break;
    case "neq": sql = `${col} <> ?`; binds.push(val(raw)); break;
    case "gt": sql = `${col} > ?`; binds.push(raw); break;
    case "gte": sql = `${col} >= ?`; binds.push(raw); break;
    case "lt": sql = `${col} < ?`; binds.push(raw); break;
    case "lte": sql = `${col} <= ?`; binds.push(raw); break;
    case "like": sql = `${col} LIKE ? ESCAPE '\\'`; binds.push(raw.replace(/\*/g, "%")); break;
    case "ilike": sql = `lower(${col}) LIKE lower(?) ESCAPE '\\'`; binds.push(raw.replace(/\*/g, "%")); break;
    case "is":
      if (raw === "null") sql = `${col} IS NULL`;
      else if (raw === "true" || raw === "false") { sql = `${col} = ?`; binds.push(raw === "true" ? 1 : 0); }
      else throw new HttpError(400, `Filtre « is » illisible : ${raw}`);
      break;
    case "in": {
      const items = raw.replace(/^\(|\)$/g, "").split(",").map((s) => s.trim().replace(/^"|"$/g, ""));
      if (!items.length) throw new HttpError(400, "Liste vide.");
      sql = `${col} IN (${items.map(() => "?").join(",")})`;
      for (const i of items) binds.push(val(i));
      break;
    }
    default: throw new HttpError(400, `Opérateur inconnu : ${op}`);
  }
  return neg ? `NOT (${sql})` : sql;
}
function where(t, params) {
  const parts = [], binds = [];
  for (const [k, v] of params) {
    if (RESERVED.has(k)) continue;
    parts.push(condition(t, k, v, binds));
  }
  const or = params.get("or");
  if (or) {
    const inner = or.replace(/^\(|\)$/g, "").split(",").filter(Boolean).map((s) => {
      const d = s.indexOf(".");
      if (d < 0) throw new HttpError(400, `Filtre « or » illisible : ${s}`);
      return condition(t, s.slice(0, d), s.slice(d + 1), binds);
    });
    if (inner.length) parts.push(`(${inner.join(" OR ")})`);
  }
  return { sql: parts.length ? ` WHERE ${parts.join(" AND ")}` : "", binds };
}
function selectCols(t, params) {
  const s = (params.get("select") || "*").trim();
  if (s === "*" || s === "") return "*";
  return s.split(",").map((c) => checkCol(t, c.trim())).join(", ");
}
function orderBy(t, params) {
  const o = params.get("order");
  if (!o) return "";
  return " ORDER BY " + o.split(",").map((p) => {
    const [c, dir, nulls] = p.split(".");
    checkCol(t, c);
    let s = `${c} ${dir === "desc" ? "DESC" : "ASC"}`;
    if (nulls === "nullsfirst") s += " NULLS FIRST";
    if (nulls === "nullslast") s += " NULLS LAST";
    return s;
  }).join(", ");
}
function limitOffset(params) {
  let s = "";
  const l = params.get("limit"), o = params.get("offset");
  if (l != null) { const n = parseInt(l, 10); if (!(n >= 0)) throw new HttpError(400, "limit invalide"); s += ` LIMIT ${n}`; }
  if (o != null) { const n = parseInt(o, 10); if (!(n >= 0)) throw new HttpError(400, "offset invalide"); s += (l == null ? " LIMIT -1" : "") + ` OFFSET ${n}`; }
  return s;
}
const prefers = (request, word) => (request.headers.get("Prefer") || "").split(/[,;]\s*/).includes(word);

/* ————— Les gestes sur une table ————— */
async function readRows(env, t, name, params) {
  const w = where(t, params);
  const sql = `SELECT ${selectCols(t, params)} FROM ${name}${w.sql}${orderBy(t, params)}${limitOffset(params)}`;
  const r = await env.DB.prepare(sql).bind(...w.binds).all();
  return json((r.results || []).map((row) => toRow(t, row)));
}

async function insertRows(env, t, name, request, params, who) {
  let body;
  try { body = await request.json(); } catch { throw new HttpError(400, "Corps JSON attendu."); }
  const rows = Array.isArray(body) ? body : [body];
  if (!rows.length || rows.some((r) => !r || typeof r !== "object" || Array.isArray(r))) throw new HttpError(400, "Ligne(s) JSON attendue(s).");
  if (rows.length > 500) throw new HttpError(413, "Trop de lignes d'un coup (500 au plus).");
  const upsert = who === "service" && prefers(request, "resolution=merge-duplicates");
  const conflict = params.get("on_conflict");
  if (conflict) checkCol(t, conflict);
  const wantRows = who === "service" && prefers(request, "return=representation"); // le jeton public ne relit jamais rien
  const stmts = rows.map((r) => {
    const cols = Object.keys(r).filter((c) => r[c] !== undefined);
    for (const c of cols) checkCol(t, c);
    if (t.generatedId && cols.includes("id")) throw new HttpError(400, "La colonne id est attribuée par la base.");
    if (!cols.length) throw new HttpError(400, "Ligne vide.");
    let sql = `INSERT INTO ${name} (${cols.join(", ")}) VALUES (${cols.map(() => "?").join(", ")})`;
    if (upsert) {
      const target = conflict || (t.cols.includes("id") ? "id" : t.cols[0]);
      const sets = cols.filter((c) => c !== target).map((c) => `${c} = excluded.${c}`);
      sql += sets.length ? ` ON CONFLICT(${target}) DO UPDATE SET ${sets.join(", ")}` : ` ON CONFLICT(${target}) DO NOTHING`;
    }
    if (wantRows) sql += " RETURNING *";
    return env.DB.prepare(sql).bind(...cols.map((c) => toDb(t, c, r[c])));
  });
  const res = await env.DB.batch(stmts);
  if (!wantRows) return new Response(null, { status: 201 });
  return json(res.flatMap((x) => x.results || []).map((row) => toRow(t, row)), 201);
}

async function updateRows(env, t, name, request, params) {
  const w = where(t, params);
  if (!w.sql) throw new HttpError(400, "Une modification exige un filtre.");
  let body;
  try { body = await request.json(); } catch { throw new HttpError(400, "Corps JSON attendu."); }
  if (!body || typeof body !== "object" || Array.isArray(body)) throw new HttpError(400, "Objet JSON attendu.");
  const cols = Object.keys(body).filter((c) => body[c] !== undefined);
  for (const c of cols) checkCol(t, c);
  if (!cols.length) throw new HttpError(400, "Rien à modifier.");
  const sql = `UPDATE ${name} SET ${cols.map((c) => `${c} = ?`).join(", ")}${w.sql} RETURNING *`;
  const r = await env.DB.prepare(sql).bind(...cols.map((c) => toDb(t, c, body[c])), ...w.binds).all();
  const rows = (r.results || []).map((row) => toRow(t, row));
  return prefers(request, "return=representation") ? json(rows) : new Response(null, { status: 204 });
}

async function deleteRows(env, t, name, request, params) {
  const w = where(t, params);
  if (!w.sql) throw new HttpError(400, "Une suppression exige un filtre.");
  const r = await env.DB.prepare(`DELETE FROM ${name}${w.sql} RETURNING *`).bind(...w.binds).all();
  const rows = (r.results || []).map((row) => toRow(t, row));
  return prefers(request, "return=representation") ? json(rows) : new Response(null, { status: 204 });
}

/* ————— La clé d'équipe contre un code d'accès (l'ancienne fonction get_team_key) ————— */
async function getTeamKey(env, request) {
  let body;
  try { body = await request.json(); } catch { throw new HttpError(400, "Corps JSON attendu."); }
  const code = String((body && body.p_code) || "");
  const hash = await sha256Hex(code);
  const ok = await env.DB.prepare("SELECT 1 FROM access_codes WHERE code_hash = ? AND coalesce(revoked, 0) = 0").bind(hash).first();
  if (!ok) return fail(400, "Code invalide ou révoqué.", "P0001");
  await env.DB.prepare("UPDATE access_codes SET last_used_at = strftime('%Y-%m-%dT%H:%M:%fZ','now') WHERE code_hash = ?").bind(hash).run();
  const row = await env.DB.prepare("SELECT team_key FROM team_config WHERE id = 1").first();
  if (!row || !row.team_key) return fail(400, "Clé d'équipe non encore configurée par l'admin.", "P0001");
  return json(row.team_key);
}

export default {
  async fetch(request, env) {
    try {
      const url = new URL(request.url);
      const path = url.pathname.replace(/\/+$/, "");
      if (path === "" || path === "/") return json({ ok: true, service: "pegasus" });

      const m = path.match(/^\/(?:rest\/)?v1\/(.+)$/);
      if (!m) return fail(404, "Route inconnue.");

      if (!env.PEGASUS_SERVICE_TOKEN || !env.PEGASUS_PUBLIC_TOKEN) return fail(503, "Jetons du Worker non configurés.");
      const who = await role(request, env);
      if (!who) return fail(401, "Jeton manquant ou invalide.");

      const route = m[1];
      if (route === "rpc/get_team_key") {
        if (request.method !== "POST") return fail(405, "POST attendu.");
        return await getTeamKey(env, request);
      }

      const t = TABLES[route];
      if (!t) return fail(404, `Table inconnue : ${route}`);
      const params = url.searchParams;
      const method = request.method;

      if (method === "POST") {
        if (who !== "service" && !t.publicInsert) return fail(403, "Permission refusée.", "42501");
        return await insertRows(env, t, route, request, params, who);
      }
      if (who !== "service") return fail(403, "Permission refusée.", "42501");
      if (method === "GET" || method === "HEAD") return await readRows(env, t, route, params);
      if (method === "PATCH") return await updateRows(env, t, route, request, params);
      if (method === "DELETE") return await deleteRows(env, t, route, request, params);
      return fail(405, "Méthode non prise en charge.");
    } catch (e) {
      if (e instanceof HttpError) return fail(e.status, e.message);
      return fail(500, `Erreur du Worker : ${String((e && e.message) || e).slice(0, 300)}`);
    }
  },
};
