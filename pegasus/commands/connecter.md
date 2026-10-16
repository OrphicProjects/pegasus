---
description: Connecter un nouveau site WordPress client à Pegasus
---

Guide l'utilisateur, étape par étape, pour connecter un nouveau site WordPress client à Pegasus. Explique clairement, puis attends confirmation entre les étapes :

1. **Installer le plugin Pegasus sur le WordPress du client.** Le fichier zip est fourni avec ce plugin, à ce chemin :
   `${CLAUDE_PLUGIN_ROOT}/wordpress-plugin/pegasus.zip`
   Dans le wp-admin du client : *Extensions → Ajouter → Téléverser une extension → choisir ce zip → Installer → Activer*.
   (Rappelle à l'utilisateur qu'il faut un compte administrateur sur le WordPress du client.)

2. **Connecter le site.** Dans le wp-admin du client, ouvrir le menu **Pegasus** (barre latérale) et cliquer sur **« Connecter ce site à Claude »**. Le plugin crée automatiquement un mot de passe d'application, le chiffre, et l'enregistre dans le registre Pegasus d'Orphic (Cloudflare). Aucun copier-coller de mot de passe.

3. **Vérifier.** Une fois le bouton cliqué, appelle `pegasus_list_clients` : le nouveau site doit apparaître. Confirme à l'utilisateur que le site est connecté et propose un premier diagnostic avec `pegasus_diagnostic <clé>`.

Si le site n'apparaît pas après le clic, propose de vérifier : plugin bien activé, bouton bien cliqué (message de succès affiché), et que le site est en HTTPS (les mots de passe d'application WordPress exigent HTTPS).
