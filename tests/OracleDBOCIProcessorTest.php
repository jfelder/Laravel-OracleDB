<?php

use Mockery as m;
use PHPUnit\Framework\TestCase as PHPUnit_Framework_TestCase;

include 'mocks/OCIMocks.php';

class OracleDBOCIProcessorTest extends PHPUnit_Framework_TestCase
{
    // defining here in case oci8 extension not installed

    protected function setUp()
    {
        if (! extension_loaded('oci8')) {
            $this->markTestSkipped('The oci8 extension is not available.');
        }
    }

    public function tearDown()
    {
        m::close();
    }

    public function testInsertGetIdProcessing()
    {
        $stmt = m::mock(new ProcessorTestOCIStatementStub());
        $stmt->shouldReceive('bindValue')->times(1)->with(1, 1, \PDO::PARAM_INT);
        $stmt->shouldReceive('bindValue')->times(1)->with(2, 'foo', \PDO::PARAM_STR);
        $stmt->shouldReceive('bindValue')->times(1)->with(3, true, \PDO::PARAM_BOOL);
        $stmt->shouldReceive('bindValue')->times(1)->with(4, null, \PDO::PARAM_NULL);
        $stmt->shouldReceive('bindParam')->once()->with(5, 0, \PDO::PARAM_INT | \PDO::PARAM_INPUT_OUTPUT, 8);
        $stmt->shouldReceive('execute')->once()->withNoArgs();

        $pdo = m::mock(new ProcessorTestOCIStub());
        $pdo->shouldReceive('prepare')->once()->with('sql')->andReturn($stmt);

        $grammar = m::mock('Illuminate\Database\Query\Grammars\Grammar');

        $connection = m::mock('Illuminate\Database\Connection');
        $connection->shouldReceive('getPdo')->once()->andReturn($pdo);
        $connection->shouldReceive('getQueryGrammar')->times(4)->andReturn($grammar);

        $builder = m::mock('Illuminate\Database\Query\Builder');
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

    public function testPrepareValueWithDates()
    {
        $first = \Carbon\Carbon::now();
        $second = date('m/d/Y');
        $third = DateTime::createFromFormat('d/m/Y', date('d/m/Y'));

        // date type that should be converted to stings
        $params = [
            $first,
            $second,
            $third
        ];

        $stmt = m::mock(new ProcessorTestOCIStatementStub());
        // since we have a check for pdo style params, these will be 1 based
        $stmt->shouldReceive('bindValue')->times(1)->with(1, $first->format('Y-m-d H:i:s'), \PDO::PARAM_STR);
        $stmt->shouldReceive('bindValue')->times(1)->with(2, (string) $second, \PDO::PARAM_STR);
        $stmt->shouldReceive('bindValue')->times(1)->with(3, $third->format('Y-m-d H:i:s'), \PDO::PARAM_STR);
        $stmt->shouldReceive('bindParam')->once()->with(4, 0, \PDO::PARAM_INT | \PDO::PARAM_INPUT_OUTPUT, 8);
        $stmt->shouldReceive('execute')->once()->withNoArgs();

        $pdo = m::mock(new ProcessorTestOCIStub());
        $pdo->shouldReceive('prepare')->once()->with('sql')->andReturn($stmt);

        $grammar = m::mock('Illuminate\Database\Query\Grammars\Grammar');
        $grammar->shouldReceive('getDateFormat')->times(2)->andReturn('Y-m-d H:i:s');

        $connection = m::mock('Illuminate\Database\Connection');
        $connection->shouldReceive('getPdo')->once()->andReturn($pdo);
        $connection->shouldReceive('getQueryGrammar')->times(3)->andReturn($grammar);

        $builder = m::mock('Illuminate\Database\Query\Builder');
        $builder->shouldReceive('getConnection')->once()->andReturn($connection);

        $processor = new Jfelder\OracleDB\Query\Processors\OracleProcessor;

        $result = $processor->processInsertGetId($builder, 'sql', $params, 'id');
        $this->assertSame(0, $result);
    }

}
