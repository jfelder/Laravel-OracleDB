<?php

namespace Jfelder\OracleDB\Tests;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Processors\Processor;
use Mockery as m;
use PHPUnit\Framework\TestCase;
use ProcessorTestPDOStub;

include 'mocks/PDOMocks.php';

class OracleDBPDOProcessorTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
    }

    public function test_insert_get_id_processing()
    {
        $pdo = $this->getMockBuilder(ProcessorTestPDOStub::class)->getMock();
        $pdo->expects($this->once())->method('lastInsertId')->with($this->equalTo('id'))->willReturn('1');

        $connection = m::mock(Connection::class);
        $connection->shouldReceive('insert')->once()->with('sql', ['foo']);
        $connection->shouldReceive('getPdo')->andReturn($pdo);

        $builder = m::mock(Builder::class);
        $builder->shouldReceive('getConnection')->times(2)->andReturn($connection);

        $processor = new Processor;

        $result = $processor->processInsertGetId($builder, 'sql', ['foo'], 'id');
        $this->assertSame(1, $result);
    }
}
