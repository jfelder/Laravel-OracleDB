<?php namespace Jfelder\OracleDB;

use Illuminate\Database\Connection;
use Jfelder\OracleDB\Schema\OracleBuilder;


class OracleConnection extends Connection {

    /**
     * Get a schema builder instance for the connection.
     *
     * @return \Illuminate\Database\Schema\OracleBuilder
     */
    public function getSchemaBuilder()
    {
        if (is_null($this->schemaGrammar)) { $this->useDefaultSchemaGrammar(); }

        return new OracleBuilder($this);
    }

    /**
	 * Get the default query grammar instance.
	 *
	 * @return Jfelder\OracleDB\Query\Grammars\OracleGrammar
	 */
	protected function getDefaultQueryGrammar()
	{
		return $this->withTablePrefix(new Jfelder\OracleDB\Query\Grammars\OracleGrammar);
	}

	/**
	 * Get the default schema grammar instance.
	 *
	 * @return Jfelder\OracleDB\Schema\Grammars\OracleGrammar
	 */
	protected function getDefaultSchemaGrammar()
	{
		return $this->withTablePrefix(new Jfelder\OracleDB\Schema\Grammars\OracleGrammar);
	}

	/**
	 * Get the default post processor instance.
	 *
	 * @return Jfelder\OracleDB\Query\Processors\OracleProcessor
	 */
	protected function getDefaultPostProcessor()
	{
		return new Query\Processors\OracleProcessor;
	}

}
