<?php

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
        $connector = new Illuminate\Database\Connectors\Connector;
        $connector->setDefaultOptions([0 => 'foo', 1 => 'bar']);
        $this->assertEquals([0 => 'baz', 1 => 'bar', 2 => 'boom'], $connector->getOptions(['options' => [0 => 'baz', 2 => 'boom']]));
    }

    /**
     * @dataProvider OracleConnectProvider
     */
    public function testOracleConnectCallsCreateConnectionWithProperArguments($dsn, $config)
    {
        $connection = m::mock('stdClass');
        $connector = $this->getMockBuilder('Jfelder\OracleDB\Connectors\OracleConnector')->setMethods(['createConnection', 'getOptions'])->getMock();
        $connector->expects($this->once())->method('getOptions')->with($this->equalTo($config))->will($this->returnValue(['options']));
        $connector->expects($this->once())->method('createConnection')->with($this->equalTo($dsn), $this->equalTo($config), $this->equalTo(['options']))->will($this->returnValue($connection));
        
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
