/**
 * Les accès de l'admin Pegasus — lus sur le Mac de l'admin, jamais dans le dépôt.
 *   • ~/.config/orphic/pegasus.local.json : { url, jeton_service, jeton_public } (le service Pegasus, Worker Cloudflare)
 *     — ou les variables PEGASUS_API_URL et PEGASUS_API_TOKEN ;
 *   • la clé privée de déchiffrement : PEGASUS_PRIVATE_KEY_FILE, sinon ../mcp-server/pegasus-private.pem,
 *     sinon celle de la clé d'équipe déjà installée (~/.pegasus/team-key).
 */
import { readFileSync, existsSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";
import { homedir } from "node:os";

const dir = dirname(fileURLToPath(import.meta.url));

export function acces() {
  let cfg = {};
  const f = join(homedir(), ".config", "orphic", "pegasus.local.json");
  if (existsSync(f)) cfg = JSON.parse(readFileSync(f, "utf8"));
  const url = (process.env.PEGASUS_API_URL || cfg.url || "").replace(/\/$/, "");
  const token = process.env.PEGASUS_API_TOKEN || cfg.jeton_service;
  if (!url || !token) throw new Error(`Accès au service Pegasus introuvable (${f}, champs url et jeton_service).`);
  return { url, token };
}

export function clePrivee() {
  const candidats = [process.env.PEGASUS_PRIVATE_KEY_FILE, join(dir, "..", "mcp-server", "pegasus-private.pem")].filter(Boolean);
  for (const c of candidats) if (existsSync(c)) return readFileSync(c, "utf8");
  const tk = join(homedir(), ".pegasus", "team-key");
  if (existsSync(tk)) {
    const t = JSON.parse(Buffer.from(readFileSync(tk, "utf8").trim(), "base64").toString("utf8"));
    if (t.private_key) return t.private_key;
  }
  throw new Error("Clé privée de déchiffrement introuvable.");
}

/** La clé d'équipe = base64( JSON { api_url, api_token, private_key } ). */
export function cleEquipe() {
  const { url, token } = acces();
  return Buffer.from(JSON.stringify({ api_url: url, api_token: token, private_key: clePrivee() })).toString("base64");
}
