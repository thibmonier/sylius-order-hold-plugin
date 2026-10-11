<?php

declare(strict_types=1);

namespace Tmonier\SyliusOrderHoldPlugin\UnpaidOrder;

use Doctrine\ORM\QueryBuilder;

/**
 * Optional SQL-level filter of the unpaid orders read by `sylius:cancel-unpaid-orders`, for
 * plugins that can exclude their held orders in the query (e.g. `NOT EXISTS` on their own
 * table) instead of loading them. Implementations are autoconfigured with the
 * `tmonier_order_hold.unpaid_order_candidate_query_extension` tag.
 */
interface UnpaidOrderCandidateQueryExtensionInterface
{
    /** `$rootAlias` is the alias of the order entity in `$queryBuilder`. */
    public function applyToCandidateQuery(QueryBuilder $queryBuilder, string $rootAlias): void;
}
