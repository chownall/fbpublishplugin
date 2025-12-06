=== FB Publish Plugin ===
Contributors: your-name
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: * Version: 1.0.1
License: GPLv2 or later

Publie automatiquement le lien de l’article sur une Page Facebook quand un article passe de brouillon à publié (y compris planification). Inclut une méta box permettant de publier manuellement avec un message personnalisé.

== Installation ==
1. Uploadez le dossier du plugin dans `wp-content/plugins`.
2. Activez le plugin depuis l’admin WordPress.
3. Rendez-vous dans `Réglages > Publication Facebook` pour renseigner le jeton de Page, l’ID de Page et le message par défaut.

== Utilisation ==
- Dans l’éditeur d’article, utilisez la méta box "Publication Facebook" pour cocher la Page, écrire un message personnalisé et déclencher une publication manuelle.
- Lors de la publication (ou publication planifiée), le lien est posté automatiquement si l’option Page est cochée.

== Permissions Facebook requises ==
- Pour poster sur une Page: `pages_manage_posts`, `pages_read_engagement` et un jeton de Page.

== Notes ==
- Utilise `wp_remote_post` vers l'API Graph v20.0 (`/{page_id}/feed`).
- Déduplication simple via metas de post: évite la double publication.
- Encodage: les apostrophes HTML (`&rsquo;`) sont normalisées en `'` avant envoi.
- Planification: le partage auto est différé de ~20s après la mise en ligne planifiée afin de laisser le temps à Facebook de récupérer les métadonnées Open Graph (image, titre, description).
- Option: vous pouvez forcer la republication par défaut pour les articles planifiés (ignorer la déduplication si l'article avait déjà été partagé).

== Génération de message IA (OpenAI) ==
- Configurez votre clé API OpenAI dans Réglages > Publication Facebook > Génération de message IA.
- Cochez "Activer l'accroche IA" pour générer automatiquement un message d'accroche percutant basé sur le titre et l'extrait de l'article.
- Le message généré est optimisé pour le copywriting Facebook (curiosité, appel à l'action, pas de spoiler).
- Vous pouvez activer/désactiver l'accroche IA par article dans la méta box.
- Les accroches générées sont affichées dans la méta box pour diagnostic.


