# Guide agent — Astro Web Indexer

## But du projet

Astro Web Indexer est une application web auto-hébergée qui indexe, affiche et organise des fichiers astronomiques FITS et XISF.

## Architecture

- `src/` : application PHP, interface web et API.
- `docker/python/` : indexeur et surveillance du système de fichiers.
- `docker/mariadb/` : configuration MariaDB.
- `docker/nginx/` : serveur web et contrôle d'accès aux fichiers.
- `src/db/migrations/` : migrations Phinx.
- `src/assets/` : JavaScript, CSS Tailwind et images.
- `external/xisf/` : bibliothèque XISF externe, gérée comme sous-module Git.

Flux principal : fichiers FITS/XISF → indexeur Python → MariaDB → interface PHP.

## Commandes utiles

```bash
npm install
npm run build
./build.sh build
./build.sh start
./build.sh stop
./build.sh logs
```

Réindexation manuelle :

```bash
docker exec -it awi-python python /opt/scripts/reindex.py /var/fits --force
```

## Règles de travail

- Ne jamais modifier les fichiers FITS/XISF de l'utilisateur.
- Préserver les modifications locales déjà présentes.
- Ne pas modifier le sous-module `external/xisf` sans demande explicite.
- Toute modification du schéma doit passer par une migration Phinx.
- Une nouvelle métadonnée indexée doit aussi mettre à jour le mécanisme de version de schéma dans `docker/python/indexer_lib/schema_upgrade.py`.
- Appliquer les permissions par dossier à toute nouvelle requête qui lit ou modifie un fichier.
- Utiliser des requêtes préparées et une liste blanche pour les noms de colonnes SQL dynamiques.
- Protéger les opérations d'écriture contre les requêtes CSRF.
- Ne jamais exposer de mot de passe, secret ou message SQL détaillé dans une réponse HTTP.
- Garder les volumes contenant les images en lecture seule.

## Points sensibles connus

- Docker fournit `DB_PASSWORD`, tandis que l'indexeur et le watcher Python lisent actuellement `DB_PASS`.
- Les routes AstroBin et Smart Frame Finder ne vérifient pas toutes les permissions du fichier de référence.
- `api/update_visibility.php` doit vérifier que chaque identifiant appartient aux dossiers autorisés et correspond au hash attendu.
- `auth_check.php` doit travailler sur un chemin canonique et refuser explicitement les traversées `..`.
- `src/includes/table.php` contient deux implémentations de la gestion des doublons.
- Les listes SQL chargent actuellement les BLOB `thumb` et `thumb_crop` via `files.*`, même lorsque seul leur état est nécessaire.

## Vérifications avant livraison

Selon la partie modifiée :

```bash
find src -name '*.php' -type f -print0 | xargs -0 -n1 php -l
python3 -c 'import ast,pathlib; [ast.parse(p.read_text(), filename=str(p)) for p in pathlib.Path("docker/python").rglob("*.py")]'
node --check src/assets/js/main.js
node --check src/assets/js/sff.js
bash -n build.sh docker/php/docker-entrypoint.sh
docker compose config --quiet
```

Faire ensuite un test manuel des fonctions concernées avec `AUTH_MODE=full`, notamment avec un utilisateur limité à un seul dossier.

## Critères de fin

- La fonctionnalité demandée est vérifiée.
- Les contrôles d'accès restent appliqués côté serveur.
- Les migrations fonctionnent sur une base existante et sur une base vide.
- Aucun secret, fichier de données ou artefact généré n'est ajouté au dépôt.
- La documentation est mise à jour si le comportement utilisateur change.
