<?php

use Mockery as m;

include 'mocks/PDOMocks.php';
use PHPUnit\Framework\TestCase as PHPUnit_Framework_TestCase;

class OracleDBPDOProcessorTest extends PHPUnit_Framework_TestCase
{
    public function tearDown()
    {
        m::close();
    }

    public function testInsertGetIdProcessing()
    {
        $pdo = m::mock('ProcessorTestPDOStub');

        $pdo->shouldReceive('lastInsertId')->once()->withArgs(['id'])->andReturn('1');

        $connection = m::mock('Illuminate\Database\Connection');
        $connection->shouldReceive('insert')->once()->with('sql', ['foo']);
        $connection->shouldReceive('getPdo')->andReturn($pdo);

        $builder = m::mock('Illuminate\Database\Query\Builder');
        $builder->shouldReceive('getConnection')->times(2)->andReturn($connection);

        $processor = new Illuminate\Database\Query\Processors\Processor;

        $result = $processor->processInsertGetId($builder, 'sql', ['foo'], 'id');
        $this->assertSame(1, $result);
    }
}
