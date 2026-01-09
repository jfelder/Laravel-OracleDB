<?php

namespace Jfelder\OracleDB\Connectors;

use Illuminate\Database\Connectors\Connector;
use Illuminate\Database\Connectors\ConnectorInterface;
use InvalidArgumentException;
use Jfelder\OracleDB\OCI_PDO\OCI;
use PDO;

class OracleConnector extends Connector implements ConnectorInterface
{
    /**
     * The default PDO connection options.
     *
     * @var array
     */
    protected $options = [
        PDO::ATTR_CASE => PDO::CASE_LOWER,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_ORACLE_NULLS => PDO::NULL_NATURAL,
    ];

    /**
     * Create a new PDO connection.
     *
     * @param  string  $dsn
     * @return PDO
     *
     * @throws InvalidArgumentException
     */
    public function createConnection($dsn, array $config, array $options)
    {
        if ($config['driver'] === 'pdo') {
            return parent::createConnection($dsn, $config, $options);
        } elseif ($config['driver'] === 'oci8') {
            return new OCI($dsn, $config['username'], $config['password'], $options, $config['charset']);
        }

        throw new InvalidArgumentException('Unsupported driver ['.$config['driver'].'].');
    }

    /**
     * Establish a database connection.
     *
     * @return PDO
     */
    public function connect(array $config)
    {
        $dsn = $this->getDsn($config);

        $options = $this->getOptions($config);

        $connection = $this->createConnection($dsn, $config, $options);

        return $connection;
    }

    /**
     * Create a DSN string from a configuration.
     *
     * @return string
     */
    protected function getDsn(array $config)
    {
        if (empty($config['tns'])) {
            $config['tns'] = "(DESCRIPTION =(ADDRESS = (PROTOCOL = TCP)(HOST = {$config['host']})(PORT = {$config['port']}))(CONNECT_DATA =(SID = {$config['database']})))";
        }

        $rv = $config['tns'];

        if ($config['driver'] != 'oci8') {
            $rv = 'oci:dbname='.$rv.(empty($config['charset']) ? '' : ';charset='.$config['charset']);
        }

        return $rv;
    }
}
