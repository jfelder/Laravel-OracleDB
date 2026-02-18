<?php

namespace Jfelder\OracleDB\Tests;

use Jfelder\OracleDB\OracleConnection;
use Jfelder\OracleDB\Query\OracleBuilder;
use Jfelder\OracleDB\Query\Processors\OracleProcessor;
use Mockery as m;
use PHPUnit\Framework\TestCase;

include 'mocks/OCIMocks.php';

class OracleDBOCIProcessorTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
    }

    public function test_insert_get_id_processing()
    {
        $connection = m::mock(OracleConnection::class);
        $connection->shouldReceive('oracleInsertGetId')->once()->with('sql', [1, 'foo', true, null])->andReturn(1234);

        $builder = m::mock(OracleBuilder::class);
        $builder->shouldReceive('getConnection')->once()->andReturn($connection);

        $processor = new OracleProcessor;
        $result = $processor->processInsertGetId($builder, 'sql', [1, 'foo', true, null]);
        $this->assertSame(1234, $result);
    }

    // Laravel's DatabaseMySqlProcessorTest, DatabasePostgresProcessorTest, etc have a test named
    // testProcessColumns, but $processor->processColumns is only used by Schema Builder class, and we
    // are planning to remove Schema Builder entirely (todo) from this package.
}
