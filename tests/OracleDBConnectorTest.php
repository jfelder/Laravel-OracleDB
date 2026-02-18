<?php

namespace Jfelder\OracleDB\Tests;

use Jfelder\OracleDB\Connectors\OracleConnector;
use Jfelder\OracleDB\OCI_PDO\OCI;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

include 'mocks/OCIFunctions.php';

class OracleDBConnectorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        global $OCITransactionStatus;

        $OCITransactionStatus = true;
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    #[DataProvider('oracleConnectProvider')]
    public function test_oracle_connect_calls_create_pdo_connection_with_proper_arguments($dsn, $config)
    {
        $options = ['options', 'charset' => $config['charset']];
        [$username, $password] = [
            $config['username'] ?? null, $config['password'] ?? null,
        ];

        $connector = m::mock(OracleConnector::class.'[getOptions, createPDOConnection]')
            ->shouldAllowMockingProtectedMethods();

        $connection = m::mock(OCI::class);

        $connector->shouldReceive('getOptions')
            ->once()
            ->with($config)
            ->andReturn($options);

        $connector->shouldReceive('createPDOConnection')
            ->once()
            ->with($dsn, $username, $password, $options)
            ->andReturn($connection);

        $result = $connector->connect($config);

        $this->assertSame($result, $connection);
    }

    public static function oracleConnectProvider()
    {
        return [
            [
                '(DESCRIPTION =(ADDRESS = (PROTOCOL = TCP)(HOST = localhost)(PORT = 6789))(CONNECT_DATA =(SID = ORCL)))',
                ['driver' => 'oracle', 'charset' => 'WE8ISO8859P1', 'tns' => '(DESCRIPTION =(ADDRESS = (PROTOCOL = TCP)(HOST = localhost)(PORT = 6789))(CONNECT_DATA =(SID = ORCL)))'],
            ],
            [
                '(DESCRIPTION =(ADDRESS = (PROTOCOL = TCP)(HOST = localhost)(PORT = 6789))(CONNECT_DATA =(SID = ORCL)))',
                ['driver' => 'oci', 'charset' => 'WE8ISO8859P1', 'tns' => '(DESCRIPTION =(ADDRESS = (PROTOCOL = TCP)(HOST = localhost)(PORT = 6789))(CONNECT_DATA =(SID = ORCL)))'],
            ],
            [
                '(DESCRIPTION =(ADDRESS = (PROTOCOL = TCP)(HOST = localhost)(PORT = 6789))(CONNECT_DATA =(SID = ORCL)))',
                ['driver' => 'oci8', 'charset' => 'WE8ISO8859P1', 'tns' => '(DESCRIPTION =(ADDRESS = (PROTOCOL = TCP)(HOST = localhost)(PORT = 6789))(CONNECT_DATA =(SID = ORCL)))'],
            ],
            [
                '(DESCRIPTION =(ADDRESS = (PROTOCOL = TCP)(HOST = localhost)(PORT = 6789))(CONNECT_DATA =(SID = ORCL)))',
                ['driver' => 'oracle', 'charset' => 'WE8ISO8859P1', 'tns' => '(DESCRIPTION =(ADDRESS = (PROTOCOL = TCP)(HOST = localhost)(PORT = 6789))(CONNECT_DATA =(SID = ORCL)))'],
            ],
            [
                '(DESCRIPTION =(ADDRESS = (PROTOCOL = TCP)(HOST = localhost)(PORT = 6789))(CONNECT_DATA =(SID = ORCL)))',
                ['driver' => 'oci', 'charset' => 'WE8ISO8859P1', 'tns' => '(DESCRIPTION =(ADDRESS = (PROTOCOL = TCP)(HOST = localhost)(PORT = 6789))(CONNECT_DATA =(SID = ORCL)))'],
            ],
            [
                '(DESCRIPTION =(ADDRESS = (PROTOCOL = TCP)(HOST = localhost)(PORT = 6789))(CONNECT_DATA =(SID = ORCL)))',
                ['driver' => 'oci8', 'charset' => 'WE8ISO8859P1', 'tns' => '(DESCRIPTION =(ADDRESS = (PROTOCOL = TCP)(HOST = localhost)(PORT = 6789))(CONNECT_DATA =(SID = ORCL)))'],
            ],
            [
                '(DESCRIPTION =(ADDRESS = (PROTOCOL = TCP)(HOST = localhost)(PORT = 9876))(CONNECT_DATA =(SID = ORCL)))',
                ['driver' => 'oracle', 'charset' => 'WE8ISO8859P1', 'host' => 'localhost', 'port' => '9876', 'database' => 'ORCL', 'tns' => ''],
            ],
            [
                '(DESCRIPTION =(ADDRESS = (PROTOCOL = TCP)(HOST = localhost)(PORT = 9876))(CONNECT_DATA =(SID = ORCL)))',
                ['driver' => 'oci', 'charset' => 'WE8ISO8859P1', 'host' => 'localhost', 'port' => '9876', 'database' => 'ORCL', 'tns' => ''],
            ],
            [
                '(DESCRIPTION =(ADDRESS = (PROTOCOL = TCP)(HOST = localhost)(PORT = 9876))(CONNECT_DATA =(SID = ORCL)))',
                ['driver' => 'oci8', 'charset' => 'WE8ISO8859P1', 'host' => 'localhost', 'port' => '9876', 'database' => 'ORCL', 'tns' => ''],
            ],
            [
                '(DESCRIPTION =(ADDRESS_LIST =(LOAD_BALANCE=OFF)(FAILOVER=ON)(ADDRESS = (PROTOCOL = TCP)(HOST = host1)(PORT = 9876))(ADDRESS = (PROTOCOL = TCP)(HOST = host2)(PORT = 9876))(ADDRESS = (PROTOCOL = TCP)(HOST = host3)(PORT = 9876)))(CONNECT_DATA =(SERVICE_NAME = ORCL)(FAILOVER_MODE=(TYPE=SELECT)(METHOD=BASIC)(RETRIES=20)(DELAY=15))))',
                ['driver' => 'oracle', 'charset' => 'WE8ISO8859P1', 'host' => 'host1,host2,host3', 'port' => '9876', 'service_name' => 'ORCL', 'load_balance' => 'OFF', 'failover' => 'ON', 'failover_mode' => ['type' => 'SELECT', 'method' => 'BASIC', 'retries' => 20, 'delay' => 15], 'tns' => ''],
            ],
            [
                '(DESCRIPTION =(ADDRESS_LIST =(LOAD_BALANCE=OFF)(FAILOVER=ON)(ADDRESS = (PROTOCOL = TCP)(HOST = host1)(PORT = 9876))(ADDRESS = (PROTOCOL = TCP)(HOST = host2)(PORT = 9876))(ADDRESS = (PROTOCOL = TCP)(HOST = host3)(PORT = 9876)))(CONNECT_DATA =(SERVICE_NAME = ORCL)(FAILOVER_MODE=(TYPE=SELECT)(METHOD=BASIC)(RETRIES=20)(DELAY=15))))',
                ['driver' => 'oci', 'charset' => 'WE8ISO8859P1', 'host' => 'host1,host2,host3', 'port' => '9876', 'service_name' => 'ORCL', 'load_balance' => 'OFF', 'failover' => 'ON', 'failover_mode' => ['type' => 'SELECT', 'method' => 'BASIC', 'retries' => 20, 'delay' => 15], 'tns' => ''],
            ],
            [
                '(DESCRIPTION =(ADDRESS_LIST =(LOAD_BALANCE=OFF)(FAILOVER=ON)(ADDRESS = (PROTOCOL = TCP)(HOST = host1)(PORT = 9876))(ADDRESS = (PROTOCOL = TCP)(HOST = host2)(PORT = 9876))(ADDRESS = (PROTOCOL = TCP)(HOST = host3)(PORT = 9876)))(CONNECT_DATA =(SERVICE_NAME = ORCL)(FAILOVER_MODE=(TYPE=SELECT)(METHOD=BASIC)(RETRIES=20)(DELAY=15))))',
                ['driver' => 'oci8', 'charset' => 'WE8ISO8859P1', 'host' => 'host1,host2,host3', 'port' => '9876', 'service_name' => 'ORCL', 'load_balance' => 'OFF', 'failover' => 'ON', 'failover_mode' => ['type' => 'SELECT', 'method' => 'BASIC', 'retries' => 20, 'delay' => 15], 'tns' => ''],
            ],
            [
                '(DESCRIPTION =(ADDRESS_LIST =(ADDRESS = (PROTOCOL = TCP)(HOST = host1)(PORT = 9876))(ADDRESS = (PROTOCOL = TCP)(HOST = host2)(PORT = 9876))(ADDRESS = (PROTOCOL = TCP)(HOST = host3)(PORT = 9876)))(CONNECT_DATA =(SERVICE_NAME = ORCL)))',
                ['driver' => 'oracle', 'charset' => 'WE8ISO8859P1', 'host' => 'host1,host2,host3', 'port' => '9876', 'service_name' => 'ORCL', 'load_balance' => '', 'failover' => '', 'failover_mode' => ['type' => 'SELECT', 'method' => 'BASIC', 'retries' => 20, 'delay' => 15], 'tns' => ''],
            ],
            [
                '(DESCRIPTION =(ADDRESS_LIST =(ADDRESS = (PROTOCOL = TCP)(HOST = host1)(PORT = 9876))(ADDRESS = (PROTOCOL = TCP)(HOST = host2)(PORT = 9876))(ADDRESS = (PROTOCOL = TCP)(HOST = host3)(PORT = 9876)))(CONNECT_DATA =(SERVICE_NAME = ORCL)))',
                ['driver' => 'oci', 'charset' => 'WE8ISO8859P1', 'host' => 'host1,host2,host3', 'port' => '9876', 'service_name' => 'ORCL', 'load_balance' => '', 'failover' => '', 'failover_mode' => ['type' => 'SELECT', 'method' => 'BASIC', 'retries' => 20, 'delay' => 15], 'tns' => ''],
            ],
            [
                '(DESCRIPTION =(ADDRESS_LIST =(ADDRESS = (PROTOCOL = TCP)(HOST = host1)(PORT = 9876))(ADDRESS = (PROTOCOL = TCP)(HOST = host2)(PORT = 9876))(ADDRESS = (PROTOCOL = TCP)(HOST = host3)(PORT = 9876)))(CONNECT_DATA =(SERVICE_NAME = ORCL)))',
                ['driver' => 'oci8', 'charset' => 'WE8ISO8859P1', 'host' => 'host1,host2,host3', 'port' => '9876', 'service_name' => 'ORCL', 'load_balance' => '', 'failover' => '', 'failover_mode' => ['type' => 'SELECT', 'method' => 'BASIC', 'retries' => 20, 'delay' => 15], 'tns' => ''],
            ],
        ];
    }

    #[TestWith(['garbage'])]
    #[TestWith(['pdo'])]
    public function test_oracle_connect_with_invalid_driver($driver)
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported driver.');

        (new OracleConnector)->createConnection('', ['driver' => $driver], []);
    }

    public function test_oracle_connect_with_no_charset_defined()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Charset has not been set.');

        (new OracleConnector)->createConnection('', ['driver' => 'oracle'], []);
    }

    #[TestWith([null])]
    #[TestWith([''])]
    #[TestWith([false])]
    public function test_oracle_connect_with_blank_charset($charset)
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Charset has not been set.');

        (new OracleConnector)->createConnection('', ['driver' => 'oracle', 'charset' => $charset], []);
    }
}
