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
     * @param  string  $sequence  no effect; only for method signature compatibility
     * @return int
     */
    public function processInsertGetId(Builder $query, $sql, $values, $sequence = null)
    {
        return $query->getConnection()->oracleInsertGetId($sql, $values);
    }
}
