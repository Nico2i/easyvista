# Prototype Calipso

Maquette cliquable du portail Calipso refondu (aucune demande n'est envoyée à EasyVista).

## Ouvrir
Double-cliquez sur `index.html` : la page fonctionne hors ligne (seules les polices viennent de Google Fonts ; sans accès internet, des polices système prennent le relais).

## Contenu
- `index.html` : la page complète, données incluses.
- `src/template.html` : le code (HTML, CSS, JavaScript) avec l'emplacement `__DATA__` où sont injectées les données.
- `src/data.json` : les catalogues compactés (sujets d'incident, services, ressources d'accès).
- `src/build_data.py` : régénère `data.json` puis `index.html` à partir des exports CSV EasyVista.

## Mettre à jour les catalogues
Depuis le dossier `src` :

    python build_data.py "Catalogue des incidents.csv" "Catalogue des demandes de service.csv"

Les CSV attendus sont ceux exportés d'EasyVista (séparateur `;`, UTF-8).

## Où modifier quoi (dans src/template.html)
- `SYN` : le dictionnaire de besoins (formulations des agents vers les sujets et services du catalogue). C'est la partie à enrichir en priorité.
- `PROB` / `NEED` : les expressions qui font pencher vers « ça ne fonctionne plus » ou « nouveau besoin ».
- `FAM` : les rubriques du catalogue (le classement des services est fait par `fam()` dans `build_data.py`).
- `:root` en tête du CSS : les couleurs et polices.
