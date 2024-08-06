<?php

namespace Jfelder\OracleDB\OCI_PDO;

use PDO;
use PDOStatement;

class OCIStatement extends PDOStatement
{
    /**
     * @var oci8 statement Statement handle
     */
    protected $stmt;

    /**
     * @var oci8 Database connection
     */
    protected $conn;

    /**
     * @var array Database statement attributes
     */
    protected $attributes;

    /**
     * @var string SQL statement
     */
    protected $sql = '';

    /**
     * @var array SQL statement parameters
     */
    protected $parameters = [];

    /**
     * @var array PDO => OCI data types conversion var
     */
    protected $datatypes = [
        PDO::PARAM_BOOL => \SQLT_INT,
        // there is no SQLT_NULL, but oracle will insert a null value if it receives an empty string
        PDO::PARAM_NULL => \SQLT_CHR,
        PDO::PARAM_INT => \SQLT_INT,
        PDO::PARAM_STR => \SQLT_CHR,
        PDO::PARAM_INPUT_OUTPUT => \SQLT_CHR,
        PDO::PARAM_LOB => \SQLT_BLOB,
    ];

    /**
     * @var array PDO errorInfo array
     */
    protected $error = [
        0 => '',
        1 => null,
        2 => null,
    ];

    /**
     * @var array Array to hold column bindings
     */
    protected $bindings = [];

    /**
     * @var array Array to hold descriptors
     */
    protected $descriptors = [];

    /**
     * Constructor.
     *
     * @param  resource  $stmt  Statement handle created with oci_parse()
     * @param  OCI  $oci  The OCI object for this statement
     * @param  array  $options  Options for the statement handle
     *
     * @throws OCIException if $stmt is not a vaild oci8 statement resource
     */
    public function __construct($stmt, OCI $oci, $sql = '', $options = [])
    {
        $resource_type = strtolower(get_resource_type($stmt));

        if ($resource_type !== 'oci8 statement') {
            throw new OCIException($this->setErrorInfo('0A000', '9999', "Invalid resource received: {$resource_type}"));
        }

        $this->stmt = $stmt;
        $this->conn = $oci;
        $this->sql = $sql;
        $this->attributes = $options;
    }

    /**
     * Destructor - Checks for an oci statment resource and frees the resource if needed.
     */
    public function __destruct()
    {
        if (strtolower(get_resource_type($this->stmt)) == 'oci8 statement') {
            oci_free_statement($this->stmt);
        }

        //Also test for descriptors
    }

    /**
     * Bind a column to a PHP variable.
     *
     * @param  mixed  $column  Number of the column (1-indexed) in the result set
     * @param  mixed  $var  Name of the PHP variable to which the column will be bound.
     * @param  int  $type  Data type of the parameter, specified by the PDO::PARAM_* constants.
     * @param  int  $maxLength  A hint for pre-allocation.
     * @param  mixed  $driverOptions  Optional parameter(s) for the driver.
     * @return bool Returns TRUE on success or FALSE on failure.
     *
     * @throws \InvalidArgumentException If an unknown data type is passed in
     */
    public function bindColumn(string|int $column, mixed &$var, ?int $type = null, int $maxLength = 0, mixed $driverOptions = null): bool
    {
        if (! is_numeric($column) || $column < 1) {
            throw new \InvalidArgumentException("Invalid column specified: {$column}");
        }

        if (! isset($this->datatypes[$type])) {
            throw new \InvalidArgumentException("Unknown data type in oci_bind_by_name: {$type}");
        }

        $this->bindings[$column] = [
            'var' => &$var,
            'data_type' => $type,
            'max_length' => $maxLength,
            'driverdata' => $driverOptions,
        ];

        return true;
    }

    /**
     * Binds a parameter to the specified variable name.
     *
     * @param  mixed  $param  Parameter identifier
     * @param  mixed  $var  Name of the PHP variable to bind to the SQL statement parameter
     * @param  int  $type  Explicit data type for the parameter using the PDO::PARAM_* constants
     * @param  int  $maxLength  Length of the data type
     * @return bool Returns TRUE on success or FALSE on failure
     *
     * @throws \InvalidArgumentException If an unknown data type is passed in
     */
    public function bindParam(string|int $param, mixed &$var, int $type = PDO::PARAM_STR, int $maxLength = -1, mixed $driverOptions = null): bool
    {
        if (is_numeric($param)) {
            $param = ":{$param}";
        }

        $this->addParameter($param, $var, $type, $maxLength, $driverOptions);

        if (! isset($this->datatypes[$type])) {
            if ($type === (PDO::PARAM_INT | PDO::PARAM_INPUT_OUTPUT)) {
                $type = PDO::PARAM_STR;
                $maxLength = $maxLength > 40 ? $maxLength : 40;
            } else {
                throw new \InvalidArgumentException("Unknown data type in oci_bind_by_name: {$type}");
            }
        }

        $result = oci_bind_by_name($this->stmt, $param, $var, $maxLength, $this->datatypes[$type]);

        return $result;
    }

    /**
     * Binds a value to a parameter.
     *
     * @param  mixed  $param  Parameter identifier.
     * @param  mixed  $value  The value to bind to the parameter
     * @param  int  $type  Explicit data type for the parameter using the PDO::PARAM_* constants
     * @return bool Returns TRUE on success or FALSE on failure.
     *
     * @throws \InvalidArgumentException If an unknown data type is passed in
     */
    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        if (is_numeric($param)) {
            $param = ":{$param}";
        }

        $this->addParameter($param, $value, $type);

        if (! isset($this->datatypes[$type])) {
            throw new \InvalidArgumentException("Unknown data type in oci_bind_by_name: {$type}");
        }

        $result = oci_bind_by_name($this->stmt, $param, $value, -1, $this->datatypes[$type]);

        return $result;
    }

    /**
     * Closes the cursor, enabling the statement to be executed again.
     *
     * Todo implement this method instead of always returning true
     *
     * @return bool Returns TRUE on success or FALSE on failure.
     */
    public function closeCursor(): bool
    {
        return true;
    }

    /**
     * Returns the number of columns in the result set.
     *
     * @return int Returns the number of columns in the result set represented by the PDOStatement object.
     *             If there is no result set, returns 0.
     */
    public function columnCount(): int
    {
        return oci_num_fields($this->stmt);
    }

    /**
     * Dump an SQL prepared command directly to the normal output.
     *
     * @return bool Returns true.
     */
    public function debugDumpParams(): ?bool
    {
        return print_r(['sql' => $this->sql, 'params' => $this->parameters]);
    }

    /**
     * Fetch the SQLSTATE associated with the last operation on the statement handle.
     *
     * @return mixed Returns an SQLSTATE or NULL if no operation has been run
     */
    public function errorCode(): ?string
    {
        return empty($this->error[0]) ? null : $this->error[0];
    }

    /**
     * Fetch extended error information associated with the last operation on the statement handle.
     *
     * @return array array of error information about the last operation performed
     */
    public function errorInfo(): array
    {
        return $this->error;
    }

    /**
     * Executes a prepared statement.
     *
     * @param  array  $params  An array of values with as many elements as there are bound parameters in the
     *                         SQL statement being executed
     * @return bool Returns TRUE on success or FALSE on failure.
     */
    public function execute(?array $params = null): bool
    {
        if (is_array($params)) {
            foreach ($params as $k => $v) {
                $this->bindParam($k, $params[$k]);
            }
        }

        $result = oci_execute($this->stmt, $this->conn->getExecuteMode());

        if (! $result) {
            $this->setErrorInfo('07000');
        }

        $this->processBindings($result);

        return $result;
    }

    /**
     * Fetches the next row from a result set.
     *
     * @param  int  $mode  Controls how the next row will be returned to the caller. This value must be one of
     *                     the PDO::FETCH_* constants
     * @param  int  $cursorOrientation  Has no effect; was only included to extend parent.
     * @param  int  $cursorOffset  Has no effect; was only included to extend parent.
     * @return mixed The return value of this function on success depends on the fetch type.
     *               In all cases, FALSE is returned on failure.
     */
    public function fetch(int $mode = PDO::FETCH_CLASS, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        // set global fetch_style
        $this->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, $mode);

        // init return value
        $rs = false;

        // determine what oci_fetch_* to run
        switch ($mode) {
            case PDO::FETCH_CLASS:
            case PDO::FETCH_ASSOC:
                $rs = oci_fetch_assoc($this->stmt);
                break;
            case PDO::FETCH_NUM:
                $rs = oci_fetch_row($this->stmt);
                break;
            default:
                $rs = oci_fetch_array($this->stmt);
                break;
        }

        if (! $rs) {
            $this->setErrorInfo('07000');
        }

        $this->processBindings($rs);

        return $this->processFetchOptions($rs);
    }

    /**
     * Returns an array containing all of the result set rows.
     *
     * @param  int|null  $mode  Controls how the next row will be returned to the caller. This value must be one
     *                          of the PDO::FETCH_* constants
     * @param  mixed  ...$args  Has no effect; was only included to extend parent.
     */
    public function fetchAll(int $mode = PDO::FETCH_CLASS, mixed ...$args): array
    {
        if ($mode != PDO::FETCH_CLASS && $mode != PDO::FETCH_ASSOC) {
            throw new \InvalidArgumentException(
                "Invalid fetch style requested: {$mode}. Only PDO::FETCH_CLASS and PDO::FETCH_ASSOC suported."
            );
        }

        $this->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, $mode);

        oci_fetch_all($this->stmt, $results, 0, -1, \OCI_FETCHSTATEMENT_BY_ROW + \OCI_ASSOC);

        foreach ($results as $k => $v) {
            $results[$k] = $this->processFetchOptions($v);
        }

        return $results;
    }

    /**
     * Returns a single column from the next row of a result set.
     *
     * @param  int  $column  0-indexed number of the column you wish to retrieve from the row.
     *                       If no value is supplied, fetchColumn fetches the first column.
     * @return mixed single column in the next row of a result set
     */
    public function fetchColumn(int $column = 0): mixed
    {
        $rs = $this->fetch(PDO::FETCH_NUM);

        return isset($rs[$column]) ? $rs[$column] : false;
    }

    /**
     * Fetches the next row and returns it as an object.
     *
     * @param  string  $class  Name of the created class
     * @param  array  $constructorArgs  Elements of this array are passed to the constructor
     * @return bool Returns an instance of the required class with property names that correspond to the column names
     *              or FALSE on failure.
     */
    public function fetchObject(?string $class = 'stdClass', array $constructorArgs = []): object|false
    {
        $this->setFetchMode(PDO::FETCH_CLASS, $class, $constructorArgs);

        return $this->fetch(PDO::FETCH_CLASS);
    }

    /**
     * Retrieve a statement attribute.
     *
     * @param  int  $name  The attribute number
     * @return mixed Returns the value of the attribute on success or null on failure
     */
    public function getAttribute(int $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }

    /**
     * Returns metadata for a column in a result set.
     *
     * @param  int  $column  The 0-indexed column in the result set.
     * @return array Returns an associative array representing the metadata for a single column
     */
    public function getColumnMeta(int $column): array|false
    {
        $column++;

        return [
            'native_type' => oci_field_type($this->stmt, $column),
            'driver:decl_type' => oci_field_type_raw($this->stmt, $column),
            'name' => oci_field_name($this->stmt, $column),
            'len' => oci_field_size($this->stmt, $column),
            'precision' => oci_field_precision($this->stmt, $column),
        ];
    }

    /**
     * Advances to the next rowset in a multi-rowset statement handle.
     *
     * @return bool Returns TRUE on success or FALSE on failure
     */
    public function nextRowset(): bool
    {
        return true;
    }

    /**
     * Returns the number of rows affected by the last SQL statement.
     *
     * @return int Returns the number of rows affected as an integer, or FALSE on errors.
     */
    public function rowCount(): int
    {
        return oci_num_rows($this->stmt);
    }

    /**
     * Set a statement attribute.
     *
     * @param  int  $attribute  The attribute number
     * @param  mixed  $value  Value of named attribute
     * @return bool Returns TRUE
     */
    public function setAttribute(int $attribute, mixed $value): bool
    {
        $this->attributes[$attribute] = $value;

        return true;
    }

    /**
     * Set the default fetch mode for this statement.
     *
     * @param  int  $mode  The fetch mode must be one of the PDO::FETCH_* constants.
     * @param  mixed  ...$args  Has no effect; was only included to extend parent.
     * @return bool Returns TRUE on success or FALSE on failure
     */
    public function setFetchMode(int $mode, mixed ...$args)
    {
        return true;
    }

    /**
     * CUSTOM CODE FROM HERE DOWN.
     *
     * All code above this is overriding the PDO base code
     * All code below this are custom helpers or other functionality provided by the oci_* functions
     */

    /**
     * Stores query parameters for debugDumpParams output.
     */
    private function addParameter($parameter, $variable, $data_type = PDO::PARAM_STR, $length = -1, $driver_options = null)
    {
        $param_count = count($this->parameters);

        $this->parameters[$param_count] = [
            'paramno' => $param_count,
            'name' => $parameter,
            'value' => $variable,
            'is_param' => 1,
            'param_type' => $data_type,
        ];
    }

    /**
     * Returns the oci8 statement handle for use with other oci_ functions.
     *
     * @return oci8 statment The oci8 statment handle
     */
    public function getOCIResource()
    {
        return $this->stmt;
    }

    /**
     * Single location to process all the bindings on a resultset.
     *
     * @param  array  $rs  The fetched array to be modified
     */
    private function processBindings($rs)
    {
        if ($rs !== false && ! empty($this->bindings)) {
            $i = 1;
            foreach ($rs as $col => $value) {
                if (isset($this->bindings[$i])) {
                    $this->bindings[$i]['var'] = $value;
                }
                $i++;
            }
        }
    }

    /**
     * Single location to process all the fetch options on a resultset.
     *
     * @param  array  $rec  The fetched array to be modified
     * @return mixed The modified resultset
     */
    private function processFetchOptions($rec)
    {
        if ($rec !== false) {
            if ($this->conn->getAttribute(PDO::ATTR_CASE) == PDO::CASE_LOWER) {
                $rec = array_change_key_case($rec, \CASE_LOWER);
            }

            $rec = ($this->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE) != PDO::FETCH_CLASS) ? $rec : (object) $rec;
        }

        return $rec;
    }

    /**
     * Single location to process all errors and set necessary fields.
     *
     * @param  string  $code  The SQLSTATE error code. defualts to custom 'JF000'
     * @param  string  $error  The driver based error code. If null, oci_error is called
     * @param  string  $message  The error message
     * @return array The local error array
     */
    private function setErrorInfo($code = null, $error = null, $message = null)
    {
        if (is_null($code)) {
            $code = 'JF000';
        }

        if (is_null($error)) {
            $e = oci_error($this->stmt);
            $error = $e['code'];
            $message = $e['message'].(empty($e['sqltext']) ? '' : ' - SQL: '.$e['sqltext']);
        }

        $this->error[0] = $code;
        $this->error[1] = $error;
        $this->error[2] = $message;

        return $this->error;
    }
}
