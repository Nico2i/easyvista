# Calipso

Portail agents de la DSN, branché sur EasyVista Service Manager.
L'agent **décrit son besoin avec ses mots** (« mon imprimante n'imprime plus », « un stagiaire arrive lundi ») et Calipso l'oriente vers le bon formulaire : **incident** ou **demande de service**, sans qu'il ait à connaître la différence.

Application Symfony 7.4 (PHP ≥ 8.2), construite à partir de la maquette validée (`docs/maquette`).

---

## 1. Démarrage rapide (mode démonstration)

Le mode démonstration (`EASYVISTA_MODE=mock`, valeur par défaut) n'appelle jamais EasyVista : les demandes créées sont stockées dans `var/mock/tickets.json`.

### Avec Docker (PHP 8.4 + Apache)

```powershell
docker compose up -d --build
```

Ouvrir http://localhost:8000. Le premier démarrage installe les dépendances Composer (une à deux minutes) ; `docker compose logs -f` permet de suivre l'avancement.
Pour un autre port : `$env:CALIPSO_PORT=8080` avant la commande.

Les sources sont montées dans le conteneur : une modification est visible immédiatement. Les fichiers `.env.local` du projet sont lus normalement.

```powershell
docker compose exec app php bin/phpunit
```

```powershell
docker compose exec app php bin/console calipso:easyvista:check votre.login
```

```powershell
docker compose down
```

### Sans Docker

Prérequis : PHP 8.2+ (extensions `ctype`, `iconv`, `mbstring`, `json`; `intl` conseillée), [Composer](https://getcomposer.org).

```powershell
composer install
php -S localhost:8000 -t public
```

Ouvrir http://localhost:8000. En développement, l'agent connecté est `CALIPSO_DEV_LOGIN` (fichier `.env.dev`).
Si la [CLI Symfony](https://symfony.com/download) est installée, `symfony serve` fonctionne aussi.

Lancer les tests :

```powershell
php bin/phpunit
```

---

## 2. Mise en service avec EasyVista

### 2.1 Configurer l'accès à l'API

Créer un fichier **`.env.local`** (jamais versionné) :

```dotenv
EASYVISTA_MODE=api
EASYVISTA_BASE_URI=https://<serveur-easyvista>/api/v1/<compte>/
# Soit un jeton…
EASYVISTA_TOKEN=xxxxxxxx
# …soit un compte de service (Basic)
EASYVISTA_LOGIN=svc_calipso
EASYVISTA_PASSWORD=********
# Certificat interne non reconnu par PHP ? (à éviter en production)
# EASYVISTA_VERIFY_SSL=0
# Votre propre login Windows pour tester en local
CALIPSO_DEV_LOGIN=votre.login
```

### 2.2 Vérifier la connexion (lecture seule, ne crée rien)

```powershell
php bin/console calipso:easyvista:check votre.login
```

La commande affiche :
- les **droits du compte API** endpoint par endpoint (`GET /employees`, `/requests`, `/catalog-requests`, `/urgencies`). Un profil EasyVista peut autoriser certains endpoints et en refuser d'autres : ils répondent alors « 403 Unauthorized method for your profile ». Seul `GET /employees` est indispensable pour ouvrir le portail ;
- l'agent trouvé, ses dernières demandes et les codes catalogue retrouvés pour quelques services.

Les endpoints d'écriture (`POST /requests`, `/documents`, `/actions`) ne sont pas testés, pour ne rien créer : vérifiez avec l'administrateur EasyVista que le profil du compte API les autorise. Le détail des erreurs HTTP est dans `var/log/dev.log`.

### 2.3 Récupérer les codes catalogue

`POST /requests` attend un **`Catalog_Code`**. Calipso le retrouve dans cet ordre :

1. colonne code de l'export CSV (option `--colonne-code` de l'import, voir § 4) ;
2. table `data/catalog-codes.json`, produite par :
   ```powershell
   php bin/console calipso:catalogue:sync-codes
   ```
3. à défaut, une recherche `GET /catalog-requests` à la volée (mise en cache 24 h).

### 2.4 Points à vérifier sur votre instance

Tous réglables dans **`config/calipso/easyvista.yaml`** (repérés « À VÉRIFIER ») :

| Réglage | Rôle | Valeur par défaut |
|---|---|---|
| `champs_identifiant` | Champ(s) de la fiche agent comparés au login Windows, essayés dans l'ordre (`login`, `identification`, `e_mail`…). Le login est essayé sans domaine puis tel que reçu (`DOMAINE\login`) | `[login]` |
| `code_incident_defaut` / `EASYVISTA_DEFAULT_INCIDENT_CODE` | Code du « Sujet par défaut » (signalement sans sujet) | recherche par chemin `Incidents/IT/Sujet par défaut` |
| `code_demande_generique` / `EASYVISTA_GENERIC_REQUEST_CODE` | Code de la « Demande générique » | recherche par intitulé |
| `urgences` | Gêne exprimée → `Urgency_ID` (voir `GET /urgencies`) | 1 / 2 / 3 |
| `envoyer_demandeur` | Envoie `Requestor_Mail` / `Requestor_Name` pour que l'agent (et non le compte API) soit demandeur | `true` |
| `recherche_mes_demandes` | Filtre `search` de « Mes demandes » | `requestor.e_mail:"{email}"` |
| `type_action_message` / `EASYVISTA_MESSAGE_ACTION_TYPE_ID` | Type d'action pour « Écrire aux intervenants » (vide = bouton masqué) | vide |
| `statuts` | Libellés de statut → pastille (attente / en cours / terminé) | expressions régulières |

Autre point à valider : le format d'envoi des pièces jointes (`POST /requests/{rfc}/documents`) n'est pas détaillé dans le swagger ; Calipso envoie `{"documents":[{"filename","filedata"(base64)}]}`. En cas d'échec, la demande est quand même créée et l'agent est prévenu.

Un test de bout en bout conseillé : créer un incident et une demande depuis le portail, puis vérifier dans EasyVista le demandeur, le bénéficiaire, l'urgence, la description et le lancement du workflow.

---

## 3. Déploiement

### Identification des agents (SSO)

Calipso n'a **pas de formulaire de connexion** : le serveur web authentifie l'agent (authentification Windows / Kerberos) et transmet son login dans une variable serveur, lue par `src/Security/PortalAuthenticator.php`.

- **IIS** : activer l'authentification Windows, désactiver l'anonyme. PHP reçoit `REMOTE_USER` (ou `AUTH_USER`, à indiquer dans `CALIPSO_SSO_SERVER_VAR`).
- **Apache** : `mod_auth_gssapi` / `mod_auth_kerb` renseignent `REMOTE_USER`.
- **Reverse proxy** : transmettre un en-tête, par exemple `X-Remote-User`, et régler `CALIPSO_SSO_SERVER_VAR=HTTP_X_REMOTE_USER` (n'accepter cet en-tête que depuis le proxy).

Les formes `DOMAINE\login` et `login@domaine` sont acceptées. L'agent est ensuite cherché dans EasyVista (`GET /employees?search=login:"…"`), une seule fois par session.
**En production, `CALIPSO_DEV_LOGIN` doit rester vide.**

### Mise en production

Dans `.env.local` du serveur : `APP_ENV=prod`, un `APP_SECRET` aléatoire (32 caractères) et la configuration EasyVista du § 2.1, sans `CALIPSO_DEV_LOGIN`.

```powershell
composer install --no-dev --optimize-autoloader
composer dump-env prod
php bin/console cache:clear --env=prod
```

La racine web est le dossier `public/`. Le dossier `var/` doit être accessible en écriture.

### Image Docker de production

La cible `prod` du `Dockerfile` produit une image autonome (sans outils de développement, OPcache avec préchargement) :

```powershell
docker build --target prod -t calipso:prod .
```

```powershell
docker run -d -p 8080:80 --env-file calipso.env calipso:prod
```

`calipso.env` (non versionné) contient `APP_SECRET`, `EASYVISTA_MODE=api`, `EASYVISTA_BASE_URI`, le jeton ou le compte, et `CALIPSO_SSO_SERVER_VAR`. Les variables `APP_*`, `EASYVISTA_*` et `CALIPSO_*` du conteneur sont transmises à PHP par `docker/entrypoint.sh`.

L'authentification Windows (Kerberos) n'est pas configurée dans l'image : placer devant le conteneur un serveur qui authentifie l'agent (IIS, Apache, reverse proxy de l'entreprise) et transmet le login dans l'en-tête `X-Remote-User`, puis régler `CALIPSO_SSO_SERVER_VAR=HTTP_X_REMOTE_USER`. Dans ce cas, le conteneur ne doit être joignable que par ce serveur, sinon l'en-tête pourrait être forgé.

---

## 4. Faire vivre le portail

| Je veux… | Fichier |
|---|---|
| Enrichir le **dictionnaire de besoins** (formulations des agents → sujets / services) | `config/calipso/besoins.yaml` |
| Modifier les expressions « ça ne marche plus » / « nouveau besoin » | `config/calipso/besoins.yaml` (`ton_probleme`, `ton_besoin`) |
| Changer les rubriques, les tuiles de l'accueil, les conseils avant envoi | `config/calipso/rubriques.yaml` |
| Changer la hotline, les services fréquents, le pied de page | `config/calipso/portail.yaml` |
| Changer couleurs et polices | `public/css/calipso.css` (`:root`) |

Après chaque modification d'un fichier de `config/` en production : `php bin/console cache:clear`.
La page **Comment ça marche** (`/comment-ca-marche`) montre comment des phrases types sont orientées : pratique pour contrôler l'effet d'un changement du dictionnaire.

### Mettre à jour le catalogue

À partir des exports CSV EasyVista (séparateur `;`, UTF-8), les mêmes que pour la maquette :

```powershell
php bin/console calipso:catalogue:import "Catalogue des incidents.csv" "Catalogue des demandes de service.csv"
```

Ajouter `--colonne-code=N` si l'export contient le code catalogue (colonne N, en partant de 0). La commande régénère `data/catalogue.json`.

---

## 5. Organisation du code

```
config/calipso/          Réglages métier (dictionnaire, rubriques, EasyVista, portail)
data/catalogue.json      Catalogue compacté (sujets d'incident, services, ressources d'accès)
src/Orientation/         Moteur d'orientation : phrase de l'agent → incident / demande / accès
src/Catalog/             Lecture du catalogue
src/EasyVista/           Client API REST (HttpEasyVistaClient), client de démonstration (Mock…), codes catalogue
src/Service/             Construction et envoi des incidents / demandes
src/Security/            SSO (REMOTE_USER) et chargement de l'agent depuis EasyVista
src/Controller/          Pages du portail
src/Command/             import du catalogue, synchronisation des codes, vérification de l'API
templates/ public/       Interface (Twig, CSS et JavaScript sans dépendance)
tests/                   Tests (dont la parité avec le moteur de la maquette)
docs/                    Maquette d'origine et swagger EasyVista
Dockerfile, compose.yaml PHP 8.4 + Apache (cibles dev et prod), réglages dans docker/
```

Le moteur d'orientation est un portage fidèle de celui de la maquette : `tests/Fixtures/orientation-reference.json` contient les résultats du moteur JavaScript d'origine et `tests/Orientation/OrienterTest.php` vérifie que la version PHP donne exactement les mêmes.

## 6. Pistes

- « À traiter » (approbations, actions) : l'API REST ne les expose pas directement. En mode API, la page renvoie vers EasyVista (`CALIPSO_LEGACY_URL`) ; les compteurs ne s'affichent qu'en démonstration.
- Formulaires propres à certains services (questionnaires EasyVista) : aujourd'hui saisis dans « Précisions utiles ». Les endpoints `/questionnaires` et `/questions` permettraient de les générer.
- Annulation d'une demande par l'agent (`PUT /requests/{rfc}/close`) : à cadrer avec les règles de gestion.
