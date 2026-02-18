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
    public function createConnection($dsn, array $config, array $options): OCI
    {
        if (! in_array($config['driver'], ['oci8', 'oci', 'oracle'])) {
            throw new InvalidArgumentException('Unsupported driver.');
        }

        if (! isset($config['charset']) || empty($config['charset'])) {
            throw new InvalidArgumentException('Charset has not been set.');
        }

        $options['charset'] = $config['charset'];

        return parent::createConnection($dsn, $config, $options);
    }

    /**
     * Establish a database connection.
     *
     * @return PDO
     */
    public function connect(array $config)
    {
        $tns = $this->getDsn($config);

        $options = $this->getOptions($config);

        return $this->createConnection($tns, $config, $options);
    }

    /**
     * Create a DSN string from a configuration.
     *
     * @return string
     */
    protected function getDsn(array $config)
    {
        if (! empty($config['tns'])) {
            return $config['tns'];
        }

        $address = $this->getAddressBlock($config);
        $connect = $this->getConnectBlock($config);

        return "(DESCRIPTION =({$address})({$connect}))";
    }

    /**
     * Determine the address block of the tns string
     */
    private function getAddressBlock(array $config): string
    {
        // determine if multiple hosts are needed
        $hosts = explode(',', $config['host']);

        if (count($hosts) > 1) {
            $rv = 'ADDRESS_LIST =';

            if (! empty($config['load_balance'])) {
                $rv .= "(LOAD_BALANCE={$config['load_balance']})";
            }

            if (! empty($config['failover'])) {
                $rv .= "(FAILOVER={$config['failover']})";
            }

            // $ports = explode(',', $config['port']);
            $ports = explode(',', $config['port']);

            foreach ($hosts as $index => $host) {
                $rv .= "(ADDRESS = (PROTOCOL = TCP)(HOST = {$host})(PORT = ".($ports[$index] ?? $ports[0]).'))';
            }

            return $rv;

        } else {
            return "ADDRESS = (PROTOCOL = TCP)(HOST = {$config['host']})(PORT = {$config['port']})";
        }
    }

    /**
     * Determine the connect block of the tns string
     */
    private function getConnectBlock(array $config): string
    {
        $rv = 'CONNECT_DATA =';

        if (! empty($config['service_name'])) {
            $rv .= "(SERVICE_NAME = {$config['service_name']})";
        } else {
            $rv .= "(SID = {$config['database']})";
        }

        if (! empty($config['failover']) && strtoupper($config['failover']) == 'ON') {
            $rv .= '(FAILOVER_MODE='
                ."(TYPE={$config['failover_mode']['type']})"
                ."(METHOD={$config['failover_mode']['method']})"
                ."(RETRIES={$config['failover_mode']['retries']})"
                ."(DELAY={$config['failover_mode']['delay']}))";
        }

        return $rv;
    }

    /**
     * Create a new PDO connection instance.
     *
     * @param  string  $dsn
     * @param  string  $username
     * @param  string  $password
     * @param  array  $options
     */
    protected function createPdoConnection($dsn, $username, #[\SensitiveParameter] $password, $options): OCI
    {
        return new OCI($dsn, $username, $password, $options);
    }
}
