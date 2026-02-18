<?php

namespace Jfelder\OracleDB\Schema;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Database\Schema\Builder;
use InvalidArgumentException;
use RuntimeException;

class OracleBuilder extends Builder
{
    /**
     * {@inheritdoc}
     *
     * @return Jfelder\OracleDB\Schema\OracleBuilder
     */
    protected function createBlueprint($table, ?Closure $callback = null): OracleBlueprint
    {
        $connection = $this->connection;

        if (isset($this->resolver)) {
            return call_user_func($this->resolver, $connection, $table, $callback);
        }

        return Container::getInstance()->make(OracleBlueprint::class, compact('connection', 'table', 'callback'));
    }

    /**
     * {@inheritdoc}
     */
    public function getCurrentSchemaListing()
    {
        return [$this->connection->getConfig('username')];
    }

    /**
     * {@inheritdoc}
     */
    public function parseSchemaAndTable($reference, $withDefaultSchema = null): array
    {
        $segments = explode('.', $reference);

        if (count($segments) > 2) {
            throw new InvalidArgumentException(
                "Using three-part references is not supported, you may use `Schema::connection('{$segments[0]}')` instead."
            );
        }

        $table = $segments[1] ?? $segments[0];

        $schema = match (true) {
            isset($segments[1]) => $segments[0],
            is_string($withDefaultSchema) => $withDefaultSchema,
            $withDefaultSchema => $this->getCurrentSchemaName(),
            default => $this->connection->getConfig('username'),
        };

        return [$schema, $table];
    }

    /**
     * Get the column listing for a given table.
     *
     * @param  string  $table
     * @return array
     */
    public function getColumnListing($table)
    {
        throw new RuntimeException('This database engine does not support column listing operations. Eloquent models must set $guarded to [] or not define it at all.');
    }
}
