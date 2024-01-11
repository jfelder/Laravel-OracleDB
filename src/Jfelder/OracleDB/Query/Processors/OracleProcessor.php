<?php

namespace Jfelder\OracleDB\Query\Processors;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Processors\Processor as Processor;

class OracleProcessor extends Processor
{
    /**
     * Process an "insert get ID" query.
     *
     * @param  string  $sql
     * @param  array  $values
     * @param  string  $sequence no effect; only for method signature compatibility
     * @return int
     */
    public function processInsertGetId(Builder $query, $sql, $values, $sequence = null)
    {
        return $query->getConnection()->oracleInsertGetId($sql, $values);
    }

    /**
     * Process the results of a column listing query.
     *
     * @param  array  $results
     * @return array
     */
    public function processColumnListing($results)
    {
        $mapping = function ($r) {
            $r = (object) $r;

            return $r->column_name;
        };

        return array_map($mapping, $results);
    }
}
