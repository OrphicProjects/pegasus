#!/usr/bin/env node
/**
 * Génère la « clé d'équipe » Pegasus à partager avec les devs (via 1Password).
 * = base64( JSON { api_url, api_token, private_key } ) — le service Pegasus (Worker Cloudflare),
 *   son jeton de service, et la clé privée de déchiffrement des mots de passe des sites.
 *
 * Lit les secrets LOCAUX (jamais versionnés) — voir scripts/_acces.mjs.
 * Réservé à l'administrateur Pegasus (Sacha), qui détient ces fichiers.
 *
 * Usage : node scripts/make-team-key.mjs
 */
import { cleEquipe } from "./_acces.mjs";

try {
  const key = cleEquipe();
  console.log("\n===== CLÉ D'ÉQUIPE PEGASUS (à coller à l'installation du plugin) =====\n");
  console.log(key);
  console.log(`\n(${key.length} caractères — partager via 1Password, JAMAIS dans un dépôt ou un chat)\n`);
} catch (e) {
  console.error("Erreur : impossible de lire les secrets locaux (" + e.message + ").");
  process.exit(1);
}
