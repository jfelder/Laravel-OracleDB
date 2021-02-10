<?php

use Jfelder\OracleDB\OracleConnection;
use Jfelder\OracleDB\Query\OracleBuilder;
use Mockery as m;
use PHPUnit\Framework\TestCase;

include 'mocks/OCIMocks.php';

class OracleDBOCIProcessorTest extends TestCase
{
    protected function setUp(): void
    {
        if (! extension_loaded('oci8')) {
            $this->markTestSkipped('The oci8 extension is not available.');
        }
    }

    public function tearDown(): void
    {
        m::close();
    }

    public function testInsertGetIdProcessing()
    {
        $stmt = m::mock(new ProcessorTestOCIStatementStub());
        $stmt->shouldReceive('bindValue')->times(4)->withAnyArgs();
        $stmt->shouldReceive('bindParam')->once()->with(5, 0, \PDO::PARAM_INT | \PDO::PARAM_INPUT_OUTPUT, 8);
        $stmt->shouldReceive('execute')->once()->withNoArgs();

        $pdo = m::mock(new ProcessorTestOCIStub());
        $pdo->shouldReceive('prepare')->once()->with('sql')->andReturn($stmt);

        $connection = m::mock(OracleConnection::class);
        $connection->shouldReceive('getPdo')->once()->andReturn($pdo);

        $builder = m::mock(OracleBuilder::class);
        $builder->shouldReceive('getConnection')->once()->andReturn($connection);

        $processor = new Jfelder\OracleDB\Query\Processors\OracleProcessor;

        $result = $processor->processInsertGetId($builder, 'sql', [1, 'foo', true, null], 'id');
        $this->assertSame(0, $result);
    }

    public function testProcessColumnListing()
    {
        $processor = new Jfelder\OracleDB\Query\Processors\OracleProcessor();
        $listing = [['column_name' => 'id'], ['column_name' => 'name'], ['column_name' => 'email']];
        $expected = ['id', 'name', 'email'];
        $this->assertEquals($expected, $processor->processColumnListing($listing));

        // convert listing to objects to simulate PDO::FETCH_CLASS
        foreach ($listing as &$row) {
            $row = (object) $row;
        }

        $this->assertEquals($expected, $processor->processColumnListing($listing));
    }
}
