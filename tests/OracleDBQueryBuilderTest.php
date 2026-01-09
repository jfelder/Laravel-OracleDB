<?php

namespace Jfelder\OracleDB\Tests;

use BadMethodCallException;
use Carbon\Carbon;
use Closure;
use DateTime;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression as Raw;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Database\Query\Processors\Processor;
use Illuminate\Pagination\AbstractPaginator as Paginator;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use InvalidArgumentException;
use Jfelder\OracleDB\OracleConnection;
use Jfelder\OracleDB\Query\Grammars\OracleGrammar;
use Jfelder\OracleDB\Query\OracleBuilder as OracleQueryBuilder;
use Jfelder\OracleDB\Query\Processors\OracleProcessor;
use Mockery as m;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

include 'mocks/PDOMocks.php';

class OracleDBQueryBuilderTest extends TestCase
{
    protected $called;

    protected function tearDown(): void
    {
        m::close();
    }

    public function test_basic_select()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users');
        $this->assertEquals('select * from "users"', $builder->toSql());
    }

    public function test_basic_select_with_get_columns()
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

    #[DoesNotPerformAssertions]
    public function test_basic_select_use_write_pdo()
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

    public function test_basic_table_wrapping_protects_quotation_marks()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('some"table');
        $this->assertEquals('select * from "some""table"', $builder->toSql());
    }

    public function test_alias_wrapping_as_whole_constant()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('x.y as foo.bar')->from('baz');
        $this->assertEquals('select "x"."y" as "foo.bar" from "baz"', $builder->toSql());
    }

    public function test_alias_wrapping_with_spaces_in_database_name()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('w x.y.z as foo.bar')->from('baz');
        $this->assertSame('select "w x"."y"."z" as "foo.bar" from "baz"', $builder->toSql());
    }

    public function test_adding_selects()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('foo')->addSelect('bar')->addSelect(['baz', 'boom'])->from('users');
        $this->assertEquals('select "foo", "bar", "baz", "boom" from "users"', $builder->toSql());
    }

    public function test_basic_select_with_prefix()
    {
        $builder = $this->getOracleBuilder(prefix: 'prefix_');
        $builder->select('*')->from('users');
        $this->assertEquals('select * from "prefix_users"', $builder->toSql());
    }

    public function test_basic_select_distinct()
    {
        $builder = $this->getOracleBuilder();
        $builder->distinct()->select('foo', 'bar')->from('users');
        $this->assertEquals('select distinct "foo", "bar" from "users"', $builder->toSql());
    }

    public function test_basic_select_distinct_on_columns()
    {
        $builder = $this->getOracleBuilder();
        $builder->distinct('foo')->select('foo', 'bar')->from('users');
        $this->assertSame('select distinct "foo", "bar" from "users"', $builder->toSql());
    }

    public function test_basic_alias()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('foo as bar')->from('users');
        $this->assertEquals('select "foo" as "bar" from "users"', $builder->toSql());
    }

    public function test_alias_with_prefix()
    {
        $builder = $this->getOracleBuilder(prefix: 'prefix_');
        $builder->select('*')->from('users as people');
        $this->assertEquals('select * from "prefix_users" as "prefix_people"', $builder->toSql());
    }

    public function test_join_aliases_with_prefix()
    {
        $builder = $this->getOracleBuilder(prefix: 'prefix_');
        $builder->select('*')->from('services')->join('translations AS t', 't.item_id', '=', 'services.id');
        $this->assertEquals('select * from "prefix_services" inner join "prefix_translations" as "prefix_t" on "prefix_t"."item_id" = "prefix_services"."id"', $builder->toSql());
    }

    public function test_basic_table_wrapping()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('public.users');
        $this->assertEquals('select * from "public"."users"', $builder->toSql());
    }

    public function test_when_callback()
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

    public function test_when_callback_with_return()
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

    public function test_when_callback_with_default()
    {
        $callback = function ($query, $condition) {
            $this->assertSame('truthy', $condition);

            $query->where('id', '=', 1);
        };

        $default = function ($query, $condition) {
            $this->assertEquals(0, $condition);

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

    public function test_unless_callback()
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

    public function test_unless_callback_with_return()
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

    public function test_unless_callback_with_default()
    {
        $callback = function ($query, $condition) {
            $this->assertEquals(0, $condition);

            $query->where('id', '=', 1);
        };

        $default = function ($query, $condition) {
            $this->assertSame('truthy', $condition);

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

    public function test_tap_callback()
    {
        $callback = function ($query) {
            return $query->where('id', '=', 1);
        };

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->tap($callback)->where('email', 'foo');
        $this->assertSame('select * from "users" where "id" = ? and "email" = ?', $builder->toSql());
    }

    public function test_basic_wheres()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('id', '=', 1);
        $this->assertSame('select * from "users" where "id" = ?', $builder->toSql());
        $this->assertEquals([0 => 1], $builder->getBindings());
    }

    public function test_basic_where_not()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereNot('name', 'foo')->whereNot('name', '<>', 'bar');
        $this->assertSame('select * from "users" where not "name" = ? and not "name" <> ?', $builder->toSql());
        $this->assertEquals(['foo', 'bar'], $builder->getBindings());
    }

    public function test_wheres_with_array_value()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('id', [12]);
        $this->assertSame('select * from "users" where "id" = ?', $builder->toSql());
        $this->assertEquals([0 => 12], $builder->getBindings());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('id', '=', [12, 30]);
        $this->assertSame('select * from "users" where "id" = ?', $builder->toSql());
        $this->assertEquals([0 => 12], $builder->getBindings());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('id', '!=', [12, 30]);
        $this->assertSame('select * from "users" where "id" != ?', $builder->toSql());
        $this->assertEquals([0 => 12], $builder->getBindings());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('id', '<>', [12, 30]);
        $this->assertSame('select * from "users" where "id" <> ?', $builder->toSql());
        $this->assertEquals([0 => 12], $builder->getBindings());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('id', '=', [[12, 30]]);
        $this->assertSame('select * from "users" where "id" = ?', $builder->toSql());
        $this->assertEquals([0 => 12], $builder->getBindings());
    }

    public function test_date_based_wheres_accepts_two_arguments()
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

    public function test_date_based_or_wheres_accepts_two_arguments()
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

    public function test_date_based_wheres_expression_is_not_bound()
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

    public function test_where_date_oracle()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereDate('created_at', '=', '2015-12-21');
        $this->assertSame('select * from "users" where TO_CHAR("created_at", \'YYYY-MM-DD\') = ?', $builder->toSql());
        $this->assertEquals([0 => '2015-12-21'], $builder->getBindings());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereDate('created_at', '=', new Raw("TO_CHAR(sysdate - 1, 'YYYY-MM-DD')"));
        $this->assertSame('select * from "users" where TO_CHAR("created_at", \'YYYY-MM-DD\') = TO_CHAR(sysdate - 1, \'YYYY-MM-DD\')', $builder->toSql());
    }

    public function test_where_day_oracle()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereDay('created_at', '=', 1);
        $this->assertEquals('select * from "users" where TO_CHAR("created_at", \'DD\') = ?', $builder->toSql());
        $this->assertEquals([0 => 1], $builder->getBindings());
    }

    public function test_or_where_day_oracle()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereDay('created_at', '=', 1)->orWhereDay('created_at', '=', 2);
        $this->assertSame('select * from "users" where TO_CHAR("created_at", \'DD\') = ? or TO_CHAR("created_at", \'DD\') = ?', $builder->toSql());
        $this->assertEquals([0 => 1, 1 => 2], $builder->getBindings());
    }

    public function test_where_month_oracle()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereMonth('created_at', '=', 5);
        $this->assertEquals('select * from "users" where TO_CHAR("created_at", \'MM\') = ?', $builder->toSql());
        $this->assertEquals([0 => 5], $builder->getBindings());
    }

    public function test_or_where_month_oracle()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereMonth('created_at', '=', 5)->orWhereMonth('created_at', '=', 6);
        $this->assertSame('select * from "users" where TO_CHAR("created_at", \'MM\') = ? or TO_CHAR("created_at", \'MM\') = ?', $builder->toSql());
        $this->assertEquals([0 => 5, 1 => 6], $builder->getBindings());
    }

    public function test_where_year_oracle()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereYear('created_at', '=', 2014);
        $this->assertEquals('select * from "users" where TO_CHAR("created_at", \'YYYY\') = ?', $builder->toSql());
        $this->assertEquals([0 => 2014], $builder->getBindings());
    }

    public function test_or_where_year_oracle()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereYear('created_at', '=', 2014)->orWhereYear('created_at', '=', 2015);
        $this->assertSame('select * from "users" where TO_CHAR("created_at", \'YYYY\') = ? or TO_CHAR("created_at", \'YYYY\') = ?', $builder->toSql());
        $this->assertEquals([0 => 2014, 1 => 2015], $builder->getBindings());
    }

    public function test_where_time_oracle()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereTime('created_at', '>=', '22:00');
        $this->assertSame('select * from "users" where TO_CHAR("created_at", \'HH24:MI:SS\') >= ?', $builder->toSql());
        $this->assertEquals([0 => '22:00'], $builder->getBindings());
    }

    public function test_where_time_operator_optional_oracle()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereTime('created_at', '22:00');
        $this->assertSame('select * from "users" where TO_CHAR("created_at", \'HH24:MI:SS\') = ?', $builder->toSql());
        $this->assertEquals([0 => '22:00'], $builder->getBindings());
    }

    public function test_where_like_oracle()
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

    #[TestWith(['whereLike: 3rd arg missing'])]
    #[TestWith(['whereLike: 3rd arg false'])]
    #[TestWith(['whereLike: 3rd arg true'])]
    #[TestWith(['whereNotLike: 3rd arg missing'])]
    #[TestWith(['whereNotLike: 3rd arg false'])]
    #[TestWith(['whereNotLike: 3rd arg true'])]
    public function test_where_like_clause_oracle($case)
    {
        if ($case === 'whereLike: 3rd arg missing') {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('This database engine does not support case insensitive like operations. The sql "UPPER(some_column) like ?" can accomplish insensitivity.');
            $builder = $this->getOracleBuilder();
            $builder->select('*')->from('users')->whereLike('id', '1');
            $builder->toSql();
        } elseif ($case === 'whereLike: 3rd arg false') {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('This database engine does not support case insensitive like operations. The sql "UPPER(some_column) like ?" can accomplish insensitivity.');
            $builder = $this->getOracleBuilder();
            $builder->select('*')->from('users')->whereLike('id', '1', false);
            $builder->toSql();
        } elseif ($case === 'whereLike: 3rd arg true') {
            $builder = $this->getOracleBuilder();
            $builder->select('*')->from('users')->whereLike('id', '1', true);
            $this->assertSame('select * from "users" where "id" like ?', $builder->toSql());
            $this->assertEquals([0 => '1'], $builder->getBindings());
        } elseif ($case === 'whereNotLike: 3rd arg missing') {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('This database engine does not support case insensitive like operations. The sql "UPPER(some_column) like ?" can accomplish insensitivity.');
            $builder = $this->getOracleBuilder();
            $builder->select('*')->from('users')->whereNotLike('id', '1');
            $builder->toSql();
        } elseif ($case === 'whereNotLike: 3rd arg false') {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('This database engine does not support case insensitive like operations. The sql "UPPER(some_column) like ?" can accomplish insensitivity.');
            $builder = $this->getOracleBuilder();
            $builder->select('*')->from('users')->whereNotLike('id', '1', false);
            $builder->toSql();
        } elseif ($case === 'whereNotLike: 3rd arg true') {
            $builder = $this->getOracleBuilder();
            $builder->select('*')->from('users')->whereNotLike('id', '1', true);
            $this->assertSame('select * from "users" where "id" not like ?', $builder->toSql());
            $this->assertEquals([0 => '1'], $builder->getBindings());
        } else {
            throw new \Exception("Unknown case number [$case]");
        }
    }

    public function test_where_betweens()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereBetween('id', [1, 2]);
        $this->assertSame('select * from "users" where "id" between ? and ?', $builder->toSql());
        $this->assertEquals([0 => 1, 1 => 2], $builder->getBindings());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereBetween('id', [[1, 2, 3]]);
        $this->assertSame('select * from "users" where "id" between ? and ?', $builder->toSql());
        $this->assertEquals([0 => 1, 1 => 2], $builder->getBindings());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereBetween('id', [[1], [2, 3]]);
        $this->assertSame('select * from "users" where "id" between ? and ?', $builder->toSql());
        $this->assertEquals([0 => 1, 1 => 2], $builder->getBindings());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereNotBetween('id', [1, 2]);
        $this->assertSame('select * from "users" where "id" not between ? and ?', $builder->toSql());
        $this->assertEquals([0 => 1, 1 => 2], $builder->getBindings());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereBetween('id', [new Raw(1), new Raw(2)]);
        $this->assertSame('select * from "users" where "id" between 1 and 2', $builder->toSql());
        $this->assertEquals([], $builder->getBindings());

        // custom long carbon period date
        $builder = $this->getOracleBuilder();
        $period = Carbon::now()->toPeriod(Carbon::now()->addMonth());
        $builder->select('*')->from('users')->whereBetween('created_at', $period);
        $this->assertSame('select * from "users" where "created_at" between ? and ?', $builder->toSql());
        $this->assertEquals([$period->start, $period->end], $builder->getBindings());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereBetween('id', collect([1, 2]));
        $this->assertSame('select * from "users" where "id" between ? and ?', $builder->toSql());
        $this->assertEquals([0 => 1, 1 => 2], $builder->getBindings());
    }

    public function test_where_between_columns()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereBetweenColumns('id', ['users.created_at', 'users.updated_at']);
        $this->assertSame('select * from "users" where "id" between "users"."created_at" and "users"."updated_at"', $builder->toSql());
        $this->assertEquals([], $builder->getBindings());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereNotBetweenColumns('id', ['created_at', 'updated_at']);
        $this->assertSame('select * from "users" where "id" not between "created_at" and "updated_at"', $builder->toSql());
        $this->assertEquals([], $builder->getBindings());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereBetweenColumns('id', [new Raw(1), new Raw(2)]);
        $this->assertSame('select * from "users" where "id" between 1 and 2', $builder->toSql());
        $this->assertEquals([], $builder->getBindings());
    }

    public function test_basic_or_wheres()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('id', '=', 1)->orWhere('email', '=', 'foo');
        $this->assertEquals('select * from "users" where "id" = ? or "email" = ?', $builder->toSql());
        $this->assertEquals([0 => 1, 1 => 'foo'], $builder->getBindings());
    }

    public function test_basic_or_where_not()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->orWhereNot('name', 'foo')->orWhereNot('name', '<>', 'bar');
        $this->assertSame('select * from "users" where not "name" = ? or not "name" <> ?', $builder->toSql());
        $this->assertEquals(['foo', 'bar'], $builder->getBindings());
    }

    public function test_raw_wheres()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereRaw('id = ? or email = ?', [1, 'foo']);
        $this->assertEquals('select * from "users" where id = ? or email = ?', $builder->toSql());
        $this->assertEquals([0 => 1, 1 => 'foo'], $builder->getBindings());
    }

    public function test_raw_or_wheres()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('id', '=', 1)->orWhereRaw('email = ?', ['foo']);
        $this->assertEquals('select * from "users" where "id" = ? or email = ?', $builder->toSql());
        $this->assertEquals([0 => 1, 1 => 'foo'], $builder->getBindings());
    }

    public function test_basic_where_ins()
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

    public function test_basic_where_not_ins()
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

    public function test_raw_where_ins()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereIn('id', [new Raw(1)]);
        $this->assertSame('select * from "users" where "id" in (1)', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('id', '=', 1)->orWhereIn('id', [new Raw(1)]);
        $this->assertSame('select * from "users" where "id" = ? or "id" in (1)', $builder->toSql());
        $this->assertEquals([0 => 1], $builder->getBindings());
    }

    public function test_empty_where_ins()
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

    public function test_empty_where_not_ins()
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

    public function test_where_integer_in_raw()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereIntegerInRaw('id', ['1a', 2]);
        $this->assertSame('select * from "users" where "id" in (1, 2)', $builder->toSql());
        $this->assertEquals([], $builder->getBindings());
    }

    public function test_or_where_integer_in_raw()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('id', '=', 1)->orWhereIntegerInRaw('id', ['1a', 2]);
        $this->assertSame('select * from "users" where "id" = ? or "id" in (1, 2)', $builder->toSql());
        $this->assertEquals([0 => 1], $builder->getBindings());
    }

    public function test_where_integer_not_in_raw()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereIntegerNotInRaw('id', ['1a', 2]);
        $this->assertSame('select * from "users" where "id" not in (1, 2)', $builder->toSql());
        $this->assertEquals([], $builder->getBindings());
    }

    public function test_or_where_integer_not_in_raw()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('id', '=', 1)->orWhereIntegerNotInRaw('id', ['1a', 2]);
        $this->assertSame('select * from "users" where "id" = ? or "id" not in (1, 2)', $builder->toSql());
        $this->assertEquals([0 => 1], $builder->getBindings());
    }

    public function test_empty_where_integer_in_raw()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereIntegerInRaw('id', []);
        $this->assertSame('select * from "users" where 0 = 1', $builder->toSql());
        $this->assertEquals([], $builder->getBindings());
    }

    public function test_empty_where_integer_not_in_raw()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereIntegerNotInRaw('id', []);
        $this->assertSame('select * from "users" where 1 = 1', $builder->toSql());
        $this->assertEquals([], $builder->getBindings());
    }

    public function test_basic_where_column()
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

    public function test_array_where_column()
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

    public function test_where_any()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereAny(['last_name', 'email'], 'like', '%Otwell%');
        $this->assertSame('select * from "users" where ("last_name" like ? or "email" like ?)', $builder->toSql());
        $this->assertEquals(['%Otwell%', '%Otwell%'], $builder->getBindings());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereAny(['last_name', 'email'], '%Otwell%');
        $this->assertSame('select * from "users" where ("last_name" = ? or "email" = ?)', $builder->toSql());
        $this->assertEquals(['%Otwell%', '%Otwell%'], $builder->getBindings());
    }

    public function test_or_where_any()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('first_name', 'like', '%Taylor%')->orWhereAny(['last_name', 'email'], 'like', '%Otwell%');
        $this->assertSame('select * from "users" where "first_name" like ? or ("last_name" like ? or "email" like ?)', $builder->toSql());
        $this->assertEquals(['%Taylor%', '%Otwell%', '%Otwell%'], $builder->getBindings());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('first_name', 'like', '%Taylor%')->whereAny(['last_name', 'email'], 'like', '%Otwell%', 'or');
        $this->assertSame('select * from "users" where "first_name" like ? or ("last_name" like ? or "email" like ?)', $builder->toSql());
        $this->assertEquals(['%Taylor%', '%Otwell%', '%Otwell%'], $builder->getBindings());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('first_name', 'like', '%Taylor%')->orWhereAny(['last_name', 'email'], '%Otwell%');
        $this->assertSame('select * from "users" where "first_name" like ? or ("last_name" = ? or "email" = ?)', $builder->toSql());
        $this->assertEquals(['%Taylor%', '%Otwell%', '%Otwell%'], $builder->getBindings());
    }

    public function test_where_none()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereNone(['last_name', 'email'], 'like', '%Otwell%');
        $this->assertSame('select * from "users" where not ("last_name" like ? or "email" like ?)', $builder->toSql());
        $this->assertEquals(['%Otwell%', '%Otwell%'], $builder->getBindings());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereNone(['last_name', 'email'], 'Otwell');
        $this->assertSame('select * from "users" where not ("last_name" = ? or "email" = ?)', $builder->toSql());
        $this->assertEquals(['Otwell', 'Otwell'], $builder->getBindings());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('first_name', 'like', '%Taylor%')->whereNone(['last_name', 'email'], 'like', '%Otwell%');
        $this->assertSame('select * from "users" where "first_name" like ? and not ("last_name" like ? or "email" like ?)', $builder->toSql());
        $this->assertEquals(['%Taylor%', '%Otwell%', '%Otwell%'], $builder->getBindings());
    }

    public function test_or_where_none()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('first_name', 'like', '%Taylor%')->orWhereNone(['last_name', 'email'], 'like', '%Otwell%');
        $this->assertSame('select * from "users" where "first_name" like ? or not ("last_name" like ? or "email" like ?)', $builder->toSql());
        $this->assertEquals(['%Taylor%', '%Otwell%', '%Otwell%'], $builder->getBindings());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('first_name', 'like', '%Taylor%')->whereNone(['last_name', 'email'], 'like', '%Otwell%', 'or');
        $this->assertSame('select * from "users" where "first_name" like ? or not ("last_name" like ? or "email" like ?)', $builder->toSql());
        $this->assertEquals(['%Taylor%', '%Otwell%', '%Otwell%'], $builder->getBindings());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('first_name', 'like', '%Taylor%')->orWhereNone(['last_name', 'email'], '%Otwell%');
        $this->assertSame('select * from "users" where "first_name" like ? or not ("last_name" = ? or "email" = ?)', $builder->toSql());
        $this->assertEquals(['%Taylor%', '%Otwell%', '%Otwell%'], $builder->getBindings());
    }

    public function test_unions()
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

    public function test_union_alls()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('id', '=', 1);
        $builder->unionAll($this->getOracleBuilder()->select('*')->from('users')->where('id', '=', 2));
        $this->assertEquals('(select * from "users" where "id" = ?) union all (select * from "users" where "id" = ?)', $builder->toSql());
        $this->assertEquals([0 => 1, 1 => 2], $builder->getBindings());
    }

    public function test_multiple_unions()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('id', '=', 1);
        $builder->union($this->getOracleBuilder()->select('*')->from('users')->where('id', '=', 2));
        $builder->union($this->getOracleBuilder()->select('*')->from('users')->where('id', '=', 3));
        $this->assertEquals('(select * from "users" where "id" = ?) union (select * from "users" where "id" = ?) union (select * from "users" where "id" = ?)', $builder->toSql());
        $this->assertEquals([0 => 1, 1 => 2, 2 => 3], $builder->getBindings());
    }

    public function test_multiple_union_alls()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('id', '=', 1);
        $builder->unionAll($this->getOracleBuilder()->select('*')->from('users')->where('id', '=', 2));
        $builder->unionAll($this->getOracleBuilder()->select('*')->from('users')->where('id', '=', 3));
        $this->assertEquals('(select * from "users" where "id" = ?) union all (select * from "users" where "id" = ?) union all (select * from "users" where "id" = ?)', $builder->toSql());
        $this->assertEquals([0 => 1, 1 => 2, 2 => 3], $builder->getBindings());
    }

    public function test_union_with_join()
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

    public function test_oracle_union_order_bys()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('id', '=', 1);
        $builder->union($this->getOracleBuilder()->select('*')->from('users')->where('id', '=', 2));
        $builder->orderBy('id', 'desc');
        $this->assertEquals('(select * from "users" where "id" = ?) union (select * from "users" where "id" = ?) order by "id" desc', $builder->toSql());
        $this->assertEquals([0 => 1, 1 => 2], $builder->getBindings());
    }

    public function test_oracle_union_limits_and_offsets()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users');
        $builder->union($this->getOracleBuilder()->select('*')->from('dogs'));
        $builder->skip(5)->take(10);
        $this->assertEquals('select t2.* from ( select rownum AS "rn", t1.* from ((select * from "users") union (select * from "dogs")) t1 ) t2 where t2."rn" between 6 and 15', $builder->toSql());
    }

    #[DoesNotPerformAssertions]
    public function test_union_aggregate()
    {
        $expected = 'select count(*) as aggregate from ((select * from "posts") union (select * from "videos")) as "temp_table"';
        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('select')->once()->with($expected, [], true);
        $builder->getProcessor()->shouldReceive('processSelect')->once();
        $builder->from('posts')->union($this->getOracleBuilder()->from('videos'))->count();
    }

    #[DoesNotPerformAssertions]
    public function test_having_aggregate()
    {
        $expected = 'select count(*) as aggregate from (select (select "count(*)" from "videos" where "posts"."id" = "videos"."post_id") as "videos_count" from "posts" having "videos_count" > ?) as "temp_table"';
        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('getDatabaseName');
        $builder->getConnection()->shouldReceive('select')->once()->with($expected, [0 => 1], true)->andReturn([['aggregate' => 1]]);
        $builder->getProcessor()->shouldReceive('processSelect')->once()->andReturnUsing(function ($builder, $results) {
            return $results;
        });

        $builder->from('posts')->selectSub(function ($query) {
            $query->from('videos')->select('count(*)')->whereColumn('posts.id', '=', 'videos.post_id');
        }, 'videos_count')->having('videos_count', '>', 1);
        $builder->count();
    }

    public function test_sub_select_where_ins()
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

    public function test_basic_where_nulls()
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

    public function test_json_where_null()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not support');

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereNull('items->id');
        $builder->toSql();
    }

    public function test_json_where_not_null()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not support');

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereNotNull('items->id');
        $builder->toSql();
    }

    public function test_array_where_nulls()
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

    public function test_basic_where_not_nulls()
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

    public function test_array_where_not_nulls()
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

    public function test_group_bys()
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

    public function test_order_bys()
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

    public function test_reorder()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->orderBy('name');
        $this->assertSame('select * from "users" order by "name" asc', $builder->toSql());
        $builder->reorder();
        $this->assertSame('select * from "users"', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->orderBy('name');
        $this->assertSame('select * from "users" order by "name" asc', $builder->toSql());
        $builder->reorder('email', 'desc');
        $this->assertSame('select * from "users" order by "email" desc', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('first');
        $builder->union($this->getOracleBuilder()->select('*')->from('second'));
        $builder->orderBy('name');
        $this->assertSame('(select * from "first") union (select * from "second") order by "name" asc', $builder->toSql());
        $builder->reorder();
        $this->assertSame('(select * from "first") union (select * from "second")', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->orderByRaw('?', [true]);
        $this->assertEquals([true], $builder->getBindings());
        $builder->reorder();
        $this->assertEquals([], $builder->getBindings());
    }

    public function test_order_by_sub_queries()
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

    public function test_order_by_invalid_direction_param()
    {
        $this->expectException(InvalidArgumentException::class);

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->orderBy('age', 'asec');
    }

    public function test_havings()
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
    }

    public function test_nested_havings()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->having('email', '=', 'foo')->orHaving(function ($q) {
            $q->having('name', '=', 'bar')->having('age', '=', 25);
        });
        $this->assertSame('select * from "users" having "email" = ? or ("name" = ? and "age" = ?)', $builder->toSql());
        $this->assertEquals([0 => 'foo', 1 => 'bar', 2 => 25], $builder->getBindings());
    }

    public function test_nested_having_bindings()
    {
        $builder = $this->getOracleBuilder();
        $builder->having('email', '=', 'foo')->having(function ($q) {
            $q->selectRaw('?', ['ignore'])->having('name', '=', 'bar');
        });
        $this->assertEquals([0 => 'foo', 1 => 'bar'], $builder->getBindings());
    }

    public function test_having_betweens()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->havingBetween('id', [1, 2, 3]);
        $this->assertSame('select * from "users" having "id" between ? and ?', $builder->toSql());
        $this->assertEquals([0 => 1, 1 => 2], $builder->getBindings());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->havingBetween('id', [[1, 2], [3, 4]]);
        $this->assertSame('select * from "users" having "id" between ? and ?', $builder->toSql());
        $this->assertEquals([0 => 1, 1 => 2], $builder->getBindings());
    }

    public function test_having_null()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->havingNull('email');
        $this->assertSame('select * from "users" having "email" is null', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')
            ->havingNull('email')
            ->havingNull('phone');
        $this->assertSame('select * from "users" having "email" is null and "phone" is null', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')
            ->orHavingNull('email')
            ->orHavingNull('phone');
        $this->assertSame('select * from "users" having "email" is null or "phone" is null', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->groupBy('email')->havingNull('email');
        $this->assertSame('select * from "users" group by "email" having "email" is null', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('email as foo_email')->from('users')->havingNull('foo_email');
        $this->assertSame('select "email" as "foo_email" from "users" having "foo_email" is null', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select(['category', new Raw('count(*) as "total"')])->from('item')->where('department', '=', 'popular')->groupBy('category')->havingNull('total');
        $this->assertSame('select "category", count(*) as "total" from "item" where "department" = ? group by "category" having "total" is null', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select(['category', new Raw('count(*) as "total"')])->from('item')->where('department', '=', 'popular')->groupBy('category')->havingNull('total');
        $this->assertSame('select "category", count(*) as "total" from "item" where "department" = ? group by "category" having "total" is null', $builder->toSql());
    }

    public function test_having_not_null()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->havingNotNull('email');
        $this->assertSame('select * from "users" having "email" is not null', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')
            ->havingNotNull('email')
            ->havingNotNull('phone');
        $this->assertSame('select * from "users" having "email" is not null and "phone" is not null', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')
            ->orHavingNotNull('email')
            ->orHavingNotNull('phone');
        $this->assertSame('select * from "users" having "email" is not null or "phone" is not null', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->groupBy('email')->havingNotNull('email');
        $this->assertSame('select * from "users" group by "email" having "email" is not null', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('email as foo_email')->from('users')->havingNotNull('foo_email');
        $this->assertSame('select "email" as "foo_email" from "users" having "foo_email" is not null', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select(['category', new Raw('count(*) as "total"')])->from('item')->where('department', '=', 'popular')->groupBy('category')->havingNotNull('total');
        $this->assertSame('select "category", count(*) as "total" from "item" where "department" = ? group by "category" having "total" is not null', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select(['category', new Raw('count(*) as "total"')])->from('item')->where('department', '=', 'popular')->groupBy('category')->havingNotNull('total');
        $this->assertSame('select "category", count(*) as "total" from "item" where "department" = ? group by "category" having "total" is not null', $builder->toSql());
    }

    public function test_having_shortcut()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->having('email', 1)->orHaving('email', 2);
        $this->assertSame('select * from "users" having "email" = ? or "email" = ?', $builder->toSql());
    }

    public function test_having_followed_by_select_get()
    {
        $builder = $this->getOracleBuilder();
        $query = 'select "category", count(*) as "total" from "item" where "department" = ? group by "category" having "total" > ?';
        $builder->getConnection()->shouldReceive('select')->once()->with($query, ['popular', 3], true)->andReturn([['category' => 'rock', 'total' => 5]]);
        $builder->getProcessor()->shouldReceive('processSelect')->andReturnUsing(function ($builder, $results) {
            return $results;
        });
        $builder->from('item');
        $result = $builder->select(['category', new Raw('count(*) as "total"')])->where('department', '=', 'popular')->groupBy('category')->having('total', '>', 3)->get();
        $this->assertEquals([['category' => 'rock', 'total' => 5]], $result->all());

        // Using \Raw value
        $builder = $this->getOracleBuilder();
        $query = 'select "category", count(*) as "total" from "item" where "department" = ? group by "category" having "total" > 3';
        $builder->getConnection()->shouldReceive('select')->once()->with($query, ['popular'], true)->andReturn([['category' => 'rock', 'total' => 5]]);
        $builder->getProcessor()->shouldReceive('processSelect')->andReturnUsing(function ($builder, $results) {
            return $results;
        });
        $builder->from('item');
        $result = $builder->select(['category', new Raw('count(*) as "total"')])->where('department', '=', 'popular')->groupBy('category')->having('total', '>', new Raw('3'))->get();
        $this->assertEquals([['category' => 'rock', 'total' => 5]], $result->all());
    }

    public function test_raw_havings()
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

    public function test_limits_and_offsets()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->offset(10);
        $this->assertEquals('select * from (select * from "users") where rownum >= 11', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->offset(5)->limit(10);
        $this->assertEquals('select t2.* from ( select rownum AS "rn", t1.* from (select * from "users") t1 ) t2 where t2."rn" between 6 and 15', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->limit(null);
        $this->assertSame('select * from "users"', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->limit(0);
        $this->assertSame('select * from (select * from "users") where rownum < 1', $builder->toSql());

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

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->skip(null)->take(null);
        $this->assertSame('select * from (select * from "users") where rownum >= 1', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->skip(5)->take(null);
        $this->assertSame('select * from (select * from "users") where rownum >= 6', $builder->toSql());
    }

    public function test_for_page()
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

    public function test_get_count_for_pagination_with_bindings()
    {
        $builder = $this->getOracleBuilder();
        $builder->from('users')->selectSub(function ($q) {
            $q->select('body')->from('posts')->where('id', 4);
        }, 'post');

        $builder->getConnection()->shouldReceive('select')->once()->with('select count(*) as aggregate from "users"', [], true)->andReturn([['aggregate' => 1]]);
        $builder->getProcessor()->shouldReceive('processSelect')->once()->andReturnUsing(function ($builder, $results) {
            return $results;
        });

        $count = $builder->getCountForPagination();
        $this->assertEquals(1, $count);
        $this->assertEquals([4], $builder->getBindings());
    }

    public function test_get_count_for_pagination_with_column_aliases()
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

    public function test_get_count_for_pagination_with_union()
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

    public function test_where_shortcut()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('id', 1)->orWhere('name', 'foo');
        $this->assertEquals('select * from "users" where "id" = ? or "name" = ?', $builder->toSql());
        $this->assertEquals([0 => 1, 1 => 'foo'], $builder->getBindings());
    }

    public function test_where_with_array_conditions()
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

    public function test_nested_wheres()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('email', '=', 'foo')->orWhere(function ($q) {
            $q->where('name', '=', 'bar')->where('age', '=', 25);
        });
        $this->assertEquals('select * from "users" where "email" = ? or ("name" = ? and "age" = ?)', $builder->toSql());
        $this->assertEquals([0 => 'foo', 1 => 'bar', 2 => 25], $builder->getBindings());
    }

    public function test_nested_where_bindings()
    {
        $builder = $this->getOracleBuilder();
        $builder->where('email', '=', 'foo')->where(function ($q) {
            $q->selectRaw('?', ['ignore'])->where('name', '=', 'bar');
        });
        $this->assertEquals([0 => 'foo', 1 => 'bar'], $builder->getBindings());
    }

    public function test_where_not()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereNot(function ($q) {
            $q->where('email', '=', 'foo');
        });
        $this->assertSame('select * from "users" where not ("email" = ?)', $builder->toSql());
        $this->assertEquals([0 => 'foo'], $builder->getBindings());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('name', '=', 'bar')->whereNot(function ($q) {
            $q->where('email', '=', 'foo');
        });
        $this->assertSame('select * from "users" where "name" = ? and not ("email" = ?)', $builder->toSql());
        $this->assertEquals([0 => 'bar', 1 => 'foo'], $builder->getBindings());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('name', '=', 'bar')->orWhereNot(function ($q) {
            $q->where('email', '=', 'foo');
        });
        $this->assertSame('select * from "users" where "name" = ? or not ("email" = ?)', $builder->toSql());
        $this->assertEquals([0 => 'bar', 1 => 'foo'], $builder->getBindings());
    }

    public function test_full_sub_selects()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('email', '=', 'foo')->orWhere('id', '=', function ($q) {
            $q->select(new Raw('max(id)'))->from('users')->where('email', '=', 'bar');
        });

        $this->assertEquals('select * from "users" where "email" = ? or "id" = (select max(id) from "users" where "email" = ?)', $builder->toSql());
        $this->assertEquals([0 => 'foo', 1 => 'bar'], $builder->getBindings());
    }

    public function test_where_exists()
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

    public function test_basic_joins()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->join('contacts', 'users.id', '=', 'contacts.id')->leftJoin('photos', 'users.id', '=', 'photos.id');
        $this->assertEquals('select * from "users" inner join "contacts" on "users"."id" = "contacts"."id" left join "photos" on "users"."id" = "photos"."id"', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->leftJoinWhere('photos', 'users.id', '=', 'bar')->joinWhere('photos', 'users.id', '=', 'foo');
        $this->assertEquals('select * from "users" left join "photos" on "users"."id" = ? inner join "photos" on "users"."id" = ?', $builder->toSql());
        $this->assertEquals(['bar', 'foo'], $builder->getBindings());
    }

    public function test_cross_joins()
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

    public function test_cross_join_subs()
    {
        $builder = $this->getOracleBuilder();
        $builder->selectRaw('(sale / "overall".sales) * 100 AS percent_of_total')->from('sales')->crossJoinSub($this->getOracleBuilder()->selectRaw('SUM(sale) AS sales')->from('sales'), 'overall');
        $this->assertSame('select (sale / "overall".sales) * 100 AS percent_of_total from "sales" cross join (select SUM(sale) AS sales from "sales") as "overall"', $builder->toSql());
    }

    public function test_complex_join()
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

    public function test_join_where_null()
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

    public function test_join_where_not_null()
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

    public function test_join_where_in()
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

    public function test_join_where_in_subquery()
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

    public function test_join_where_not_in()
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

    public function test_joins_with_nested_conditions()
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

    public function test_joins_with_advanced_conditions()
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

    public function test_joins_with_subquery_condition()
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

    public function test_joins_with_advanced_subquery_condition()
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

    public function test_joins_with_nested_joins()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('users.id', 'contacts.id', 'contact_types.id')->from('users')->leftJoin('contacts', function ($j) {
            $j->on('users.id', 'contacts.id')->join('contact_types', 'contacts.contact_type_id', '=', 'contact_types.id');
        });
        $this->assertSame('select "users"."id", "contacts"."id", "contact_types"."id" from "users" left join ("contacts" inner join "contact_types" on "contacts"."contact_type_id" = "contact_types"."id") on "users"."id" = "contacts"."id"', $builder->toSql());
    }

    public function test_joins_with_multiple_nested_joins()
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

    public function test_joins_with_nested_join_with_advanced_subquery_condition()
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

    public function test_join_sub()
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

    public function test_join_sub_with_prefix()
    {
        $builder = $this->getOracleBuilder(prefix: 'prefix_');
        $builder->from('users')->joinSub('select * from "contacts"', 'sub', 'users.id', '=', 'sub.id');
        $this->assertSame('select * from "prefix_users" inner join (select * from "contacts") as "prefix_sub" on "prefix_users"."id" = "prefix_sub"."id"', $builder->toSql());
    }

    public function test_left_join_sub()
    {
        $builder = $this->getOracleBuilder();
        $builder->from('users')->leftJoinSub($this->getOracleBuilder()->from('contacts'), 'sub', 'users.id', '=', 'sub.id');
        $this->assertSame('select * from "users" left join (select * from "contacts") as "sub" on "users"."id" = "sub"."id"', $builder->toSql());

        $this->expectException(InvalidArgumentException::class);
        $builder = $this->getOracleBuilder();
        $builder->from('users')->leftJoinSub(['foo'], 'sub', 'users.id', '=', 'sub.id');
    }

    public function test_right_join_sub()
    {
        $builder = $this->getOracleBuilder();
        $builder->from('users')->rightJoinSub($this->getOracleBuilder()->from('contacts'), 'sub', 'users.id', '=', 'sub.id');
        $this->assertSame('select * from "users" right join (select * from "contacts") as "sub" on "users"."id" = "sub"."id"', $builder->toSql());

        $this->expectException(InvalidArgumentException::class);
        $builder = $this->getOracleBuilder();
        $builder->from('users')->rightJoinSub(['foo'], 'sub', 'users.id', '=', 'sub.id');
    }

    public function test_raw_expressions_in_select()
    {
        $builder = $this->getOracleBuilder();
        $builder->select(new Raw('substr(foo, 6)'))->from('users');
        $this->assertEquals('select substr(foo, 6) from "users"', $builder->toSql());
    }

    public function test_find_returns_first_result_by_id()
    {
        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('select')->once()->with('select t2.* from ( select rownum AS "rn", t1.* from (select * from "users" where "id" = ?) t1 ) t2 where t2."rn" between 1 and 1', [1], true)->andReturn([['foo' => 'bar']]);
        $builder->getProcessor()->shouldReceive('processSelect')->once()->with($builder, [['foo' => 'bar']])->andReturnUsing(function ($query, $results) {
            return $results;
        });
        $results = $builder->from('users')->find(1);
        $this->assertEquals(['foo' => 'bar'], $results);
    }

    public function test_first_method_returns_first_result()
    {
        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('select')->once()->with('select t2.* from ( select rownum AS "rn", t1.* from (select * from "users" where "id" = ?) t1 ) t2 where t2."rn" between 1 and 1', [1], true)->andReturn([['foo' => 'bar']]);
        $builder->getProcessor()->shouldReceive('processSelect')->once()->with($builder, [['foo' => 'bar']])->andReturnUsing(function ($query, $results) {
            return $results;
        });
        $results = $builder->from('users')->where('id', '=', 1)->first();
        $this->assertEquals(['foo' => 'bar'], $results);
    }

    public function test_pluck_methods_gets_array_of_column_values()
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

    public function test_implode()
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

    public function test_value_method_returns_single_column()
    {
        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('select')->once()->with('select t2.* from ( select rownum AS "rn", t1.* from (select "foo" from "users" where "id" = ?) t1 ) t2 where t2."rn" between 1 and 1', [1], true)->andReturn([['foo' => 'bar']]);
        $builder->getProcessor()->shouldReceive('processSelect')->once()->with($builder, [['foo' => 'bar']])->andReturn([['foo' => 'bar']]);
        $results = $builder->from('users')->where('id', '=', 1)->value('foo');
        $this->assertEquals('bar', $results);
    }

    public function test_aggregate_functions()
    {
        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('select')->once()->with('select count(*) as aggregate from "users"', [], true)->andReturn([['aggregate' => 1]]);
        $builder->getProcessor()->shouldReceive('processSelect')->once()->andReturnUsing(function ($builder, $results) {
            return $results;
        });
        $results = $builder->from('users')->count();
        $this->assertEquals(1, $results);

        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('select')->once()->with('select t2."rn" as "exists" from ( select rownum AS "rn", t1.* from (select * from "users") t1 ) t2 where t2."rn" between 1 and 1', [], true)->andReturn([['exists' => 1]]);
        $results = $builder->from('users')->exists();
        $this->assertTrue($results);

        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('select')->once()->with('select t2."rn" as "exists" from ( select rownum AS "rn", t1.* from (select * from "users") t1 ) t2 where t2."rn" between 1 and 1', [], true)->andReturn([['exists' => 0]]);
        $results = $builder->from('users')->doesntExist();
        $this->assertTrue($results);

        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('select')->once()->with('select max("id") as aggregate from "users"', [], true)->andReturn([['aggregate' => 1]]);
        $builder->getProcessor()->shouldReceive('processSelect')->once()->andReturnUsing(function ($builder, $results) {
            return $results;
        });
        $results = $builder->from('users')->max('id');
        $this->assertEquals(1, $results);

        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('select')->once()->with('select min("id") as aggregate from "users"', [], true)->andReturn([['aggregate' => 1]]);
        $builder->getProcessor()->shouldReceive('processSelect')->once()->andReturnUsing(function ($builder, $results) {
            return $results;
        });
        $results = $builder->from('users')->min('id');
        $this->assertEquals(1, $results);

        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('select')->once()->with('select sum("id") as aggregate from "users"', [], true)->andReturn([['aggregate' => 1]]);
        $builder->getProcessor()->shouldReceive('processSelect')->once()->andReturnUsing(function ($builder, $results) {
            return $results;
        });
        $results = $builder->from('users')->sum('id');
        $this->assertEquals(1, $results);
    }

    public function test_exists_or()
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
            throw new RuntimeException;
        });
        $this->assertTrue($results);
    }

    public function test_doesnt_exists_or()
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
            throw new RuntimeException;
        });
        $this->assertTrue($results);
    }

    public function test_aggregate_reset_followed_by_get()
    {
        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('select')->once()->with('select count(*) as aggregate from "users"', [], true)->andReturn([['aggregate' => 1]]);
        $builder->getConnection()->shouldReceive('select')->once()->with('select sum("id") as aggregate from "users"', [], true)->andReturn([['aggregate' => 2]]);
        $builder->getConnection()->shouldReceive('select')->once()->with('select "column1", "column2" from "users"', [], true)->andReturn([['column1' => 'foo', 'column2' => 'bar']]);
        $builder->getProcessor()->shouldReceive('processSelect')->andReturnUsing(function ($builder, $results) {
            return $results;
        });
        $builder->from('users')->select('column1', 'column2');
        $count = $builder->count();
        $this->assertEquals(1, $count);
        $sum = $builder->sum('id');
        $this->assertEquals(2, $sum);
        $result = $builder->get();
        $this->assertEquals([['column1' => 'foo', 'column2' => 'bar']], $result->all());
    }

    public function test_aggregate_reset_followed_by_select_get()
    {
        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('select')->once()->with('select count("column1") as aggregate from "users"', [], true)->andReturn([['aggregate' => 1]]);
        $builder->getConnection()->shouldReceive('select')->once()->with('select "column2", "column3" from "users"', [], true)->andReturn([['column2' => 'foo', 'column3' => 'bar']]);
        $builder->getProcessor()->shouldReceive('processSelect')->andReturnUsing(function ($builder, $results) {
            return $results;
        });
        $builder->from('users');
        $count = $builder->count('column1');
        $this->assertEquals(1, $count);
        $result = $builder->select('column2', 'column3')->get();
        $this->assertEquals([['column2' => 'foo', 'column3' => 'bar']], $result->all());
    }

    public function test_aggregate_reset_followed_by_get_with_columns()
    {
        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('select')->once()->with('select count("column1") as aggregate from "users"', [], true)->andReturn([['aggregate' => 1]]);
        $builder->getConnection()->shouldReceive('select')->once()->with('select "column2", "column3" from "users"', [], true)->andReturn([['column2' => 'foo', 'column3' => 'bar']]);
        $builder->getProcessor()->shouldReceive('processSelect')->andReturnUsing(function ($builder, $results) {
            return $results;
        });
        $builder->from('users');
        $count = $builder->count('column1');
        $this->assertEquals(1, $count);
        $result = $builder->get(['column2', 'column3']);
        $this->assertEquals([['column2' => 'foo', 'column3' => 'bar']], $result->all());
    }

    public function test_aggregate_with_sub_select()
    {
        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('select')->once()->with('select count(*) as aggregate from "users"', [], true)->andReturn([['aggregate' => 1]]);
        $builder->getProcessor()->shouldReceive('processSelect')->once()->andReturnUsing(function ($builder, $results) {
            return $results;
        });
        $builder->from('users')->selectSub(function ($query) {
            $query->from('posts')->select('foo', 'bar')->where('title', 'foo');
        }, 'post');
        $count = $builder->count();
        $this->assertEquals(1, $count);
        $this->assertSame('(select "foo", "bar" from "posts" where "title" = ?) as "post"', $builder->getGrammar()->getValue($builder->columns[0]));
        $this->assertEquals(['foo'], $builder->getBindings());
    }

    public function test_subqueries_bindings()
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

    public function test_insert_method()
    {
        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('insert')->once()->with('insert into "users" ("email") values (?)', ['foo'])->andReturn(true);
        $result = $builder->from('users')->insert(['email' => 'foo']);
        $this->assertTrue($result);
    }

    public function test_insert_using_method()
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

    public function test_insert_using_invalid_subquery()
    {
        $this->expectException(InvalidArgumentException::class);
        $builder = $this->getOracleBuilder();
        $builder->from('table1')->insertUsing(['foo'], ['bar']);
    }

    public function test_insert_or_ignore_method()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not support');
        $builder = $this->getOracleBuilder();
        $builder->from('users')->insertOrIgnore(['email' => 'foo']);
    }

    public function test_multiple_insert_method()
    {
        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('insert')->once()->with('insert all into "users" ("email") values (?) into "users" ("email") values (?)  select 1 from dual', [0 => 'foo', 1 => 'bar'])->andReturn(true);
        $result = $builder->from('users')->insert([['email' => 'foo'], ['email' => 'bar']]);
        $this->assertTrue($result);
    }

    public function test_insert_get_id_method()
    {
        $builder = $this->getOracleBuilder();
        $builder->getProcessor()->shouldReceive('processInsertGetId')->once()->with($builder, 'insert into "users" ("email") values (?) returning "id" into ?', ['foo'], 'id')->andReturn(1);
        $result = $builder->from('users')->insertGetId(['email' => 'foo'], 'id');
        $this->assertEquals(1, $result);
    }

    public function test_insert_get_id_method_removes_expressions()
    {
        $builder = $this->getOracleBuilder();
        $builder->getProcessor()->shouldReceive('processInsertGetId')->once()->with($builder, 'insert into "users" ("email", "bar") values (?, bar) returning "id" into ?', ['foo'], 'id')->andReturn(1);
        $result = $builder->from('users')->insertGetId(['email' => 'foo', 'bar' => new Raw('bar')], 'id');
        $this->assertEquals(1, $result);
    }

    public function test_insert_get_id_with_empty_values()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not support');
        $builder = $this->getOracleBuilder();
        $builder->from('users')->insertGetId([]);
    }

    public function test_insert_method_respects_raw_bindings()
    {
        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('insert')->once()->with('insert into "users" ("email") values (CURRENT TIMESTAMP)', [])->andReturn(true);
        $result = $builder->from('users')->insert(['email' => new Raw('CURRENT TIMESTAMP')]);
        $this->assertTrue($result);
    }

    public function test_multiple_inserts_with_expression_values()
    {
        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('insert')->once()->with('insert all into "users" ("email") values (UPPER(\'Foo\')) into "users" ("email") values (UPPER(\'Foo\'))  select 1 from dual', [])->andReturn(true);
        $result = $builder->from('users')->insert([['email' => new Raw("UPPER('Foo')")], ['email' => new Raw("LOWER('Foo')")]]);
        $this->assertTrue($result);
    }

    public function test_update_method()
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

    public function test_upsert_method()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not support');
        $builder = $this->getOracleBuilder();
        $builder->from('users')->upsert([['email' => 'foo', 'name' => 'bar'], ['name' => 'bar2', 'email' => 'foo2']], 'email');
    }

    public function test_upsert_method_with_update_columns()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not support');
        $builder = $this->getOracleBuilder();
        $builder->from('users')->upsert([['email' => 'foo', 'name' => 'bar'], ['name' => 'bar2', 'email' => 'foo2']], 'email', ['name']);
    }

    public function test_update_method_with_joins()
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

    public function test_update_method_respects_raw()
    {
        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('update')->once()->with('update "users" set "email" = foo, "name" = ? where "id" = ?', ['bar', 1])->andReturn(1);
        $result = $builder->from('users')->where('id', '=', 1)->update(['email' => new Raw('foo'), 'name' => 'bar']);
        $this->assertEquals(1, $result);
    }

    public function test_update_method_works_with_query_as_value()
    {
        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('update')->once()->with('update "users" set "credits" = (select sum(credits) from "transactions" where "transactions"."user_id" = "users"."id" and "type" = ?) where "id" = ?', ['foo', 1])->andReturn(1);
        $result = $builder->from('users')->where('id', '=', 1)->update(['credits' => $this->getOracleBuilder()->from('transactions')->selectRaw('sum(credits)')->whereColumn('transactions.user_id', 'users.id')->where('type', 'foo')]);

        $this->assertEquals(1, $result);
    }

    public function test_update_or_insert_method()
    {
        $builder = m::spy(OracleQueryBuilder::class.'[where,exists,insert]', [
            $connection = m::mock(OracleConnection::class),
            new OracleGrammar($connection),
            m::mock(OracleProcessor::class),
        ]);

        $builder->shouldReceive('where')->once()->with(['email' => 'foo'])->andReturn(m::self());
        $builder->shouldReceive('exists')->once()->andReturn(false);
        $builder->shouldReceive('insert')->once()->with(['email' => 'foo', 'name' => 'bar'])->andReturn(true);

        $this->assertTrue($builder->updateOrInsert(['email' => 'foo'], ['name' => 'bar']));

        $builder = m::spy(OracleQueryBuilder::class.'[where,exists,update]', [
            $connection = m::mock(OracleConnection::class),
            new OracleGrammar($connection),
            m::mock(OracleProcessor::class),
        ]);

        $builder->shouldReceive('where')->once()->with(['email' => 'foo'])->andReturn(m::self());
        $builder->shouldReceive('exists')->once()->andReturn(true);
        $builder->shouldReceive('take')->andReturnSelf();
        $builder->shouldReceive('update')->once()->with(['name' => 'bar'])->andReturn(1);

        $this->assertTrue($builder->updateOrInsert(['email' => 'foo'], ['name' => 'bar']));
    }

    public function test_update_or_insert_method_works_with_empty_update_values()
    {
        $builder = m::spy(OracleQueryBuilder::class.'[where,exists,update]', [
            $connection = m::mock(OracleConnection::class),
            new OracleGrammar($connection),
            m::mock(OracleProcessor::class),
        ]);

        $builder->shouldReceive('where')->once()->with(['email' => 'foo'])->andReturn(m::self());
        $builder->shouldReceive('exists')->once()->andReturn(true);

        $this->assertTrue($builder->updateOrInsert(['email' => 'foo']));
        $builder->shouldNotHaveReceived('update');
    }

    public function test_delete_method()
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

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not support');
        $builder = $this->getOracleBuilder();
        $builder->from('users')->where('email', '=', 'foo')->orderBy('id')->take(1)->delete();
    }

    public function test_delete_with_join_method()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not support');
        $builder = $this->getOracleBuilder();
        $builder->from('users')->join('contacts', 'users.id', '=', 'contacts.id')->where('users.email', '=', 'foo')->orderBy('users.id')->limit(1)->delete();
    }

    #[DoesNotPerformAssertions]
    public function test_truncate_method()
    {
        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('statement')->once()->with('truncate table "users"', []);
        $builder->from('users')->truncate();
    }

    public function test_preserve_adds_closure_to_array()
    {
        $builder = $this->getOracleBuilder();
        $builder->beforeQuery(function () {});
        $this->assertCount(1, $builder->beforeQueryCallbacks);
        $this->assertInstanceOf(Closure::class, $builder->beforeQueryCallbacks[0]);
    }

    public function test_apply_preserve_cleans_array()
    {
        $builder = $this->getOracleBuilder();
        $builder->beforeQuery(function () {});
        $this->assertCount(1, $builder->beforeQueryCallbacks);
        $builder->applyBeforeQueryCallbacks();
        $this->assertCount(0, $builder->beforeQueryCallbacks);
    }

    public function test_preserved_are_applied_by_to_sql()
    {
        $builder = $this->getOracleBuilder();
        $builder->beforeQuery(function ($builder) {
            $builder->where('foo', 'bar');
        });
        $this->assertSame('select * where "foo" = ?', $builder->toSql());
        $this->assertEquals(['bar'], $builder->getBindings());
    }

    #[DoesNotPerformAssertions]
    public function test_preserved_are_applied_by_insert()
    {
        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('insert')->once()->with('insert into "users" ("email") values (?)', ['foo']);
        $builder->beforeQuery(function ($builder) {
            $builder->from('users');
        });
        $builder->insert(['email' => 'foo']);
    }

    #[DoesNotPerformAssertions]
    public function test_preserved_are_applied_by_insert_get_id()
    {
        $this->called = false;
        $builder = $this->getOracleBuilder();
        $builder->getProcessor()->shouldReceive('processInsertGetId')->once()->with($builder, 'insert into "users" ("email") values (?) returning "id" into ?', ['foo'], 'id');
        $builder->beforeQuery(function ($builder) {
            $builder->from('users');
        });
        $builder->insertGetId(['email' => 'foo'], 'id');
    }

    #[DoesNotPerformAssertions]
    public function test_preserved_are_applied_by_insert_using()
    {
        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('affectingStatement')->once()->with('insert into "users" ("email") select *', []);
        $builder->beforeQuery(function ($builder) {
            $builder->from('users');
        });
        $builder->insertUsing(['email'], $this->getOracleBuilder());
    }

    // NOTE: testPreservedAreAppliedByUpsert omitted since ->upsert is on the "not implemented" list

    #[DoesNotPerformAssertions]
    public function test_preserved_are_applied_by_update()
    {
        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('update')->once()->with('update "users" set "email" = ? where "id" = ?', ['foo', 1]);
        $builder->from('users')->beforeQuery(function ($builder) {
            $builder->where('id', 1);
        });
        $builder->update(['email' => 'foo']);
    }

    #[DoesNotPerformAssertions]
    public function test_preserved_are_applied_by_delete()
    {
        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('delete')->once()->with('delete from "users"', []);
        $builder->beforeQuery(function ($builder) {
            $builder->from('users');
        });
        $builder->delete();
    }

    #[DoesNotPerformAssertions]
    public function test_preserved_are_applied_by_truncate()
    {
        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('statement')->once()->with('truncate table "users"', []);
        $builder->beforeQuery(function ($builder) {
            $builder->from('users');
        });
        $builder->truncate();
    }

    #[DoesNotPerformAssertions]
    public function test_preserved_are_applied_by_exists()
    {
        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('select')->once()->with('select t2."rn" as "exists" from ( select rownum AS "rn", t1.* from (select * from "users") t1 ) t2 where t2."rn" between 1 and 1', [], true);
        $builder->beforeQuery(function ($builder) {
            $builder->from('users');
        });
        $builder->exists();
    }

    public function test_update_wrapping_json()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not support');
        $builder = $this->getOracleBuilder();
        $builder->from('users')->where('active', '=', 1)->update(['name->first_name' => 'John', 'name->last_name' => 'Doe']);
    }

    public function test_update_wrapping_nested_json()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not support');
        $builder = $this->getOracleBuilder();
        $builder->from('users')->where('active', '=', 1)->update(['meta->name->first_name' => 'John', 'meta->name->last_name' => 'Doe']);
    }

    public function test_update_wrapping_json_array()
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

    public function test_update_wrapping_nested_json_array()
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

    public function test_update_with_json_prepares_bindings_correctly()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not support');
        $builder = $this->getOracleBuilder();
        $builder->from('users')->where('id', '=', 0)->update(['options->enable' => false, 'updated_at' => '2015-05-26 22:02:06']);
    }

    public function test_wrapping_json_with_string()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not support');
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('items->sku', '=', 'foo-bar')->toSql();
    }

    public function test_wrapping_json_with_integer()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not support');
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('items->price', '=', 1)->toSql();
    }

    public function test_wrapping_json_with_double()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not support');
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('items->price', '=', 1.5)->toSql();
    }

    public function test_wrapping_json_with_boolean()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not support');
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('items->available', '=', true)->toSql();
    }

    public function test_wrapping_json_with_boolean_and_integer_that_looks_like_one()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not support');
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('items->available', '=', true)->where('items->active', '=', false)->where('items->number_available', '=', 0)->toSql();
    }

    public function test_json_path_escaping()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not support');
        $builder = $this->getOracleBuilder();
        $builder->select("json->'))#")->toSql();
    }

    public function test_wrapping_json()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not support');
        $builder = $this->getOracleBuilder();
        $builder->select('items->price')->from('users')->where('users.items->price', '=', 1)->orderBy('items->price')->toSql();
    }

    public function test_bitwise_operators()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('bar', '&', 1);
        $this->assertSame('select * from "users" where "bar" & ?', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->having('bar', '&', 1);
        $this->assertSame('select * from "users" having "bar" & ?', $builder->toSql());
    }

    public function test_merge_wheres_can_merge_wheres_and_bindings()
    {
        $builder = $this->getOracleBuilder();
        $builder->wheres = ['foo'];
        $builder->mergeWheres(['wheres'], [12 => 'foo', 13 => 'bar']);
        $this->assertEquals(['foo', 'wheres'], $builder->wheres);
        $this->assertEquals(['foo', 'bar'], $builder->getBindings());
    }

    public function test_providing_null_or_false_as_second_parameter_builds_correctly()
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

    public function test_dynamic_where()
    {
        $method = 'whereFooBarAndBazOrQux';
        $parameters = ['corge', 'waldo', 'fred'];
        $builder = m::mock(OracleQueryBuilder::class)->makePartial();

        $builder->shouldReceive('where')->with('foo_bar', '=', $parameters[0], 'and')->once()->andReturn($builder);
        $builder->shouldReceive('where')->with('baz', '=', $parameters[1], 'and')->once()->andReturn($builder);
        $builder->shouldReceive('where')->with('qux', '=', $parameters[2], 'or')->once()->andReturn($builder);

        $this->assertEquals($builder, $builder->dynamicWhere($method, $parameters));
    }

    #[DoesNotPerformAssertions]
    public function test_dynamic_where_is_not_greedy()
    {
        $method = 'whereIosVersionAndAndroidVersionOrOrientation';
        $parameters = ['6.1', '4.2', 'Vertical'];
        $builder = m::mock(OracleQueryBuilder::class)->makePartial();

        $builder->shouldReceive('where')->with('ios_version', '=', '6.1', 'and')->once()->andReturn($builder);
        $builder->shouldReceive('where')->with('android_version', '=', '4.2', 'and')->once()->andReturn($builder);
        $builder->shouldReceive('where')->with('orientation', '=', 'Vertical', 'or')->once()->andReturn($builder);

        $builder->dynamicWhere($method, $parameters);
    }

    public function test_call_triggers_dynamic_where()
    {
        $builder = $this->getOracleBuilder();

        $this->assertEquals($builder, $builder->whereFooAndBar('baz', 'qux'));
        $this->assertCount(2, $builder->wheres);
    }

    public function test_builder_throws_expected_exception_with_undefined_method()
    {
        $builder = $this->getOracleBuilder();

        $this->expectException(BadMethodCallException::class);

        $builder->noValidMethodHere();
    }

    public function setupCacheTestQuery($cache, $driver)
    {
        $connection = m::mock(ConnectionInterface::class);
        $connection->shouldReceive('getName')->andReturn('connection_name');
        $connection->shouldReceive('getCacheManager')->once()->andReturn($cache);
        $cache->shouldReceive('driver')->once()->andReturn($driver);
        $grammar = new Illuminate\Database\Query\Grammars\Grammar;
        $processor = m::mock(OracleProcessor::class);

        $builder = $this->getMock(OracleQueryBuilder::class, ['getFresh'], [$connection, $grammar, $processor]);
        $builder->expects($this->once())->method('getFresh')->with($this->equalTo(['*']))->will($this->returnValue(['results']));

        return $builder->select('*')->from('users')->where('email', 'foo@bar.com');
    }

    public function test_oracle_lock()
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

    #[DoesNotPerformAssertions]
    public function test_select_with_lock_uses_write_pdo()
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

    public function test_binding_order()
    {
        $expectedSql = 'select * from "users" inner join "othertable" on "bar" = ? where "registered" = ? group by "city" having "population" > ? order by match ("foo") against(?)';
        $expectedBindings = ['foo', 1, 3, 'bar'];

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->join('othertable', function ($join) {
            $join->where('bar', '=', 'foo');
        })->where('registered', 1)->groupBy('city')->having('population', '>', 3)->orderByRaw('match ("foo") against(?)', ['bar']);
        $this->assertEquals($expectedSql, $builder->toSql());
        $this->assertEquals($expectedBindings, $builder->getBindings());

        // order of statements reversed
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->orderByRaw('match ("foo") against(?)', ['bar'])->having('population', '>', 3)->groupBy('city')->where('registered', 1)->join('othertable', function ($join) {
            $join->where('bar', '=', 'foo');
        });
        $this->assertEquals($expectedSql, $builder->toSql());
        $this->assertEquals($expectedBindings, $builder->getBindings());
    }

    public function test_add_binding_with_array_merges_bindings()
    {
        $builder = $this->getOracleBuilder();
        $builder->addBinding(['foo', 'bar']);
        $builder->addBinding(['baz']);
        $this->assertEquals(['foo', 'bar', 'baz'], $builder->getBindings());
    }

    public function test_add_binding_with_array_merges_bindings_in_correct_order()
    {
        $builder = $this->getOracleBuilder();
        $builder->addBinding(['bar', 'baz'], 'having');
        $builder->addBinding(['foo'], 'where');
        $this->assertEquals(['foo', 'bar', 'baz'], $builder->getBindings());
    }

    public function test_merge_builders()
    {
        $builder = $this->getOracleBuilder();
        $builder->addBinding(['foo', 'bar']);
        $otherBuilder = $this->getOracleBuilder();
        $otherBuilder->addBinding(['baz']);
        $builder->mergeBindings($otherBuilder);
        $this->assertEquals(['foo', 'bar', 'baz'], $builder->getBindings());
    }

    public function test_merge_builders_binding_order()
    {
        $builder = $this->getOracleBuilder();
        $builder->addBinding('foo', 'where');
        $builder->addBinding('baz', 'having');
        $otherBuilder = $this->getOracleBuilder();
        $otherBuilder->addBinding('bar', 'where');
        $builder->mergeBindings($otherBuilder);
        $this->assertEquals(['foo', 'bar', 'baz'], $builder->getBindings());
    }

    public function test_sub_select()
    {
        $expectedSql = 'select "foo", "bar", (select "baz" from "two" where "subkey" = ?) as "sub" from "one" where "key" = ?';
        $expectedBindings = ['subval', 'val'];

        $builder = $this->getOracleBuilder();
        $builder->from('one')->select(['foo', 'bar'])->where('key', '=', 'val');
        $builder->selectSub(function ($query) {
            $query->from('two')->select('baz')->where('subkey', '=', 'subval');
        }, 'sub');
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

    public function test_sub_select_reset_bindings()
    {
        $builder = $this->getOracleBuilder();
        $builder->from('one')->selectSub(function ($query) {
            $query->from('two')->select('baz')->where('subkey', '=', 'subval');
        }, 'sub');

        $this->assertSame('select (select "baz" from "two" where "subkey" = ?) as "sub" from "one"', $builder->toSql());
        $this->assertEquals(['subval'], $builder->getBindings());

        $builder->select('*');

        $this->assertSame('select * from "one"', $builder->toSql());
        $this->assertEquals([], $builder->getBindings());
    }

    public function test_uppercase_leading_booleans_are_removed()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('name', '=', 'Taylor', 'AND');
        $this->assertSame('select * from "users" where "name" = ?', $builder->toSql());
    }

    public function test_lowercase_leading_booleans_are_removed()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('name', '=', 'Taylor', 'and');
        $this->assertSame('select * from "users" where "name" = ?', $builder->toSql());
    }

    #[DoesNotPerformAssertions]
    public function test_chunk_with_last_chunk_complete()
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

    #[DoesNotPerformAssertions]
    public function test_chunk_with_last_chunk_partial()
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

    #[DoesNotPerformAssertions]
    public function test_chunk_can_be_stopped_by_returning_false()
    {
        $builder = $this->getMockQueryBuilder();
        $builder->orders[] = ['column' => 'foobar', 'direction' => 'asc'];

        $chunk1 = collect(['foo1', 'foo2']);
        $chunk2 = collect(['foo3']);
        $builder->shouldReceive('getOffset')->once()->andReturnNull();
        $builder->shouldReceive('getLimit')->once()->andReturnNull();
        $builder->shouldReceive('offset')->once()->with(0)->andReturnSelf();
        $builder->shouldReceive('limit')->once()->with(2)->andReturnSelf();
        $builder->shouldReceive('get')->times(1)->andReturn($chunk1);

        $callbackAssertor = m::mock(stdClass::class);
        $callbackAssertor->shouldReceive('doSomething')->once()->with($chunk1);
        $callbackAssertor->shouldReceive('doSomething')->never()->with($chunk2);

        $builder->chunk(2, function ($results) use ($callbackAssertor) {
            $callbackAssertor->doSomething($results);

            return false;
        });
    }

    public function test_chunk_with_count_zero()
    {
        $builder = $this->getMockQueryBuilder();
        $builder->orders[] = ['column' => 'foobar', 'direction' => 'asc'];

        $builder->shouldReceive('getOffset')->once()->andReturnNull();
        $builder->shouldReceive('getLimit')->once()->andReturnNull();
        $builder->shouldReceive('offset')->never();
        $builder->shouldReceive('limit')->never();
        $builder->shouldReceive('get')->never();

        $builder->chunk(0, function () {
            $this->fail('Should never be called.');
        });
    }

    #[DoesNotPerformAssertions]
    public function test_chunk_paginates_using_id_with_last_chunk_complete()
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

    #[DoesNotPerformAssertions]
    public function test_chunk_paginates_using_id_with_last_chunk_partial()
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

    public function test_chunk_paginates_using_id_with_count_zero()
    {
        $builder = $this->getMockQueryBuilder();
        $builder->orders[] = ['column' => 'foobar', 'direction' => 'asc'];

        $builder->shouldReceive('forPageAfterId')->never();
        $builder->shouldReceive('get')->never();

        $builder->chunkById(0, function () {
            $this->fail('Should never be called.');
        }, 'someIdField');
    }

    #[DoesNotPerformAssertions]
    public function test_chunk_paginates_using_id_with_alias()
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

    public function test_paginate()
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

    public function test_paginate_with_default_arguments()
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

    public function test_paginate_when_no_results()
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

    public function test_paginate_with_specific_columns()
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

    public function test_cursor_paginate()
    {
        $perPage = 16;
        $columns = ['test'];
        $cursorName = 'cursor-name';
        $cursor = new Cursor(['test' => 'bar']);
        $builder = $this->getMockQueryBuilder();
        $builder->from('foobar')->orderBy('test');
        $builder->shouldReceive('newQuery')->andReturnUsing(function () use ($builder) {
            return new Builder($builder->connection, $builder->grammar, $builder->processor);
        });

        $path = 'http://foo.bar?cursor='.$cursor->encode();

        $results = collect([['test' => 'foo'], ['test' => 'bar']]);

        $builder->shouldReceive('get')->once()->andReturnUsing(function () use ($builder, $results) {
            $this->assertEquals(
                'select * from "foobar" where ("test" > ?) order by "test" asc limit 17',
                $builder->toSql());
            $this->assertEquals(['bar'], $builder->bindings['where']);

            return $results;
        });

        Paginator::currentPathResolver(function () use ($path) {
            return $path;
        });

        $result = $builder->cursorPaginate($perPage, $columns, $cursorName, $cursor);

        $this->assertEquals(new CursorPaginator($results, $perPage, $cursor, [
            'path' => $path,
            'cursorName' => $cursorName,
            'parameters' => ['test'],
        ]), $result);
    }

    public function test_cursor_paginate_multiple_order_columns()
    {
        $perPage = 16;
        $columns = ['test', 'another'];
        $cursorName = 'cursor-name';
        $cursor = new Cursor(['test' => 'bar', 'another' => 'foo']);
        $builder = $this->getMockQueryBuilder();
        $builder->from('foobar')->orderBy('test')->orderBy('another');
        $builder->shouldReceive('newQuery')->andReturnUsing(function () use ($builder) {
            return new Builder($builder->connection, $builder->grammar, $builder->processor);
        });

        $path = 'http://foo.bar?cursor='.$cursor->encode();

        $results = collect([['test' => 'foo', 'another' => 1], ['test' => 'bar', 'another' => 2]]);

        $builder->shouldReceive('get')->once()->andReturnUsing(function () use ($builder, $results) {
            $this->assertEquals(
                'select * from "foobar" where ("test" > ? or ("test" = ? and ("another" > ?))) order by "test" asc, "another" asc limit 17',
                $builder->toSql()
            );
            $this->assertEquals(['bar', 'bar', 'foo'], $builder->bindings['where']);

            return $results;
        });

        Paginator::currentPathResolver(function () use ($path) {
            return $path;
        });

        $result = $builder->cursorPaginate($perPage, $columns, $cursorName, $cursor);

        $this->assertEquals(new CursorPaginator($results, $perPage, $cursor, [
            'path' => $path,
            'cursorName' => $cursorName,
            'parameters' => ['test', 'another'],
        ]), $result);
    }

    public function test_cursor_paginate_with_default_arguments()
    {
        $perPage = 15;
        $cursorName = 'cursor';
        $cursor = new Cursor(['test' => 'bar']);
        $builder = $this->getMockQueryBuilder();
        $builder->from('foobar')->orderBy('test');
        $builder->shouldReceive('newQuery')->andReturnUsing(function () use ($builder) {
            return new Builder($builder->connection, $builder->grammar, $builder->processor);
        });

        $path = 'http://foo.bar?cursor='.$cursor->encode();

        $results = collect([['test' => 'foo'], ['test' => 'bar']]);

        $builder->shouldReceive('get')->once()->andReturnUsing(function () use ($builder, $results) {
            $this->assertEquals(
                'select * from "foobar" where ("test" > ?) order by "test" asc limit 16',
                $builder->toSql());
            $this->assertEquals(['bar'], $builder->bindings['where']);

            return $results;
        });

        CursorPaginator::currentCursorResolver(function () use ($cursor) {
            return $cursor;
        });

        Paginator::currentPathResolver(function () use ($path) {
            return $path;
        });

        $result = $builder->cursorPaginate();

        $this->assertEquals(new CursorPaginator($results, $perPage, $cursor, [
            'path' => $path,
            'cursorName' => $cursorName,
            'parameters' => ['test'],
        ]), $result);
    }

    public function test_cursor_paginate_when_no_results()
    {
        $perPage = 15;
        $cursorName = 'cursor';
        $builder = $this->getMockQueryBuilder()->orderBy('test');
        $path = 'http://foo.bar?cursor=3';

        $results = [];

        $builder->shouldReceive('get')->once()->andReturn($results);

        CursorPaginator::currentCursorResolver(function () {
            return null;
        });

        Paginator::currentPathResolver(function () use ($path) {
            return $path;
        });

        $result = $builder->cursorPaginate();

        $this->assertEquals(new CursorPaginator($results, $perPage, null, [
            'path' => $path,
            'cursorName' => $cursorName,
            'parameters' => ['test'],
        ]), $result);
    }

    public function test_cursor_paginate_with_specific_columns()
    {
        $perPage = 16;
        $columns = ['id', 'name'];
        $cursorName = 'cursor-name';
        $cursor = new Cursor(['id' => 2]);
        $builder = $this->getMockQueryBuilder();
        $builder->from('foobar')->orderBy('id');
        $builder->shouldReceive('newQuery')->andReturnUsing(function () use ($builder) {
            return new Builder($builder->connection, $builder->grammar, $builder->processor);
        });

        $path = 'http://foo.bar?cursor=3';

        $results = collect([['id' => 3, 'name' => 'Taylor'], ['id' => 5, 'name' => 'Mohamed']]);

        $builder->shouldReceive('get')->once()->andReturnUsing(function () use ($builder, $results) {
            $this->assertEquals(
                'select * from "foobar" where ("id" > ?) order by "id" asc limit 17',
                $builder->toSql());
            $this->assertEquals([2], $builder->bindings['where']);

            return $results;
        });

        Paginator::currentPathResolver(function () use ($path) {
            return $path;
        });

        $result = $builder->cursorPaginate($perPage, $columns, $cursorName, $cursor);

        $this->assertEquals(new CursorPaginator($results, $perPage, $cursor, [
            'path' => $path,
            'cursorName' => $cursorName,
            'parameters' => ['id'],
        ]), $result);
    }

    public function test_cursor_paginate_with_mixed_orders()
    {
        $perPage = 16;
        $columns = ['foo', 'bar', 'baz'];
        $cursorName = 'cursor-name';
        $cursor = new Cursor(['foo' => 1, 'bar' => 2, 'baz' => 3]);
        $builder = $this->getMockQueryBuilder();
        $builder->from('foobar')->orderBy('foo')->orderByDesc('bar')->orderBy('baz');
        $builder->shouldReceive('newQuery')->andReturnUsing(function () use ($builder) {
            return new Builder($builder->connection, $builder->grammar, $builder->processor);
        });

        $path = 'http://foo.bar?cursor='.$cursor->encode();

        $results = collect([['foo' => 1, 'bar' => 2, 'baz' => 4], ['foo' => 1, 'bar' => 1, 'baz' => 1]]);

        $builder->shouldReceive('get')->once()->andReturnUsing(function () use ($builder, $results) {
            $this->assertEquals(
                'select * from "foobar" where ("foo" > ? or ("foo" = ? and ("bar" < ? or ("bar" = ? and ("baz" > ?))))) order by "foo" asc, "bar" desc, "baz" asc limit 17',
                $builder->toSql()
            );
            $this->assertEquals([1, 1, 2, 2, 3], $builder->bindings['where']);

            return $results;
        });

        Paginator::currentPathResolver(function () use ($path) {
            return $path;
        });

        $result = $builder->cursorPaginate($perPage, $columns, $cursorName, $cursor);

        $this->assertEquals(new CursorPaginator($results, $perPage, $cursor, [
            'path' => $path,
            'cursorName' => $cursorName,
            'parameters' => ['foo', 'bar', 'baz'],
        ]), $result);
    }

    public function test_cursor_paginate_with_dynamic_column_in_select_raw()
    {
        $perPage = 15;
        $cursorName = 'cursor';
        $cursor = new Cursor(['test' => 'bar']);
        $builder = $this->getMockQueryBuilder();
        $builder->from('foobar')->select('*')->selectRaw('(CONCAT(firstname, \' \', lastname)) as test')->orderBy('test');
        $builder->shouldReceive('newQuery')->andReturnUsing(function () use ($builder) {
            return new Builder($builder->connection, $builder->grammar, $builder->processor);
        });

        $path = 'http://foo.bar?cursor='.$cursor->encode();

        $results = collect([['test' => 'foo'], ['test' => 'bar']]);

        $builder->shouldReceive('get')->once()->andReturnUsing(function () use ($builder, $results) {
            $this->assertEquals(
                'select *, (CONCAT(firstname, \' \', lastname)) as test from "foobar" where ((CONCAT(firstname, \' \', lastname)) > ?) order by "test" asc limit 16',
                $builder->toSql());
            $this->assertEquals(['bar'], $builder->bindings['where']);

            return $results;
        });

        CursorPaginator::currentCursorResolver(function () use ($cursor) {
            return $cursor;
        });

        Paginator::currentPathResolver(function () use ($path) {
            return $path;
        });

        $result = $builder->cursorPaginate();

        $this->assertEquals(new CursorPaginator($results, $perPage, $cursor, [
            'path' => $path,
            'cursorName' => $cursorName,
            'parameters' => ['test'],
        ]), $result);
    }

    public function test_cursor_paginate_with_dynamic_column_in_select_sub()
    {
        $perPage = 15;
        $cursorName = 'cursor';
        $cursor = new Cursor(['test' => 'bar']);
        $builder = $this->getMockQueryBuilder();
        $builder->from('foobar')->select('*')->selectSub('CONCAT(firstname, \' \', lastname)', 'test')->orderBy('test');
        $builder->shouldReceive('newQuery')->andReturnUsing(function () use ($builder) {
            return new Builder($builder->connection, $builder->grammar, $builder->processor);
        });

        $path = 'http://foo.bar?cursor='.$cursor->encode();

        $results = collect([['test' => 'foo'], ['test' => 'bar']]);

        $builder->shouldReceive('get')->once()->andReturnUsing(function () use ($builder, $results) {
            $this->assertEquals(
                'select *, (CONCAT(firstname, \' \', lastname)) as "test" from "foobar" where ((CONCAT(firstname, \' \', lastname)) > ?) order by "test" asc limit 16',
                $builder->toSql());
            $this->assertEquals(['bar'], $builder->bindings['where']);

            return $results;
        });

        CursorPaginator::currentCursorResolver(function () use ($cursor) {
            return $cursor;
        });

        Paginator::currentPathResolver(function () use ($path) {
            return $path;
        });

        $result = $builder->cursorPaginate();

        $this->assertEquals(new CursorPaginator($results, $perPage, $cursor, [
            'path' => $path,
            'cursorName' => $cursorName,
            'parameters' => ['test'],
        ]), $result);
    }

    public function test_where_row_values()
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

    public function test_where_row_values_arity_mismatch()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The number of columns must match the number of values');

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('orders')->whereRowValues(['last_update'], '<', [1, 2]);
    }

    public function test_where_json_contains()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not support');
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereJsonContains('options', ['en'])->toSql();
    }

    public function test_where_json_doesnt_contain()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not support');
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereJsonDoesntContain('options->languages', ['en'])->toSql();
    }

    public function test_where_json_length()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not support');
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereJsonLength('options', 0)->toSql();
    }

    public function test_basic_select_not_using_quotes()
    {
        $builder = $this->getOracleBuilder(quoting: false);
        $builder->select('*')->from('users');
        $this->assertEquals('select * from users', $builder->toSql());

        $builder = $this->getOracleBuilder(quoting: false);
        $builder->select('id')->from('users');
        $this->assertEquals('select id from users', $builder->toSql());

        $builder = $this->getOracleBuilder(quoting: false);
        $builder->select('id')->from('users')->where('id', 1);
        $this->assertEquals('select id from users where id = ?', $builder->toSql());

        $builder = $this->getOracleBuilder(quoting: false);
        $builder->select('id')->from('users')->orderBy('id');
        $this->assertEquals('select id from users order by id asc', $builder->toSql());
    }

    public function test_from_sub()
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

    public function test_from_sub_with_prefix()
    {
        $builder = $this->getOracleBuilder(prefix: 'prefix_');
        $builder->fromSub(function ($query) {
            $query->select(new Raw('max(last_seen_at) as last_seen_at'))->from('user_sessions')->where('foo', '=', '1');
        }, 'sessions')->where('bar', '<', '10');
        $this->assertSame('select * from (select max(last_seen_at) as last_seen_at from "prefix_user_sessions" where "foo" = ?) as "prefix_sessions" where "bar" < ?', $builder->toSql());
        $this->assertEquals(['1', '10'], $builder->getBindings());
    }

    public function test_from_sub_without_bindings()
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

    public function test_from_raw()
    {
        $builder = $this->getOracleBuilder();
        $builder->fromRaw(new Raw('(select max(last_seen_at) as last_seen_at from "user_sessions") as "sessions"'));
        $this->assertSame('select * from (select max(last_seen_at) as last_seen_at from "user_sessions") as "sessions"', $builder->toSql());
    }

    public function test_from_raw_with_where_on_the_main_query()
    {
        $builder = $this->getOracleBuilder();
        $builder->fromRaw(new Raw('(select max(last_seen_at) as last_seen_at from "sessions") as "last_seen_at"'))->where('last_seen_at', '>', '1520652582');
        $this->assertSame('select * from (select max(last_seen_at) as last_seen_at from "sessions") as "last_seen_at" where "last_seen_at" > ?', $builder->toSql());
        $this->assertEquals(['1520652582'], $builder->getBindings());
    }

    public function test_group_by_not_using_quotes()
    {
        // add group by
        $builder = $this->getOracleBuilder(quoting: false);
        $builder->select(['category', new Raw('count(*) as "total"')])->from('item')->where('department', '=', 'popular')->groupBy('category')->having('total', '>', new Raw('3'));
        $this->assertEquals('select category, count(*) as "total" from item where department = ? group by category having total > 3', $builder->toSql());
    }

    public function test_join_not_using_quotes()
    {
        // add join
        $builder = $this->getOracleBuilder(quoting: false);
        $builder->select(['category', new Raw('count(*) as "total"')])->from('item')->where('department', '=', 'popular')->groupBy('category')->having('total', '>', new Raw('3'));
        $this->assertEquals('select category, count(*) as "total" from item where department = ? group by category having total > 3', $builder->toSql());
    }

    public function test_union_not_using_quotes()
    {
        $builder = $this->getOracleBuilder(quoting: false);
        $builder->select('*')->from('users')->where('id', '=', 1);
        $builder->union($this->getOracleBuilder(quoting: false)->select('*')->from('users')->where('id', '=', 2));
        $this->assertEquals('(select * from users where id = ?) union (select * from users where id = ?)', $builder->toSql());
        $this->assertEquals([0 => 1, 1 => 2], $builder->getBindings());
    }

    public function test_having_not_using_quotes()
    {
        $builder = $this->getOracleBuilder(quoting: false);
        $builder->select('*')->from('users')->havingRaw('user_foo < user_bar');
        $this->assertEquals('select * from users having user_foo < user_bar', $builder->toSql());

        $builder = $this->getOracleBuilder(quoting: false);
        $builder->select('*')->from('users')->having('baz', '=', 1)->orHavingRaw('user_foo < user_bar');
        $this->assertEquals('select * from users having baz = ? or user_foo < user_bar', $builder->toSql());
    }

    public function test_limits_and_offsets_not_using_quotes()
    {
        $builder = $this->getOracleBuilder(quoting: false);
        $builder->select('*')->from('users')->offset(10);
        $this->assertEquals('select * from (select * from users) where rownum >= 11', $builder->toSql());

        $builder = $this->getOracleBuilder(quoting: false);
        $builder->select('*')->from('users')->offset(5)->limit(10);
        $this->assertEquals('select t2.* from ( select rownum AS "rn", t1.* from (select * from users) t1 ) t2 where t2."rn" between 6 and 15', $builder->toSql());

        $builder = $this->getOracleBuilder(quoting: false);
        $builder->select('*')->from('users')->skip(5)->take(10);
        $this->assertEquals('select t2.* from ( select rownum AS "rn", t1.* from (select * from users) t1 ) t2 where t2."rn" between 6 and 15', $builder->toSql());

        $builder = $this->getOracleBuilder(quoting: false);
        $builder->select('*')->from('users')->forPage(2, 15);
        $this->assertEquals('select t2.* from ( select rownum AS "rn", t1.* from (select * from users) t1 ) t2 where t2."rn" between 16 and 30', $builder->toSql());
    }

    public function test_clone()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users');
        $clone = $builder->clone()->where('email', 'foo');

        $this->assertNotSame($builder, $clone);
        $this->assertSame('select * from "users"', $builder->toSql());
        $this->assertSame('select * from "users" where "email" = ?', $clone->toSql());
    }

    public function test_clone_without()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('email', 'foo')->orderBy('email');
        $clone = $builder->cloneWithout(['orders']);

        $this->assertSame('select * from "users" where "email" = ? order by "email" asc', $builder->toSql());
        $this->assertSame('select * from "users" where "email" = ?', $clone->toSql());
    }

    public function test_clone_without_bindings()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('email', 'foo')->orderBy('email');
        $clone = $builder->cloneWithout(['wheres'])->cloneWithoutBindings(['where']);

        $this->assertSame('select * from "users" where "email" = ? order by "email" asc', $builder->toSql());
        $this->assertEquals([0 => 'foo'], $builder->getBindings());

        $this->assertSame('select * from "users" order by "email" asc', $clone->toSql());
        $this->assertEquals([], $clone->getBindings());
    }

    protected function getConnection(
        ?string $prefix = '',
        ?bool $quoting = true
    ) {
        $connection = m::mock(OracleConnection::class);
        $connection->shouldReceive('getDatabaseName')->andReturn('database');
        $connection->shouldReceive('getTablePrefix')->andReturn($prefix);
        $connection->shouldReceive('getConfig')->with('quoting')->andReturn($quoting);

        return $connection;
    }

    protected function getOracleBuilder(
        ?string $prefix = '',
        ?bool $quoting = true
    ) {
        $connection = $this->getConnection(prefix: $prefix, quoting: $quoting);
        $grammar = new OracleGrammar($connection);
        $processor = m::mock(Processor::class);

        return new Builder($connection, $grammar, $processor);
    }

    protected function getOracleBuilderWithProcessor(
        ?string $prefix = '',
        ?bool $quoting = true
    ) {
        $connection = $this->getConnection(prefix: $prefix, quoting: $quoting);
        $grammar = new OracleGrammar($connection);
        $processor = new OracleProcessor;

        return new Builder($connection, $grammar, $processor);
    }

    protected function getGrammar(?OracleConnection $connection = null)
    {
        return new OracleGrammar($connection ?? $this->getConnection());
    }

    /**
     * @return \Mockery\MockInterface|\Illuminate\Database\Query\Builder
     */
    protected function getMockQueryBuilder()
    {
        return m::mock(Builder::class, [
            $connection = $this->getConnection(),
            new Grammar($connection),
            m::mock(Processor::class),
        ])->makePartial();
    }
}
