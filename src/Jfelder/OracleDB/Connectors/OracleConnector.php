<?php

namespace Jfelder\OracleDB\Connectors;

use Illuminate\Database\Connectors\Connector as Connector;
use Illuminate\Database\Connectors\ConnectorInterface as ConnectorInterface;
use Jfelder\OracleDB\OCI_PDO\OCI as OCI;

class OracleConnector extends Connector implements ConnectorInterface
{
    /**
     * The default PDO connection options.
     *
     * @var array
     */
    protected $options = [
        \PDO::ATTR_CASE => \PDO::CASE_LOWER,
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_ORACLE_NULLS => \PDO::NULL_NATURAL,
    ];

    /**
     * Create a new PDO connection.
     *
     * @param  string  $dsn
     * @param  array   $config
     * @param  array   $options
     * @return PDO
     */
    public function createConnection($dsn, array $config, array $options)
    {
        if ($config['driver'] == 'pdo') {
            return parent::createConnection($dsn, $config, $options);
        } else {
            return new OCI($dsn, $config['username'], $config['password'], $options, $config['charset']);
        }
    }

    /**
     * Establish a database connection.
     *
     * @param  array  $config
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
     * @param  array   $config
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
