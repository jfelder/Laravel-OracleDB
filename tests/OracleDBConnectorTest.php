<?php

use Mockery as m;
use PHPUnit\Framework\TestCase as PHPUnit_Framework_TestCase;

class OracleDBConnectorTest extends PHPUnit_Framework_TestCase
{
    public function tearDown()
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

        $connector = m::mock('Jfelder\OracleDB\Connectors\OracleConnector[createConnection,getOptions]');
        $connector->shouldReceive('getOptions')->once()->withArgs([$config])->andReturn(['options']);
        $connector->shouldReceive('createConnection')->once()->withArgs([$dsn, $config, ['options']])->andReturn($connection);

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
