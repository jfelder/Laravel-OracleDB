<?php

namespace Jfelder\OracleDB\Tests;

use Jfelder\OracleDB\OracleConnection;
use Jfelder\OracleDB\Query\Grammars\OracleGrammar;
use Mockery as m;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

class OracleDBQueryGrammarTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
    }

    public function test_to_raw_sql()
    {
        $connection = m::mock(OracleConnection::class);
        $connection->shouldReceive('escape')->with('foo', false)->andReturn("'foo'");
        $grammar = new OracleGrammar($connection);

        $query = $grammar->substituteBindingsIntoRawSql(
            'select * from "users" where \'Hello\\\'World?\' IS NOT NULL AND "email" = ?',
            ['foo'],
        );

        $this->assertSame('select * from "users" where \'Hello\\\'World?\' IS NOT NULL AND "email" = \'foo\'', $query);
    }

    #[TestWith([null, 'Y-m-d H:i:s'])]
    #[TestWith(['d-M-y H:i:s', 'd-M-y H:i:s'])]
    public function test_get_date_format($valFetchedFromConfig, $expectedResult)
    {
        $connection = m::mock(OracleConnection::class);
        $connection->shouldReceive('getConfig')->with('date_format')->andReturn($valFetchedFromConfig);
        $grammar = new OracleGrammar($connection);

        $this->assertSame($expectedResult, $grammar->getDateFormat());
    }
}
