<?php

use Jfelder\OracleDB\OCI_PDO\OCI;
use Jfelder\OracleDB\OCI_PDO\OCIStatement;

if (! class_exists('TestOCIStub')) {
    class TestOCIStub extends OCI
    {
        public function __construct($dsn = '', $username = null, $password = null, $driver_options = [], $charset = '')
        {
            $this->attributes = $driver_options + $this->attributes;
            $this->conn = 'oci8';
        }

        public function __destruct() {}
    }
}

if (! class_exists('TestOCIStatementStub')) {
    class TestOCIStatementStub extends OCIStatement
    {
        public function __construct($stmt, $conn, $sql, $options)
        {
            $this->stmt = $stmt;
            $this->conn = $conn;
            $this->sql = $sql;
            $this->attributes = $options;
        }

        public function __destruct() {}
    }
}

if (! class_exists('ProcessorTestOCIStub')) {
    class ProcessorTestOCIStub extends OCI
    {
        public function __construct() {}

        public function __destruct() {}

        public function prepare(string $query, array $options = []): OCIStatement|false {}
    }
}

if (! class_exists('ProcessorTestOCIStatementStub')) {
    class ProcessorTestOCIStatementStub extends OCIStatement
    {
        public function __construct() {}

        public function __destruct() {}

        public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool {}

        public function bindParam(string|int $param, mixed &$var, int $type = PDO::PARAM_STR, int $maxLength = 0, mixed $driverOptions = null): bool {}

        public function execute(?array $params = null): bool {}
    }
}
