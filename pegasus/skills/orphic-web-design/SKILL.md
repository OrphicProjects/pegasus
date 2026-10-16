---
name: orphic-web-design
description: "Constitution design web de l'agence Orphic (Monaco). À utiliser SYSTÉMATIQUEMENT pour toute création, maquette, refonte, critique ou direction artistique de site web, landing page, hero, section ou composant web — en WordPress/Pegasus, Next.js, Framer, Webflow ou React. Couvre le choix du niveau technique (flat, shaders, 3D, WebGL), la palette, la typographie, les animations et la validation anti-template. Déclencher dès que la conversation mentionne site web, site internet, maquette, webdesign, DA, direction artistique, landing, hero, section, composant, animation web, WebGL, Three.js, shader, Pegasus, ou un projet client web — même si le mot design n'est pas prononcé."
---

# Orphic Web Design — Skill racine

Ce skill est la **constitution** du design web d'Orphic. Tous les autres skills web
(motion, 3D, perf, SEO…) s'y réfèrent. En cas de conflit entre skills, ce document gagne.

Orphic est une agence 360° basée à Monaco ("Beyond Understanding"), avec une exigence
de niveau Awwwards. Les clients sont exigeants : chaque livrable doit viser
le meilleur résultat possible, pas le plus rapide à produire.

---

## 1. La règle-mère

> Un site Orphic doit être **animé avec intention, singulier, user-friendly et rapide**
> — les quatre EN MÊME TEMPS, jamais l'un au détriment d'un autre.

Ces quatre exigences sont en tension permanente. Le travail de design consiste à
les résoudre ensemble, pas à en sacrifier une :

- **Animé** pousse vers plus d'effets → mais **rapide** impose un budget strict.
  Résolution : chaque animation doit avoir une intention (révéler, guider, donner
  de la matière). Zéro décoration gratuite.
- **Singulier** pousse vers l'audace → mais **user-friendly** impose la lisibilité.
  Résolution : l'audace se loge dans la matière, le concept et le détail, jamais
  dans le sabotage de la navigation ou du contraste.

## 2. Les 4 interdits absolus

1. **Jamais de site sans animation.** Le mouvement fait partie du design.
   Un site statique n'est pas un site Orphic.
2. **Jamais un site qui ressemble aux autres.** Chaque projet doit contenir au
   moins une idée que personne d'autre n'a. Ça vaut aussi pour la signature
   Orphic elle-même : dark+chrome+glow appliqué mécaniquement = template.
3. **Jamais un site pas user-friendly.** Contrastes lisibles (viser AA), cibles
   tactiles ≥ 44px, navigation évidente, états de focus visibles,
   `prefers-reduced-motion` respecté. Le beau ne justifie jamais l'inutilisable.
4. **Jamais un site lent.** Budgets : LCP < 2,5 s, INP < 200 ms, CLS < 0,1.
   Si la 3D fait exploser le budget, on descend d'un niveau (voir §3) —
   souvent N4→N3 (temps réel → pré-rendu) résout tout. On ne livre pas lent.

## 3. Choisir le niveau (offre à 4 paliers)

Principe directeur (Akaru) : *« Maîtriser des techniques à l'avant-garde pour
pouvoir tout imaginer, mais utiliser uniquement celles que le projet justifie,
sans démonstration de force. »*

L'échelle est à la fois **technique et commerciale** : chaque palier ajoute une
compétence et du temps, donc un prix. La frontière tarifaire majeure est
**"Blender ou pas Blender"** (dès qu'on modélise, saut de compétence + de coût).

| Niveau | Nom (offre) | 3D ? | Blender ? | Compétence ajoutée | Références |
|---|---|---|---|---|---|
| **1** | **Premium** | Non | Non | Motion web (GSAP, Lenis, CSS 3D léger) | Kalinsky, Locomotive, Editorial New |
| **2** | **Luxe** | 3D perçue | Non | Shaders GLSL / WebGL 2D | Ryan Ritzenthaler, Lusion, Akaru, **Emotions Arts** |
| **3** | **Luxe supérieur** | 3D réelle pré-rendue | Oui | 3D pré-rendu (Blender → séquence → scrub) | Apple scroll-scrub, packshots Orphic animés |
| **4** | **Ultra luxe** | 3D temps réel | Oui | Portage temps réel + optimisation (glTF, budgets) | Igloo, Active Theory, Orphic Production |

**Ce que chaque niveau recouvre :**
- **N1 Premium** — Motion pur, zéro WebGL. GSAP/ScrollTrigger/Lenis/SplitType/
  Barba, micro-interactions, CSS 3D (Atropos, perspective, preserve-3d).
  Rapide, imbattable en perf, accessible.
- **N2 Luxe** — Effet 3D/wow avec du code + assets existants, sans modélisation :
  shaders sur surfaces 2D (Curtains.js, PixiJS, Paper Shaders), displacement au
  hover, parallax multi-couches, CSS 3D avancé. **Sweet spot** : spectaculaire,
  marge excellente, perf préservée.
- **N3 Luxe supérieur** — On ouvre Blender (→ prix). Objet modélisé/rendu, exporté
  en **vidéo ou séquence d'images**, scrubé au scroll. Rendu parfait
  (ray-tracing, matière), runtime léger. Parcours **figé**, pas d'interaction libre.
- **N4 Ultra luxe** — Vraie scène temps réel : Blender → glTF → R3F/Three, caméra
  pilotée, shaders custom, post-processing, **orbite/interaction libre**.
  Le plus cher, le plus long.

**Les deux frontières qui décident (et qui justifient le prix au client) :**
1. **N2 → N3** : *« Faut-il une matière ou un objet sur-mesure impossible sans
   modélisation ? »* Si oui → Blender → N3.
2. **N3 → N4** : *« L'utilisateur doit-il manipuler l'objet librement ? »*
   Non (dévoilement au scroll suffit) → **N3 pré-rendu**, souvent PLUS BEAU et
   MOINS CHER. Oui (orbite libre) → **N4 temps réel**.

⚠️ Ne pas survendre le N4 : un N3 pré-rendu bat souvent un N4 en qualité de
rendu pure, pour moins cher. Le temps réel ne se justifie que si
l'interactivité libre est un vrai besoin.

En cas de doute entre deux niveaux : prendre le plus bas et l'exécuter
parfaitement. Un niveau bien exécuté bat un niveau au-dessus bâclé.

## 4. Axe d'intention

En plus du niveau technique, qualifier l'intention — elle change les règles :

- **Vitrine / portfolio** (montrer un savoir-faire) : liberté artistique maximale.
- **Produit / e-commerce** (vendre) : l'immersion ne doit JAMAIS masquer le chemin
  d'achat. CTA, panier, fiche produit restent accessibles en permanence
  (leçon Organimo). Un site magnifique qui ne vend pas est un échec.
- **Univers / récit** (faire ressentir une identité) : le storytelling structure
  tout — loader qui pose le ton, transitions qui racontent, détails qui
  incarnent (leçon Michael Gatt).

## 5. La signature Orphic

**La méthode est constante, le registre s'adapte au client.**

Méthode (toujours) :
- Palette **2 couleurs** par défaut ; une 3e seulement si justifiée.
- **Un seul point focal fort** par écran. Retenue partout ailleurs.
- **La matière avant la couleur** : ce qui rend un site premium, c'est la
  qualité des surfaces (grain, verre, métal, lumière), pas le nombre de teintes.
- Typo : hiérarchie nette, souvent fine et aérée, espace négatif généreux.
- Chaque projet contient **une idée signature** que personne d'autre n'a.

**Aucun registre par défaut.** La signature est une MÉTHODE, pas un style.
Les registres sont à égalité, choisis au cadrage selon le client (voir §7) :
**sombre-dramatique** (chrome/verre/or sur noir — le goût personnel de Sacha
et l'univers Blender/packshots d'Orphic Production), **clair-épuré**
(Kalinsky), **chaleureux-ludique** (Trionn), **clair-conversion**
(Emotions Arts). Détail complet dans `references/aesthetic.md`.

La signature est une voix, pas un uniforme — l'interdit #2 s'applique aussi
à Orphic lui-même : appliquer mécaniquement le registre sombre partout
serait notre propre template.

## 6. Références = des ingrédients, jamais des modèles

Une référence fournit des **ingrédients à extraire et recombiner** —
registre, niveau, palette, matière, pattern d'animation, intention — jamais
un modèle à copier. Trop ressembler à une réf est un échec (interdit #2),
même si la réf plaisait au client. C'est aussi la réponse au N4 ultra-luxe
sans réf parfaite : croiser des refs partielles (la matière d'Igloo +
l'ambiance d'une joaillerie N2 + un pattern d'interaction d'ailleurs) —
l'absence de modèle garantit l'originalité.

Les refs fournies par le client sont **optionnelles** : le skill fonctionne
sans, mais les exploite comme ingrédients si elles existent. Banque de
départ : `references/sites.md` et `references/secteurs.md` ; banque
vivante : la bibliothèque Orphic (§10).

## 7. Cadrage et workflow — qui juge quoi

Un projet ne se définit pas par un style : il se définit par le croisement
**business/secteur × niveau (N1-N4) × registre**. Les trois axes sont
indépendants — un yachting peut être clair-épuré N2, un restaurant
sombre-dramatique N1 (`references/secteurs.md` donne les ingrédients par
secteur).

Règle de gouvernance : **Sacha juge le goût, la machine mesure le
mesurable.** Ne jamais inverser.

- **Étape 1 — Stratégie & arborescence (le QUOI).** Client type (business,
  catégorie, niveau de luxe visé) + brief overall (objectifs,
  fonctionnalités) + refs client si fournies. On construit l'arborescence
  ensemble → validation client. AUCUN design ici.
- **Étape 2 — Conception (le COMMENT).** Brief design + niveau
  **réalisable** (budget × temps). Le niveau visé en étape 1 n'est pas
  forcément le niveau réalisable : l'offre 4 paliers (§3) sert d'outil de
  négociation. Puis recherche de DA — boucle créative, Sacha juge, itérer
  TANT QUE la DA ne convient pas, jamais d'autonomie ici.
- **Production.** Raffinement section par section, composant par composant
  (Sacha juge toujours) → check général → **optimisation** page par page
  (vitesse, SEO) — seule phase où un agent est autorisé, sous zones
  protégées (§8).

## 8. Zones protégées (pour toute optimisation automatisée)

Quand un agent ou une passe d'optimisation intervient (phase d'optimisation,
fin de production), la DA validée est **intouchable** :

- Ne pas supprimer/simplifier une animation validée ; proposer une alternative
  au même effet visuel si elle coûte trop cher, et demander validation.
- Ne pas compresser une image/texture au point de tuer la matière.
- Ne pas remplacer une police, une couleur, un easing validés.
- Optimiser AUTOUR de la DA (code, chargement, ordre, cache, formats),
  jamais DEDANS sans validation humaine.

## 9. Boucle de critique obligatoire

Aucune maquette ne part sans au moins **une passe de critique** contre ce skill.
Protocole complet dans `references/critique.md`. Version courte : **faits
d'abord (scripts), jugement ensuite (grilles)** → corriger → re-livrer. 2-3 passes.

Principe de division du travail : **le code pour les faits, le langage pour le
jugement.** Tout ce qui peut se vérifier par un `if` passe par `scripts/`
(déterministe, fiable, pas de variance). Le LLM ne se prononce que sur ce que
lui seul sait évaluer : singularité, intention, matière, goût.

| Script | Vérifie | Quand |
|---|---|---|
| `scripts/check_contrast.py fg bg [--large]` | Ratio WCAG AA/AAA (interdit #3) | Chaque couple texte/fond de la maquette |
| `scripts/audit_page.py URL` | SEO de base, nb polices, couleurs CSS, reduced-motion, alt, libs, poids | Check général et avant toute livraison d'une page en ligne |
| `scripts/audit_refs.py URL1 URL2… [--file liste.txt]` | Audit groupé de refs : agrège audit_page (libs, polices, palettes) pour comparer | Constitution d'un dossier de refs ; veille |
| `scripts/check_perf_budget.py URL [mobile\|desktop]` | LCP/CLS/INP réels vs budgets (interdit #4), via PageSpeed | Phase d'optimisation ; lent (20-60 s), lancer en fin de passe |
| `scripts/check_glb.py fichier.glb [--hero]` | Taille, triangles, Draco vs budgets 3D | Chaque export Blender→web (N3 pré-rendu, N4 temps réel) |

## 10. Bibliothèque Orphic & protocole d'évolution

Ce skill porte la **méthode** (stable). Les **données vivantes** — références,
animations validées, fiches secteurs — vivent dans la bibliothèque Orphic
(service Pegasus sur Cloudflare, via les outils MCP Pegasus), enrichie au quotidien par la veille
de l'équipe :

- `pegasus_get_references` — chercher (filtres : kind, niveau, registre,
  business, intention, texte libre). À interroger au cadrage et en recherche
  de DA, en complément de `references/sites.md` et `secteurs.md`.
- `pegasus_add_reference` — proposer (statut `candidat` par défaut).
- `pegasus_validate_reference` — passer en `valide` : décision humaine
  explicite UNIQUEMENT. Ne jamais valider de sa propre initiative.

Flux : **proposer (candidat) → valider (humain) → enregistré (base) →
vivant pour toute l'agence.**

Quand un secteur, une référence ou une animation manque :
1. Ne pas bloquer.
2. Construire les ingrédients à la volée (analyse + recherche web si besoin).
3. S'en servir pour le projet en cours.
4. Proposer de l'enregistrer en candidat dans la bibliothèque (ou dans les
   .md du skill si Pegasus est indisponible).
5. Validation humaine → disponible pour toute l'agence.

Le skill démarre incomplet et devient complet **par l'usage**.

## 11. Annexes — quand lire quoi

| Fichier | Lire quand |
|---|---|
| `references/aesthetic.md` | Définir ou évaluer une DA, choisir registre/palette/matière/typo |
| `references/sites.md` | Chercher une référence, positionner un projet, argumenter un choix |
| `references/secteurs.md` | Cadrer un projet pour un secteur Monaco/Riviera ; ingrédients métier |
| `references/stack.md` | Choisir une lib d'animation/3D, arbitrer une techno |
| `references/3d-pipeline.md` | Tout projet N2-N4 : 3D perçue, pré-rendu, temps réel, shaders, budgets |
| `references/critique.md` | Avant CHAQUE livraison de maquette ; construire un prompt de critique |
