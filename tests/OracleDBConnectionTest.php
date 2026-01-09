<?php

namespace Jfelder\OracleDB\Tests;

use Jfelder\OracleDB\OCI_PDO\OCI;
use Jfelder\OracleDB\OCI_PDO\OCIStatement;
use Jfelder\OracleDB\OracleConnection;
use Mockery as m;
use PDO;
use PHPUnit\Framework\TestCase;

class OracleDBConnectionTest extends TestCase
{
    protected function setUp(): void
    {
        if (! extension_loaded('oci8')) {
            $this->markTestSkipped('The oci8 extension is not available.');
        }
    }

    protected function tearDown(): void
    {
        m::close();
    }

    public function test_oracle_insert_get_id_properly_calls_pdo()
    {
        $pdo = $this->getMockBuilder(OracleDBConnectionTestMockPDO::class)->onlyMethods(['prepare'])->getMock();
        $statement = $this->getMockBuilder(OracleDBConnectionTestMockOCIStatement::class)->onlyMethods(['execute', 'bindValue', 'bindParam'])->getMock();
        $statement->expects($this->once())->method('bindValue')->with(0, 'bar', 2);
        $statement->expects($this->once())->method('bindParam')->with(1, 0, PDO::PARAM_INT | PDO::PARAM_INPUT_OUTPUT, 8);
        $statement->expects($this->once())->method('execute');
        $pdo->expects($this->once())->method('prepare')->with($this->equalTo('foo'))->willReturn($statement);
        $mock = $this->getMockConnection(['prepareBindings'], $pdo);
        $mock->expects($this->once())->method('prepareBindings')->with($this->equalTo(['bar']))->willReturn(['bar']);
        $results = $mock->oracleInsertGetId('foo', ['bar']);
        $this->assertSame(0, $results);
        $log = $mock->getQueryLog();
        $this->assertSame('foo', $log[0]['query']);
        $this->assertEquals(['bar'], $log[0]['bindings']);
        $this->assertIsNumeric($log[0]['time']);
    }

    protected function getMockConnection($methods = [], $pdo = null)
    {
        $pdo = $pdo ?: new OracleDBConnectionTestMockPDO;
        $defaults = ['getDefaultQueryGrammar', 'getDefaultPostProcessor', 'getDefaultSchemaGrammar'];
        $connection = $this->getMockBuilder(OracleConnection::class)->onlyMethods(array_merge($defaults, $methods))->setConstructorArgs([$pdo])->getMock();
        $connection->enableQueryLog();

        return $connection;
    }
}

class OracleDBConnectionTestMockPDO extends OCI
{
    public function __construct()
    {
        //
    }

    public function __destruct()
    {
        //
    }
}

class OracleDBConnectionTestMockOCIStatement extends OCIStatement
{
    public function __construct()
    {
        //
    }

    public function __destruct()
    {
        //
    }
}
