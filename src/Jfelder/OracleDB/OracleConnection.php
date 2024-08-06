<?php

namespace Jfelder\OracleDB;

use Exception;
use Illuminate\Database\Connection;
use Jfelder\OracleDB\Query\Grammars\OracleGrammar as QueryGrammar;
use Jfelder\OracleDB\Query\OracleBuilder as OracleQueryBuilder;
use Jfelder\OracleDB\Query\Processors\OracleProcessor;
use Jfelder\OracleDB\Schema\Grammars\OracleGrammar as SchemaGrammar;
use Jfelder\OracleDB\Schema\OracleBuilder as OracleSchemaBuilder;
use PDO;

class OracleConnection extends Connection
{
    /**
     * {@inheritdoc}
     */
    public function getDriverTitle()
    {
        return 'Oracle';
    }

    /**
     * Get the server version for the connection.
     */
    public function getServerVersion(): string
    {
        return 'Run SELECT * FROM V$VERSION; to get the Oracle server version.';
    }

    /**
     * Get a schema builder instance for the connection.
     *
     * @return \Jfelder\OracleDB\Schema\OracleBuilder
     */
    public function getSchemaBuilder()
    {
        if (is_null($this->schemaGrammar)) {
            $this->useDefaultSchemaGrammar();
        }

        return new OracleSchemaBuilder($this);
    }

    /**
     * Get a new query builder instance.
     *
     * @return \Jfelder\OracleDB\Query\OracleBuilder
     */
    public function query()
    {
        return new OracleQueryBuilder(
            $this, $this->getQueryGrammar(), $this->getPostProcessor()
        );
    }

    /**
     * Get the default query grammar instance.
     *
     * @return \Jfelder\OracleDB\Query\Grammars\OracleGrammar
     */
    protected function getDefaultQueryGrammar()
    {
        ($grammar = new QueryGrammar)->setConnection($this);

        return $this->withTablePrefix($grammar);
    }

    /**
     * Get the default schema grammar instance.
     *
     * @return \Jfelder\OracleDB\Schema\Grammars\OracleGrammar|null
     */
    protected function getDefaultSchemaGrammar()
    {
        ($grammar = new SchemaGrammar)->setConnection($this);

        return $this->withTablePrefix($grammar);
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
     * Bind values to their parameters in the given statement.
     *
     * @param  \PDOStatement  $statement
     * @param  array  $bindings
     * @return void
     */
    public function bindValues($statement, $bindings)
    {
        foreach ($bindings as $key => $value) {
            $statement->bindValue(
                $key,
                $value,
                match (true) {
                    is_int($value) => PDO::PARAM_INT,
                    is_bool($value) => PDO::PARAM_BOOL,
                    is_null($value) => PDO::PARAM_NULL,
                    is_resource($value) => PDO::PARAM_LOB,
                    default => PDO::PARAM_STR
                },
            );
        }
    }

    /**
     * Run an "insert get ID" statement against an oracle database.
     *
     * @param  string  $query
     * @param  array  $bindings
     * @return int
     */
    public function oracleInsertGetId($query, $bindings = [])
    {
        return $this->run($query, $bindings, function ($query, $bindings) {
            $last_insert_id = 0;

            $statement = $this->getPdo()->prepare($query);

            $this->bindValues($statement, $this->prepareBindings($bindings));

            // bind final param to a var to capture the id obtained by the query's "returning id into" clause
            $statement->bindParam(count($bindings), $last_insert_id, PDO::PARAM_INT | PDO::PARAM_INPUT_OUTPUT, 8);

            $this->recordsHaveBeenModified();

            $statement->execute();

            return (int) $last_insert_id;
        });
    }

    /**
     * Determine if the given database exception was caused by a unique constraint violation.
     *
     * @return bool
     */
    protected function isUniqueConstraintError(Exception $exception)
    {
        return boolval(preg_match('#ORA-00001: unique constraint#i', $exception->getMessage()));
    }

    /**
     * Get the schema state for the connection.
     *
     * @throws \RuntimeException
     */
    public function getSchemaState()
    {
        throw new RuntimeException('Schema dumping is not supported when using Oracle.');
    }
}
