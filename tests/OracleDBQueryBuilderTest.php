<?php

use Mockery as m;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression as Raw;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Database\Query\Processors\Processor;
use Illuminate\Pagination\AbstractPaginator as Paginator;
use Illuminate\Pagination\LengthAwarePaginator;
use PHPUnit\Framework\TestCase;

include 'mocks/PDOMocks.php';

class OracleDBQueryBuilderTest extends TestCase
{
    public function tearDown(): void
    {
        m::close();
    }

    public function testBasicSelect()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users');
        $this->assertEquals('select * from "users"', $builder->toSql());
    }

    public function testBasicSelectWithGetColumns()
    {
        $builder = $this->getOracleBuilder();
        $builder->getProcessor()->shouldReceive('processSelect');
        $builder->getConnection()->shouldReceive('select')->once()->andReturnUsing(function ($sql) {
            $this->assertSame('select * from "users"', $sql);
        });
        $builder->getConnection()->shouldReceive('select')->once()->andReturnUsing(function ($sql) {
            $this->assertSame('select "foo", "bar" from "users"', $sql);
        });
        $builder->getConnection()->shouldReceive('select')->once()->andReturnUsing(function ($sql) {
            $this->assertSame('select "baz" from "users"', $sql);
        });

        $builder->from('users')->get();
        $this->assertNull($builder->columns);

        $builder->from('users')->get(['foo', 'bar']);
        $this->assertNull($builder->columns);

        $builder->from('users')->get('baz');
        $this->assertNull($builder->columns);

        $this->assertSame('select * from "users"', $builder->toSql());
        $this->assertNull($builder->columns);
    }

    /**
     * @doesNotPerformAssertions
     */
    public function testBasicSelectUseWritePdo()
    {
        $builder = $this->getOracleBuilderWithProcessor();
        $builder->getConnection()->shouldReceive('select')->once()
            ->with('select * from "users"', [], false);
        $builder->useWritePdo()->select('*')->from('users')->get();

        $builder = $this->getOracleBuilderWithProcessor();
        $builder->getConnection()->shouldReceive('select')->once()
            ->with('select * from "users"', [], true);
        $builder->select('*')->from('users')->get();
    }

    public function testBasicTableWrappingProtectsQuotationMarks()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('some"table');
        $this->assertEquals('select * from "some""table"', $builder->toSql());
    }

    public function testAliasWrappingAsWholeConstant()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('x.y as foo.bar')->from('baz');
        $this->assertEquals('select "x"."y" as "foo.bar" from "baz"', $builder->toSql());
    }

    public function testAliasWrappingWithSpacesInDatabaseName()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('w x.y.z as foo.bar')->from('baz');
        $this->assertSame('select "w x"."y"."z" as "foo.bar" from "baz"', $builder->toSql());
    }    

    public function testAddingSelects()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('foo')->addSelect('bar')->addSelect(['baz', 'boom'])->from('users');
        $this->assertEquals('select "foo", "bar", "baz", "boom" from "users"', $builder->toSql());
    }

    public function testBasicSelectWithPrefix()
    {
        $builder = $this->getOracleBuilder();
        $builder->getGrammar()->setTablePrefix('prefix_');
        $builder->select('*')->from('users');
        $this->assertEquals('select * from "prefix_users"', $builder->toSql());
    }

    public function testBasicSelectDistinct()
    {
        $builder = $this->getOracleBuilder();
        $builder->distinct()->select('foo', 'bar')->from('users');
        $this->assertEquals('select distinct "foo", "bar" from "users"', $builder->toSql());
    }

    public function testBasicSelectDistinctOnColumns()
    {
        $builder = $this->getOracleBuilder();
        $builder->distinct('foo')->select('foo', 'bar')->from('users');
        $this->assertSame('select distinct "foo", "bar" from "users"', $builder->toSql());
    }

    public function testBasicAlias()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('foo as bar')->from('users');
        $this->assertEquals('select "foo" as "bar" from "users"', $builder->toSql());
    }

    public function testAliasWithPrefix()
    {
        $builder = $this->getOracleBuilder();
        $builder->getGrammar()->setTablePrefix('prefix_');
        $builder->select('*')->from('users as people');
        $this->assertEquals('select * from "prefix_users" as "prefix_people"', $builder->toSql());
    }

    public function testJoinAliasesWithPrefix()
    {
        $builder = $this->getOracleBuilder();
        $builder->getGrammar()->setTablePrefix('prefix_');
        $builder->select('*')->from('services')->join('translations AS t', 't.item_id', '=', 'services.id');
        $this->assertEquals('select * from "prefix_services" inner join "prefix_translations" as "prefix_t" on "prefix_t"."item_id" = "prefix_services"."id"', $builder->toSql());
    }

    public function testBasicTableWrapping()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('public.users');
        $this->assertEquals('select * from "public"."users"', $builder->toSql());
    }

    public function testWhenCallback()
    {
        $callback = function ($query, $condition) {
            $this->assertTrue($condition);

            $query->where('id', '=', 1);
        };

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->when(true, $callback)->where('email', 'foo');
        $this->assertSame('select * from "users" where "id" = ? and "email" = ?', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->when(false, $callback)->where('email', 'foo');
        $this->assertSame('select * from "users" where "email" = ?', $builder->toSql());
    }    

    public function testWhenCallbackWithReturn()
    {
        $callback = function ($query, $condition) {
            $this->assertTrue($condition);

            return $query->where('id', '=', 1);
        };

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->when(true, $callback)->where('email', 'foo');
        $this->assertSame('select * from "users" where "id" = ? and "email" = ?', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->when(false, $callback)->where('email', 'foo');
        $this->assertSame('select * from "users" where "email" = ?', $builder->toSql());
    }    

    public function testWhenCallbackWithDefault()
    {
        $callback = function ($query, $condition) {
            $this->assertEquals($condition, 'truthy');

            $query->where('id', '=', 1);
        };

        $default = function ($query, $condition) {
            $this->assertEquals($condition, 0);

            $query->where('id', '=', 2);
        };

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->when('truthy', $callback, $default)->where('email', 'foo');
        $this->assertSame('select * from "users" where "id" = ? and "email" = ?', $builder->toSql());
        $this->assertEquals([0 => 1, 1 => 'foo'], $builder->getBindings());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->when(0, $callback, $default)->where('email', 'foo');
        $this->assertSame('select * from "users" where "id" = ? and "email" = ?', $builder->toSql());
        $this->assertEquals([0 => 2, 1 => 'foo'], $builder->getBindings());
    }    

    public function testUnlessCallback()
    {
        $callback = function ($query, $condition) {
            $this->assertFalse($condition);

            $query->where('id', '=', 1);
        };

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->unless(false, $callback)->where('email', 'foo');
        $this->assertSame('select * from "users" where "id" = ? and "email" = ?', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->unless(true, $callback)->where('email', 'foo');
        $this->assertSame('select * from "users" where "email" = ?', $builder->toSql());
    }

    public function testUnlessCallbackWithReturn()
    {
        $callback = function ($query, $condition) {
            $this->assertFalse($condition);

            return $query->where('id', '=', 1);
        };

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->unless(false, $callback)->where('email', 'foo');
        $this->assertSame('select * from "users" where "id" = ? and "email" = ?', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->unless(true, $callback)->where('email', 'foo');
        $this->assertSame('select * from "users" where "email" = ?', $builder->toSql());
    }

    public function testUnlessCallbackWithDefault()
    {
        $callback = function ($query, $condition) {
            $this->assertEquals($condition, 0);

            $query->where('id', '=', 1);
        };

        $default = function ($query, $condition) {
            $this->assertEquals($condition, 'truthy');

            $query->where('id', '=', 2);
        };

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->unless(0, $callback, $default)->where('email', 'foo');
        $this->assertSame('select * from "users" where "id" = ? and "email" = ?', $builder->toSql());
        $this->assertEquals([0 => 1, 1 => 'foo'], $builder->getBindings());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->unless('truthy', $callback, $default)->where('email', 'foo');
        $this->assertSame('select * from "users" where "id" = ? and "email" = ?', $builder->toSql());
        $this->assertEquals([0 => 2, 1 => 'foo'], $builder->getBindings());
    }    

    public function testTapCallback()
    {
        $callback = function ($query) {
            return $query->where('id', '=', 1);
        };

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->tap($callback)->where('email', 'foo');
        $this->assertSame('select * from "users" where "id" = ? and "email" = ?', $builder->toSql());
    }    

    public function testBasicWheres()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('id', '=', 1);
        $this->assertEquals('select * from "users" where "id" = ?', $builder->toSql());
        $this->assertEquals([0 => 1], $builder->getBindings());
    }

    public function testWheresWithArrayValue()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('id', [12, 30]);
        $this->assertSame('select * from "users" where "id" = ?', $builder->toSql());
        $this->assertEquals([0 => 12, 1 => 30], $builder->getBindings());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('id', '=', [12, 30]);
        $this->assertSame('select * from "users" where "id" = ?', $builder->toSql());
        $this->assertEquals([0 => 12, 1 => 30], $builder->getBindings());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('id', '!=', [12, 30]);
        $this->assertSame('select * from "users" where "id" != ?', $builder->toSql());
        $this->assertEquals([0 => 12, 1 => 30], $builder->getBindings());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('id', '<>', [12, 30]);
        $this->assertSame('select * from "users" where "id" <> ?', $builder->toSql());
        $this->assertEquals([0 => 12, 1 => 30], $builder->getBindings());
    }    

    public function testDateBasedWheresAcceptsTwoArguments()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereDate('created_at', 1);
        $this->assertSame('select * from "users" where TO_CHAR("created_at", \'YYYY-MM-DD\') = ?', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereDay('created_at', 1);
        $this->assertSame('select * from "users" where TO_CHAR("created_at", \'DD\') = ?', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereMonth('created_at', 1);
        $this->assertSame('select * from "users" where TO_CHAR("created_at", \'MM\') = ?', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereYear('created_at', 1);
        $this->assertSame('select * from "users" where TO_CHAR("created_at", \'YYYY\') = ?', $builder->toSql());
    }

    public function testDateBasedOrWheresAcceptsTwoArguments()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('id', 1)->orWhereDate('created_at', 1);
        $this->assertSame('select * from "users" where "id" = ? or TO_CHAR("created_at", \'YYYY-MM-DD\') = ?', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('id', 1)->orWhereDay('created_at', 1);
        $this->assertSame('select * from "users" where "id" = ? or TO_CHAR("created_at", \'DD\') = ?', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('id', 1)->orWhereMonth('created_at', 1);
        $this->assertSame('select * from "users" where "id" = ? or TO_CHAR("created_at", \'MM\') = ?', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('id', 1)->orWhereYear('created_at', 1);
        $this->assertSame('select * from "users" where "id" = ? or TO_CHAR("created_at", \'YYYY\') = ?', $builder->toSql());
    }

    public function testDateBasedWheresExpressionIsNotBound()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereDate('created_at', new Raw('sysdate'))->where('admin', true);
        $this->assertEquals([true], $builder->getBindings());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereDay('created_at', new Raw('sysdate'));
        $this->assertEquals([], $builder->getBindings());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereMonth('created_at', new Raw('sysdate'));
        $this->assertEquals([], $builder->getBindings());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereYear('created_at', new Raw('sysdate'));
        $this->assertEquals([], $builder->getBindings());
    }

    public function testWhereDateOracle()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereDate('created_at', '=', '2015-12-21');
        $this->assertSame('select * from "users" where TO_CHAR("created_at", \'YYYY-MM-DD\') = ?', $builder->toSql());
        $this->assertEquals([0 => '2015-12-21'], $builder->getBindings());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereDate('created_at', '=', new Raw("TO_CHAR(sysdate - 1, 'YYYY-MM-DD')"));
        $this->assertSame('select * from "users" where TO_CHAR("created_at", \'YYYY-MM-DD\') = TO_CHAR(sysdate - 1, \'YYYY-MM-DD\')', $builder->toSql());
    }    

    public function testWhereDayOracle()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereDay('created_at', '=', 1);
        $this->assertEquals('select * from "users" where TO_CHAR("created_at", \'DD\') = ?', $builder->toSql());
        $this->assertEquals([0 => 1], $builder->getBindings());
    }

    public function testOrWhereDayOracle()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereDay('created_at', '=', 1)->orWhereDay('created_at', '=', 2);
        $this->assertSame('select * from "users" where TO_CHAR("created_at", \'DD\') = ? or TO_CHAR("created_at", \'DD\') = ?', $builder->toSql());
        $this->assertEquals([0 => 1, 1 => 2], $builder->getBindings());
    }    

    public function testWhereMonthOracle()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereMonth('created_at', '=', 5);
        $this->assertEquals('select * from "users" where TO_CHAR("created_at", \'MM\') = ?', $builder->toSql());
        $this->assertEquals([0 => 5], $builder->getBindings());
    }

    public function testOrWhereMonthOracle()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereMonth('created_at', '=', 5)->orWhereMonth('created_at', '=', 6);
        $this->assertSame('select * from "users" where TO_CHAR("created_at", \'MM\') = ? or TO_CHAR("created_at", \'MM\') = ?', $builder->toSql());
        $this->assertEquals([0 => 5, 1 => 6], $builder->getBindings());
    }

    public function testWhereYearOracle()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereYear('created_at', '=', 2014);
        $this->assertEquals('select * from "users" where TO_CHAR("created_at", \'YYYY\') = ?', $builder->toSql());
        $this->assertEquals([0 => 2014], $builder->getBindings());
    }

    public function testOrWhereYearOracle()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereYear('created_at', '=', 2014)->orWhereYear('created_at', '=', 2015);
        $this->assertSame('select * from "users" where TO_CHAR("created_at", \'YYYY\') = ? or TO_CHAR("created_at", \'YYYY\') = ?', $builder->toSql());
        $this->assertEquals([0 => 2014, 1 => 2015], $builder->getBindings());
    }    

    public function testWhereTimeOracle()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereTime('created_at', '>=', '22:00');
        $this->assertSame('select * from "users" where TO_CHAR("created_at", \'HH24:MI:SS\') >= ?', $builder->toSql());
        $this->assertEquals([0 => '22:00'], $builder->getBindings());
    }

    public function testWhereTimeOperatorOptionalOracle()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereTime('created_at', '22:00');
        $this->assertSame('select * from "users" where TO_CHAR("created_at", \'HH24:MI:SS\') = ?', $builder->toSql());
        $this->assertEquals([0 => '22:00'], $builder->getBindings());
    }

    public function testWhereLikeOracle()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('id', 'like', '1');
        $this->assertSame('select * from "users" where "id" like ?', $builder->toSql());
        $this->assertEquals([0 => '1'], $builder->getBindings());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('id', 'LIKE', '1');
        $this->assertSame('select * from "users" where "id" LIKE ?', $builder->toSql());
        $this->assertEquals([0 => '1'], $builder->getBindings());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('id', 'not like', '1');
        $this->assertSame('select * from "users" where "id" not like ?', $builder->toSql());
        $this->assertEquals([0 => '1'], $builder->getBindings());
    }
    
    public function testWhereBetweens()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereBetween('id', [1, 2]);
        $this->assertEquals('select * from "users" where "id" between ? and ?', $builder->toSql());
        $this->assertEquals([0 => 1, 1 => 2], $builder->getBindings());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereNotBetween('id', [1, 2]);
        $this->assertEquals('select * from "users" where "id" not between ? and ?', $builder->toSql());
        $this->assertEquals([0 => 1, 1 => 2], $builder->getBindings());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereBetween('id', [new Raw(1), new Raw(2)]);
        $this->assertSame('select * from "users" where "id" between 1 and 2', $builder->toSql());
        $this->assertEquals([], $builder->getBindings());        
    }

    public function testBasicOrWheres()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('id', '=', 1)->orWhere('email', '=', 'foo');
        $this->assertEquals('select * from "users" where "id" = ? or "email" = ?', $builder->toSql());
        $this->assertEquals([0 => 1, 1 => 'foo'], $builder->getBindings());
    }

    public function testRawWheres()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereRaw('id = ? or email = ?', [1, 'foo']);
        $this->assertEquals('select * from "users" where id = ? or email = ?', $builder->toSql());
        $this->assertEquals([0 => 1, 1 => 'foo'], $builder->getBindings());
    }

    public function testRawOrWheres()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('id', '=', 1)->orWhereRaw('email = ?', ['foo']);
        $this->assertEquals('select * from "users" where "id" = ? or email = ?', $builder->toSql());
        $this->assertEquals([0 => 1, 1 => 'foo'], $builder->getBindings());
    }

    public function testBasicWhereIns()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereIn('id', [1, 2, 3]);
        $this->assertEquals('select * from "users" where "id" in (?, ?, ?)', $builder->toSql());
        $this->assertEquals([0 => 1, 1 => 2, 2 => 3], $builder->getBindings());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('id', '=', 1)->orWhereIn('id', [1, 2, 3]);
        $this->assertEquals('select * from "users" where "id" = ? or "id" in (?, ?, ?)', $builder->toSql());
        $this->assertEquals([0 => 1, 1 => 1, 2 => 2, 3 => 3], $builder->getBindings());
    }

    public function testBasicWhereNotIns()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereNotIn('id', [1, 2, 3]);
        $this->assertEquals('select * from "users" where "id" not in (?, ?, ?)', $builder->toSql());
        $this->assertEquals([0 => 1, 1 => 2, 2 => 3], $builder->getBindings());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('id', '=', 1)->orWhereNotIn('id', [1, 2, 3]);
        $this->assertEquals('select * from "users" where "id" = ? or "id" not in (?, ?, ?)', $builder->toSql());
        $this->assertEquals([0 => 1, 1 => 1, 2 => 2, 3 => 3], $builder->getBindings());
    }

    public function testRawWhereIns()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereIn('id', [new Raw(1)]);
        $this->assertSame('select * from "users" where "id" in (1)', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('id', '=', 1)->orWhereIn('id', [new Raw(1)]);
        $this->assertSame('select * from "users" where "id" = ? or "id" in (1)', $builder->toSql());
        $this->assertEquals([0 => 1], $builder->getBindings());
    }

    public function testEmptyWhereIns()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereIn('id', []);
        $this->assertEquals('select * from "users" where 0 = 1', $builder->toSql());
        $this->assertEquals([], $builder->getBindings());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('id', '=', 1)->orWhereIn('id', []);
        $this->assertEquals('select * from "users" where "id" = ? or 0 = 1', $builder->toSql());
        $this->assertEquals([0 => 1], $builder->getBindings());
    }

    public function testEmptyWhereNotIns()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereNotIn('id', []);
        $this->assertEquals('select * from "users" where 1 = 1', $builder->toSql());
        $this->assertEquals([], $builder->getBindings());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('id', '=', 1)->orWhereNotIn('id', []);
        $this->assertEquals('select * from "users" where "id" = ? or 1 = 1', $builder->toSql());
        $this->assertEquals([0 => 1], $builder->getBindings());
    }

    public function testWhereIntegerInRaw()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereIntegerInRaw('id', ['1a', 2]);
        $this->assertSame('select * from "users" where "id" in (1, 2)', $builder->toSql());
        $this->assertEquals([], $builder->getBindings());
    }

    public function testWhereIntegerNotInRaw()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereIntegerNotInRaw('id', ['1a', 2]);
        $this->assertSame('select * from "users" where "id" not in (1, 2)', $builder->toSql());
        $this->assertEquals([], $builder->getBindings());
    }

    public function testEmptyWhereIntegerInRaw()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereIntegerInRaw('id', []);
        $this->assertSame('select * from "users" where 0 = 1', $builder->toSql());
        $this->assertEquals([], $builder->getBindings());
    }

    public function testEmptyWhereIntegerNotInRaw()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereIntegerNotInRaw('id', []);
        $this->assertSame('select * from "users" where 1 = 1', $builder->toSql());
        $this->assertEquals([], $builder->getBindings());
    }    

    public function testBasicWhereColumn()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereColumn('first_name', 'last_name')->orWhereColumn('first_name', 'middle_name');
        $this->assertSame('select * from "users" where "first_name" = "last_name" or "first_name" = "middle_name"', $builder->toSql());
        $this->assertEquals([], $builder->getBindings());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereColumn('updated_at', '>', 'created_at');
        $this->assertSame('select * from "users" where "updated_at" > "created_at"', $builder->toSql());
        $this->assertEquals([], $builder->getBindings());
    }

    public function testArrayWhereColumn()
    {
        $conditions = [
            ['first_name', 'last_name'],
            ['updated_at', '>', 'created_at'],
        ];

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereColumn($conditions);
        $this->assertSame('select * from "users" where ("first_name" = "last_name" and "updated_at" > "created_at")', $builder->toSql());
        $this->assertEquals([], $builder->getBindings());
    }

    public function testUnions()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('id', '=', 1);
        $builder->union($this->getOracleBuilder()->select('*')->from('users')->where('id', '=', 2));
        $this->assertEquals('(select * from "users" where "id" = ?) union (select * from "users" where "id" = ?)', $builder->toSql());
        $this->assertEquals([0 => 1, 1 => 2], $builder->getBindings());

        $builder = $this->getOracleBuilder();
        $expectedSql = 'select t2.* from ( select rownum AS "rn", t1.* from ((select "a" from "t3" where "a" = ? and "b" = ?) union (select "a" from "t4" where "a" = ? and "b" = ?) order by "a" asc) t1 ) t2 where t2."rn" between 1 and 10';
        $union = $this->getOracleBuilder()->select('a')->from('t4')->where('a', 11)->where('b', 2);
        $builder->select('a')->from('t3')->where('a', 10)->where('b', 1)->union($union)->orderBy('a')->limit(10);
        $this->assertEquals($expectedSql, $builder->toSql());
        $this->assertEquals([0 => 10, 1 => 1, 2 => 11, 3 => 2], $builder->getBindings());
    }

    public function testUnionAlls()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('id', '=', 1);
        $builder->unionAll($this->getOracleBuilder()->select('*')->from('users')->where('id', '=', 2));
        $this->assertEquals('(select * from "users" where "id" = ?) union all (select * from "users" where "id" = ?)', $builder->toSql());
        $this->assertEquals([0 => 1, 1 => 2], $builder->getBindings());
    }

    public function testMultipleUnions()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('id', '=', 1);
        $builder->union($this->getOracleBuilder()->select('*')->from('users')->where('id', '=', 2));
        $builder->union($this->getOracleBuilder()->select('*')->from('users')->where('id', '=', 3));
        $this->assertEquals('(select * from "users" where "id" = ?) union (select * from "users" where "id" = ?) union (select * from "users" where "id" = ?)', $builder->toSql());
        $this->assertEquals([0 => 1, 1 => 2, 2 => 3], $builder->getBindings());
    }

    public function testMultipleUnionAlls()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('id', '=', 1);
        $builder->unionAll($this->getOracleBuilder()->select('*')->from('users')->where('id', '=', 2));
        $builder->unionAll($this->getOracleBuilder()->select('*')->from('users')->where('id', '=', 3));
        $this->assertEquals('(select * from "users" where "id" = ?) union all (select * from "users" where "id" = ?) union all (select * from "users" where "id" = ?)', $builder->toSql());
        $this->assertEquals([0 => 1, 1 => 2, 2 => 3], $builder->getBindings());
    }

    public function testUnionWithJoin()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users');
        $builder->union($this->getOracleBuilder()->select('*')->from('dogs')->join('breeds', function ($join) {
            $join->on('dogs.breed_id', '=', 'breeds.id')
                ->where('breeds.is_native', '=', 1);
        }));
        $this->assertSame('(select * from "users") union (select * from "dogs" inner join "breeds" on "dogs"."breed_id" = "breeds"."id" and "breeds"."is_native" = ?)', $builder->toSql());
        $this->assertEquals([0 => 1], $builder->getBindings());
    }    

    public function testOracleUnionOrderBys()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('id', '=', 1);
        $builder->union($this->getOracleBuilder()->select('*')->from('users')->where('id', '=', 2));
        $builder->orderBy('id', 'desc');
        $this->assertEquals('(select * from "users" where "id" = ?) union (select * from "users" where "id" = ?) order by "id" desc', $builder->toSql());
        $this->assertEquals([0 => 1, 1 => 2], $builder->getBindings());
    }

    public function testOracleUnionLimitsAndOffsets()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users');
        $builder->union($this->getOracleBuilder()->select('*')->from('dogs'));
        $builder->skip(5)->take(10);
        $this->assertEquals('select t2.* from ( select rownum AS "rn", t1.* from ((select * from "users") union (select * from "dogs")) t1 ) t2 where t2."rn" between 6 and 15', $builder->toSql());
    }

    /**
     * @doesNotPerformAssertions
     */
    public function testUnionAggregate()
    {
        $expected = 'select count(*) as aggregate from ((select * from "posts") union (select * from "videos")) as "temp_table"';
        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('select')->once()->with($expected, [], true);
        $builder->getProcessor()->shouldReceive('processSelect')->once();
        $builder->from('posts')->union($this->getOracleBuilder()->from('videos'))->count();
    }

    public function testSubSelectWhereIns()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereIn('id', function ($q) {
            $q->select('id')->from('users')->where('age', '>', 25)->take(3);
        });
        $this->assertEquals('select * from "users" where "id" in (select t2.* from ( select rownum AS "rn", t1.* from (select "id" from "users" where "age" > ?) t1 ) t2 where t2."rn" between 1 and 3)', $builder->toSql());
        $this->assertEquals([25], $builder->getBindings());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereNotIn('id', function ($q) {
            $q->select('id')->from('users')->where('age', '>', 25)->take(3);
        });
        $this->assertEquals('select * from "users" where "id" not in (select t2.* from ( select rownum AS "rn", t1.* from (select "id" from "users" where "age" > ?) t1 ) t2 where t2."rn" between 1 and 3)', $builder->toSql());
        $this->assertEquals([25], $builder->getBindings());
    }

    public function testBasicWhereNulls()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereNull('id');
        $this->assertEquals('select * from "users" where "id" is null', $builder->toSql());
        $this->assertEquals([], $builder->getBindings());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('id', '=', 1)->orWhereNull('id');
        $this->assertEquals('select * from "users" where "id" = ? or "id" is null', $builder->toSql());
        $this->assertEquals([0 => 1], $builder->getBindings());
    }

    public function testArrayWhereNulls()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereNull(['id', 'expires_at']);
        $this->assertSame('select * from "users" where "id" is null and "expires_at" is null', $builder->toSql());
        $this->assertEquals([], $builder->getBindings());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('id', '=', 1)->orWhereNull(['id', 'expires_at']);
        $this->assertSame('select * from "users" where "id" = ? or "id" is null or "expires_at" is null', $builder->toSql());
        $this->assertEquals([0 => 1], $builder->getBindings());
    }    

    public function testBasicWhereNotNulls()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereNotNull('id');
        $this->assertEquals('select * from "users" where "id" is not null', $builder->toSql());
        $this->assertEquals([], $builder->getBindings());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('id', '>', 1)->orWhereNotNull('id');
        $this->assertEquals('select * from "users" where "id" > ? or "id" is not null', $builder->toSql());
        $this->assertEquals([0 => 1], $builder->getBindings());
    }

    public function testArrayWhereNotNulls()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereNotNull(['id', 'expires_at']);
        $this->assertSame('select * from "users" where "id" is not null and "expires_at" is not null', $builder->toSql());
        $this->assertEquals([], $builder->getBindings());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('id', '>', 1)->orWhereNotNull(['id', 'expires_at']);
        $this->assertSame('select * from "users" where "id" > ? or "id" is not null or "expires_at" is not null', $builder->toSql());
        $this->assertEquals([0 => 1], $builder->getBindings());
    }

    public function testGroupBys()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->groupBy('email');
        $this->assertSame('select * from "users" group by "email"', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->groupBy('id', 'email');
        $this->assertSame('select * from "users" group by "id", "email"', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->groupBy(['id', 'email']);
        $this->assertSame('select * from "users" group by "id", "email"', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->groupBy(new Raw('DATE(created_at)'));
        $this->assertSame('select * from "users" group by DATE(created_at)', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->groupByRaw('DATE(created_at), ? DESC', ['foo']);
        $this->assertSame('select * from "users" group by DATE(created_at), ? DESC', $builder->toSql());
        $this->assertEquals(['foo'], $builder->getBindings());

        $builder = $this->getOracleBuilder();
        $builder->havingRaw('?', ['havingRawBinding'])->groupByRaw('?', ['groupByRawBinding'])->whereRaw('?', ['whereRawBinding']);
        $this->assertEquals(['whereRawBinding', 'groupByRawBinding', 'havingRawBinding'], $builder->getBindings());
    }

    public function testOrderBys()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->orderBy('email')->orderBy('age', 'desc');
        $this->assertSame('select * from "users" order by "email" asc, "age" desc', $builder->toSql());

        $builder->orders = null;
        $this->assertSame('select * from "users"', $builder->toSql());

        $builder->orders = [];
        $this->assertSame('select * from "users"', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->orderBy('email')->orderByRaw('"age" ? desc', ['foo']);
        $this->assertSame('select * from "users" order by "email" asc, "age" ? desc', $builder->toSql());
        $this->assertEquals(['foo'], $builder->getBindings());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->orderByDesc('name');
        $this->assertSame('select * from "users" order by "name" desc', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('posts')->where('public', 1)
            ->unionAll($this->getOracleBuilder()->select('*')->from('videos')->where('public', 1))
            ->orderByRaw('field(category, ?, ?) asc', ['news', 'opinion']);
        $this->assertSame('(select * from "posts" where "public" = ?) union all (select * from "videos" where "public" = ?) order by field(category, ?, ?) asc', $builder->toSql());
        $this->assertEquals([1, 1, 'news', 'opinion'], $builder->getBindings());
    }

    public function testOrderBySubQueries()
    {
        $expected = 'select * from "users" order by (select t2.* from ( select rownum AS "rn", t1.* from (select "created_at" from "logins" where "user_id" = "users"."id") t1 ) t2 where t2."rn" between 1 and 1)';
        $subQuery = function ($query) {
            return $query->select('created_at')->from('logins')->whereColumn('user_id', 'users.id')->limit(1);
        };

        $builder = $this->getOracleBuilder()->select('*')->from('users')->orderBy($subQuery);
        $this->assertSame("$expected asc", $builder->toSql());

        $builder = $this->getOracleBuilder()->select('*')->from('users')->orderBy($subQuery, 'desc');
        $this->assertSame("$expected desc", $builder->toSql());

        $builder = $this->getOracleBuilder()->select('*')->from('users')->orderByDesc($subQuery);
        $this->assertSame("$expected desc", $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('posts')->where('public', 1)
            ->unionAll($this->getOracleBuilder()->select('*')->from('videos')->where('public', 1))
            ->orderBy($this->getOracleBuilder()->selectRaw('field(category, ?, ?)', ['news', 'opinion']));
        $this->assertSame('(select * from "posts" where "public" = ?) union all (select * from "videos" where "public" = ?) order by (select field(category, ?, ?)) asc', $builder->toSql());
        $this->assertEquals([1, 1, 'news', 'opinion'], $builder->getBindings());
    }    

    public function testOrderByInvalidDirectionParam()
    {
        $this->expectException(InvalidArgumentException::class);

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->orderBy('age', 'asec');
    }    

    public function testHavings()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->having('email', '>', 1);
        $this->assertSame('select * from "users" having "email" > ?', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')
            ->orHaving('email', '=', 'test@example.com')
            ->orHaving('email', '=', 'test2@example.com');
        $this->assertSame('select * from "users" having "email" = ? or "email" = ?', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->groupBy('email')->having('email', '>', 1);
        $this->assertSame('select * from "users" group by "email" having "email" > ?', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('email as foo_email')->from('users')->having('foo_email', '>', 1);
        $this->assertSame('select "email" as "foo_email" from "users" having "foo_email" > ?', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select(['category', new Raw('count(*) as "total"')])->from('item')->where('department', '=', 'popular')->groupBy('category')->having('total', '>', new Raw('3'));
        $this->assertSame('select "category", count(*) as "total" from "item" where "department" = ? group by "category" having "total" > 3', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select(['category', new Raw('count(*) as "total"')])->from('item')->where('department', '=', 'popular')->groupBy('category')->having('total', '>', 3);
        $this->assertSame('select "category", count(*) as "total" from "item" where "department" = ? group by "category" having "total" > ?', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->havingBetween('last_login_date', ['2018-11-16', '2018-12-16']);
        $this->assertSame('select * from "users" having "last_login_date" between ? and ?', $builder->toSql());
    }

    public function testHavingShortcut()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->having('email', 1)->orHaving('email', 2);
        $this->assertSame('select * from "users" having "email" = ? or "email" = ?', $builder->toSql());
    }

    public function testHavingFollowedBySelectGet()
    {
        $builder = $this->getOracleBuilder();
        $query = 'select "category", count(*) as "total" from "item" where "department" = ? group by "category" having "total" > ?';
        $builder->getConnection()->shouldReceive('select')->once()->with($query, ['popular', 3], true)->andReturn([['category' => 'rock', 'total' => 5]]);
        $builder->getProcessor()->shouldReceive('processSelect')->andReturnUsing(function ($builder, $results) { return $results; });
        $builder->from('item');
        $result = $builder->select(['category', new Raw('count(*) as "total"')])->where('department', '=', 'popular')->groupBy('category')->having('total', '>', 3)->get();
        $this->assertEquals([['category' => 'rock', 'total' => 5]], $result->all());

        // Using \Raw value
        $builder = $this->getOracleBuilder();
        $query = 'select "category", count(*) as "total" from "item" where "department" = ? group by "category" having "total" > 3';
        $builder->getConnection()->shouldReceive('select')->once()->with($query, ['popular'], true)->andReturn([['category' => 'rock', 'total' => 5]]);
        $builder->getProcessor()->shouldReceive('processSelect')->andReturnUsing(function ($builder, $results) { return $results; });
        $builder->from('item');
        $result = $builder->select(['category', new Raw('count(*) as "total"')])->where('department', '=', 'popular')->groupBy('category')->having('total', '>', new Raw('3'))->get();
        $this->assertEquals([['category' => 'rock', 'total' => 5]], $result->all());
    }

    public function testRawHavings()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->havingRaw('user_foo < user_bar');
        $this->assertEquals('select * from "users" having user_foo < user_bar', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->having('baz', '=', 1)->orHavingRaw('user_foo < user_bar');
        $this->assertEquals('select * from "users" having "baz" = ? or user_foo < user_bar', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->havingBetween('last_login_date', ['2018-11-16', '2018-12-16'])->orHavingRaw('user_foo < user_bar');
        $this->assertSame('select * from "users" having "last_login_date" between ? and ? or user_foo < user_bar', $builder->toSql());        
    }

    public function testLimitsAndOffsets()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->offset(10);
        $this->assertEquals('select * from (select * from "users") where rownum >= 11', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->offset(5)->limit(10);
        $this->assertEquals('select t2.* from ( select rownum AS "rn", t1.* from (select * from "users") t1 ) t2 where t2."rn" between 6 and 15', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->skip(5)->take(10);
        $this->assertEquals('select t2.* from ( select rownum AS "rn", t1.* from (select * from "users") t1 ) t2 where t2."rn" between 6 and 15', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->skip(0)->take(0);
        $this->assertSame('select * from (select * from "users") where rownum < 1', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->skip(0);
        $this->assertEquals('select * from (select * from "users") where rownum >= 1', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->skip(-5)->take(10);
        $this->assertEquals('select t2.* from ( select rownum AS "rn", t1.* from (select * from "users") t1 ) t2 where t2."rn" between 1 and 10', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->skip(-5)->take(-10);
        $this->assertSame('select * from (select * from "users") where rownum >= 1', $builder->toSql());
    }

    public function testForPage()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->forPage(2, 15);
        $this->assertSame('select t2.* from ( select rownum AS "rn", t1.* from (select * from "users") t1 ) t2 where t2."rn" between 16 and 30', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->forPage(0, 15);
        $this->assertSame('select t2.* from ( select rownum AS "rn", t1.* from (select * from "users") t1 ) t2 where t2."rn" between 1 and 15', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->forPage(-2, 15);
        $this->assertSame('select t2.* from ( select rownum AS "rn", t1.* from (select * from "users") t1 ) t2 where t2."rn" between 1 and 15', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->forPage(2, 0);
        $this->assertSame('select * from (select * from "users") where rownum < 1', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->forPage(0, 0);
        $this->assertSame('select * from (select * from "users") where rownum < 1', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->forPage(-2, 0);
        $this->assertSame('select * from (select * from "users") where rownum < 1', $builder->toSql());
    }    

    public function testGetCountForPaginationWithBindings()
    {
        $builder = $this->getOracleBuilder();
        $builder->from('users')->selectSub(function ($q) { $q->select('body')->from('posts')->where('id', 4); }, 'post');

        $builder->getConnection()->shouldReceive('select')->once()->with('select count(*) as aggregate from "users"', [], true)->andReturn([['aggregate' => 1]]);
        $builder->getProcessor()->shouldReceive('processSelect')->once()->andReturnUsing(function ($builder, $results) { return $results; });

        $count = $builder->getCountForPagination();
        $this->assertEquals(1, $count);
        $this->assertEquals([4], $builder->getBindings());
    }

    public function testGetCountForPaginationWithColumnAliases()
    {
        $builder = $this->getOracleBuilder();
        $columns = ['body as post_body', 'teaser', 'posts.created as published'];
        $builder->from('posts')->select($columns);

        $builder->getConnection()->shouldReceive('select')->once()->with('select count("body", "teaser", "posts"."created") as aggregate from "posts"', [], true)->andReturn([['aggregate' => 1]]);
        $builder->getProcessor()->shouldReceive('processSelect')->once()->andReturnUsing(function ($builder, $results) {
            return $results;
        });

        $count = $builder->getCountForPagination($columns);
        $this->assertEquals(1, $count);
    }    

    public function testGetCountForPaginationWithUnion()
    {
        $builder = $this->getOracleBuilder();
        $builder->from('posts')->select('id')->union($this->getOracleBuilder()->from('videos')->select('id'));

        $builder->getConnection()->shouldReceive('select')->once()->with('select count(*) as aggregate from ((select "id" from "posts") union (select "id" from "videos")) as "temp_table"', [], true)->andReturn([['aggregate' => 1]]);
        $builder->getProcessor()->shouldReceive('processSelect')->once()->andReturnUsing(function ($builder, $results) {
            return $results;
        });

        $count = $builder->getCountForPagination();
        $this->assertEquals(1, $count);
    }    

    public function testWhereShortcut()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('id', 1)->orWhere('name', 'foo');
        $this->assertEquals('select * from "users" where "id" = ? or "name" = ?', $builder->toSql());
        $this->assertEquals([0 => 1, 1 => 'foo'], $builder->getBindings());
    }

    public function testWhereWithArrayConditions()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where([['foo', 1], ['bar', 2]]);
        $this->assertSame('select * from "users" where ("foo" = ? and "bar" = ?)', $builder->toSql());
        $this->assertEquals([0 => 1, 1 => 2], $builder->getBindings());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where(['foo' => 1, 'bar' => 2]);
        $this->assertSame('select * from "users" where ("foo" = ? and "bar" = ?)', $builder->toSql());
        $this->assertEquals([0 => 1, 1 => 2], $builder->getBindings());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where([['foo', 1], ['bar', '<', 2]]);
        $this->assertSame('select * from "users" where ("foo" = ? and "bar" < ?)', $builder->toSql());
        $this->assertEquals([0 => 1, 1 => 2], $builder->getBindings());
    }    

    public function testNestedWheres()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('email', '=', 'foo')->orWhere(function ($q) {
            $q->where('name', '=', 'bar')->where('age', '=', 25);
        });
        $this->assertEquals('select * from "users" where "email" = ? or ("name" = ? and "age" = ?)', $builder->toSql());
        $this->assertEquals([0 => 'foo', 1 => 'bar', 2 => 25], $builder->getBindings());
    }

    public function testNestedWhereBindings()
    {
        $builder = $this->getOracleBuilder();
        $builder->where('email', '=', 'foo')->where(function ($q) {
            $q->selectRaw('?', ['ignore'])->where('name', '=', 'bar');
        });
        $this->assertEquals([0 => 'foo', 1 => 'bar'], $builder->getBindings());
    }

    public function testFullSubSelects()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('email', '=', 'foo')->orWhere('id', '=', function ($q) {
            $q->select(new Raw('max(id)'))->from('users')->where('email', '=', 'bar');
        });

        $this->assertEquals('select * from "users" where "email" = ? or "id" = (select max(id) from "users" where "email" = ?)', $builder->toSql());
        $this->assertEquals([0 => 'foo', 1 => 'bar'], $builder->getBindings());
    }

    public function testWhereExists()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('orders')->whereExists(function ($q) {
            $q->select('*')->from('products')->where('products.id', '=', new Raw('orders.id'));
        });
        $this->assertEquals('select * from "orders" where exists (select * from "products" where "products"."id" = orders.id)', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('orders')->whereNotExists(function ($q) {
            $q->select('*')->from('products')->where('products.id', '=', new Raw('orders.id'));
        });
        $this->assertEquals('select * from "orders" where not exists (select * from "products" where "products"."id" = orders.id)', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('orders')->where('id', '=', 1)->orWhereExists(function ($q) {
            $q->select('*')->from('products')->where('products.id', '=', new Raw('orders.id'));
        });
        $this->assertEquals('select * from "orders" where "id" = ? or exists (select * from "products" where "products"."id" = orders.id)', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('orders')->where('id', '=', 1)->orWhereNotExists(function ($q) {
            $q->select('*')->from('products')->where('products.id', '=', new Raw('orders.id'));
        });
        $this->assertEquals('select * from "orders" where "id" = ? or not exists (select * from "products" where "products"."id" = orders.id)', $builder->toSql());
    }

    public function testBasicJoins()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->join('contacts', 'users.id', '=', 'contacts.id')->leftJoin('photos', 'users.id', '=', 'photos.id');
        $this->assertEquals('select * from "users" inner join "contacts" on "users"."id" = "contacts"."id" left join "photos" on "users"."id" = "photos"."id"', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->leftJoinWhere('photos', 'users.id', '=', 'bar')->joinWhere('photos', 'users.id', '=', 'foo');
        $this->assertEquals('select * from "users" left join "photos" on "users"."id" = ? inner join "photos" on "users"."id" = ?', $builder->toSql());
        $this->assertEquals(['bar', 'foo'], $builder->getBindings());
    }

    public function testCrossJoins()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('sizes')->crossJoin('colors');
        $this->assertSame('select * from "sizes" cross join "colors"', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('tableB')->join('tableA', 'tableA.column1', '=', 'tableB.column2', 'cross');
        $this->assertSame('select * from "tableB" cross join "tableA" on "tableA"."column1" = "tableB"."column2"', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('tableB')->crossJoin('tableA', 'tableA.column1', '=', 'tableB.column2');
        $this->assertSame('select * from "tableB" cross join "tableA" on "tableA"."column1" = "tableB"."column2"', $builder->toSql());
    }

    public function testComplexJoin()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->join('contacts', function ($j) {
            $j->on('users.id', '=', 'contacts.id')->orOn('users.name', '=', 'contacts.name');
        });
        $this->assertEquals('select * from "users" inner join "contacts" on "users"."id" = "contacts"."id" or "users"."name" = "contacts"."name"', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->join('contacts', function ($j) {
            $j->where('users.id', '=', 'foo')->orWhere('users.name', '=', 'bar');
        });
        $this->assertEquals('select * from "users" inner join "contacts" on "users"."id" = ? or "users"."name" = ?', $builder->toSql());
        $this->assertEquals(['foo', 'bar'], $builder->getBindings());

        // Run the assertions again
        $this->assertEquals('select * from "users" inner join "contacts" on "users"."id" = ? or "users"."name" = ?', $builder->toSql());
        $this->assertEquals(['foo', 'bar'], $builder->getBindings());
    }

    public function testJoinWhereNull()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->join('contacts', function ($j) {
            $j->on('users.id', '=', 'contacts.id')->whereNull('contacts.deleted_at');
        });
        $this->assertEquals('select * from "users" inner join "contacts" on "users"."id" = "contacts"."id" and "contacts"."deleted_at" is null', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->join('contacts', function ($j) {
            $j->on('users.id', '=', 'contacts.id')->orWhereNull('contacts.deleted_at');
        });
        $this->assertEquals('select * from "users" inner join "contacts" on "users"."id" = "contacts"."id" or "contacts"."deleted_at" is null', $builder->toSql());
    }

    public function testJoinWhereNotNull()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->join('contacts', function ($j) {
            $j->on('users.id', '=', 'contacts.id')->whereNotNull('contacts.deleted_at');
        });
        $this->assertEquals('select * from "users" inner join "contacts" on "users"."id" = "contacts"."id" and "contacts"."deleted_at" is not null', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->join('contacts', function ($j) {
            $j->on('users.id', '=', 'contacts.id')->orWhereNotNull('contacts.deleted_at');
        });
        $this->assertEquals('select * from "users" inner join "contacts" on "users"."id" = "contacts"."id" or "contacts"."deleted_at" is not null', $builder->toSql());
    }

    public function testJoinWhereIn()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->join('contacts', function ($j) {
            $j->on('users.id', '=', 'contacts.id')->whereIn('contacts.name', [48, 'baz', null]);
        });
        $this->assertEquals('select * from "users" inner join "contacts" on "users"."id" = "contacts"."id" and "contacts"."name" in (?, ?, ?)', $builder->toSql());
        $this->assertEquals([48, 'baz', null], $builder->getBindings());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->join('contacts', function ($j) {
            $j->on('users.id', '=', 'contacts.id')->orWhereIn('contacts.name', [48, 'baz', null]);
        });
        $this->assertEquals('select * from "users" inner join "contacts" on "users"."id" = "contacts"."id" or "contacts"."name" in (?, ?, ?)', $builder->toSql());
        $this->assertEquals([48, 'baz', null], $builder->getBindings());
    }

    public function testJoinWhereInSubquery()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->join('contacts', function ($j) {
            $q = $this->getOracleBuilder();
            $q->select('name')->from('contacts')->where('name', 'baz');
            $j->on('users.id', '=', 'contacts.id')->whereIn('contacts.name', $q);
        });
        $this->assertSame('select * from "users" inner join "contacts" on "users"."id" = "contacts"."id" and "contacts"."name" in (select "name" from "contacts" where "name" = ?)', $builder->toSql());
        $this->assertEquals(['baz'], $builder->getBindings());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->join('contacts', function ($j) {
            $q = $this->getOracleBuilder();
            $q->select('name')->from('contacts')->where('name', 'baz');
            $j->on('users.id', '=', 'contacts.id')->orWhereIn('contacts.name', $q);
        });
        $this->assertSame('select * from "users" inner join "contacts" on "users"."id" = "contacts"."id" or "contacts"."name" in (select "name" from "contacts" where "name" = ?)', $builder->toSql());
        $this->assertEquals(['baz'], $builder->getBindings());
    }

    public function testJoinWhereNotIn()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->join('contacts', function ($j) {
            $j->on('users.id', '=', 'contacts.id')->whereNotIn('contacts.name', [48, 'baz', null]);
        });
        $this->assertEquals('select * from "users" inner join "contacts" on "users"."id" = "contacts"."id" and "contacts"."name" not in (?, ?, ?)', $builder->toSql());
        $this->assertEquals([48, 'baz', null], $builder->getBindings());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->join('contacts', function ($j) {
            $j->on('users.id', '=', 'contacts.id')->orWhereNotIn('contacts.name', [48, 'baz', null]);
        });
        $this->assertEquals('select * from "users" inner join "contacts" on "users"."id" = "contacts"."id" or "contacts"."name" not in (?, ?, ?)', $builder->toSql());
        $this->assertEquals([48, 'baz', null], $builder->getBindings());
    }

    public function testJoinsWithNestedConditions()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->leftJoin('contacts', function ($j) {
            $j->on('users.id', '=', 'contacts.id')->where(function ($j) {
                $j->where('contacts.country', '=', 'US')->orWhere('contacts.is_partner', '=', 1);
            });
        });
        $this->assertSame('select * from "users" left join "contacts" on "users"."id" = "contacts"."id" and ("contacts"."country" = ? or "contacts"."is_partner" = ?)', $builder->toSql());
        $this->assertEquals(['US', 1], $builder->getBindings());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->leftJoin('contacts', function ($j) {
            $j->on('users.id', '=', 'contacts.id')->where('contacts.is_active', '=', 1)->orOn(function ($j) {
                $j->orWhere(function ($j) {
                    $j->where('contacts.country', '=', 'UK')->orOn('contacts.type', '=', 'users.type');
                })->where(function ($j) {
                    $j->where('contacts.country', '=', 'US')->orWhereNull('contacts.is_partner');
                });
            });
        });
        $this->assertSame('select * from "users" left join "contacts" on "users"."id" = "contacts"."id" and "contacts"."is_active" = ? or (("contacts"."country" = ? or "contacts"."type" = "users"."type") and ("contacts"."country" = ? or "contacts"."is_partner" is null))', $builder->toSql());
        $this->assertEquals([1, 'UK', 'US'], $builder->getBindings());
    }    

    public function testJoinsWithAdvancedConditions()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->leftJoin('contacts', function ($j) {
            $j->on('users.id', 'contacts.id')->where(function ($j) {
                $j->whereRole('admin')
                    ->orWhereNull('contacts.disabled')
                    ->orWhereRaw('year(contacts.created_at) = 2016');
            });
        });
        $this->assertSame('select * from "users" left join "contacts" on "users"."id" = "contacts"."id" and ("role" = ? or "contacts"."disabled" is null or year(contacts.created_at) = 2016)', $builder->toSql());
        $this->assertEquals(['admin'], $builder->getBindings());
    }

    public function testJoinsWithSubqueryCondition()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->leftJoin('contacts', function ($j) {
            $j->on('users.id', 'contacts.id')->whereIn('contact_type_id', function ($q) {
                $q->select('id')->from('contact_types')
                    ->where('category_id', '1')
                    ->whereNull('deleted_at');
            });
        });
        $this->assertSame('select * from "users" left join "contacts" on "users"."id" = "contacts"."id" and "contact_type_id" in (select "id" from "contact_types" where "category_id" = ? and "deleted_at" is null)', $builder->toSql());
        $this->assertEquals(['1'], $builder->getBindings());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->leftJoin('contacts', function ($j) {
            $j->on('users.id', 'contacts.id')->whereExists(function ($q) {
                $q->selectRaw('1')->from('contact_types')
                    ->whereRaw('contact_types.id = contacts.contact_type_id')
                    ->where('category_id', '1')
                    ->whereNull('deleted_at');
            });
        });
        $this->assertSame('select * from "users" left join "contacts" on "users"."id" = "contacts"."id" and exists (select 1 from "contact_types" where contact_types.id = contacts.contact_type_id and "category_id" = ? and "deleted_at" is null)', $builder->toSql());
        $this->assertEquals(['1'], $builder->getBindings());
    }    

    public function testJoinsWithAdvancedSubqueryCondition()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->leftJoin('contacts', function ($j) {
            $j->on('users.id', 'contacts.id')->whereExists(function ($q) {
                $q->selectRaw('1')->from('contact_types')
                    ->whereRaw('contact_types.id = contacts.contact_type_id')
                    ->where('category_id', '1')
                    ->whereNull('deleted_at')
                    ->whereIn('level_id', function ($q) {
                        $q->select('id')->from('levels')
                            ->where('is_active', true);
                    });
            });
        });
        $this->assertSame('select * from "users" left join "contacts" on "users"."id" = "contacts"."id" and exists (select 1 from "contact_types" where contact_types.id = contacts.contact_type_id and "category_id" = ? and "deleted_at" is null and "level_id" in (select "id" from "levels" where "is_active" = ?))', $builder->toSql());
        $this->assertEquals(['1', true], $builder->getBindings());
    }    

    public function testJoinsWithNestedJoins()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('users.id', 'contacts.id', 'contact_types.id')->from('users')->leftJoin('contacts', function ($j) {
            $j->on('users.id', 'contacts.id')->join('contact_types', 'contacts.contact_type_id', '=', 'contact_types.id');
        });
        $this->assertSame('select "users"."id", "contacts"."id", "contact_types"."id" from "users" left join ("contacts" inner join "contact_types" on "contacts"."contact_type_id" = "contact_types"."id") on "users"."id" = "contacts"."id"', $builder->toSql());
    }    

    public function testJoinsWithMultipleNestedJoins()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('users.id', 'contacts.id', 'contact_types.id', 'countrys.id', 'planets.id')->from('users')->leftJoin('contacts', function ($j) {
            $j->on('users.id', 'contacts.id')
                ->join('contact_types', 'contacts.contact_type_id', '=', 'contact_types.id')
                ->leftJoin('countrys', function ($q) {
                    $q->on('contacts.country', '=', 'countrys.country')
                        ->join('planets', function ($q) {
                            $q->on('countrys.planet_id', '=', 'planet.id')
                                ->where('planet.is_settled', '=', 1)
                                ->where('planet.population', '>=', 10000);
                        });
                });
        });
        $this->assertSame('select "users"."id", "contacts"."id", "contact_types"."id", "countrys"."id", "planets"."id" from "users" left join ("contacts" inner join "contact_types" on "contacts"."contact_type_id" = "contact_types"."id" left join ("countrys" inner join "planets" on "countrys"."planet_id" = "planet"."id" and "planet"."is_settled" = ? and "planet"."population" >= ?) on "contacts"."country" = "countrys"."country") on "users"."id" = "contacts"."id"', $builder->toSql());
        $this->assertEquals(['1', 10000], $builder->getBindings());
    }    

    public function testJoinsWithNestedJoinWithAdvancedSubqueryCondition()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('users.id', 'contacts.id', 'contact_types.id')->from('users')->leftJoin('contacts', function ($j) {
            $j->on('users.id', 'contacts.id')
                ->join('contact_types', 'contacts.contact_type_id', '=', 'contact_types.id')
                ->whereExists(function ($q) {
                    $q->select('*')->from('countrys')
                        ->whereColumn('contacts.country', '=', 'countrys.country')
                        ->join('planets', function ($q) {
                            $q->on('countrys.planet_id', '=', 'planet.id')
                                ->where('planet.is_settled', '=', 1);
                        })
                        ->where('planet.population', '>=', 10000);
                });
        });
        $this->assertSame('select "users"."id", "contacts"."id", "contact_types"."id" from "users" left join ("contacts" inner join "contact_types" on "contacts"."contact_type_id" = "contact_types"."id") on "users"."id" = "contacts"."id" and exists (select * from "countrys" inner join "planets" on "countrys"."planet_id" = "planet"."id" and "planet"."is_settled" = ? where "contacts"."country" = "countrys"."country" and "planet"."population" >= ?)', $builder->toSql());
        $this->assertEquals(['1', 10000], $builder->getBindings());
    }

    public function testJoinSub()
    {
        $builder = $this->getOracleBuilder();
        $builder->from('users')->joinSub('select * from "contacts"', 'sub', 'users.id', '=', 'sub.id');
        $this->assertSame('select * from "users" inner join (select * from "contacts") as "sub" on "users"."id" = "sub"."id"', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->from('users')->joinSub(function ($q) {
            $q->from('contacts');
        }, 'sub', 'users.id', '=', 'sub.id');
        $this->assertSame('select * from "users" inner join (select * from "contacts") as "sub" on "users"."id" = "sub"."id"', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $eloquentBuilder = new EloquentBuilder($this->getOracleBuilder()->from('contacts'));
        $builder->from('users')->joinSub($eloquentBuilder, 'sub', 'users.id', '=', 'sub.id');
        $this->assertSame('select * from "users" inner join (select * from "contacts") as "sub" on "users"."id" = "sub"."id"', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $sub1 = $this->getOracleBuilder()->from('contacts')->where('name', 'foo');
        $sub2 = $this->getOracleBuilder()->from('contacts')->where('name', 'bar');
        $builder->from('users')
            ->joinSub($sub1, 'sub1', 'users.id', '=', 1, 'inner', true)
            ->joinSub($sub2, 'sub2', 'users.id', '=', 'sub2.user_id');
        $expected = 'select * from "users" ';
        $expected .= 'inner join (select * from "contacts" where "name" = ?) as "sub1" on "users"."id" = ? ';
        $expected .= 'inner join (select * from "contacts" where "name" = ?) as "sub2" on "users"."id" = "sub2"."user_id"';
        $this->assertEquals($expected, $builder->toSql());
        $this->assertEquals(['foo', 1, 'bar'], $builder->getRawBindings()['join']);

        $this->expectException(InvalidArgumentException::class);
        $builder = $this->getOracleBuilder();
        $builder->from('users')->joinSub(['foo'], 'sub', 'users.id', '=', 'sub.id');
    }    

    public function testJoinSubWithPrefix()
    {
        $builder = $this->getOracleBuilder();
        $builder->getGrammar()->setTablePrefix('prefix_');
        $builder->from('users')->joinSub('select * from "contacts"', 'sub', 'users.id', '=', 'sub.id');
        $this->assertSame('select * from "prefix_users" inner join (select * from "contacts") as "prefix_sub" on "prefix_users"."id" = "prefix_sub"."id"', $builder->toSql());
    }

    public function testLeftJoinSub()
    {
        $builder = $this->getOracleBuilder();
        $builder->from('users')->leftJoinSub($this->getOracleBuilder()->from('contacts'), 'sub', 'users.id', '=', 'sub.id');
        $this->assertSame('select * from "users" left join (select * from "contacts") as "sub" on "users"."id" = "sub"."id"', $builder->toSql());

        $this->expectException(InvalidArgumentException::class);
        $builder = $this->getOracleBuilder();
        $builder->from('users')->leftJoinSub(['foo'], 'sub', 'users.id', '=', 'sub.id');
    }

    public function testRightJoinSub()
    {
        $builder = $this->getOracleBuilder();
        $builder->from('users')->rightJoinSub($this->getOracleBuilder()->from('contacts'), 'sub', 'users.id', '=', 'sub.id');
        $this->assertSame('select * from "users" right join (select * from "contacts") as "sub" on "users"."id" = "sub"."id"', $builder->toSql());

        $this->expectException(InvalidArgumentException::class);
        $builder = $this->getOracleBuilder();
        $builder->from('users')->rightJoinSub(['foo'], 'sub', 'users.id', '=', 'sub.id');
    }

    public function testRawExpressionsInSelect()
    {
        $builder = $this->getOracleBuilder();
        $builder->select(new Raw('substr(foo, 6)'))->from('users');
        $this->assertEquals('select substr(foo, 6) from "users"', $builder->toSql());
    }

    public function testFindReturnsFirstResultByID()
    {
        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('select')->once()->with('select t2.* from ( select rownum AS "rn", t1.* from (select * from "users" where "id" = ?) t1 ) t2 where t2."rn" between 1 and 1', [1], true)->andReturn([['foo' => 'bar']]);
        $builder->getProcessor()->shouldReceive('processSelect')->once()->with($builder, [['foo' => 'bar']])->andReturnUsing(function ($query, $results) { return $results; });
        $results = $builder->from('users')->find(1);
        $this->assertEquals(['foo' => 'bar'], $results);
    }

    public function testFirstMethodReturnsFirstResult()
    {
        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('select')->once()->with('select t2.* from ( select rownum AS "rn", t1.* from (select * from "users" where "id" = ?) t1 ) t2 where t2."rn" between 1 and 1', [1], true)->andReturn([['foo' => 'bar']]);
        $builder->getProcessor()->shouldReceive('processSelect')->once()->with($builder, [['foo' => 'bar']])->andReturnUsing(function ($query, $results) { return $results; });
        $results = $builder->from('users')->where('id', '=', 1)->first();
        $this->assertEquals(['foo' => 'bar'], $results);
    }

    public function testPluckMethodsGetsArrayOfColumnValues()
    {
        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('select')->once()->andReturn([['foo' => 'bar'], ['foo' => 'baz']]);
        $builder->getProcessor()->shouldReceive('processSelect')->once()->with($builder, [['foo' => 'bar'], ['foo' => 'baz']])->andReturnUsing(function ($query, $results) {
            return $results;
        });
        $results = $builder->from('users')->where('id', '=', 1)->pluck('foo');
        $this->assertEquals(['bar', 'baz'], $results->all());

        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('select')->once()->andReturn([['id' => 1, 'foo' => 'bar'], ['id' => 10, 'foo' => 'baz']]);
        $builder->getProcessor()->shouldReceive('processSelect')->once()->with($builder, [['id' => 1, 'foo' => 'bar'], ['id' => 10, 'foo' => 'baz']])->andReturnUsing(function ($query, $results) {
            return $results;
        });
        $results = $builder->from('users')->where('id', '=', 1)->pluck('foo', 'id');
        $this->assertEquals([1 => 'bar', 10 => 'baz'], $results->all());
    }

    public function testImplode()
    {
        // Test without glue.
        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('select')->once()->andReturn([['foo' => 'bar'], ['foo' => 'baz']]);
        $builder->getProcessor()->shouldReceive('processSelect')->once()->with($builder, [['foo' => 'bar'], ['foo' => 'baz']])->andReturnUsing(function ($query, $results) {
            return $results;
        });
        $results = $builder->from('users')->where('id', '=', 1)->implode('foo');
        $this->assertEquals('barbaz', $results);

        // Test with glue.
        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('select')->once()->andReturn([['foo' => 'bar'], ['foo' => 'baz']]);
        $builder->getProcessor()->shouldReceive('processSelect')->once()->with($builder, [['foo' => 'bar'], ['foo' => 'baz']])->andReturnUsing(function ($query, $results) {
            return $results;
        });
        $results = $builder->from('users')->where('id', '=', 1)->implode('foo', ',');
        $this->assertEquals('bar,baz', $results);
    }

    public function testValueMethodReturnsSingleColumn()
    {
        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('select')->once()->with('select t2.* from ( select rownum AS "rn", t1.* from (select "foo" from "users" where "id" = ?) t1 ) t2 where t2."rn" between 1 and 1', [1], true)->andReturn([['foo' => 'bar']]);
        $builder->getProcessor()->shouldReceive('processSelect')->once()->with($builder, [['foo' => 'bar']])->andReturn([['foo' => 'bar']]);
        $results = $builder->from('users')->where('id', '=', 1)->value('foo');
        $this->assertEquals('bar', $results);
    }

    public function testAggregateFunctions()
    {
        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('select')->once()->with('select count(*) as aggregate from "users"', [], true)->andReturn([['aggregate' => 1]]);
        $builder->getProcessor()->shouldReceive('processSelect')->once()->andReturnUsing(function ($builder, $results) { return $results; });
        $results = $builder->from('users')->count();
        $this->assertEquals(1, $results);

        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('select')->once()->with('select t2."rn" as "exists" from ( select rownum AS "rn", t1.* from (select * from "users") t1 ) t2 where t2."rn" between 1 and 1', [], true)->andReturn([["exists" => 1]]);
        $results = $builder->from('users')->exists();
        $this->assertTrue($results);

        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('select')->once()->with('select t2."rn" as "exists" from ( select rownum AS "rn", t1.* from (select * from "users") t1 ) t2 where t2."rn" between 1 and 1', [], true)->andReturn([['exists' => 0]]);
        $results = $builder->from('users')->doesntExist();
        $this->assertTrue($results);        

        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('select')->once()->with('select max("id") as aggregate from "users"', [], true)->andReturn([['aggregate' => 1]]);
        $builder->getProcessor()->shouldReceive('processSelect')->once()->andReturnUsing(function ($builder, $results) { return $results; });
        $results = $builder->from('users')->max('id');
        $this->assertEquals(1, $results);

        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('select')->once()->with('select min("id") as aggregate from "users"', [], true)->andReturn([['aggregate' => 1]]);
        $builder->getProcessor()->shouldReceive('processSelect')->once()->andReturnUsing(function ($builder, $results) { return $results; });
        $results = $builder->from('users')->min('id');
        $this->assertEquals(1, $results);

        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('select')->once()->with('select sum("id") as aggregate from "users"', [], true)->andReturn([['aggregate' => 1]]);
        $builder->getProcessor()->shouldReceive('processSelect')->once()->andReturnUsing(function ($builder, $results) { return $results; });
        $results = $builder->from('users')->sum('id');
        $this->assertEquals(1, $results);
    }

    public function testExistsOr()
    {
        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('select')->andReturn([['exists' => 1]]);
        $results = $builder->from('users')->doesntExistOr(function () {
            return 123;
        });
        $this->assertSame(123, $results);
        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('select')->andReturn([['exists' => 0]]);
        $results = $builder->from('users')->doesntExistOr(function () {
            throw new RuntimeException();
        });
        $this->assertTrue($results);
    }    

    public function testDoesntExistsOr()
    {
        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('select')->andReturn([['exists' => 0]]);
        $results = $builder->from('users')->existsOr(function () {
            return 123;
        });
        $this->assertSame(123, $results);
        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('select')->andReturn([['exists' => 1]]);
        $results = $builder->from('users')->existsOr(function () {
            throw new RuntimeException();
        });
        $this->assertTrue($results);
    }    

    public function testAggregateResetFollowedByGet()
    {
        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('select')->once()->with('select count(*) as aggregate from "users"', [], true)->andReturn([['aggregate' => 1]]);
        $builder->getConnection()->shouldReceive('select')->once()->with('select sum("id") as aggregate from "users"', [], true)->andReturn([['aggregate' => 2]]);
        $builder->getConnection()->shouldReceive('select')->once()->with('select "column1", "column2" from "users"', [], true)->andReturn([['column1' => 'foo', 'column2' => 'bar']]);
        $builder->getProcessor()->shouldReceive('processSelect')->andReturnUsing(function ($builder, $results) { return $results; });
        $builder->from('users')->select('column1', 'column2');
        $count = $builder->count();
        $this->assertEquals(1, $count);
        $sum = $builder->sum('id');
        $this->assertEquals(2, $sum);
        $result = $builder->get();
        $this->assertEquals([['column1' => 'foo', 'column2' => 'bar']], $result->all());
    }

    public function testAggregateResetFollowedBySelectGet()
    {
        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('select')->once()->with('select count("column1") as aggregate from "users"', [], true)->andReturn([['aggregate' => 1]]);
        $builder->getConnection()->shouldReceive('select')->once()->with('select "column2", "column3" from "users"', [], true)->andReturn([['column2' => 'foo', 'column3' => 'bar']]);
        $builder->getProcessor()->shouldReceive('processSelect')->andReturnUsing(function ($builder, $results) { return $results; });
        $builder->from('users');
        $count = $builder->count('column1');
        $this->assertEquals(1, $count);
        $result = $builder->select('column2', 'column3')->get();
        $this->assertEquals([['column2' => 'foo', 'column3' => 'bar']], $result->all());
    }

    public function testAggregateResetFollowedByGetWithColumns()
    {
        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('select')->once()->with('select count("column1") as aggregate from "users"', [], true)->andReturn([['aggregate' => 1]]);
        $builder->getConnection()->shouldReceive('select')->once()->with('select "column2", "column3" from "users"', [], true)->andReturn([['column2' => 'foo', 'column3' => 'bar']]);
        $builder->getProcessor()->shouldReceive('processSelect')->andReturnUsing(function ($builder, $results) { return $results; });
        $builder->from('users');
        $count = $builder->count('column1');
        $this->assertEquals(1, $count);
        $result = $builder->get(['column2', 'column3']);
        $this->assertEquals([['column2' => 'foo', 'column3' => 'bar']], $result->all());
    }

    public function testAggregateWithSubSelect()
    {
        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('select')->once()->with('select count(*) as aggregate from "users"', [], true)->andReturn([['aggregate' => 1]]);
        $builder->getProcessor()->shouldReceive('processSelect')->once()->andReturnUsing(function ($builder, $results) { return $results; });
        $builder->from('users')->selectSub(function ($query) { $query->from('posts')->select('foo', 'bar')->where('title', 'foo'); }, 'post');
        $count = $builder->count();
        $this->assertEquals(1, $count);
        $this->assertSame('(select "foo", "bar" from "posts" where "title" = ?) as "post"', $builder->columns[0]->getValue());
        $this->assertEquals(['foo'], $builder->getBindings());
    }

    public function testSubqueriesBindings()
    {
        $builder = $this->getOracleBuilder();
        $second = $this->getOracleBuilder()->select('*')->from('users')->orderByRaw('id = ?', 2);
        $third = $this->getOracleBuilder()->select('*')->from('users')->where('id', 3)->groupBy('id')->having('id', '!=', 4);
        $builder->groupBy('a')->having('a', '=', 1)->union($second)->union($third);
        $this->assertEquals([0 => 1, 1 => 2, 2 => 3, 3 => 4], $builder->getBindings());

        $builder = $this->getOracleBuilder()->select('*')->from('users')->where('email', '=', function ($q) {
            $q->select(new Raw('max(id)'))
                ->from('users')->where('email', '=', 'bar')
                ->orderByRaw('email like ?', '%.com')
                ->groupBy('id')->having('id', '=', 4);
        })->orWhere('id', '=', 'foo')->groupBy('id')->having('id', '=', 5);
        $this->assertEquals([0 => 'bar', 1 => 4, 2 => '%.com', 3 => 'foo', 4 => 5], $builder->getBindings());
    }

    public function testInsertMethod()
    {
        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('insert')->once()->with('insert into "users" ("email") values (?)', ['foo'])->andReturn(true);
        $result = $builder->from('users')->insert(['email' => 'foo']);
        $this->assertTrue($result);
    }

    public function testInsertUsingMethod()
    {
        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('affectingStatement')->once()->with('insert into "table1" ("foo") select "bar" from "table2" where "foreign_id" = ?', [5])->andReturn(1);

        $result = $builder->from('table1')->insertUsing(
            ['foo'],
            function (Builder $query) {
                $query->select(['bar'])->from('table2')->where('foreign_id', '=', 5);
            }
        );

        $this->assertEquals(1, $result);
    }    

    public function testInsertUsingInvalidSubquery()
    {
        $this->expectException(InvalidArgumentException::class);
        $builder = $this->getOracleBuilder();
        $builder->from('table1')->insertUsing(['foo'], ['bar']);
    }    

    public function testInsertOrIgnoreMethod()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not support');
        $builder = $this->getOracleBuilder();
        $builder->from('users')->insertOrIgnore(['email' => 'foo']);
    }    

    public function testMultipleInsertMethod()
    {
        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('insert')->once()->with('insert all into "users" ("email") values (?) into "users" ("email") values (?)  select 1 from dual', [0 => 'foo',1 => 'bar'])->andReturn(true);
        $result = $builder->from('users')->insert([['email' => 'foo'], ['email' => 'bar']]);
        $this->assertTrue($result);
    }

    public function testInsertGetIdMethod()
    {
        $builder = $this->getOracleBuilder();
        $builder->getProcessor()->shouldReceive('processInsertGetId')->once()->with($builder, 'insert into "users" ("email") values (?) returning "id" into ?', ['foo'], 'id')->andReturn(1);
        $result = $builder->from('users')->insertGetId(['email' => 'foo'], 'id');
        $this->assertEquals(1, $result);
    }

    public function testInsertGetIdMethodRemovesExpressions()
    {
        $builder = $this->getOracleBuilder();
        $builder->getProcessor()->shouldReceive('processInsertGetId')->once()->with($builder, 'insert into "users" ("email", "bar") values (?, bar) returning "id" into ?', ['foo'], 'id')->andReturn(1);
        $result = $builder->from('users')->insertGetId(['email' => 'foo', 'bar' => new Illuminate\Database\Query\Expression('bar')], 'id');
        $this->assertEquals(1, $result);
    }

    public function testInsertGetIdWithEmptyValues()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not support');
        $builder = $this->getOracleBuilder();
        $builder->from('users')->insertGetId([]);
    }    

    public function testInsertMethodRespectsRawBindings()
    {
        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('insert')->once()->with('insert into "users" ("email") values (CURRENT TIMESTAMP)', [])->andReturn(true);
        $result = $builder->from('users')->insert(['email' => new Raw('CURRENT TIMESTAMP')]);
        $this->assertTrue($result);
    }

    public function testMultipleInsertsWithExpressionValues()
    {
        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('insert')->once()->with('insert all into "users" ("email") values (UPPER(\'Foo\')) into "users" ("email") values (UPPER(\'Foo\'))  select 1 from dual', [])->andReturn(true);
        $result = $builder->from('users')->insert([['email' => new Raw("UPPER('Foo')")], ['email' => new Raw("LOWER('Foo')")]]);
        $this->assertTrue($result);
    }

    public function testUpdateMethod()
    {
        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('update')->once()->with('update "users" set "email" = ?, "name" = ? where "id" = ?', ['foo', 'bar', 1])->andReturn(1);
        $result = $builder->from('users')->where('id', '=', 1)->update(['email' => 'foo', 'name' => 'bar']);
        $this->assertEquals(1, $result);

        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('update')->once()->with('update "users" set "email" = ?, "name" = ? where "id" = ?', ['foo', 'bar', 1])->andReturn(1);
        $result = $builder->from('users')->where('id', '=', 1)->orderBy('foo', 'desc')->limit(5)->update(['email' => 'foo', 'name' => 'bar']);
        $this->assertEquals(1, $result);
    }

    public function testUpdateMethodWithJoins()
    {
        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('update')->once()->with('update "users" inner join "orders" on "users"."id" = "orders"."user_id" set "email" = ?, "name" = ? where "users"."id" = ?', ['foo', 'bar', 1])->andReturn(1);
        $result = $builder->from('users')->join('orders', 'users.id', '=', 'orders.user_id')->where('users.id', '=', 1)->update(['email' => 'foo', 'name' => 'bar']);
        $this->assertEquals(1, $result);

        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('update')->once()->with('update "users" inner join "orders" on "users"."id" = "orders"."user_id" and "users"."id" = ? set "email" = ?, "name" = ?', [1, 'foo', 'bar'])->andReturn(1);
        $result = $builder->from('users')->join('orders', function ($join) {
            $join->on('users.id', '=', 'orders.user_id')
                ->where('users.id', '=', 1);
        })->update(['email' => 'foo', 'name' => 'bar']);
        $this->assertEquals(1, $result);
    }

    public function testUpdateMethodRespectsRaw()
    {
        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('update')->once()->with('update "users" set "email" = foo, "name" = ? where "id" = ?', ['bar', 1])->andReturn(1);
        $result = $builder->from('users')->where('id', '=', 1)->update(['email' => new Raw('foo'), 'name' => 'bar']);
        $this->assertEquals(1, $result);
    }

    public function testUpdateOrInsertMethod()
    {
        $builder = m::mock(Builder::class.'[where,exists,insert]', [
            m::mock(ConnectionInterface::class),
            new Grammar,
            m::mock(Processor::class),
        ]);

        $builder->shouldReceive('where')->once()->with(['email' => 'foo'])->andReturn(m::self());
        $builder->shouldReceive('exists')->once()->andReturn(false);
        $builder->shouldReceive('insert')->once()->with(['email' => 'foo', 'name' => 'bar'])->andReturn(true);

        $this->assertTrue($builder->updateOrInsert(['email' => 'foo'], ['name' => 'bar']));

        $builder = m::mock(Builder::class.'[where,exists,update]', [
            m::mock(ConnectionInterface::class),
            new Grammar,
            m::mock(Processor::class),
        ]);

        $builder->shouldReceive('where')->once()->with(['email' => 'foo'])->andReturn(m::self());
        $builder->shouldReceive('exists')->once()->andReturn(true);
        $builder->shouldReceive('take')->andReturnSelf();
        $builder->shouldReceive('update')->once()->with(['name' => 'bar'])->andReturn(1);

        $this->assertTrue($builder->updateOrInsert(['email' => 'foo'], ['name' => 'bar']));
    }

    public function testUpdateOrInsertMethodWorksWithEmptyUpdateValues()
    {
        $builder = m::spy(Builder::class.'[where,exists,update]', [
            m::mock(ConnectionInterface::class),
            new Grammar,
            m::mock(Processor::class),
        ]);

        $builder->shouldReceive('where')->once()->with(['email' => 'foo'])->andReturn(m::self());
        $builder->shouldReceive('exists')->once()->andReturn(true);

        $this->assertTrue($builder->updateOrInsert(['email' => 'foo']));
        $builder->shouldNotHaveReceived('update');
    }    

    public function testDeleteMethod()
    {
        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('delete')->once()->with('delete from "users" where "email" = ?', ['foo'])->andReturn(1);
        $result = $builder->from('users')->where('email', '=', 'foo')->delete();
        $this->assertEquals(1, $result);

        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('delete')->once()->with('delete from "users" where "users"."id" = ?', [1])->andReturn(1);
        $result = $builder->from('users')->delete(1);
        $this->assertEquals(1, $result);

        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('delete')->once()->with('delete from "users" where "users"."id" = ?', [1])->andReturn(1);
        $result = $builder->from('users')->selectRaw('?', ['ignore'])->delete(1);
        $this->assertEquals(1, $result);        
    }

    public function testDeleteWithJoinMethod()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not support');
        $builder = $this->getOracleBuilder();
        $builder->from('users')->join('contacts', 'users.id', '=', 'contacts.id')->where('users.email', '=', 'foo')->orderBy('users.id')->limit(1)->delete();
    }    

    /**
     * @doesNotPerformAssertions
     */
    public function testTruncateMethod()
    {
        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('statement')->once()->with('truncate table "users"', []);
        $builder->from('users')->truncate();
    }

    public function testUpdateWrappingJson()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not support');
        $builder = $this->getOracleBuilder();
        $builder->from('users')->where('active', '=', 1)->update(['name->first_name' => 'John', 'name->last_name' => 'Doe']);
    }    

    public function testUpdateWrappingNestedJson()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not support');
        $builder = $this->getOracleBuilder();
        $builder->from('users')->where('active', '=', 1)->update(['meta->name->first_name' => 'John', 'meta->name->last_name' => 'Doe']);
    }

    public function testUpdateWrappingJsonArray()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not support');
        $builder = $this->getOracleBuilder();
        $builder->from('users')->where('active', 1)->update([
            'options' => ['2fa' => false, 'presets' => ['laravel', 'vue']],
            'meta->tags' => ['white', 'large'],
            'group_id' => new Raw('45'),
            'created_at' => new DateTime('2019-08-06'),
        ]);
    }    

    public function testUpdateWrappingNestedJsonArray()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not support');
        $builder = $this->getOracleBuilder();

        $builder->from('users')->update([
            'options->name' => 'Taylor',
            'group_id' => new Raw('45'),
            'options->security' => ['2fa' => false, 'presets' => ['laravel', 'vue']],
            'options->sharing->twitter' => 'username',
            'created_at' => new DateTime('2019-08-06'),
        ]);
    }    

    public function testUpdateWithJsonPreparesBindingsCorrectly()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not support');
        $builder = $this->getOracleBuilder();
        $builder->from('users')->where('id', '=', 0)->update(['options->enable' => false, 'updated_at' => '2015-05-26 22:02:06']);
    }   
    
    public function testWrappingJsonWithString()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not support');
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('items->sku', '=', 'foo-bar')->toSql();
    }

    public function testWrappingJsonWithInteger()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not support');
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('items->price', '=', 1)->toSql();
    }

    public function testWrappingJsonWithDouble()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not support');
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('items->price', '=', 1.5)->toSql();
    }

    public function testWrappingJsonWithBoolean()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not support');
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('items->available', '=', true)->toSql();
    }

    public function testWrappingJsonWithBooleanAndIntegerThatLooksLikeOne()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not support');
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('items->available', '=', true)->where('items->active', '=', false)->where('items->number_available', '=', 0)->toSql();
    }    

    public function testJsonPathEscaping()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not support');
        $builder = $this->getOracleBuilder();
        $builder->select("json->'))#")->toSql();
    }

    public function testWrappingJson()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not support');
        $builder = $this->getOracleBuilder();
        $builder->select('items->price')->from('users')->where('users.items->price', '=', 1)->orderBy('items->price')->toSql();
    }

    public function testMergeWheresCanMergeWheresAndBindings()
    {
        $builder = $this->getOracleBuilder();
        $builder->wheres = ['foo'];
        $builder->mergeWheres(['wheres'], [12 => 'foo', 13 => 'bar']);
        $this->assertEquals(['foo', 'wheres'], $builder->wheres);
        $this->assertEquals(['foo', 'bar'], $builder->getBindings());
    }

    public function testProvidingNullOrFalseAsSecondParameterBuildsCorrectly()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('foo', null);
        $this->assertSame('select * from "users" where "foo" is null', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('foo', '=', null);
        $this->assertSame('select * from "users" where "foo" is null', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('foo', '!=', null);
        $this->assertSame('select * from "users" where "foo" is not null', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('foo', '<>', null);
        $this->assertSame('select * from "users" where "foo" is not null', $builder->toSql());
    }

    public function testDynamicWhere()
    {
        $method = 'whereFooBarAndBazOrQux';
        $parameters = ['corge', 'waldo', 'fred'];
        $builder = m::mock('Illuminate\Database\Query\Builder')->makePartial();

        $builder->shouldReceive('where')->with('foo_bar', '=', $parameters[0], 'and')->once()->andReturn($builder);
        $builder->shouldReceive('where')->with('baz', '=', $parameters[1], 'and')->once()->andReturn($builder);
        $builder->shouldReceive('where')->with('qux', '=', $parameters[2], 'or')->once()->andReturn($builder);

        $this->assertEquals($builder, $builder->dynamicWhere($method, $parameters));
    }

    /**
     * @doesNotPerformAssertions
     */
    public function testDynamicWhereIsNotGreedy()
    {
        $method = 'whereIosVersionAndAndroidVersionOrOrientation';
        $parameters = ['6.1', '4.2', 'Vertical'];
        $builder = m::mock('Illuminate\Database\Query\Builder')->makePartial();

        $builder->shouldReceive('where')->with('ios_version', '=', '6.1', 'and')->once()->andReturn($builder);
        $builder->shouldReceive('where')->with('android_version', '=', '4.2', 'and')->once()->andReturn($builder);
        $builder->shouldReceive('where')->with('orientation', '=', 'Vertical', 'or')->once()->andReturn($builder);

        $builder->dynamicWhere($method, $parameters);
    }

    public function testCallTriggersDynamicWhere()
    {
        $builder = $this->getOracleBuilder();

        $this->assertEquals($builder, $builder->whereFooAndBar('baz', 'qux'));
        $this->assertCount(2, $builder->wheres);
    }

    public function testBuilderThrowsExpectedExceptionWithUndefinedMethod()
    {
        $builder = $this->getOracleBuilder();

        $this->expectException(BadMethodCallException::class);

        $builder->noValidMethodHere();
    }

    public function setupCacheTestQuery($cache, $driver)
    {
        $connection = m::mock('Illuminate\Database\ConnectionInterface');
        $connection->shouldReceive('getName')->andReturn('connection_name');
        $connection->shouldReceive('getCacheManager')->once()->andReturn($cache);
        $cache->shouldReceive('driver')->once()->andReturn($driver);
        $grammar = new Illuminate\Database\Query\Grammars\Grammar;
        $processor = m::mock('Illuminate\Database\Query\Processors\Processor');

        $builder = $this->getMock('Illuminate\Database\Query\Builder', ['getFresh'], [$connection, $grammar, $processor]);
        $builder->expects($this->once())->method('getFresh')->with($this->equalTo(['*']))->will($this->returnValue(['results']));

        return $builder->select('*')->from('users')->where('email', 'foo@bar.com');
    }

    public function testOracleLock()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('foo')->where('bar', '=', 'baz')->lock();
        $this->assertEquals('select * from "foo" where "bar" = ? for update', $builder->toSql());
        $this->assertEquals(['baz'], $builder->getBindings());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('foo')->where('bar', '=', 'baz')->lock(false);
        $this->assertEquals('select * from "foo" where "bar" = ? lock in share mode', $builder->toSql());
        $this->assertEquals(['baz'], $builder->getBindings());
    }

    /**
     * @doesNotPerformAssertions
     */
    public function testSelectWithLockUsesWritePdo()
    {
        $builder = $this->getOracleBuilderWithProcessor();
        $builder->getConnection()->shouldReceive('select')->once()
            ->with(m::any(), m::any(), false);
        $builder->select('*')->from('foo')->where('bar', '=', 'baz')->lock()->get();

        $builder = $this->getOracleBuilderWithProcessor();
        $builder->getConnection()->shouldReceive('select')->once()
            ->with(m::any(), m::any(), false);
        $builder->select('*')->from('foo')->where('bar', '=', 'baz')->lock(false)->get();
    }

    public function testBindingOrder()
    {
        $expectedSql = 'select * from "users" inner join "othertable" on "bar" = ? where "registered" = ? group by "city" having "population" > ? order by match ("foo") against(?)';
        $expectedBindings = ['foo', 1, 3, 'bar'];

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->join('othertable', function ($join) { $join->where('bar', '=', 'foo'); })->where('registered', 1)->groupBy('city')->having('population', '>', 3)->orderByRaw('match ("foo") against(?)', ['bar']);
        $this->assertEquals($expectedSql, $builder->toSql());
        $this->assertEquals($expectedBindings, $builder->getBindings());

        // order of statements reversed
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->orderByRaw('match ("foo") against(?)', ['bar'])->having('population', '>', 3)->groupBy('city')->where('registered', 1)->join('othertable', function ($join) { $join->where('bar', '=', 'foo'); });
        $this->assertEquals($expectedSql, $builder->toSql());
        $this->assertEquals($expectedBindings, $builder->getBindings());
    }

    public function testAddBindingWithArrayMergesBindings()
    {
        $builder = $this->getOracleBuilder();
        $builder->addBinding(['foo', 'bar']);
        $builder->addBinding(['baz']);
        $this->assertEquals(['foo', 'bar', 'baz'], $builder->getBindings());
    }

    public function testAddBindingWithArrayMergesBindingsInCorrectOrder()
    {
        $builder = $this->getOracleBuilder();
        $builder->addBinding(['bar', 'baz'], 'having');
        $builder->addBinding(['foo'], 'where');
        $this->assertEquals(['foo', 'bar', 'baz'], $builder->getBindings());
    }

    public function testMergeBuilders()
    {
        $builder = $this->getOracleBuilder();
        $builder->addBinding(['foo', 'bar']);
        $otherBuilder = $this->getOracleBuilder();
        $otherBuilder->addBinding(['baz']);
        $builder->mergeBindings($otherBuilder);
        $this->assertEquals(['foo', 'bar', 'baz'], $builder->getBindings());
    }

    public function testMergeBuildersBindingOrder()
    {
        $builder = $this->getOracleBuilder();
        $builder->addBinding('foo', 'where');
        $builder->addBinding('baz', 'having');
        $otherBuilder = $this->getOracleBuilder();
        $otherBuilder->addBinding('bar', 'where');
        $builder->mergeBindings($otherBuilder);
        $this->assertEquals(['foo', 'bar', 'baz'], $builder->getBindings());
    }

    public function testSubSelect()
    {
        $expectedSql = 'select "foo", "bar", (select "baz" from "two" where "subkey" = ?) as "sub" from "one" where "key" = ?';
        $expectedBindings = ['subval', 'val'];

        $builder = $this->getOracleBuilder();
        $builder->from('one')->select(['foo', 'bar'])->where('key', '=', 'val');
        $builder->selectSub(function ($query) { $query->from('two')->select('baz')->where('subkey', '=', 'subval'); }, 'sub');
        $this->assertEquals($expectedSql, $builder->toSql());
        $this->assertEquals($expectedBindings, $builder->getBindings());

        $builder = $this->getOracleBuilder();
        $builder->from('one')->select(['foo', 'bar'])->where('key', '=', 'val');
        $subBuilder = $this->getOracleBuilder();
        $subBuilder->from('two')->select('baz')->where('subkey', '=', 'subval');
        $builder->selectSub($subBuilder, 'sub');
        $this->assertEquals($expectedSql, $builder->toSql());
        $this->assertEquals($expectedBindings, $builder->getBindings());
    }

    public function testUppercaseLeadingBooleansAreRemoved()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('name', '=', 'Taylor', 'AND');
        $this->assertSame('select * from "users" where "name" = ?', $builder->toSql());
    }    

    public function testLowercaseLeadingBooleansAreRemoved()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('name', '=', 'Taylor', 'and');
        $this->assertSame('select * from "users" where "name" = ?', $builder->toSql());
    }    

    /**
     * @doesNotPerformAssertions
     */
    public function testChunkWithLastChunkComplete()
    {
        $builder = $this->getMockQueryBuilder();
        $builder->orders[] = ['column' => 'foobar', 'direction' => 'asc'];

        $chunk1 = collect(['foo1', 'foo2']);
        $chunk2 = collect(['foo3', 'foo4']);
        $chunk3 = collect([]);
        $builder->shouldReceive('forPage')->once()->with(1, 2)->andReturnSelf();
        $builder->shouldReceive('forPage')->once()->with(2, 2)->andReturnSelf();
        $builder->shouldReceive('forPage')->once()->with(3, 2)->andReturnSelf();
        $builder->shouldReceive('get')->times(3)->andReturn($chunk1, $chunk2, $chunk3);

        $callbackAssertor = m::mock(stdClass::class);
        $callbackAssertor->shouldReceive('doSomething')->once()->with($chunk1);
        $callbackAssertor->shouldReceive('doSomething')->once()->with($chunk2);
        $callbackAssertor->shouldReceive('doSomething')->never()->with($chunk3);

        $builder->chunk(2, function ($results) use ($callbackAssertor) {
            $callbackAssertor->doSomething($results);
        });
    }    

    /**
     * @doesNotPerformAssertions
     */
    public function testChunkWithLastChunkPartial()
    {
        $builder = $this->getMockQueryBuilder();
        $builder->orders[] = ['column' => 'foobar', 'direction' => 'asc'];

        $chunk1 = collect(['foo1', 'foo2']);
        $chunk2 = collect(['foo3']);
        $builder->shouldReceive('forPage')->once()->with(1, 2)->andReturnSelf();
        $builder->shouldReceive('forPage')->once()->with(2, 2)->andReturnSelf();
        $builder->shouldReceive('get')->times(2)->andReturn($chunk1, $chunk2);

        $callbackAssertor = m::mock(stdClass::class);
        $callbackAssertor->shouldReceive('doSomething')->once()->with($chunk1);
        $callbackAssertor->shouldReceive('doSomething')->once()->with($chunk2);

        $builder->chunk(2, function ($results) use ($callbackAssertor) {
            $callbackAssertor->doSomething($results);
        });
    }

    /**
     * @doesNotPerformAssertions
     */
    public function testChunkCanBeStoppedByReturningFalse()
    {
        $builder = $this->getMockQueryBuilder();
        $builder->orders[] = ['column' => 'foobar', 'direction' => 'asc'];

        $chunk1 = collect(['foo1', 'foo2']);
        $chunk2 = collect(['foo3']);
        $builder->shouldReceive('forPage')->once()->with(1, 2)->andReturnSelf();
        $builder->shouldReceive('forPage')->never()->with(2, 2);
        $builder->shouldReceive('get')->times(1)->andReturn($chunk1);

        $callbackAssertor = m::mock(stdClass::class);
        $callbackAssertor->shouldReceive('doSomething')->once()->with($chunk1);
        $callbackAssertor->shouldReceive('doSomething')->never()->with($chunk2);

        $builder->chunk(2, function ($results) use ($callbackAssertor) {
            $callbackAssertor->doSomething($results);

            return false;
        });
    }

    /**
     * @doesNotPerformAssertions
     */    
    public function testChunkWithCountZero()
    {
        $builder = $this->getMockQueryBuilder();
        $builder->orders[] = ['column' => 'foobar', 'direction' => 'asc'];

        $chunk = collect([]);
        $builder->shouldReceive('forPage')->once()->with(1, 0)->andReturnSelf();
        $builder->shouldReceive('get')->times(1)->andReturn($chunk);

        $callbackAssertor = m::mock(stdClass::class);
        $callbackAssertor->shouldReceive('doSomething')->never();

        $builder->chunk(0, function ($results) use ($callbackAssertor) {
            $callbackAssertor->doSomething($results);
        });
    }    

    /**
     * @doesNotPerformAssertions
     */    
    public function testChunkPaginatesUsingIdWithLastChunkComplete()
    {
        $builder = $this->getMockQueryBuilder();
        $builder->orders[] = ['column' => 'foobar', 'direction' => 'asc'];

        $chunk1 = collect([(object) ['someIdField' => 1], (object) ['someIdField' => 2]]);
        $chunk2 = collect([(object) ['someIdField' => 10], (object) ['someIdField' => 11]]);
        $chunk3 = collect([]);
        $builder->shouldReceive('forPageAfterId')->once()->with(2, 0, 'someIdField')->andReturnSelf();
        $builder->shouldReceive('forPageAfterId')->once()->with(2, 2, 'someIdField')->andReturnSelf();
        $builder->shouldReceive('forPageAfterId')->once()->with(2, 11, 'someIdField')->andReturnSelf();
        $builder->shouldReceive('get')->times(3)->andReturn($chunk1, $chunk2, $chunk3);

        $callbackAssertor = m::mock(stdClass::class);
        $callbackAssertor->shouldReceive('doSomething')->once()->with($chunk1);
        $callbackAssertor->shouldReceive('doSomething')->once()->with($chunk2);
        $callbackAssertor->shouldReceive('doSomething')->never()->with($chunk3);

        $builder->chunkById(2, function ($results) use ($callbackAssertor) {
            $callbackAssertor->doSomething($results);
        }, 'someIdField');
    }

    /**
     * @doesNotPerformAssertions
     */    
    public function testChunkPaginatesUsingIdWithLastChunkPartial()
    {
        $builder = $this->getMockQueryBuilder();
        $builder->orders[] = ['column' => 'foobar', 'direction' => 'asc'];

        $chunk1 = collect([(object) ['someIdField' => 1], (object) ['someIdField' => 2]]);
        $chunk2 = collect([(object) ['someIdField' => 10]]);
        $builder->shouldReceive('forPageAfterId')->once()->with(2, 0, 'someIdField')->andReturnSelf();
        $builder->shouldReceive('forPageAfterId')->once()->with(2, 2, 'someIdField')->andReturnSelf();
        $builder->shouldReceive('get')->times(2)->andReturn($chunk1, $chunk2);

        $callbackAssertor = m::mock(stdClass::class);
        $callbackAssertor->shouldReceive('doSomething')->once()->with($chunk1);
        $callbackAssertor->shouldReceive('doSomething')->once()->with($chunk2);

        $builder->chunkById(2, function ($results) use ($callbackAssertor) {
            $callbackAssertor->doSomething($results);
        }, 'someIdField');
    }

    /**
     * @doesNotPerformAssertions
     */    
    public function testChunkPaginatesUsingIdWithCountZero()
    {
        $builder = $this->getMockQueryBuilder();
        $builder->orders[] = ['column' => 'foobar', 'direction' => 'asc'];

        $chunk = collect([]);
        $builder->shouldReceive('forPageAfterId')->once()->with(0, 0, 'someIdField')->andReturnSelf();
        $builder->shouldReceive('get')->times(1)->andReturn($chunk);

        $callbackAssertor = m::mock(stdClass::class);
        $callbackAssertor->shouldReceive('doSomething')->never();

        $builder->chunkById(0, function ($results) use ($callbackAssertor) {
            $callbackAssertor->doSomething($results);
        }, 'someIdField');
    }    

    /**
     * @doesNotPerformAssertions
     */
    public function testChunkPaginatesUsingIdWithAlias()
    {
        $builder = $this->getMockQueryBuilder();
        $builder->orders[] = ['column' => 'foobar', 'direction' => 'asc'];

        $chunk1 = collect([(object) ['table_id' => 1], (object) ['table_id' => 10]]);
        $chunk2 = collect([]);
        $builder->shouldReceive('forPageAfterId')->once()->with(2, 0, 'table.id')->andReturnSelf();
        $builder->shouldReceive('forPageAfterId')->once()->with(2, 10, 'table.id')->andReturnSelf();
        $builder->shouldReceive('get')->times(2)->andReturn($chunk1, $chunk2);

        $callbackAssertor = m::mock(stdClass::class);
        $callbackAssertor->shouldReceive('doSomething')->once()->with($chunk1);
        $callbackAssertor->shouldReceive('doSomething')->never()->with($chunk2);

        $builder->chunkById(2, function ($results) use ($callbackAssertor) {
            $callbackAssertor->doSomething($results);
        }, 'table.id', 'table_id');
    }    

    public function testPaginate()
    {
        $perPage = 16;
        $columns = ['test'];
        $pageName = 'page-name';
        $page = 1;
        $builder = $this->getMockQueryBuilder();
        $path = 'http://foo.bar?page=3';

        $results = collect([['test' => 'foo'], ['test' => 'bar']]);

        $builder->shouldReceive('getCountForPagination')->once()->andReturn(2);
        $builder->shouldReceive('forPage')->once()->with($page, $perPage)->andReturnSelf();
        $builder->shouldReceive('get')->once()->andReturn($results);

        Paginator::currentPathResolver(function () use ($path) {
            return $path;
        });

        $result = $builder->paginate($perPage, $columns, $pageName, $page);

        $this->assertEquals(new LengthAwarePaginator($results, 2, $perPage, $page, [
            'path' => $path,
            'pageName' => $pageName,
        ]), $result);
    }

    public function testPaginateWithDefaultArguments()
    {
        $perPage = 15;
        $pageName = 'page';
        $page = 1;
        $builder = $this->getMockQueryBuilder();
        $path = 'http://foo.bar?page=3';

        $results = collect([['test' => 'foo'], ['test' => 'bar']]);

        $builder->shouldReceive('getCountForPagination')->once()->andReturn(2);
        $builder->shouldReceive('forPage')->once()->with($page, $perPage)->andReturnSelf();
        $builder->shouldReceive('get')->once()->andReturn($results);

        Paginator::currentPageResolver(function () {
            return 1;
        });

        Paginator::currentPathResolver(function () use ($path) {
            return $path;
        });

        $result = $builder->paginate();

        $this->assertEquals(new LengthAwarePaginator($results, 2, $perPage, $page, [
            'path' => $path,
            'pageName' => $pageName,
        ]), $result);
    }

    public function testPaginateWhenNoResults()
    {
        $perPage = 15;
        $pageName = 'page';
        $page = 1;
        $builder = $this->getMockQueryBuilder();
        $path = 'http://foo.bar?page=3';

        $results = [];

        $builder->shouldReceive('getCountForPagination')->once()->andReturn(0);
        $builder->shouldNotReceive('forPage');
        $builder->shouldNotReceive('get');

        Paginator::currentPageResolver(function () {
            return 1;
        });

        Paginator::currentPathResolver(function () use ($path) {
            return $path;
        });

        $result = $builder->paginate();

        $this->assertEquals(new LengthAwarePaginator($results, 0, $perPage, $page, [
            'path' => $path,
            'pageName' => $pageName,
        ]), $result);
    }    

    public function testPaginateWithSpecificColumns()
    {
        $perPage = 16;
        $columns = ['id', 'name'];
        $pageName = 'page-name';
        $page = 1;
        $builder = $this->getMockQueryBuilder();
        $path = 'http://foo.bar?page=3';

        $results = collect([['id' => 3, 'name' => 'Taylor'], ['id' => 5, 'name' => 'Mohamed']]);

        $builder->shouldReceive('getCountForPagination')->once()->andReturn(2);
        $builder->shouldReceive('forPage')->once()->with($page, $perPage)->andReturnSelf();
        $builder->shouldReceive('get')->once()->andReturn($results);

        Paginator::currentPathResolver(function () use ($path) {
            return $path;
        });

        $result = $builder->paginate($perPage, $columns, $pageName, $page);

        $this->assertEquals(new LengthAwarePaginator($results, 2, $perPage, $page, [
            'path' => $path,
            'pageName' => $pageName,
        ]), $result);
    }    

    public function testWhereRowValues()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('orders')->whereRowValues(['last_update', 'order_number'], '<', [1, 2]);
        $this->assertSame('select * from "orders" where ("last_update", "order_number") < (?, ?)', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('orders')->where('company_id', 1)->orWhereRowValues(['last_update', 'order_number'], '<', [1, 2]);
        $this->assertSame('select * from "orders" where "company_id" = ? or ("last_update", "order_number") < (?, ?)', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('orders')->whereRowValues(['last_update', 'order_number'], '<', [1, new Raw('2')]);
        $this->assertSame('select * from "orders" where ("last_update", "order_number") < (?, 2)', $builder->toSql());
        $this->assertEquals([1], $builder->getBindings());
    }

    public function testWhereRowValuesArityMismatch()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The number of columns must match the number of values');

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('orders')->whereRowValues(['last_update'], '<', [1, 2]);
    }    

    public function testWhereJsonContains()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not support');
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereJsonContains('options', ['en'])->toSql();
    }    

    public function testWhereJsonDoesntContain()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not support');
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereJsonDoesntContain('options->languages', ['en'])->toSql();
    }    

    public function testWhereJsonLength()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not support');
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereJsonLength('options', 0)->toSql();
    }    

    public function testBasicSelectNotUsingQuotes()
    {
        $builder = $this->getOracleBuilder(false);
        $builder->select('*')->from('users');
        $this->assertEquals('select * from users', $builder->toSql());

        $builder = $this->getOracleBuilder(false);
        $builder->select('id')->from('users');
        $this->assertEquals('select id from users', $builder->toSql());

        $builder = $this->getOracleBuilder(false);
        $builder->select('id')->from('users')->where('id',1);
        $this->assertEquals('select id from users where id = ?', $builder->toSql());

        $builder = $this->getOracleBuilder(false);
        $builder->select('id')->from('users')->orderBy('id');
        $this->assertEquals('select id from users order by id asc', $builder->toSql());
    }

    public function testFromSub()
    {
        $builder = $this->getOracleBuilder();
        $builder->fromSub(function ($query) {
            $query->select(new Raw('max(last_seen_at) as last_seen_at'))->from('user_sessions')->where('foo', '=', '1');
        }, 'sessions')->where('bar', '<', '10');
        $this->assertSame('select * from (select max(last_seen_at) as last_seen_at from "user_sessions" where "foo" = ?) as "sessions" where "bar" < ?', $builder->toSql());
        $this->assertEquals(['1', '10'], $builder->getBindings());

        $this->expectException(InvalidArgumentException::class);
        $builder = $this->getOracleBuilder();
        $builder->fromSub(['invalid'], 'sessions')->where('bar', '<', '10');
    }

    public function testFromSubWithPrefix()
    {
        $builder = $this->getOracleBuilder();
        $builder->getGrammar()->setTablePrefix('prefix_');
        $builder->fromSub(function ($query) {
            $query->select(new Raw('max(last_seen_at) as last_seen_at'))->from('user_sessions')->where('foo', '=', '1');
        }, 'sessions')->where('bar', '<', '10');
        $this->assertSame('select * from (select max(last_seen_at) as last_seen_at from "prefix_user_sessions" where "foo" = ?) as "prefix_sessions" where "bar" < ?', $builder->toSql());
        $this->assertEquals(['1', '10'], $builder->getBindings());
    }

    public function testFromSubWithoutBindings()
    {
        $builder = $this->getOracleBuilder();
        $builder->fromSub(function ($query) {
            $query->select(new Raw('max(last_seen_at) as last_seen_at'))->from('user_sessions');
        }, 'sessions');
        $this->assertSame('select * from (select max(last_seen_at) as last_seen_at from "user_sessions") as "sessions"', $builder->toSql());

        $this->expectException(InvalidArgumentException::class);
        $builder = $this->getOracleBuilder();
        $builder->fromSub(['invalid'], 'sessions');
    }

    public function testFromRaw()
    {
        $builder = $this->getOracleBuilder();
        $builder->fromRaw(new Raw('(select max(last_seen_at) as last_seen_at from "user_sessions") as "sessions"'));
        $this->assertSame('select * from (select max(last_seen_at) as last_seen_at from "user_sessions") as "sessions"', $builder->toSql());
    }

    public function testFromRawWithWhereOnTheMainQuery()
    {
        $builder = $this->getOracleBuilder();
        $builder->fromRaw(new Raw('(select max(last_seen_at) as last_seen_at from "sessions") as "last_seen_at"'))->where('last_seen_at', '>', '1520652582');
        $this->assertSame('select * from (select max(last_seen_at) as last_seen_at from "sessions") as "last_seen_at" where "last_seen_at" > ?', $builder->toSql());
        $this->assertEquals(['1520652582'], $builder->getBindings());
    }

    public function testGroupByNotUsingQuotes()
    {
        // add group by
        $builder = $this->getOracleBuilder(false);
        $builder->select(['category', new Raw('count(*) as "total"')])->from('item')->where('department', '=', 'popular')->groupBy('category')->having('total', '>', new Raw('3'));
        $this->assertEquals('select category, count(*) as "total" from item where department = ? group by category having total > 3', $builder->toSql());
    }

    public function testJoinNotUsingQuotes()
    {
        // add join
        $builder = $this->getOracleBuilder(false);
        $builder->select(['category', new Raw('count(*) as "total"')])->from('item')->where('department', '=', 'popular')->groupBy('category')->having('total', '>', new Raw('3'));
        $this->assertEquals('select category, count(*) as "total" from item where department = ? group by category having total > 3', $builder->toSql());
    }

    public function testUnionNotUsingQuotes()
    {
        $builder = $this->getOracleBuilder(false);
        $builder->select('*')->from('users')->where('id', '=', 1);
        $builder->union($this->getOracleBuilder(false)->select('*')->from('users')->where('id', '=', 2));
        $this->assertEquals('(select * from users where id = ?) union (select * from users where id = ?)', $builder->toSql());
        $this->assertEquals([0 => 1, 1 => 2], $builder->getBindings());
    }

    public function testHavingNotUsingQuotes()
    {
        $builder = $this->getOracleBuilder(false);
        $builder->select('*')->from('users')->havingRaw('user_foo < user_bar');
        $this->assertEquals('select * from users having user_foo < user_bar', $builder->toSql());

        $builder = $this->getOracleBuilder(false);
        $builder->select('*')->from('users')->having('baz', '=', 1)->orHavingRaw('user_foo < user_bar');
        $this->assertEquals('select * from users having baz = ? or user_foo < user_bar', $builder->toSql());
    }

    public function testLimitsAndOffsetsNotUsingQuotes()
    {
        $builder = $this->getOracleBuilder(false);
        $builder->select('*')->from('users')->offset(10);
        $this->assertEquals('select * from (select * from users) where rownum >= 11', $builder->toSql());

        $builder = $this->getOracleBuilder(false);
        $builder->select('*')->from('users')->offset(5)->limit(10);
        $this->assertEquals('select t2.* from ( select rownum AS "rn", t1.* from (select * from users) t1 ) t2 where t2."rn" between 6 and 15', $builder->toSql());

        $builder = $this->getOracleBuilder(false);
        $builder->select('*')->from('users')->skip(5)->take(10);
        $this->assertEquals('select t2.* from ( select rownum AS "rn", t1.* from (select * from users) t1 ) t2 where t2."rn" between 6 and 15', $builder->toSql());

        $builder = $this->getOracleBuilder(false);
        $builder->select('*')->from('users')->forPage(2, 15);
        $this->assertEquals('select t2.* from ( select rownum AS "rn", t1.* from (select * from users) t1 ) t2 where t2."rn" between 16 and 30', $builder->toSql());
    }

    protected function getOracleBuilder($quote = true)
    {
        global $ConfigReturnValue;
        $ConfigReturnValue = $quote;

        $grammar = new Jfelder\OracleDB\Query\Grammars\OracleGrammar;
        $processor = m::mock('Jfelder\OracleDB\Query\Processors\OracleProcessor');

        return new Builder(m::mock('Illuminate\Database\ConnectionInterface'), $grammar, $processor);
    }

    protected function getOracleBuilderWithProcessor()
    {
        $grammar = new Jfelder\OracleDB\Query\Grammars\OracleGrammar;
        $processor = new Jfelder\OracleDB\Query\Processors\OracleProcessor;

        return new Builder(m::mock('Illuminate\Database\ConnectionInterface'), $grammar, $processor);
    }

    /**
     * @return m\MockInterface
     */
    protected function getMockQueryBuilder()
    {
        return m::mock(Builder::class, [
            m::mock(ConnectionInterface::class),
            new Grammar,
            m::mock(Processor::class),
        ])->makePartial();
    }    
}
