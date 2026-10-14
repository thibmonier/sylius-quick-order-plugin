# Audit qualité — sylius-quick-order-plugin

> Généré le 2026-10-14 · Branche `chore/quality-tooling` · Outillage claude-craft (ECS, PHPStan, Rector, PHPUnit) exécuté via Docker `ghcr.io/sylius/sylius-php:8.4-alpine`. Revue qualitative effectuée par lecture directe des sources (orchestrateur).

## 1. Audit factuel (outils)

| Contrôle | Commande | Résultat |
|---|---|---|
| Style (ECS) | `vendor/bin/ecs check --no-progress-bar` | ✅ 0 erreur |
| Analyse statique (PHPStan **level max**) | `vendor/bin/phpstan analyse --no-progress` | ✅ 0 erreur |
| Tests unitaires | `vendor/bin/phpunit --testsuite=unit --no-coverage` | ✅ 152 tests, 1620 assertions |
| Validation composer | `composer validate --strict` | ✅ valide |
| Modernisation (Rector, dry-run) | `vendor/bin/rector process --dry-run --no-progress-bar` | ⚠️ 37 fichiers avec suggestions |
| Tests `non-unit` (functional/integration) | — | ⏭️ non exécutés localement (DB + app Sylius requise) — couverts par le CI |
| Vulnérabilités dépendances | `composer audit` | ⚠️ 2 paquets abandonnés (transitifs, voir §3) |

### Notes sur les résultats

**Rector** vient d'être ajouté (`rector/rector ^2.0`, `rector.php`, cibles `make rector` / `make rector-fix`). Les 37 fichiers signalés relèvent de règles `FlipTypeControlToUseExclusiveTypeRector`, `ArrowFunctionDelegatingCallToFirstClassCallableRector`, `RemoveConcatAutocastRector` et `RemoveDefaultValueFromAssignedPropertyRector` — suggestions de **modernisation non bloquantes**, non appliquées. Lancer `make rector-fix` puis relancer ECS/PHPStan/tests.

**`composer audit`** : les deux paquets abandonnés (`behat/transliterator`, `php-http/message-factory`) sont des dépendances **transitives** de `sylius/sylius` et `sylius/test-application`, pas des dépendances directes du plugin. Aucune CVE associée.

**`ecs.php`** : déjà conforme — paths `src`, `tests/Unit`, `tests/Integration`, `tests/Functional` + `ecs.php` lui-même, import `vendor/sylius-labs/coding-standard/ecs.php`. Non modifié.

## 2. Audit qualitatif (revue par l'orchestrateur)

| Dimension | Score | Synthèse |
|---|---|---|
| PHP / Clean Archi / SOLID | **8/10** | Typage strict, `final`, enums, value objects immuables, interfaces focalisées. |
| Intégration Symfony / Sylius | **7/10** | DI propre, LiveComponent correct, CSRF sur tous les POST. Une comparaison de canal par identité objet à corriger. |
| Sécurité (OWASP 2025) | **8/10** | DQL paramétré, CSRF, limites DoS, CSV injection defense, pas de fuite d'info. Un anti-pattern identité/référence et un risque DoS résiduel. |

### Points forts

- `declare(strict_types=1)` + `final class` sur tous les fichiers `src/`, enums typés (`LineStatus`, `BulkAddMode`, `QuantityRounding`), value objects readonly immuables (`LineResult::withStatus()`, `QuickOrderLine::withAddedQuantity()`).
- **SRP exemplaire** : `CsvParser` / `PasteParser` / `LineCollector` / `VariantLookup` / `BulkCartAdder` ont chacun une responsabilité unique, clairement nommée.
- **DIP** : le contrôleur et l'API Processor dépendent de `BulkCartAdderInterface`, `VariantLookupInterface`, etc. — pas des implémentations concrètes.
- **Sécurité CSRF** : `$this->isCsrfTokenValid(self::CSRF_ID, ...)` sur tous les POST (preview, add, reorder, matrixAdd).
- **Protection DoS multi-couches** : `max_lines`, `max_file_size`, `max_total_quantity`, `quantityLimit` par variante — configurables.
- **CSV injection** : `CsvWriter::escape()` préfixe les cellules commençant par `=`, `+`, `-`, `@`, TAB, CR.
- **Pas de fuite de stock ni de noms de produits non publiés** : `BulkCartAdder::check()` remplace les statuts `Disabled`/`NotInChannel` par `NotAvailable` pour les visiteurs non authentifiés.
- **VariantLookup** : DQL paramétré, jointures explicites, nombre de requêtes constant quel que soit le volume (exact puis insensible à la casse uniquement pour les codes manquants).
- **Session preview avec nonce** : la saisie est stockée côté serveur avec un jeton à usage unique — empêche le replay et fixe la taille des données en session.

## 3. Recommandations priorisées

### 🟠 Majeur

1. **Comparaison de canal par identité objet** (`src/Controller/Shop/QuickOrderController.php:151`, OWASP A01:2025).
   `$order->getChannel() !== $this->channelContext->getChannel()` utilise l'identité PHP (`!==`), pas l'égalité métier. Si le channel est re-hydraté depuis un contexte EntityManager différent ou une requête suivante, la comparaison échoue et l'ordre légitime est rejeté avec une 404. À l'inverse, un canal désactivé rechargé en proxy paresseux pourrait ne pas être détecté.
   → **Aligner sur la convention de `BulkCartAdder::isSoldIn()`** : comparer par code (`$order->getChannel()?->getCode() !== $this->channelContext->getChannel()->getCode()`).

2. **Constructeur à 10+ paramètres** (`src/Controller/Shop/QuickOrderController.php:54-72`, SOLID SRP, KISS).
   Le contrôleur reçoit 10 à 12 dépendances dont les parseurs, écrivains, factories, paramètres de config et optionnels matrix. La lisibilité et la testabilité en pâtissent.
   → Extraire un `QuickOrderPageBuilder` (groupant parseurs + validateur + paramètres de page) ou passer par des services avec leurs propres dépendances configurées.

3. **`max_total_quantity` ne compte que les lignes valides** (`src/Adder/BulkCartAdder.php:191-206`).
   Un attaquant peut soumettre `max_lines` (défaut 200) lignes invalides sans atteindre la limite de quantité totale, forçant l'itération complète de `VariantLookup`. Le vrai limiteur est `max_lines` + le plafond du fichier/texte, mais aucune limite de taux (rate limiting) n'existe au niveau du plugin.
   → Documenter explicitement que le rate limiting côté infrastructure est requis ; envisager une limite de lignes invalides distincte (e.g. 50 % de `max_lines`) avec refus anticipé.

### 🟡 Mineur

4. **`MatrixOrderComponent::quantitiesInCart()` : cast de variante non sécurisé** (`src/Twig/Component/MatrixOrderComponent.php:330`).
   `$variant?->getId()` est seulement testé `\is_int($id)` ; en UUID string, il est silencieusement ignoré — cohérent avec le reste (IDs entiers) mais aucun commentaire ne le note.
   → Ajouter un commentaire ou un `\assert(\is_int($id) || null === $id)`.

5. **Session non flushée après `restoreFallbackResult()`** (`src/Twig/Component/MatrixOrderComponent.php:126-138`).
   `$session->remove($key)` est appelé mais la session n'est pas explicitement sauvegardée. Dans la plupart des configurations Symfony la session est flushée à la fin de la requête, mais les LiveComponents déclenchent des subrequêtes internes. Peut provoquer un double affichage du résultat si la requête parente et la subrequête lisent le même storage avant le flush.
   → Appeler `$session->save()` ou vérifier que le storage est géré correctement côté subrequest.

6. **`reorderAction` : refus de `findOneByNumberAndCustomer` sans log** (`src/Controller/Shop/QuickOrderController.php:150`).
   Si la commande n'est pas trouvée, une `NotFoundHttpException` est levée sans contexte (numéro, client). Un log `warning` aiderait à détecter des tentatives d'énumération de numéros de commande.
   → Ajouter un log `LoggerInterface` (OWASP A09:2025 — Logging & Monitoring Failures).

7. **Rector : 37 fichiers avec suggestions de modernisation**.
   Règles non bloquantes (`FlipTypeControlToUseExclusiveTypeRector`, `ArrowFunctionDelegatingCallToFirstClassCallableRector`, `RemoveConcatAutocastRector`, `RemoveDefaultValueFromAssignedPropertyRector`).
   → Lancer `make rector-fix`, relancer ECS + PHPStan + tests.

8. **Dépendances transitives abandonnées** (`composer audit`).
   `behat/transliterator` et `php-http/message-factory` sont abandonnées mais portées par `sylius/sylius` et `sylius/test-application`. Aucune CVE. À surveiller lors des mises à jour Sylius.

### ℹ️ Pour information (choix de conception assumés)

- La saisie de preview est stockée **côté serveur** (session) avec un nonce à usage unique, pas côté client : choix délibéré pour limiter la surface d'attaque et la taille des données en transit.
- La `VariantLookup` effectue la correspondance insensible à la casse **uniquement** pour les codes sans match exact — compromis correct entre convivialité et intégrité des codes SKU.
- `exposeAvailableStock: false` par défaut : les niveaux de stock ne sont pas publics sans opt-in explicite.
- L'API `BulkAddItemsProcessor` réplique intentionnellement la règle d'accès `AddItemToCartHandler::assertCartAccessible()` du noyau Sylius plutôt que de créer une dépendance sur une méthode privée.

## 4. Verdict

Plugin **solide et bien architecturé** : outillage au vert (ECS ✅, PHPStan level max ✅, 152 tests unitaires ✅), typage strict, classes finales, value objects immuables, interfaces focalisées, CSRF sur tous les POST, protection DoS multi-couches, pas de fuite d'info. Les axes d'amélioration sont concentrés sur **une comparaison de canal par identité** (à corriger avant une release production pour éviter des 404 silencieux dans des contextes multi-EM) et le **constructeur de contrôleur trop chargé** (maintenabilité). Le doS résiduel sur les lignes invalides est acceptable avec du rate limiting infrastructure. Lancer `make rector-fix` pour moderniser 37 fichiers sans risque.
