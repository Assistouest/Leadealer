<div align="center">

# Leadealer

**Un constructeur de formulaires WordPress pensé pour ne pas perdre un prospect parce qu’un e-mail n’est jamais arrivé.**

<p>
  <img src="https://img.shields.io/badge/version-0.9.0-0069e2" alt="Version 0.9.0">
  <img src="https://img.shields.io/badge/WordPress-6.6%2B-21759B?logo=wordpress&logoColor=white" alt="WordPress 6.6 ou supérieur">
  <img src="https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php&logoColor=white" alt="PHP 7.4 ou supérieur">
  <img src="https://img.shields.io/badge/licence-GPL--2.0--or--later-green" alt="Licence GPL-2.0-or-later">
</p>

</div>

---

Un formulaire de contact peut fonctionner parfaitement côté visiteur et pourtant perdre sa valeur au dernier moment. Le client remplit ses informations, clique sur Envoyer et voit apparaître un message de confirmation. De votre côté, une erreur SMTP, un problème temporaire avec le serveur de messagerie ou une mauvaise configuration peut suffire à empêcher l’e-mail d’arriver.

Dans beaucoup de formulaires WordPress, cet e-mail constitue la seule trace de la demande.

**Leadealer conserve la demande avant de l'envoyer.**

Lorsqu’une soumission est valide, la demande est d’abord enregistrée dans **Lead Vault**. L’e-mail est envoyé ensuite. La demande client existe donc indépendamment de sa livraison dans votre boîte de réception.

> L’e-mail est un canal de livraison.

## Vos demandes sont enregistrées avant l’envoi de l’e-mail

Lead Vault est le cœur de Leadealer. Une demande acceptée y est enregistrée avant toute tentative d’envoi par e-mail. Si `wp_mail()` ou le système SMTP rencontre un problème, le prospect ne disparaît pas avec l’échec de la notification.

Leadealer conserve les informations nécessaires pour comprendre ce qui s’est passé et reprendre la livraison. Vous pouvez retrouver les données envoyées par le visiteur, le destinataire, le sujet et le contenu de la notification, les éventuelles photos ainsi que l’historique des tentatives d’envoi.

Lorsqu’une notification échoue, Leadealer peut effectuer de nouvelles tentatives automatiquement. Un worker de récupération et un watchdog surveillent également les livraisons interrompues ou bloquées. Si le problème ne peut plus être résolu automatiquement, la demande reste accessible dans Lead Vault afin de pouvoir intervenir manuellement.


## Créez vos formulaires directement dans WordPress

Leadealer intègre un constructeur visuel permettant de créer et d’organiser les formulaires depuis l’administration WordPress.

Les champs texte, e-mail, téléphone, texte long, nombre, URL, listes déroulantes, boutons radio, cases à cocher, consentements et photos sont pris en charge. La mise en page repose sur une grille responsive afin d’adapter la largeur des champs sur ordinateur, tablette et mobile.

La logique conditionnelle permet d’afficher certaines questions uniquement lorsqu’elles sont pertinentes. Un formulaire peut ainsi rester simple au premier regard tout en demander davantage d’informations lorsque les réponses du visiteur le nécessitent.

Leadealer fournit également un bloc Gutenberg et un shortcode pour insérer un formulaire dans une page.

```text
[leadealer_form id="123"]
```

## Recevez vos notifications pendant vos horaires de travail

Leadealer peut différer la remise des notifications en dehors de vos horaires d’ouverture sans retarder l’enregistrement de la demande.

Un client peut par exemple envoyer une demande le samedi à 23 h 19. Le lead est immédiatement enregistré dans Lead Vault, mais l’e-mail peut attendre le lundi matin avant d’être transmis.

Cela permet de séparer la disponibilité du formulaire de votre propre disponibilité. Le site continue de recevoir des demandes 24 heures sur 24 sans imposer que chaque nouvelle notification arrive immédiatement dans votre boîte mail.

Les horaires sont facultatifs et désactivés par défaut. Lorsqu’ils sont activés, ils peuvent être configurés pour chaque jour de la semaine et utilisent automatiquement le fuseau horaire défini dans WordPress.

Les mécanismes de récupération, les workers, les watchdogs et les relances respectent également ces horaires afin qu’une notification différée ne soit pas envoyée prématurément par une tâche automatique.

## Les photos sont associées au lead

Pour une demande de dépannage, un devis ou simplement pour montrer un problème, une photo peut apporter beaucoup plus d’informations qu’une longue description.

Leadealer permet d’ajouter des photos directement aux formulaires. Elles sont associées au lead enregistré dans Lead Vault et ne dépendent pas uniquement de la pièce jointe transmise par e-mail.

Les fichiers reçus ne sont pas considérés comme fiables simplement parce qu’ils possèdent une extension d’image. Leadealer vérifie leur type, leur signature, leurs dimensions et leur nombre de pixels avant de les décoder complètement puis de les réencoder côté serveur.

Le fichier original n’est pas conservé tel quel. La réencodage permet de supprimer les métadonnées EXIF et GPS et d’enregistrer une nouvelle image sous un nom généré par le serveur.

Les formats JPEG, PNG et WebP sont pris en charge côté serveur.

## Compatible avec votre solution SMTP

Leadealer utilise l’API native `wp_mail()` de WordPress pour transmettre les notifications.

Il peut donc fonctionner avec les extensions et services qui utilisent ce mécanisme pour prendre en charge la livraison des e-mails, notamment WP Mail SMTP, FluentSMTP, Post SMTP, Brevo, Amazon SES et d’autres solutions compatibles.

Leadealer ne remplace pas votre infrastructure de messagerie. Il intervient avant elle en enregistrant la demande puis en suivant sa remise au système de transport WordPress.

## Une sécurité pensée pour un formulaire public

Un formulaire accessible sans authentification reçoit nécessairement des données provenant de visiteurs inconnus. Leadealer considère donc le navigateur comme une source non fiable et applique ses contrôles côté serveur.

Les soumissions sont vérifiées selon le schéma publié du formulaire. Le plugin utilise également un Proof of Work signé par HMAC, une protection contre la réutilisation des challenges, une limitation de débit, un honeypot et un mécanisme d’idempotence destiné à éviter qu’une même demande soit créée plusieurs fois lors d’une perte de connexion ou d’une nouvelle tentative du navigateur.

Les pièces jointes suivent leur propre chaîne de validation avant d’être associées à une demande. Les fichiers conservés par Lead Vault ne disposent pas d’une URL publique générée par le plugin pour leur consultation.

Leadealer ne charge aucune bibliothèque JavaScript, feuille de style ou police depuis un CDN pendant son fonctionnement et n’intègre aucune télémétrie externe.

## Les données de Lead Vault peuvent être maîtrisées

Les demandes reçues peuvent contenir des données personnelles. Leadealer s’intègre donc aux mécanismes WordPress d’export et d’effacement des données personnelles.

La durée de conservation des leads remis peut être configurée depuis les réglages du plugin. Les champs de consentement peuvent également intégrer un lien vers la page de politique de confidentialité définie dans WordPress.

Le mécanisme anti-abus n’enregistre pas l’adresse IP brute dans la base de données. Lorsqu’un identifiant réseau temporaire est nécessaire au fonctionnement de la protection, il est dérivé par HMAC.

La suppression complète des données lors de la désinstallation peut également être activée depuis les réglages.

## Installation

Téléchargez la dernière archive `leadealer.zip` depuis la page des [releases](../../releases/latest).

Dans WordPress, ouvrez **Extensions > Ajouter une extension > Téléverser une extension**, sélectionnez l’archive puis activez Leadealer.

Vous pouvez ensuite créer un formulaire depuis **Leadealer > Forms**, configurer sa notification, le publier puis l’insérer dans une page avec le bloc **Leadealer Form** ou le shortcode.

Leadealer nécessite **WordPress 6.6 ou supérieur** et **PHP 7.4 ou supérieur**.

Les tâches de récupération automatique utilisent WP-Cron. Lorsqu’un site désactive WP-Cron avec `DISABLE_WP_CRON`, un cron système doit appeler régulièrement `wp-cron.php` afin que les traitements planifiés puissent continuer à fonctionner.

## Licence

Leadealer est développé par **Adrien Piron** et distribué sous licence **GPL-2.0-or-later**.

Consultez le fichier [`LICENSE`](LICENSE) pour le texte complet de la licence.
