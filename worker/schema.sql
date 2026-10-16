-- ════════════════════════════════════════════════════════════════
-- PEGASUS — la base D1 « pegasus » (ré-exécutable)
-- Les six tables d'avant, à l'identique. Les droits ne sont plus des
-- politiques de la base : c'est le Worker qui les applique (src/index.js).
--   • jeton public (plugin WordPress, application de bureau) : AJOUTER une
--     ligne à `sites` ou à `reports`, et échanger un code d'accès contre la
--     clé d'équipe. Rien d'autre — aucune lecture.
--   • jeton de service (dans la clé d'équipe) : tout.
-- Les colonnes JSON (jsonb avant) sont du texte JSON ; les dates, du texte ISO.
-- ════════════════════════════════════════════════════════════════

-- Le registre des sites : append-only, mots de passe chiffrés RSA (base64).
create table if not exists sites (
  id               integer primary key autoincrement,
  site_url         text not null,
  username         text not null,
  app_password_enc text not null,
  label            text,
  created_at       text default (strftime('%Y-%m-%dT%H:%M:%fZ','now'))
);
create index if not exists sites_site_url_idx on sites (site_url);

-- Les rapports quotidiens (SEO / Performance / Sécurité / Audience), poussés par chaque site à 18 h.
create table if not exists reports (
  id         integer primary key autoincrement,
  site_url   text not null,
  day        text not null,
  seo        text,
  perf       text,
  secu       text,
  audience   text,
  created_at text not null default (strftime('%Y-%m-%dT%H:%M:%fZ','now'))
);
create index if not exists reports_site_day on reports (site_url, day desc);

-- La bibliothèque Orphic : références vivantes du design.
create table if not exists references_library (
  id          integer primary key autoincrement,
  kind        text not null default 'site',
  titre       text not null,
  url         text,
  niveau      text,
  technique   text,
  intention   text,
  registre    text,
  business    text,
  ingredients text,
  notes       text,
  statut      text not null default 'candidat',
  auteur      text,
  created_at  text not null default (strftime('%Y-%m-%dT%H:%M:%fZ','now'))
);
create index if not exists references_library_statut_idx on references_library (statut);
create index if not exists references_library_kind_idx   on references_library (kind);

-- Les sauvegardes de sites (filet de sécurité / retour arrière).
create table if not exists site_backups (
  id         integer primary key autoincrement,
  site_key   text not null,
  label      text,
  kind       text not null default 'manual',
  structure  text,
  content    text,
  home_html  text,
  note       text,
  created_at text not null default (strftime('%Y-%m-%dT%H:%M:%fZ','now'))
);
create index if not exists site_backups_key_idx on site_backups (site_key, created_at desc);

-- La clé d'équipe (un seul blob) — jamais lisible avec le jeton public.
create table if not exists team_config (
  id         integer primary key default 1,
  team_key   text not null,
  updated_at text default (strftime('%Y-%m-%dT%H:%M:%fZ','now'))
);

-- Les codes d'accès des collègues (on ne garde que le SHA-256 du code).
create table if not exists access_codes (
  code_hash    text primary key,
  label        text,
  revoked      integer default 0,
  created_at   text default (strftime('%Y-%m-%dT%H:%M:%fZ','now')),
  last_used_at text
);
