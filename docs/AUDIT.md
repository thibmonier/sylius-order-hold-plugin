# Audit qualité — sylius-order-hold-plugin

> Généré le 2026-10-14 · Branche `chore/quality-tooling` · Outillage claude-craft (ECS, PHPStan, Rector, PHPUnit) exécuté via Docker `ghcr.io/sylius/sylius-php:8.4-alpine`.

## 1. Audit factuel (outils)

| Contrôle | Commande | Résultat |
|---|---|---|
| Style (ECS) | `vendor/bin/ecs check` | ✅ 0 erreur |
| Analyse statique (PHPStan **level max**) | `vendor/bin/phpstan analyse` | ✅ 0 erreur |
| Tests unitaires | `vendor/bin/phpunit --testsuite=unit` | ✅ 66 tests, 306 assertions |
| Validation composer | `composer validate --strict` | ✅ valide |
| Lint conteneur | `console lint:container` | ✅ OK |
| Modernisation (Rector, dry-run) | `vendor/bin/rector process --dry-run` | ⚠️ 18 fichiers avec suggestions |
| Tests `non-unit` (functional/integration) | — | ⏭️ non exécutés localement (DB + app Sylius requise) — couverts par le CI |
| Vulnérabilités dépendances | `composer audit` | ✅ aucune advisory |

**Rector** vient d'être ajouté (`rector/rector ^2.0`, `rector.php`, cibles `make rector` / `make rector-fix`). Les 18 fichiers signalés relèvent de règles `FlipTypeControlToUseExclusiveTypeRector`, `RemoveConcatAutocastRector`, etc. — ce sont des **suggestions de modernisation non bloquantes**, non appliquées. Lancer `make rector-fix` pour les appliquer puis relancer ECS/PHPStan/tests.

## 2. Audit qualitatif (revue par agents)

| Dimension | Score | Synthèse |
|---|---|---|
| PHP / Clean Archi / SOLID | **8/10** | Typage strict partout, `final`, value objects immuables, décorateurs isolés (OCP). |
| Intégration Symfony / Sylius | **7/10** | Décorations centralisées, tags autoconfigurés, compiler pass + listener bien positionnés. |
| Sécurité (OWASP 2025) | **8/10** | DQL 100 % paramétré, pas de fuite d'info, enforcement en couches. Risque résiduel architectural. |

> Caveats : la suite de tests n'a pas été relue par les agents, et la compat exacte des API core Sylius 2.2/2.3 (`findOneByOrderToken`, format `order_expiration_period`) reste à confirmer avant release.

### Points forts
- `declare(strict_types=1)` + classes `final` sur les 32 fichiers de `src/`, enum typé, value objects immuables (`UnpaidOrderHold`, `PaymentMethodIneligibility`).
- Toutes les décorations de services Sylius concentrées dans `config/services.php` (un seul point, `.inner` correct, `IGNORE_ON_INVALID_REFERENCE` pour l'API optionnelle).
- `HoldAwareUnpaidOrdersStateUpdater` : pagination keyset, flush par annulation, garde `assertParametersUnchanged`, gestion de l'EntityManager fermé.
- `StateMachineAdapterCheckPass` : vérification à la compilation + repli runtime.
- Sécurité : double enforcement (listener shop + validateurs API), DQL paramétré, exceptions Payum capturées sans fuite vers le client.

## 3. Recommandations priorisées

### 🟠 Majeur
1. **Durcir le blocage de paiement — risque « fail-open »** (`src/EventListener/PaymentEntryBlockingListener.php:41-45,115-151`, OWASP A01:2025). Le blocage côté shop repose sur une **allowlist de routes codée en dur** : toute route de capture ajoutée/renommée (montée 2.2→2.3, gateway tiers) n'est plus interceptée et la commande en attente redevient payable, sans trace. → Ajouter un enforcement **deny-by-default** au niveau du state-machine de paiement / extension Payum, en complément de l'allowlist.
2. **Incohérence de finder** (`PaymentEntryBlockingListener.php:122`). Utilise `findOneByTokenValue()` alors que `CLAUDE.md` et `EligiblePaymentMethodsValidator.php:32` imposent `findOneBy(['tokenValue' => ...])` (le finder magique exclut les paniers). → Aligner sur la convention.
3. **Composite sans défaut sûr** (`CompositePaymentMethodEligibilityChecker.php:27`, `CompositePaymentEntryBlocker.php:26`, OWASP A04:2025). Retour `null` si aucun checker/blocker tagué → tout devient payable silencieusement si le câblage aval manque. → Logger un warning (ou health-check) quand aucun checker n'est présent.
4. **SRP du listener** (`PaymentEntryBlockingListener.php:39-208`, 208 lignes). Cumule routage, flash, résolution de tokens Payum, locale. → Extraire `PaymentEntryRouteResolver` + `LocaleParametersResolver`.
5. **Constructeur à 10 paramètres + réflexion par appel** (`HoldAwareUnpaidOrdersStateUpdater.php:44-55,100`). → Regrouper les dépendances de requête dans un collaborateur ; calculer le flag de réflexion une fois.
6. **Duplication (DRY)** entre validateurs : construction de violation (`EligiblePaymentMethodsValidator.php:50-54` / `EligiblePaymentRequestMethodValidator.php:57-61`) et résolution payment-request→paiement (`PaymentEntryAllowedValidator.php:53-58`, `EligiblePaymentRequestMethodValidator.php:66-71`, `PaymentEntryBlockingListener.php:127-136`). → Extraire des helpers communs.

### 🟡 Mineur
- `catch` silencieux : `EligibleOrderPaymentProvider.php` (catch vide sur `UnresolvedDefaultPaymentMethodException`), `PaymentEntryBlockingListener.php:175` (lecture token), `StateMachineAdapterCheckPass.php:50` (`catch \Throwable` trop large). → Ajouter un log.
- Typage `mixed $defaultAdapter` (`StateMachineAdapterChecker.php:30`) → `string|null`.
- `PaymentEntryAllowedValidator.php:49` : violation sans `setCode()`, incohérent avec les autres validateurs.
- Confirmer les API core 2.2/2.3 : `findOneByOrderToken`, `getLastPayment`, format de `order_expiration_period`.
- Appliquer les 18 suggestions Rector (`make rector-fix`).

### ℹ️ Pour information (choix de conception assumés)
- Route `payum_notify_do` volontairement non interceptée (transaction déjà engagée offsite).
- `CompleteOrder.xml` ne porte pas `PaymentEntryAllowed` (le hold s'applique après placement).

## 4. Verdict

Plugin **solide et discipliné** : outillage au vert (ECS, PHPStan level max, 66 tests unitaires), typage strict, décorations isolées, aucune injection ni fuite d'info. Les axes d'amélioration sont concentrés sur le **listener de paiement** (SRP + convention + durcissement anti fail-open) et le **défaut sûr des composites** — à traiter avant une release « production-grade » étant donné l'enjeu paiement. Rien de bloquant côté outillage.
