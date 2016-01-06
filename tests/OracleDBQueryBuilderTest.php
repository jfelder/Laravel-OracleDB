<?php

use Mockery as m;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression as Raw;

include 'mocks/PDOMocks.php';

class OracleDBQueryBuilderTest extends PHPUnit_Framework_TestCase
{
    public function tearDown()
    {
        m::close();
    }

    public function testBasicSelect()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users');
        $this->assertEquals('select * from "users"', $builder->toSql());
    }

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

    public function testBasicWheres()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('id', '=', 1);
        $this->assertEquals('select * from "users" where "id" = ?', $builder->toSql());
        $this->assertEquals([0 => 1], $builder->getBindings());
    }

    public function testWhereDayOracle()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereDay('created_at', '=', 1);
        $this->assertEquals('select * from "users" where TO_CHAR("created_at", \'DD\') = ?', $builder->toSql());
        $this->assertEquals([0 => 1], $builder->getBindings());
    }

    public function testWhereMonthMySql()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereMonth('created_at', '=', 5);
        $this->assertEquals('select * from "users" where TO_CHAR("created_at", \'MM\') = ?', $builder->toSql());
        $this->assertEquals([0 => 5], $builder->getBindings());
    }

    public function testWhereYearMySql()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->whereYear('created_at', '=', 2014);
        $this->assertEquals('select * from "users" where TO_CHAR("created_at", \'YYYY\') = ?', $builder->toSql());
        $this->assertEquals([0 => 2014], $builder->getBindings());
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

    public function testUnions()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('id', '=', 1);
        $builder->union($this->getOracleBuilder()->select('*')->from('users')->where('id', '=', 2));
        $this->assertEquals('select * from "users" where "id" = ? union select * from "users" where "id" = ?', $builder->toSql());
        $this->assertEquals([0 => 1, 1 => 2], $builder->getBindings());

        $builder = $this->getOracleBuilder();
        $expectedSql = 'select t2.* from ( select rownum AS "rn", t1.* from (select "a" from "t3" where "a" = ? and "b" = ? union select "a" from "t4" where "a" = ? and "b" = ? order by "a" asc) t1 ) t2 where t2."rn" between 1 and 10';
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
        $this->assertEquals('select * from "users" where "id" = ? union all select * from "users" where "id" = ?', $builder->toSql());
        $this->assertEquals([0 => 1, 1 => 2], $builder->getBindings());
    }

    public function testMultipleUnions()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('id', '=', 1);
        $builder->union($this->getOracleBuilder()->select('*')->from('users')->where('id', '=', 2));
        $builder->union($this->getOracleBuilder()->select('*')->from('users')->where('id', '=', 3));
        $this->assertEquals('select * from "users" where "id" = ? union select * from "users" where "id" = ? union select * from "users" where "id" = ?', $builder->toSql());
        $this->assertEquals([0 => 1, 1 => 2, 2 => 3], $builder->getBindings());
    }

    public function testMultipleUnionAlls()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('id', '=', 1);
        $builder->unionAll($this->getOracleBuilder()->select('*')->from('users')->where('id', '=', 2));
        $builder->unionAll($this->getOracleBuilder()->select('*')->from('users')->where('id', '=', 3));
        $this->assertEquals('select * from "users" where "id" = ? union all select * from "users" where "id" = ? union all select * from "users" where "id" = ?', $builder->toSql());
        $this->assertEquals([0 => 1, 1 => 2, 2 => 3], $builder->getBindings());
    }

    public function testOracleUnionOrderBys()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('id', '=', 1);
        $builder->union($this->getOracleBuilder()->select('*')->from('users')->where('id', '=', 2));
        $builder->orderBy('id', 'desc');
        $this->assertEquals('select * from "users" where "id" = ? union select * from "users" where "id" = ? order by "id" desc', $builder->toSql());
        $this->assertEquals([0 => 1, 1 => 2], $builder->getBindings());
    }

    public function testOracleUnionLimitsAndOffsets()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users');
        $builder->union($this->getOracleBuilder()->select('*')->from('dogs'));
        $builder->skip(5)->take(10);
        $this->assertEquals('select t2.* from ( select rownum AS "rn", t1.* from (select * from "users" union select * from "dogs") t1 ) t2 where t2."rn" between 6 and 15', $builder->toSql());
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

    public function testGroupBys()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->groupBy('id', 'email');
        $this->assertEquals('select * from "users" group by "id", "email"', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->groupBy(['id', 'email']);
        $this->assertEquals('select * from "users" group by "id", "email"', $builder->toSql());
    }

    public function testOrderBys()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->orderBy('email')->orderBy('age', 'desc');
        $this->assertEquals('select * from "users" order by "email" asc, "age" desc', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->orderBy('email')->orderByRaw('age ? desc', ['foo']);
        $this->assertEquals('select * from "users" order by "email" asc, age ? desc', $builder->toSql());
        $this->assertEquals(['foo'], $builder->getBindings());
    }

    public function testHavings()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->having('email', '>', 1);
        $this->assertEquals('select * from "users" having "email" > ?', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')
            ->orHaving('email', '=', 'test@example.com')
            ->orHaving('email', '=', 'test2@example.com');
        $this->assertEquals('select * from "users" having "email" = ? or "email" = ?', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->groupBy('email')->having('email', '>', 1);
        $this->assertEquals('select * from "users" group by "email" having "email" > ?', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('email as foo_email')->from('users')->having('foo_email', '>', 1);
        $this->assertEquals('select "email" as "foo_email" from "users" having "foo_email" > ?', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select(['category', new Raw('count(*) as "total"')])->from('item')->where('department', '=', 'popular')->groupBy('category')->having('total', '>', new Raw('3'));
        $this->assertEquals('select "category", count(*) as "total" from "item" where "department" = ? group by "category" having "total" > 3', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select(['category', new Raw('count(*) as "total"')])->from('item')->where('department', '=', 'popular')->groupBy('category')->having('total', '>', 3);
        $this->assertEquals('select "category", count(*) as "total" from "item" where "department" = ? group by "category" having "total" > ?', $builder->toSql());
    }

    public function testHavingFollowedBySelectGet()
    {
        $builder = $this->getOracleBuilder();
        $query = 'select "category", count(*) as "total" from "item" where "department" = ? group by "category" having "total" > ?';
        $builder->getConnection()->shouldReceive('select')->once()->with($query, ['popular', 3], true)->andReturn([['category' => 'rock', 'total' => 5]]);
        $builder->getProcessor()->shouldReceive('processSelect')->andReturnUsing(function ($builder, $results) { return $results; });
        $builder->from('item');
        $result = $builder->select(['category', new Raw('count(*) as "total"')])->where('department', '=', 'popular')->groupBy('category')->having('total', '>', 3)->get();
        $this->assertEquals([['category' => 'rock', 'total' => 5]], $result);

        // Using \Raw value
        $builder = $this->getOracleBuilder();
        $query = 'select "category", count(*) as "total" from "item" where "department" = ? group by "category" having "total" > 3';
        $builder->getConnection()->shouldReceive('select')->once()->with($query, ['popular'], true)->andReturn([['category' => 'rock', 'total' => 5]]);
        $builder->getProcessor()->shouldReceive('processSelect')->andReturnUsing(function ($builder, $results) { return $results; });
        $builder->from('item');
        $result = $builder->select(['category', new Raw('count(*) as "total"')])->where('department', '=', 'popular')->groupBy('category')->having('total', '>', new Raw('3'))->get();
        $this->assertEquals([['category' => 'rock', 'total' => 5]], $result);
    }

    public function testRawHavings()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->havingRaw('user_foo < user_bar');
        $this->assertEquals('select * from "users" having user_foo < user_bar', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->having('baz', '=', 1)->orHavingRaw('user_foo < user_bar');
        $this->assertEquals('select * from "users" having "baz" = ? or user_foo < user_bar', $builder->toSql());
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
        $builder->select('*')->from('users')->skip(-5)->take(10);
        $this->assertEquals('select t2.* from ( select rownum AS "rn", t1.* from (select * from "users") t1 ) t2 where t2."rn" between 1 and 10', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->forPage(2, 15);
        $this->assertEquals('select t2.* from ( select rownum AS "rn", t1.* from (select * from "users") t1 ) t2 where t2."rn" between 16 and 30', $builder->toSql());

        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->forPage(-2, 15);
        $this->assertEquals('select t2.* from ( select rownum AS "rn", t1.* from (select * from "users") t1 ) t2 where t2."rn" between 1 and 15', $builder->toSql());
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

    public function testWhereShortcut()
    {
        $builder = $this->getOracleBuilder();
        $builder->select('*')->from('users')->where('id', 1)->orWhere('name', 'foo');
        $this->assertEquals('select * from "users" where "id" = ? or "name" = ?', $builder->toSql());
        $this->assertEquals([0 => 1, 1 => 'foo'], $builder->getBindings());
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

    public function testListMethodsGetsArrayOfColumnValues()
    {
        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('select')->once()->andReturn([['foo' => 'bar'], ['foo' => 'baz']]);
        $builder->getProcessor()->shouldReceive('processSelect')->once()->with($builder, [['foo' => 'bar'], ['foo' => 'baz']])->andReturnUsing(function ($query, $results) {
            return $results;
        });
        $results = $builder->from('users')->where('id', '=', 1)->lists('foo');
        $this->assertEquals(['bar', 'baz'], $results);

        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('select')->once()->andReturn([['id' => 1, 'foo' => 'bar'], ['id' => 10, 'foo' => 'baz']]);
        $builder->getProcessor()->shouldReceive('processSelect')->once()->with($builder, [['id' => 1, 'foo' => 'bar'], ['id' => 10, 'foo' => 'baz']])->andReturnUsing(function ($query, $results) {
            return $results;
        });
        $results = $builder->from('users')->where('id', '=', 1)->lists('foo', 'id');
        $this->assertEquals([1 => 'bar', 10 => 'baz'], $results);
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
        $this->assertEquals([['column1' => 'foo', 'column2' => 'bar']], $result);
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
        $this->assertEquals([['column2' => 'foo', 'column3' => 'bar']], $result);
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
        $this->assertEquals([['column2' => 'foo', 'column3' => 'bar']], $result);
    }

    public function testAggregateWithSubSelect()
    {
        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('select')->once()->with('select count(*) as aggregate from "users"', [], true)->andReturn([['aggregate' => 1]]);
        $builder->getProcessor()->shouldReceive('processSelect')->once()->andReturnUsing(function ($builder, $results) { return $results; });
        $builder->from('users')->selectSub(function ($query) { $query->from('posts')->select('foo')->where('title', 'foo'); }, 'post');
        $count = $builder->count();
        $this->assertEquals(1, $count);
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
    }

    public function testUpdateMethodRespectsRaw()
    {
        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('update')->once()->with('update "users" set "email" = foo, "name" = ? where "id" = ?', ['bar', 1])->andReturn(1);
        $result = $builder->from('users')->where('id', '=', 1)->update(['email' => new Raw('foo'), 'name' => 'bar']);
        $this->assertEquals(1, $result);
    }

    public function testDeleteMethod()
    {
        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('delete')->once()->with('delete from "users" where "email" = ?', ['foo'])->andReturn(1);
        $result = $builder->from('users')->where('email', '=', 'foo')->delete();
        $this->assertEquals(1, $result);

        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('delete')->once()->with('delete from "users" where "id" = ?', [1])->andReturn(1);
        $result = $builder->from('users')->delete(1);
        $this->assertEquals(1, $result);
    }

    public function testTruncateMethod()
    {
        $builder = $this->getOracleBuilder();
        $builder->getConnection()->shouldReceive('statement')->once()->with('truncate "users"', []);
        $builder->from('users')->truncate();
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
        $this->assertEquals('select * from "users" where "foo" is null', $builder->toSql());
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

    /**
     * @expectedException BadMethodCallException
     */
    public function testBuilderThrowsExpectedExceptionWithUndefinedMethod()
    {
        $builder = $this->getOracleBuilder();

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
        $this->assertEquals('select * from users where id = ? union select * from users where id = ?', $builder->toSql());
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
}
