<?php

namespace Jfelder\OracleDB\Query;

use Carbon\CarbonPeriod;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;

class OracleBuilder extends Builder
{
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
}
