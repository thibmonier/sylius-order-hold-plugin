# Sylius Order Hold Plugin

> English: [README.md](README.md) <!-- vitrine:lang -->

[![Build](https://github.com/thibmonier/sylius-order-hold-plugin/actions/workflows/build.yaml/badge.svg)](https://github.com/thibmonier/sylius-order-hold-plugin/actions/workflows/build.yaml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

**Un petit socle commun pour les plugins Sylius 2 qui gardent des commandes passées impayées
pendant un certain temps : paiement sur compte (crédit client), validation d'achat, examen manuel.**

Ces plugins ont tous besoin des mêmes quatre modifications de Sylius : garder leurs commandes hors
de `sylius:cancel-unpaid-orders`, masquer certains modes de paiement partout où Sylius en propose ou
en attribue un, et empêcher le client de payer une commande qui n'est pas prête à être payée.
Quand deux plugins décorent chacun de leur côté les mêmes services Sylius, les décorations entrent en
concurrence et se cassent mutuellement ; certaines implémentations qui semblent évidentes sont aussi
fausses (une garde sur la transition `cancel` de la commande fait boucler indéfiniment la commande
des commandes impayées (`sylius:cancel-unpaid-orders`) ; un résolveur de modes de paiement supplémentaire étiqueté *remplace* le
résolveur basé sur le canal au lieu de le filtrer).

Ce paquet décore chacun de ces services Sylius **une seule fois** et expose à la place de petits
contrats étiquetés. Il n'a ni entité, ni table, ni route, ni interface. Seul, il ne change rien de
visible, sauf que `sylius:cancel-unpaid-orders` ne peut plus boucler indéfiniment.

## Fonctionnalités

| Besoin | Ce que fait le paquet |
|---|---|
| Les commandes à conditions de crédit ou en attente de validation ne doivent pas être annulées au bout de 5 jours | `UnpaidOrderCancellationExclusionInterface` : renvoyez une mise en attente, **indéfinie** ou **depuis une date** (la période d'expiration court alors à partir de cette date, par exemple la date de validation). Un décorateur de `sylius.updater.unpaid_orders_state` lit les commandes impayées par identifiant croissant, de sorte que les commandes en attente et les annulations refusées ou en échec ne sont jamais lues deux fois : la commande se termine toujours. Chaque annulation est enregistrée séparément ; celle qui lève une exception (par exemple pas assez d'unités en réserve, ou une violation de contrainte pendant l'enregistrement, après laquelle l'entity manager est réinitialisé) est journalisée et ignorée. Une surcharge applicative de `OrderRepository::findOrdersUnpaidSince()` est respectée (son résultat restreint les candidates). |
| Exclure les commandes en attente en SQL plutôt qu'en PHP | `UnpaidOrderCandidateQueryExtensionInterface` ajoute des conditions à la requête des candidates (par exemple `NOT EXISTS` sur la table du plugin). Les paramètres de base sont préfixés `tmonier_oh_` : une extension qui en écrase un fait échouer la commande au lieu de changer silencieusement la sélection. |
| Proposer un mode de paiement à certains clients seulement | `PaymentMethodEligibilityCheckerInterface` renvoie un refus avec une raison (enum `IneligibilityReason`) et un message traduisible. Appliqué au `sylius.resolver.payment_methods` composite (tunnel de commande et page de commande de la boutique, choix et validateurs de l'API), à `sylius.resolver.payment_method.default` (mode des paiements créés par le processeur de commande), à `sylius.provider.payment.order` (un paiement créé après un paiement échoué ou annulé copie son mode : un mode refusé est remplacé par le mode par défaut éligible), au changeur de mode de paiement de l'API (changement après le tunnel de commande), aux demandes de paiement de l'API boutique (`AddPaymentRequest` / `UpdatePaymentRequest`, le cœur accepte n'importe quel code de mode), à la finalisation du tunnel de commande (commande de la boutique et `CompleteOrder` de l'API), et sur les routes de paiement de la boutique (un mode refusé depuis qu'il a été choisi renvoie le client à la page de commande pour en choisir un autre). |
| Ne pas laisser le client payer avant la validation | `PaymentEntryBlockerInterface` : les routes de la boutique qui démarrent un paiement (`sylius_shop_order_pay`, atteinte après le tunnel de commande et depuis la page de commande ; `sylius_shop_payment_request_pay` ; les routes Payum `payum_capture_do`, `payum_capture_do_session` et `payum_authorize_do`, pour les jetons créés avant le blocage ; uniquement les paiements encore `new`, même quand la passerelle a déjà enregistré des détails ; un paiement que la passerelle est en train de traiter et ses notifications passent toujours) redirigent vers la page de remerciement avec un message flash (dans la langue de la requête quand le canal la propose, sinon dans la langue de la commande quand le canal la propose, sinon dans la langue par défaut du canal) ; l'API boutique refuse de créer ou de modifier les demandes de paiement de la commande (422). |
| Savoir de qui une commande est la responsabilité | `AccountOwnerResolverInterface` : le client par défaut ; un plugin d'entreprise décore `tmonier_order_hold.resolver.account_owner`. |
| Échouer tôt sur une configuration non prise en charge | Une compiler pass arrête la construction du conteneur quand le graphe du tunnel de commande, de la commande ou du paiement tourne sur winzou : les plugins de mise en attente écoutent les événements Symfony Workflow. Les adaptateurs définis par des variables d'environnement sont vérifiés avec leur valeur à la construction, puis à nouveau à l'exécution (première requête ou commande de console). |

Également inclus : messages par défaut en anglais, français, allemand, espagnol, italien, néerlandais
et polonais ; prise en charge de MySQL, MariaDB et PostgreSQL (query builder Doctrine uniquement).

## Prérequis

| | Version |
|---|---|
| PHP | 8.2+ (8.3+ avec Sylius 2.3) |
| Sylius | 2.2.9+, 2.3 |
| Symfony | 6.4, 7.4 (Sylius 2.2) ; 6.4, 7.4, 8.0 (Sylius 2.3) |

Sylius 2.2.9 est la première version 2.2 qui contient les correctifs de GHSA-2rv4-pjmm-7fxf et
GHSA-vv4h-q2x8-74g4 (demandes de paiement).

L'abstraction de machine à états doit utiliser l'adaptateur **Symfony Workflow** pour les graphes
`sylius_order_checkout`, `sylius_order` et `sylius_payment` (le défaut de Sylius 2).

## Installation

Généralement installé comme dépendance d'un autre plugin. Pour l'installer directement :

1. Ajoutez le paquet :

    ```bash
    composer require tmonier/sylius-order-hold-plugin
    ```

2. Enregistrez le bundle dans `config/bundles.php` :

    ```php
    Tmonier\SyliusOrderHoldPlugin\TmonierSyliusOrderHoldPlugin::class => ['all' => true],
    ```

Pas de route, pas de migration, pas d'asset.

## Configuration

Facultative, avec les valeurs par défaut :

```yaml
# config/packages/tmonier_sylius_order_hold.yaml
tmonier_sylius_order_hold:
    unpaid_orders:
        # Orders read per query by sylius:cancel-unpaid-orders (the entity manager is cleared after each batch).
        batch_size: 100
    # Fail at container compilation when the checkout, order or payment graph runs on winzou.
    check_state_machine_adapters: true
```

La période d'expiration elle-même est celle de Sylius (`sylius_order.expiration.order`, 5 jours par
défaut).

## Écrire un plugin de mise en attente

Implémentez les interfaces dans des services avec `autoconfigure: true` (les tags sont ajoutés
automatiquement) ou étiquetez-les vous-même :

| Interface | Tag |
|---|---|
| `UnpaidOrder\UnpaidOrderCancellationExclusionInterface` | `tmonier_order_hold.unpaid_order_cancellation_exclusion` |
| `UnpaidOrder\UnpaidOrderCandidateQueryExtensionInterface` | `tmonier_order_hold.unpaid_order_candidate_query_extension` |
| `PaymentMethod\PaymentMethodEligibilityCheckerInterface` | `tmonier_order_hold.payment_method_eligibility_checker` |
| `PaymentEntry\PaymentEntryBlockerInterface` | `tmonier_order_hold.payment_entry_blocker` |

```php
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Tmonier\SyliusOrderHoldPlugin\PaymentEntry\PaymentEntryBlock;
use Tmonier\SyliusOrderHoldPlugin\PaymentEntry\PaymentEntryBlockerInterface;
use Tmonier\SyliusOrderHoldPlugin\PaymentMethod\IneligibilityReason;
use Tmonier\SyliusOrderHoldPlugin\PaymentMethod\PaymentMethodEligibilityCheckerInterface;
use Tmonier\SyliusOrderHoldPlugin\PaymentMethod\PaymentMethodIneligibility;
use Tmonier\SyliusOrderHoldPlugin\UnpaidOrder\UnpaidOrderCancellationExclusionInterface;
use Tmonier\SyliusOrderHoldPlugin\UnpaidOrder\UnpaidOrderHold;

final class ApprovalHold implements UnpaidOrderCancellationExclusionInterface, PaymentEntryBlockerInterface, PaymentMethodEligibilityCheckerInterface
{
    public function getHold(OrderInterface $order): ?UnpaidOrderHold
    {
        $approval = $this->approvals->findForOrder($order);

        return match (true) {
            null === $approval => null,
            $approval->isPending() => UnpaidOrderHold::indefinitely('approval'),
            default => UnpaidOrderHold::since($approval->getApprovedAt(), 'approval'),
        };
    }

    public function getBlock(OrderInterface $order): ?PaymentEntryBlock
    {
        return $this->approvals->isPending($order) ? new PaymentEntryBlock('app.order.awaiting_approval') : null;
    }

    public function getIneligibility(PaymentMethodInterface $paymentMethod, PaymentInterface $payment): ?PaymentMethodIneligibility
    {
        return $this->isOnAccount($paymentMethod) && !$this->canBuyOnAccount($payment)
            ? new PaymentMethodIneligibility(IneligibilityReason::CreditLimitExceeded, null, ['%available%' => '120.00 EUR'])
            : null;
    }
}
```

Règles :

- **Ne mettez jamais de garde sur la transition `sylius_order.cancel`** pour protéger une commande :
  renvoyez une mise en attente. Le processus de mise à jour ignore de toute façon les annulations
  refusées (journalisées en avertissement), mais les autres chemins de code (annulation depuis
  l'administration) doivent continuer à fonctionner.
- **N'étiquetez jamais un `sylius.payment_method_resolver` supplémentaire** pour filtrer les modes :
  le composite du cœur renvoie les modes du premier résolveur qui prend en charge le paiement.
- **Ne décorez pas** les services listés ci-dessous dans votre plugin ; implémentez plutôt un
  contrôleur d'éligibilité.
- Les contrôleurs sont appelés souvent (à chaque liste de modes de paiement) : gardez-les peu
  coûteux, mettez en cache par requête.
- Les extensions de requête ne doivent pas utiliser de noms de paramètres commençant par
  `tmonier_oh_`.
- Les messages sont des clés de traduction : domaine `validators` pour les refus et les blocages de
  l'API, domaine `flashes` pour le blocage dans la boutique. Sans message, le message par défaut de
  la raison est utilisé (`tmonier_order_hold.payment_method.ineligible.<reason>`, paramètre
  `%paymentMethodName%`).

Services décorés (chacun exactement une fois) :

| Service | Décorateur |
|---|---|
| `sylius.updater.unpaid_orders_state` | `tmonier_order_hold.updater.unpaid_orders_state` |
| `sylius.resolver.payment_methods` (composite) | `tmonier_order_hold.resolver.payment_methods` |
| `sylius.resolver.payment_method.default` | `tmonier_order_hold.resolver.default_payment_method` |
| `sylius.provider.payment.order` | `tmonier_order_hold.provider.order_payment` |
| `sylius_api.changer.payment_method` (quand le bundle API est activé) | `tmonier_order_hold.changer.payment_method` |

Agrégats que vous pouvez injecter : `UnpaidOrderHoldResolverInterface` (une commande est-elle en
attente ? depuis quand ?), `PaymentMethodEligibilityCheckerInterface` (tous les contrôleurs),
`PaymentEntryBlockerInterface` (tous les bloqueurs), `AccountOwnerResolverInterface`.

## Limitations

- Le processus de mise à jour des commandes impayées remplace l'algorithme du cœur (même sélection,
  pagination par clé) et n'appelle pas le service décoré. Une autre décoration de
  `sylius.updater.unpaid_orders_state` appliquée *avant* celle-ci est ignorée. Un dépôt qui surcharge
  `findOrdersUnpaidSince()` est appelé une fois par exécution sans limite (ses identifiants
  restreignent alors la requête paginée), ce qui charge une fois toutes ses candidates en mémoire.
- L'éligibilité est contrôlée là où Sylius propose, attribue ou valide un mode de paiement. Le code
  qui appelle directement `Payment::setMethod()` (outils d'administration sur mesure, imports) la
  contourne.
- Le côté administration n'est pas touché : les administrateurs peuvent toujours changer les modes de
  paiement et finaliser les paiements des commandes en attente, volontairement.
- Une page de paiement bloquée de la boutique redirige vers la page de remerciement ; si la session ne
  peut pas être écrite (clients sans état), la page de remerciement se replie sur la page d'accueil
  comme dans Sylius.
- Compromis de l'interception Payum : une passerelle externe qui capture au retour du navigateur
  (paiement encore `new`) ne capture pas tant que la commande est bloquée ; le client paie de nouveau
  une fois le blocage levé. L'argent déjà encaissé par la passerelle est enregistré grâce à ses
  notifications, qui ne sont jamais bloquées.
- Les passerelles de paiement qui capturent pendant le tunnel de commande, avant la page de paiement,
  ne sont pas arrêtées par le bloqueur d'entrée de paiement : utilisez pour elles l'éligibilité des
  modes de paiement. Les notifications des passerelles (serveur à serveur) ne sont jamais bloquées :
  un paiement que la passerelle a déjà encaissé est enregistré.

## Développement

```bash
docker compose up -d mysql mailhog   # host ports in compose.override.yml (gitignored)
composer database-reset              # dev database with fixtures (prefix with APP_ENV=test for tests)
vendor/bin/phpunit                   # unit, integration (cron) and functional (shop + API) tests
vendor/bin/phpstan analyse
vendor/bin/ecs check
```

Voir [SPEC.md](SPEC.md) pour la recherche et les décisions de conception.

<!-- vitrine:demo-b2b:start -->
## Démo B2B

Ce plugin est utilisé dans la [démo B2B Sylius](https://github.com/thibmonier/sylius-b2b-demo) : une boutique Sylius 2.3 avec des données d'exemple fictives, où les plugins B2B fonctionnent ensemble. Le code source, l'installation et les fixtures de la démo sont dans ce dépôt.

- Démo en ligne : [sylius-b2b.thibaut-monier.fr](https://sylius-b2b.thibaut-monier.fr)
- Auteur : [Thibaut Monier](https://www.linkedin.com/in/thibautmonier/)

La démo est un exemple, pas une configuration de production.
<!-- vitrine:demo-b2b:end -->

## Licence

MIT, voir [LICENSE](LICENSE).
