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
        $connection = m::mock(OracleConnection::class);
        $connection->shouldReceive('oracleInsertGetId')->once()->with('sql', [1, 'foo', true, null])->andReturn(1234);

        $builder = m::mock(OracleBuilder::class);
        $builder->shouldReceive('getConnection')->once()->andReturn($connection);

        $processor = new OracleProcessor;
        $result = $processor->processInsertGetId($builder, 'sql', [1, 'foo', true, null]);
        $this->assertSame(1234, $result);
    }

    public function testProcessColumnListing()
    {
        $processor = new OracleProcessor;
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
