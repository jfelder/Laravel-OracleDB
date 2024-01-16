<?php

namespace Jfelder\OracleDB\Tests;

use Jfelder\OracleDB\OracleConnection;
use Jfelder\OracleDB\Query\Grammars\OracleGrammar;
use Mockery as m;
use PHPUnit\Framework\TestCase;

class OracleDBQueryGrammarTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
    }

    public function testToRawSql()
    {
        $connection = m::mock(OracleConnection::class);
        $connection->shouldReceive('escape')->with('foo', false)->andReturn("'foo'");
        $grammar = new OracleGrammar;
        $grammar->setConnection($connection);

        $query = $grammar->substituteBindingsIntoRawSql(
            'select * from "users" where \'Hello\\\'World?\' IS NOT NULL AND "email" = ?',
            ['foo'],
        );

        $this->assertSame('select * from "users" where \'Hello\\\'World?\' IS NOT NULL AND "email" = \'foo\'', $query);
    }
}