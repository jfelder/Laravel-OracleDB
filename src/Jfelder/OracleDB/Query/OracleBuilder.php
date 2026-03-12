<?php

namespace Jfelder\OracleDB\Query;

use Carbon\CarbonPeriod;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;

class OracleBuilder extends Builder
{
    /**
     * The result alias used by Oracle exists queries.
     */
    protected const EXISTS_ALIAS = 'oracle_exists';

    /**
     * Add a subquery cross join to the query.
     *
     * @param  \Closure|\Illuminate\Database\Query\Builder|string  $query
     * @param  string  $as
     * @return $this
     */
    public function crossJoinSub($query, $as)
    {
        [$query, $bindings] = $this->createSub($query);

        $expression = '('.$query.') '.$this->grammar->wrapTable($as);

        $this->addBinding($bindings, 'join');

        $this->joins[] = $this->newJoinClause($this, 'cross', new Expression($expression));

        return $this;
    }

    /**
     * Add a where between statement to the query.
     *
     * @param  \Illuminate\Contracts\Database\Query\Expression|string  $column
     * @param  string  $boolean
     * @param  bool  $not
     * @return $this
     */
    public function whereBetween($column, iterable $values, $boolean = 'and', $not = false)
    {
        if ($values instanceof CarbonPeriod) {
            $values = [$values->getStartDate(), $values->getEndDate()];
        }

        if ($this->isQueryable($column)) {
            [$query, $bindings] = $this->createSub($column);

            $column = new Expression("({$query})");

            $this->addBinding($bindings, 'where');
        }

        return parent::whereBetween($column, $values, $boolean, $not);
    }

    /**
     * Determine if any rows exist for the current query.
     *
     * Oracle cannot safely alias this result to "exists", so read the
     * Oracle-specific alias first and fall back to the Laravel default.
     *
     * @return bool
     */
    public function exists()
    {
        $this->applyBeforeQueryCallbacks();

        $results = $this->connection->select(
            $this->grammar->compileExists($this), $this->getBindings(), ! $this->useWritePdo
        );

        if (isset($results[0])) {
            $results = (array) $results[0];

            return (bool) ($results[static::EXISTS_ALIAS] ?? $results['exists'] ?? false);
        }

        return false;
    }
}
