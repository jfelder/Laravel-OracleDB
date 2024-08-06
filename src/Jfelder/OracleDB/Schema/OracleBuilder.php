<?php

namespace Jfelder\OracleDB\Schema;

use RuntimeException;

class OracleBuilder extends \Illuminate\Database\Schema\Builder
{
    /**
     * Determine if the given table exists.
     *
     * @param  string  $table
     * @return bool
     */
    public function hasTable($table)
    {
        $sql = $this->grammar->compileTableExists();

        $database = $this->connection->getDatabaseName();

        $table = $this->connection->getTablePrefix().$table;

        return count($this->connection->select($sql, [$database, $table])) > 0;
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
