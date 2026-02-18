<?php

namespace Jfelder\OracleDB\Tests;

use Jfelder\OracleDB\OCI_PDO\OCI;
use Jfelder\OracleDB\OCI_PDO\OCIStatement;
use Jfelder\OracleDB\OracleConnection;
use Jfelder\OracleDB\Query\OracleBuilder as QueryOracleBuilder;
use Jfelder\OracleDB\Schema\OracleBuilder as SchemaOracleBuilder;
use Mockery as m;
use PDO;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class OracleDBConnectionTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    public function test_get_driver_title_returns_correctly()
    {
        $pdo = m::mock(OCI::class);
        $conn = new OracleConnection($pdo);

        $this->assertEquals('Oracle', $conn->getDriverTitle());
    }
    
    public function test_get_schema_builder_returns_correctly()
    {
        $conn = m::mock(OracleConnection::class)->makePartial();

        $result = $conn->getSchemaBuilder();

        $this->assertEquals(SchemaOracleBuilder::class, get_class($result));

        //second call should skip the useDefaultSchemaGrammar call
        $conn->shouldNotReceive('useDefaultSchemaGrammar');

        $result2 = $conn->getSchemaBuilder();

        $this->assertEquals(SchemaOracleBuilder::class, get_class($result2));
    }

    public function test_query_return_correct_builder()
    {
        $conn = m::mock(OracleConnection::class)->makePartial();

        $result = $conn->query();

        $this->assertEquals(QueryOracleBuilder::class, get_class($result));
    }

    public function test_oracle_insert_get_id_properly_calls_pdo()
    {
        $statement = m::mock(OCIStatement::class)->makePartial();
        $statement->shouldReceive('bindValue')->once()->with(0, 'bar', 2);
        $statement->shouldReceive('bindParam')->once()->with(1, 0, PDO::PARAM_INT | PDO::PARAM_INPUT_OUTPUT, 8);
        $statement->shouldReceive('execute')->once();

        $pdo = m::mock(OCI::class)->makePartial();
        $pdo->shouldReceive('prepare')->once()->with('foo')->andReturn($statement);

        $conn = m::mock(OracleConnection::class)->makePartial();
        $conn->shouldReceive('reconnectIfMissingConnection');
        $conn->shouldReceive('getPDO')->andReturn($pdo);
        $conn->shouldReceive('prepareBindings')->with(['bar'])->andReturn(['bar']);
        $conn->enableQueryLog();

        $results = $conn->oracleInsertGetId('foo', ['bar']);

        $this->assertSame(0, $results);
        $log = $conn->getQueryLog();
        $this->assertSame('foo', $log[0]['query']);
        $this->assertEquals(['bar'], $log[0]['bindings']);
        $this->assertIsNumeric($log[0]['time']);
    }

    public function test_get_schema_state_errors_correctly()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Schema dumping is not supported when using Oracle.');

        $conn = m::mock(OracleConnection::class)->makePartial();
        $conn->getSchemaState();
    }

    #[TestWith([[
        'NLS_DATE_FORMAT' => 'YYYY-MM-DD HH24:MI:SS',
        'NLS_TIMESTAMP_FORMAT' => 'YYYY-MM-DD HH24:MI:SS.FF6',
        'CURRENT_SCHEMA' => 'MY_SCHEMA',
    ],
        "NLS_DATE_FORMAT = 'YYYY-MM-DD HH24:MI:SS' NLS_TIMESTAMP_FORMAT = 'YYYY-MM-DD HH24:MI:SS.FF6' CURRENT_SCHEMA = MY_SCHEMA"]
    )]
    #[TestWith([['TIME_ZONE' => '+00:00'], "TIME_ZONE = '+00:00'"])]
    public function test_set_session_parameters($parameters, $set_string)
    {
        $conn = m::mock(OracleConnection::class)->makePartial();

        $conn->shouldReceive('statement')
            ->once()
            ->with("ALTER SESSION SET {$set_string}")
            ->andReturn(true);

        $conn->setSessionParameters($parameters);
    }

    public function test_set_session_parameters_skips_execution_when_empty_array()
    {
        $conn = m::mock(OracleConnection::class)->makePartial();

        $conn->shouldReceive('statement')->never();

        $conn->setSessionParameters([]);
    }
}
