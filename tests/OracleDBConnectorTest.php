<?php

namespace Jfelder\OracleDB\Tests;

use Jfelder\OracleDB\Connectors\OracleConnector;
use Mockery as m;
use PHPUnit\Framework\TestCase;

class OracleDBConnectorTest extends TestCase
{
    public function tearDown(): void
    {
        m::close();
    }

    /**
     * @dataProvider oracleConnectProvider
     */
    public function testOracleConnectCallsCreateConnectionWithProperArguments($dsn, $config)
    {
        $connector = $this->getMockBuilder(OracleConnector::class)->onlyMethods(['createConnection', 'getOptions'])->getMock();
        $connection = m::mock(\stdClass::class);
        $connector->expects($this->once())->method('getOptions')->with($this->equalTo($config))->willReturn(['options']);
        $connector->expects($this->once())->method('createConnection')->with($this->equalTo($dsn), $this->equalTo($config), $this->equalTo(['options']))->willReturn($connection);
        $result = $connector->connect($config);

        $this->assertSame($result, $connection);
    }

    public static function oracleConnectProvider()
    {
        return [
            [
                'oci:dbname=(DESCRIPTION =(ADDRESS = (PROTOCOL = TCP)(HOST = localhost)(PORT = 1234))(CONNECT_DATA =(SID = ORCL)))',
                ['driver' => 'pdo', 'host' => 'localhost', 'port' => '1234', 'database' => 'ORCL', 'tns' => ''],
            ],
            [
                'oci:dbname=(DESCRIPTION =(ADDRESS = (PROTOCOL = TCP)(HOST = localhost)(PORT = 4321))(CONNECT_DATA =(SID = ORCL)))',
                ['driver' => 'pdo', 'tns' => '(DESCRIPTION =(ADDRESS = (PROTOCOL = TCP)(HOST = localhost)(PORT = 4321))(CONNECT_DATA =(SID = ORCL)))'],
            ],
            [
                '(DESCRIPTION =(ADDRESS = (PROTOCOL = TCP)(HOST = localhost)(PORT = 6789))(CONNECT_DATA =(SID = ORCL)))',
                ['driver' => 'oci8', 'tns' => '(DESCRIPTION =(ADDRESS = (PROTOCOL = TCP)(HOST = localhost)(PORT = 6789))(CONNECT_DATA =(SID = ORCL)))'],
            ],
            [
                '(DESCRIPTION =(ADDRESS = (PROTOCOL = TCP)(HOST = localhost)(PORT = 9876))(CONNECT_DATA =(SID = ORCL)))',
                ['driver' => 'oci8', 'host' => 'localhost', 'port' => '9876', 'database' => 'ORCL', 'tns' => ''],
            ],
        ];
    }

    public function testOracleConnectWithInvalidDriver()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported driver [garbage].');

        (new OracleConnector)->createConnection('', ['driver' => 'garbage'], []);
    }
}
