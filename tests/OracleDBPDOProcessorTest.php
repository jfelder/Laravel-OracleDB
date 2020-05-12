<?php

use Mockery as m;
use PHPUnit\Framework\TestCase;

include 'mocks/PDOMocks.php';

class OracleDBPDOProcessorTest extends TestCase
{
    public function tearDown(): void
    {
        m::close();
    }

    public function testInsertGetIdProcessing()
    {
        $pdo = $this->getMockBuilder('ProcessorTestPDOStub')->getMock();
        $pdo->expects($this->once())->method('lastInsertId')->with($this->equalTo('id'))->will($this->returnValue('1'));

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
