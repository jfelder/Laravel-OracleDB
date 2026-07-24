<?php

namespace Jfelder\OracleDB\Tests;

use Jfelder\OracleDB\OracleConnection;
use Mockery as m;
use PHPUnit\Framework\TestCase;

class OracleDBConnectionHooksTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    public function test_before_executing_callback_and_reconnector_are_used_when_pdo_is_missing()
    {
        $events = [];

        $connection = new class(null, '', '', ['driver' => 'oracle']) extends OracleConnection
        {
            public function runExposed($query, $bindings, $callback)
            {
                return $this->run($query, $bindings, $callback);
            }
        };

        $connection->beforeExecuting(function ($query, $bindings, $conn) use (&$events, $connection) {
            $events[] = ['beforeExecuting', $query, $bindings, $conn === $connection];
        });

        $connection->setReconnector(function ($conn) use (&$events) {
            $events[] = ['reconnect', $conn->getDriverTitle()];
            $conn->setPdo(new \stdClass);
        });

        $result = $connection->runExposed('select 1 from dual', ['foo'], function () use (&$events) {
            $events[] = ['callback'];

            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertSame([
            ['beforeExecuting', 'select 1 from dual', ['foo'], true],
            ['reconnect', 'Oracle'],
            ['callback'],
        ], $events);
    }

    public function test_before_starting_transaction_callback_and_reconnector_are_used_when_pdo_is_missing()
    {
        $events = [];

        $connection = new class(null, '', '', ['driver' => 'oracle']) extends OracleConnection
        {
            protected function executeBeginTransactionStatement(): void
            {
                $this->eventsForTest[] = 'begin';
            }

            public array $eventsForTest = [];
        };

        $connection->beforeStartingTransaction(function ($conn) use (&$events, $connection) {
            $events[] = ['beforeStartingTransaction', $conn === $connection];
        });

        $connection->setReconnector(function ($conn) use (&$events) {
            $events[] = ['reconnect', $conn->getDriverTitle()];
            $conn->setPdo(new \stdClass);
        });

        $connection->beginTransaction();

        $this->assertSame([
            ['beforeStartingTransaction', true],
            ['reconnect', 'Oracle'],
            'begin',
        ], array_merge($events, $connection->eventsForTest));
        $this->assertSame(1, $connection->transactionLevel());
    }
}
