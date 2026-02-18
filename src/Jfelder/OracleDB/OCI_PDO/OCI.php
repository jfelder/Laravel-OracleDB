<?php

namespace Jfelder\OracleDB\OCI_PDO;

use PDO;

class OCI extends PDO
{
    /**
     * @var string
     */
    protected $dsn;

    /**
     * @var string Username for connection
     */
    protected $username;

    /**
     * @var string Password for connection
     */
    protected $password;

    /**
     * @var string Charset for connection
     */
    protected $charset;

    /**
     * @var resource oci8 Database connection
     */
    /**
     * @var bool|resource|string
     */
    protected $conn;

    /**
     * @var array Database connection attributes
     */
    protected $attributes = [
        PDO::ATTR_AUTOCOMMIT => 1,
        PDO::ATTR_ERRMODE => 0,
        PDO::ATTR_CASE => 0,
        PDO::ATTR_ORACLE_NULLS => 0,
    ];

    /**
     * @var bool Tracks if currently in a transaction
     */
    protected $transaction = false;

    /**
     * @var int Mode for executing on Database Connection
     */
    protected $mode = \OCI_COMMIT_ON_SUCCESS;

    /**
     * @var array PDO errorInfo array
     */
    protected $error = [0 => '', 1 => null, 2 => null];

    /**
     * @var string SQL statement to be run
     */
    public $queryString = '';

    /**
     * @var OCIStatement Statement object
     */
    protected $stmt = null;

    /**
     * @var bool Set this to FALSE to turn debug output off or TRUE to turn it on.
     */
    protected $internalDebug = false;

    /**
     * Constructor.
     *
     * @param  string  $dsn  DSN string to connect to database
     * @param  string  $username  Username of creditial to login to database
     * @param  string  $password  Password of creditial to login to database
     * @param  array  $driver_options  Options for the connection handle
     * @param  string  $charset  Character set to specify to the database when connecting
     *
     * @throws OCIException if connection fails
     */
    public function __construct($dsn, $username, $password, $driver_options = [], $charset = '')
    {
        $this->dsn = $dsn;
        $this->username = $username;
        $this->password = $password;
        $this->attributes = $driver_options + $this->attributes;
        $this->charset = $charset;

        if ($this->getAttribute(PDO::ATTR_PERSISTENT)) {
            $this->conn = oci_pconnect($username, $password, $dsn, $charset);
        } else {
            $this->conn = oci_connect($username, $password, $dsn, $charset);
        }

        // Check if connection was successful
        if (! $this->conn) {
            throw new OCIException($this->setErrorInfo('08006'));
        }
    }

    /**
     * Destructor - Checks for an oci resource and frees the resource if needed.
     */
    public function __destruct()
    {
        if (strtolower(get_resource_type($this->conn)) === 'oci8') {
            oci_close($this->conn);
        }
    }

    /**
     * Initiates a transaction.
     *
     * @return bool Returns TRUE on success
     *
     * @throws OCIException If already in a transaction
     */
    public function beginTransaction(): bool
    {
        if ($this->inTransaction()) {
            throw new OCIException($this->setErrorInfo('25000', '9999', 'Already in a transaction'));
        }

        $this->transaction = $this->setExecuteMode(\OCI_NO_AUTO_COMMIT);

        return true;
    }

    /**
     * Commits a transaction.
     *
     * @return bool Returns TRUE on success or FALSE on failure
     *
     * @throws OCIException If oci_commit fails
     */
    public function commit(): bool
    {
        if ($this->inTransaction()) {
            $r = oci_commit($this->conn);
            if (! $r) {
                throw new OCIException($this->setErrorInfo('08007'));
            }
            $this->transaction = ! $this->flipExecuteMode();

            return true;
        }

        return false;
    }

    /**
     * Fetch the SQLSTATE associated with the last operation on the database handle.
     *
     * @return mixed Returns SQLSTATE if available, otherwise null
     */
    public function errorCode(): ?string
    {
        return empty($this->error[0]) ? null : $this->error[0];
    }

    /**
     * Fetch extended error information associated with the last operation on the database handle.
     *
     * @return array Array of error information about the last operation performed
     */
    public function errorInfo(): array
    {
        return $this->error;
    }

    /**
     * Execute an SQL statement and return the number of affected rows.
     *
     * @param  string  $statement  The SQL statement to prepare and execute.
     * @return int Returns the number of rows that were modified or deleted by the statement
     */
    public function exec(string $statement): int|false
    {
        $this->prepare($statement);

        $result = $this->stmt->execute();

        return $result ? $this->stmt->rowCount() : false;
    }

    /**
     * Retrieve a database connection attribute.
     *
     * @param  int  $attribute  One of the PDO::ATTR_* constants.
     * @return mixed The value of the requested PDO attribute or null if it does not exist.
     */
    public function getAttribute(int $attribute): mixed
    {
        if ($attribute == PDO::ATTR_SERVER_VERSION) {
            return oci_server_version($this->conn);
        }

        return $this->attributes[$attribute] ?? null;
    }

    /**
     * Checks if inside a transaction.
     *
     * @return bool Returns TRUE if a transaction is currently active, and FALSE if not.
     */
    public function inTransaction(): bool
    {
        return $this->transaction;
    }

    /**
     * Returns the ID of the last inserted row or sequence value.
     *
     * @throws OCIException This feature is not supported
     */
    public function lastInsertId(?string $name = null): string|false
    {
        throw new OCIException($this->setErrorInfo('IM001', '0000', 'Driver does not support this function'));
    }

    /**
     * Prepares a statement for execution and returns a Jfelder\OracleDB\OCI_PDO\OCIStatement object.
     *
     * @param  string  $query  Valid SQL statement for the target database server.
     * @param  array  $options  Attribute values for the OCIStatement object
     * @return mixed Returns a OCIStatement on success, false otherwise
     */
    public function prepare(string $query, array $options = []): OCIStatement|false
    {
        $tokens = explode('?', $query);

        $count = count($tokens) - 1;
        if ($count) {
            $query = '';
            for ($i = 0; $i < $count; $i++) {
                $query .= trim($tokens[$i])." :{$i} ";
            }
            $query .= trim($tokens[$i]);
        }

        $this->queryString = $query;
        $stmt = oci_parse($this->conn, $this->queryString);
        $this->stmt = new OCIStatement($stmt, $this, $this->queryString, $options);

        return $this->stmt;
    }

    /**
     * Executes an SQL statement, returning a result set as a Jfelder\OracleDB\OCI_PDO\OCIStatement object
     * on success or false on failure.
     *
     * @param  string  $query  Valid SQL statement for the target database server.
     * @param  int  $fetchMode  The fetch mode must be one of the PDO::FETCH_* constants.
     * @param  mixed  ...$fetchModeArgs  Has no effect; was only included to extend parent.
     * @return mixed Returns a OCIStatement on success, false otherwise
     */
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): OCIStatement|false
    {
        $this->prepare($query);

        if ($fetchMode) {
            $this->stmt->setFetchMode($fetchMode, $fetchModeArgs);
        }

        $result = $this->stmt->execute();

        return $result ? $this->stmt : false;
    }

    /**
     * Quotes a string for use in a query.
     *
     * @param  string  $string  The string to be quoted.
     * @param  int  $type  Provides a data type hint for drivers that have alternate quoting styles.
     * @return string Returns false
     */
    public function quote(string $string, int $type = PDO::PARAM_STR): string|false
    {
        return false;
    }

    /**
     * Rolls back a transaction.
     *
     * @return bool Returns TRUE on success or FALSE on failure.
     *
     * @throws OCIException If oci_rollback returns an error.
     */
    public function rollBack(): bool
    {
        if ($this->inTransaction()) {
            $r = oci_rollback($this->conn);
            if (! $r) {
                throw new OCIException($this->setErrorInfo('40003'));
            }
            $this->transaction = ! $this->flipExecuteMode();

            return true;
        }

        return false;
    }

    /**
     * Set an attribute.
     *
     * @param  int  $attribute  PDO::ATTR_* attribute identifier
     * @param  mixed  $value  Value of PDO::ATTR_* attribute
     * @return true
     */
    public function setAttribute(int $attribute, mixed $value): bool
    {
        $this->attributes[$attribute] = $value;

        return true;
    }

    /**
     * CUSTOM CODE FROM HERE DOWN.
     *
     * All code above this is overriding the PDO base code
     * All code below this are custom helpers or other functionality provided by the oci_* functions
     */

    /**
     * Flip the execute mode.
     *
     * @return int Returns true
     */
    public function flipExecuteMode()
    {
        $this->setExecuteMode($this->getExecuteMode() == \OCI_COMMIT_ON_SUCCESS ? \OCI_NO_AUTO_COMMIT : \OCI_COMMIT_ON_SUCCESS);

        return true;
    }

    /**
     * Get the current Execute Mode for the conneciton.
     *
     * @return int Either \OCI_COMMIT_ON_SUCCESS or \OCI_NO_AUTO_COMMIT
     */
    public function getExecuteMode()
    {
        return $this->mode;
    }

    /**
     * Returns the oci8 connection handle for use with other oci_ functions.
     *
     * @return bool|oci8|resource|string The oci8 connection handle
     */
    public function getOCIResource()
    {
        return $this->conn;
    }

    /**
     * Set the PDO errorInfo array values.
     *
     * @param  string  $code  SQLSTATE identifier
     * @param  string  $error  Driver error code
     * @param  string  $message  Driver error message
     * @return array Returns the PDO errorInfo array
     */
    private function setErrorInfo($code = null, $error = null, $message = null)
    {
        if (is_null($code)) {
            $code = 'JF000';
        }

        if (is_null($error)) {
            $e = oci_error($this->conn);
            $error = $e['code'];
            $message = $e['message'].(empty($e['sqltext']) ? '' : ' - SQL: '.$e['sqltext']);
        }

        $this->error[0] = $code;
        $this->error[1] = $error;
        $this->error[2] = $message;

        return $this->error;
    }

    /**
     * Set the execute mode for the connection.
     *
     * @param  int  $mode  Either \OCI_COMMIT_ON_SUCCESS or \OCI_NO_AUTO_COMMIT
     * @return bool
     *
     * @throws OCIException If any value other than the above are passed in
     */
    public function setExecuteMode($mode)
    {
        if ($mode === \OCI_COMMIT_ON_SUCCESS || $mode === \OCI_NO_AUTO_COMMIT) {
            $this->mode = $mode;

            return true;
        }

        throw new OCIException($this->setErrorInfo('0A000', '9999', "Invalid commit mode specified: {$mode}"));
    }
}
