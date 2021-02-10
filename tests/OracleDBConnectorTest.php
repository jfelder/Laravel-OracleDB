<?php

use Illuminate\Database\Connectors\Connector;
use Jfelder\OracleDB\Connectors\OracleConnector;
use Mockery as m;
use PHPUnit\Framework\TestCase;

class OracleDBConnectorTest extends TestCase
{
    public function tearDown(): void
    {
        m::close();
    }

    public function testOptionResolution()
    {
        $connector = new Connector;
        $connector->setDefaultOptions([0 => 'foo', 1 => 'bar']);
        $this->assertEquals([0 => 'baz', 1 => 'bar', 2 => 'boom'], $connector->getOptions(['options' => [0 => 'baz', 2 => 'boom']]));
    }

    /**
     * @dataProvider OracleConnectProvider
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

    public function OracleConnectProvider()
    {
        return [
            ['oci:dbname=(DESCRIPTION =(ADDRESS = (PROTOCOL = TCP)(HOST = localhost)(PORT = 1234))(CONNECT_DATA =(SID = ORCL)))',
                ['driver' => 'pdo', 'host' => 'localhost', 'port' => '1234', 'database' => 'ORCL', 'tns' => ''], ],
            ['oci:dbname=(DESCRIPTION =(ADDRESS = (PROTOCOL = TCP)(HOST = localhost)(PORT = 4321))(CONNECT_DATA =(SID = ORCL)))',
                ['driver' => 'pdo', 'tns' => '(DESCRIPTION =(ADDRESS = (PROTOCOL = TCP)(HOST = localhost)(PORT = 4321))(CONNECT_DATA =(SID = ORCL)))'], ],
            ['(DESCRIPTION =(ADDRESS = (PROTOCOL = TCP)(HOST = localhost)(PORT = 6789))(CONNECT_DATA =(SID = ORCL)))',
                ['driver' => 'oci8', 'tns' => '(DESCRIPTION =(ADDRESS = (PROTOCOL = TCP)(HOST = localhost)(PORT = 6789))(CONNECT_DATA =(SID = ORCL)))'], ],
            ['(DESCRIPTION =(ADDRESS = (PROTOCOL = TCP)(HOST = localhost)(PORT = 9876))(CONNECT_DATA =(SID = ORCL)))',
                ['driver' => 'oci8', 'host' => 'localhost', 'port' => '9876', 'database' => 'ORCL', 'tns' => ''], ],
        ];
    }
}
