<?php

namespace Jfelder\OracleDB;

use Illuminate\Database\Connection;
use Jfelder\OracleDB\Schema\OracleBuilder;
use Jfelder\OracleDB\Query\Processors\OracleProcessor;
use Doctrine\DBAL\Driver\OCI8\Driver as DoctrineDriver;
use Jfelder\OracleDB\Query\Grammars\OracleGrammar as QueryGrammer;
use Jfelder\OracleDB\Schema\Grammars\OracleGrammar as SchemaGrammer;

class OracleConnection extends Connection
{
    /**
     * Get a schema builder instance for the connection.
     *
     * @return \Illuminate\Database\Schema\OracleBuilder
     */
    public function getSchemaBuilder()
    {
        if (is_null($this->schemaGrammar)) {
            $this->useDefaultSchemaGrammar();
        }

        return new OracleBuilder($this);
    }

    /**
     * Get the default query grammar instance.
     *
     * @return \Jfelder\OracleDB\Query\Grammars\OracleGrammar
     */
    protected function getDefaultQueryGrammar()
    {
        return $this->withTablePrefix(new QueryGrammer);
    }

    /**
     * Get the default schema grammar instance.
     *
     * @return \Jfelder\OracleDB\Schema\Grammars\OracleGrammar
     */
    protected function getDefaultSchemaGrammar()
    {
        return $this->withTablePrefix(new SchemaGrammer);
    }

    /**
     * Get the default post processor instance.
     *
     * @return \Jfelder\OracleDB\Query\Processors\OracleProcessor
     */
    protected function getDefaultPostProcessor()
    {
        return new OracleProcessor;
    }

    /**
     * Get the Doctrine DBAL driver.
     *
     * @return \Doctrine\DBAL\Driver\OCI8\Driver
     */
    protected function getDoctrineDriver()
    {
        return new DoctrineDriver;
    }
}
