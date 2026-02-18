<?php

namespace Jfelder\OracleDB;

use Exception;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Connection;
use Jfelder\OracleDB\Query\Grammars\OracleGrammar as OracleQueryGrammar;
use Jfelder\OracleDB\Query\OracleBuilder as OracleQueryBuilder;
use Jfelder\OracleDB\Query\Processors\OracleProcessor;
use Jfelder\OracleDB\Schema\Grammars\OracleGrammar as OracleSchemaGrammar;
use Jfelder\OracleDB\Schema\OracleBuilder as OracleSchemaBuilder;
use PDO;
use RuntimeException;

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
     * {@inheritdoc}
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
     * {@inheritdoc}
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
     * {@inheritdoc}
     *
     * @return \Jfelder\OracleDB\Query\Grammars\OracleGrammar
     */
    protected function getDefaultQueryGrammar()
    {
        return new OracleQueryGrammar($this);
    }

    /**
     * {@inheritdoc}
     *
     * @return \Jfelder\OracleDB\Schema\Grammars\OracleGrammar|null
     */
    protected function getDefaultSchemaGrammar()
    {
        return new OracleSchemaGrammar($this);
    }

    /**
     * {@inheritdoc}
     *
     * @return \Jfelder\OracleDB\Query\Processors\OracleProcessor
     */
    protected function getDefaultPostProcessor()
    {
        return new OracleProcessor;
    }

    /**
     * {@inheritdoc}
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
     */
    public function oracleInsertGetId(string $query, array $bindings = []): int
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
     * {@inheritdoc}
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
    public function getSchemaState(?Filesystem $files = null, ?callable $processFactory = null)
    {
        throw new RuntimeException('Schema dumping is not supported when using Oracle.');
    }

    /**
     * Update oracle session parameters.
     */
    public function setSessionParameters(array $sessionParameters): void
    {
        $params = [];
        foreach ($sessionParameters as $option => $value) {
            if (strtoupper($option) == 'CURRENT_SCHEMA') {
                $params[] = "$option = $value";
            } else {
                $params[] = "$option = '$value'";
            }
        }

        if ($params) {
            $sql = 'ALTER SESSION SET '.implode(' ', $params);
            $this->statement($sql);
        }
    }
}
