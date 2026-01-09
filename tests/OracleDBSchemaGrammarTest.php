<?php

namespace Jfelder\OracleDB\Tests;

use Illuminate\Database\Query\Expression as Raw;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ForeignIdColumnDefinition;
use Jfelder\OracleDB\OracleConnection;
use Jfelder\OracleDB\Schema\Grammars\OracleGrammar;
use Jfelder\OracleDB\Schema\OracleBuilder;
use LogicException;
use Mockery as m;
use PHPUnit\Framework\TestCase;

include 'mocks/PDOMocks.php';

class OracleDBSchemaGrammarTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
    }

    public function test_create_database()
    {
        $grammar = new OracleGrammar($this->getConnection());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('This database driver does not support creating databases.');

        $grammar->compileCreateDatabase('foo');
    }

    public function test_drop_database_if_exists()
    {
        $grammar = new OracleGrammar($this->getConnection());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('This database driver does not support dropping databases.');

        $grammar->compileDropDatabaseIfExists('foo');
    }

    public function test_basic_create_table()
    {
        $blueprint = new Blueprint($this->getConnection(), 'users');
        $blueprint->create();
        $blueprint->increments('id');
        $blueprint->string('email');
        $statements = $blueprint->toSql();

        $this->assertCount(1, $statements);
        $this->assertSame('create table users ( id number(10,0) not null, email varchar2(255) not null, constraint users_id_primary primary key ( id ) )', $statements[0]);
    }

    public function test_basic_create_table_with_primary()
    {
        $blueprint = new Blueprint($this->getConnection(), 'users');
        $blueprint->create();
        $blueprint->integer('id')->primary();
        $blueprint->string('email');

        $statements = $blueprint->toSql();

        $this->assertEquals(1, count($statements));
        $this->assertEquals('create table users ( id number(10,0) not null, email varchar2(255) not null, constraint users_id_primary primary key ( id ) )', $statements[0]);
    }

    public function test_basic_create_table_with_prefix()
    {
        $blueprint = new Blueprint($this->getConnection(prefix: 'prefix_'), 'users');
        $blueprint->create();
        $blueprint->increments('id');
        $blueprint->string('email');

        $statements = $blueprint->toSql();

        $this->assertEquals(1, count($statements));
        $this->assertEquals('create table prefix_users ( id number(10,0) not null, email varchar2(255) not null, constraint users_id_primary primary key ( id ) )', $statements[0]);
    }

    public function test_basic_create_table_with_default_value_and_is_not_null()
    {
        $blueprint = new Blueprint($this->getConnection(), 'users');
        $blueprint->create();
        $blueprint->integer('id')->primary();
        $blueprint->string('email')->default('user@test.com');

        $statements = $blueprint->toSql();

        $this->assertEquals(1, count($statements));
        $this->assertEquals('create table users ( id number(10,0) not null, email varchar2(255) default \'user@test.com\' not null, constraint users_id_primary primary key ( id ) )', $statements[0]);
    }

    public function test_basic_create_table_with_prefix_and_primary()
    {
        $blueprint = new Blueprint($this->getConnection(prefix: 'prefix_'), 'users');
        $blueprint->create();
        $blueprint->integer('id')->primary();
        $blueprint->string('email');
        $statements = $blueprint->toSql();

        $this->assertEquals(1, count($statements));
        $this->assertEquals('create table prefix_users ( id number(10,0) not null, email varchar2(255) not null, constraint users_id_primary primary key ( id ) )', $statements[0]);
    }

    public function test_basic_create_table_with_prefix_primary_and_foreign_keys()
    {
        $blueprint = new Blueprint($this->getConnection(prefix: 'prefix_'), 'users');
        $blueprint->create();
        $blueprint->integer('id')->primary();
        $blueprint->string('email');
        $blueprint->integer('foo_id');
        $blueprint->foreign('foo_id')->references('id')->on('orders');

        $statements = $blueprint->toSql();

        $this->assertEquals(1, count($statements));
        $this->assertEquals('create table prefix_users ( id number(10,0) not null, email varchar2(255) not null, foo_id number(10,0) not null, constraint users_foo_id_foreign foreign key ( foo_id ) references prefix_orders ( id ), constraint users_id_primary primary key ( id ) )', $statements[0]);
    }

    public function test_basic_create_table_with_prefix_primary_and_foreign_keys_with_cascade_delete()
    {
        $blueprint = new Blueprint($this->getConnection(prefix: 'prefix_'), 'users');
        $blueprint->create();
        $blueprint->integer('id')->primary();
        $blueprint->string('email');
        $blueprint->integer('foo_id');
        $blueprint->foreign('foo_id')->references('id')->on('orders')->onDelete('cascade');

        $statements = $blueprint->toSql();

        $this->assertEquals(1, count($statements));
        $this->assertEquals('create table prefix_users ( id number(10,0) not null, email varchar2(255) not null, foo_id number(10,0) not null, constraint users_foo_id_foreign foreign key ( foo_id ) references prefix_orders ( id ) on delete cascade, constraint users_id_primary primary key ( id ) )', $statements[0]);
    }

    public function test_basic_alter_table()
    {
        $blueprint = new Blueprint($this->getConnection(), 'users');
        $blueprint->increments('id');
        $blueprint->string('email');

        $statements = $blueprint->toSql();

        $this->assertCount(2, $statements);
        $this->assertSame([
            'alter table users add ( id number(10,0) not null, constraint users_id_primary primary key ( id ) )',
            'alter table users add ( email varchar2(255) not null )',
        ], $statements);
    }

    public function test_basic_alter_table_with_prefix()
    {
        $blueprint = new Blueprint($this->getConnection(prefix: 'prefix_'), 'users');
        $blueprint->increments('id');
        $blueprint->string('email');

        $statements = $blueprint->toSql();

        // todo fix OracleGrammar.php code to name the constraint prefix_users_id_primary

        $this->assertCount(2, $statements);
        $this->assertSame([
            'alter table prefix_users add ( id number(10,0) not null, constraint users_id_primary primary key ( id ) )',
            'alter table prefix_users add ( email varchar2(255) not null )',
        ], $statements);
    }

    public function test_drop_table()
    {
        $blueprint = new Blueprint($this->getConnection(), 'users');
        $blueprint->drop();
        $statements = $blueprint->toSql();

        $this->assertEquals(1, count($statements));
        $this->assertEquals('drop table users', $statements[0]);
    }

    public function test_drop_table_with_prefix()
    {
        $blueprint = new Blueprint($this->getConnection(), 'users');
        $blueprint->drop();
        $statements = $blueprint->toSql();

        $this->assertEquals(1, count($statements));
        $this->assertEquals('drop table users', $statements[0]);
    }

    public function test_drop_column()
    {
        $blueprint = new Blueprint($this->getConnection(), 'users');
        $blueprint->dropColumn('foo');
        $statements = $blueprint->toSql();

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users drop ( foo )', $statements[0]);

        $blueprint = new Blueprint($this->getConnection(), 'users');
        $blueprint->dropColumn(['foo', 'bar']);
        $statements = $blueprint->toSql();

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users drop ( foo, bar )', $statements[0]);

        $blueprint = new Blueprint($this->getConnection(), 'users');
        $blueprint->dropColumn('foo', 'bar');
        $statements = $blueprint->toSql();

        $this->assertCount(1, $statements);
        $this->assertSame('alter table users drop ( foo, bar )', $statements[0]);
    }

    public function test_drop_primary()
    {
        $blueprint = new Blueprint($this->getConnection(), 'users');
        $blueprint->dropPrimary('users_pk');
        $statements = $blueprint->toSql();

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users drop constraint users_pk', $statements[0]);
    }

    public function test_drop_unique()
    {
        $blueprint = new Blueprint($this->getConnection(), 'users');
        $blueprint->dropUnique('foo');
        $statements = $blueprint->toSql();

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users drop constraint foo', $statements[0]);
    }

    public function test_drop_index()
    {
        $blueprint = new Blueprint($this->getConnection(), 'users');
        $blueprint->dropIndex('foo');
        $statements = $blueprint->toSql();

        $this->assertEquals(1, count($statements));
        $this->assertEquals('drop index foo', $statements[0]);
    }

    public function test_drop_foreign()
    {
        $blueprint = new Blueprint($this->getConnection(), 'users');
        $blueprint->dropForeign('foo');
        $statements = $blueprint->toSql();

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users drop constraint foo', $statements[0]);
    }

    public function test_drop_timestamps()
    {
        $blueprint = new Blueprint($this->getConnection(), 'users');
        $blueprint->dropTimestamps();
        $statements = $blueprint->toSql();

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users drop ( created_at, updated_at )', $statements[0]);
    }

    public function test_drop_timestamps_tz()
    {
        $blueprint = new Blueprint($this->getConnection(), 'users');
        $blueprint->dropTimestampsTz();
        $statements = $blueprint->toSql();

        $this->assertCount(1, $statements);
        $this->assertSame('alter table users drop ( created_at, updated_at )', $statements[0]);
    }

    public function test_drop_morphs()
    {
        $blueprint = new Blueprint($this->getConnection(), 'photos');
        $blueprint->dropMorphs('imageable');
        $statements = $blueprint->toSql();

        $this->assertCount(2, $statements);
        $this->assertSame('drop index photos_imageable_type_imageable_id_index', $statements[0]);
        $this->assertSame('alter table photos drop ( imageable_type, imageable_id )', $statements[1]);
    }

    public function test_rename_table()
    {
        $blueprint = new Blueprint($this->getConnection(), 'users');
        $blueprint->rename('foo');
        $statements = $blueprint->toSql();

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users rename to foo', $statements[0]);
    }

    public function test_rename_table_with_prefix()
    {
        $blueprint = new Blueprint($this->getConnection(prefix: 'prefix_'), 'users');
        $blueprint->rename('foo');

        $statements = $blueprint->toSql();

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table prefix_users rename to prefix_foo', $statements[0]);
    }

    public function test_adding_primary_key()
    {
        $blueprint = new Blueprint($this->getConnection(), 'users');
        $blueprint->primary('foo', 'bar');
        $statements = $blueprint->toSql();

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users add constraint bar primary key (foo)', $statements[0]);
    }

    public function test_adding_unique_key()
    {
        $blueprint = new Blueprint($this->getConnection(), 'users');
        $blueprint->unique('foo', 'bar');
        $statements = $blueprint->toSql();

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users add constraint bar unique ( foo )', $statements[0]);
    }

    public function test_adding_index()
    {
        $blueprint = new Blueprint($this->getConnection(), 'users');
        $blueprint->index(['foo', 'bar'], 'baz');
        $statements = $blueprint->toSql();

        $this->assertEquals(1, count($statements));

        $this->assertEquals('create index baz on users ( foo, bar )', $statements[0]);
    }

    public function test_adding_raw_index()
    {
        $blueprint = new Blueprint($this->getConnection(), 'users');
        $blueprint->rawIndex('(function(column))', 'raw_index');
        $statements = $blueprint->toSql();

        $this->assertCount(1, $statements);
        $this->assertSame('create index raw_index on users ( (function(column)) )', $statements[0]);
    }

    public function test_adding_foreign_key()
    {
        $blueprint = new Blueprint($this->getConnection(), 'users');
        $blueprint->foreign('foo_id')->references('id')->on('orders');
        $statements = $blueprint->toSql();

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users add constraint users_foo_id_foreign foreign key ( foo_id ) references orders ( id )', $statements[0]);

        $blueprint = new Blueprint($this->getConnection(), 'users');
        $blueprint->foreign('foo_id')->references('id')->on('orders')->cascadeOnDelete();
        $statements = $blueprint->toSql();

        $this->assertCount(1, $statements);
        $this->assertSame('alter table users add constraint users_foo_id_foreign foreign key ( foo_id ) references orders ( id ) on delete cascade', $statements[0]);

        $blueprint = new Blueprint($this->getConnection(), 'users');
        $blueprint->foreign('foo_id')->references('id')->on('orders')->cascadeOnUpdate();
        $statements = $blueprint->toSql();

        $this->assertCount(1, $statements);
        $this->assertSame('alter table users add constraint users_foo_id_foreign foreign key ( foo_id ) references orders ( id ) on update cascade', $statements[0]);
    }

    public function test_adding_foreign_key_with_cascade_delete()
    {
        $blueprint = new Blueprint($this->getConnection(), 'users');
        $blueprint->foreign('foo_id')->references('id')->on('orders')->onDelete('cascade');
        $statements = $blueprint->toSql();

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users add constraint users_foo_id_foreign foreign key ( foo_id ) references orders ( id ) on delete cascade', $statements[0]);
    }

    public function test_adding_incrementing_id()
    {
        $blueprint = new Blueprint($this->getConnection(), 'users');
        $blueprint->increments('id');
        $statements = $blueprint->toSql();

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users add ( id number(10,0) not null, constraint users_id_primary primary key ( id ) )', $statements[0]);
    }

    public function test_adding_small_incrementing_id()
    {
        $blueprint = new Blueprint($this->getConnection(), 'users');
        $blueprint->smallIncrements('id');
        $statements = $blueprint->toSql();

        $this->assertCount(1, $statements);
        $this->assertSame('alter table users add ( id number(5,0) not null, constraint users_id_primary primary key ( id ) )', $statements[0]);
    }

    public function test_adding_id()
    {
        $blueprint = new Blueprint($this->getConnection(), 'users');
        $blueprint->id();
        $statements = $blueprint->toSql();

        $this->assertCount(1, $statements);
        $this->assertSame('alter table users add ( id number(19,0) not null, constraint users_id_primary primary key ( id ) )', $statements[0]);

        $blueprint = new Blueprint($this->getConnection(), 'users');
        $blueprint->id('foo');
        $statements = $blueprint->toSql();

        $this->assertCount(1, $statements);
        $this->assertSame('alter table users add ( foo number(19,0) not null, constraint users_foo_primary primary key ( foo ) )', $statements[0]);
    }

    public function test_adding_foreign_id()
    {
        $blueprint = new Blueprint($this->getConnection(), 'users');
        $foreignId = $blueprint->foreignId('foo');
        $blueprint->foreignId('company_id')->constrained();
        $blueprint->foreignId('laravel_idea_id')->constrained();
        $blueprint->foreignId('team_id')->references('id')->on('teams');
        $blueprint->foreignId('team_column_id')->constrained('teams');

        $statements = $blueprint->toSql();

        $this->assertInstanceOf(ForeignIdColumnDefinition::class, $foreignId);
        $this->assertCount(9, $statements);
        $this->assertSame([
            'alter table users add ( foo number(19,0) not null )',
            'alter table users add ( company_id number(19,0) not null )',
            'alter table users add constraint users_company_id_foreign foreign key ( company_id ) references companies ( id )',
            'alter table users add ( laravel_idea_id number(19,0) not null )',
            'alter table users add constraint users_laravel_idea_id_foreign foreign key ( laravel_idea_id ) references laravel_ideas ( id )',
            'alter table users add ( team_id number(19,0) not null )',
            'alter table users add constraint users_team_id_foreign foreign key ( team_id ) references teams ( id )',
            'alter table users add ( team_column_id number(19,0) not null )',
            'alter table users add constraint users_team_column_id_foreign foreign key ( team_column_id ) references teams ( id )',
        ], $statements);
    }

    public function test_adding_big_incrementing_id()
    {
        $blueprint = new Blueprint($this->getConnection(), 'users');
        $blueprint->bigIncrements('id');
        $statements = $blueprint->toSql();

        $this->assertCount(1, $statements);
        $this->assertSame('alter table users add ( id number(19,0) not null, constraint users_id_primary primary key ( id ) )', $statements[0]);
    }

    public function test_adding_string()
    {
        $blueprint = new Blueprint($this->getConnection(), 'users');
        $blueprint->string('foo');
        $statements = $blueprint->toSql();

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users add ( foo varchar2(255) not null )', $statements[0]);

        $blueprint = new Blueprint($this->getConnection(), 'users');
        $blueprint->string('foo', 100);
        $statements = $blueprint->toSql();

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users add ( foo varchar2(100) not null )', $statements[0]);

        $blueprint = new Blueprint($this->getConnection(), 'users');
        $blueprint->string('foo', 100)->nullable()->default('bar');
        $statements = $blueprint->toSql();

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users add ( foo varchar2(100) default \'bar\' null )', $statements[0]);

        $blueprint = new Blueprint($this->getConnection(), 'users');
        $blueprint->string('foo', 100)->nullable()->default(new Raw('CURRENT TIMESTAMP'));
        $statements = $blueprint->toSql();

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users add ( foo varchar2(100) default CURRENT TIMESTAMP null )', $statements[0]);
    }

    public function test_adding_long_text()
    {
        $blueprint = new Blueprint($this->getConnection(), 'users');
        $blueprint->longText('foo');
        $statements = $blueprint->toSql();

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users add ( foo clob not null )', $statements[0]);
    }

    public function test_adding_medium_text()
    {
        $blueprint = new Blueprint($this->getConnection(), 'users');
        $blueprint->mediumText('foo');
        $statements = $blueprint->toSql();

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users add ( foo clob not null )', $statements[0]);
    }

    public function test_adding_text()
    {
        $blueprint = new Blueprint($this->getConnection(), 'users');
        $blueprint->text('foo');
        $statements = $blueprint->toSql();

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users add ( foo varchar2(4000) not null )', $statements[0]);
    }

    public function test_adding_big_integer()
    {
        $blueprint = new Blueprint($this->getConnection(), 'users');
        $blueprint->bigInteger('foo');
        $statements = $blueprint->toSql();

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users add ( foo number(19,0) not null )', $statements[0]);

        $blueprint = new Blueprint($this->getConnection(), 'users');
        $blueprint->bigInteger('foo', true);
        $statements = $blueprint->toSql();

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users add ( foo number(19,0) not null, constraint users_foo_primary primary key ( foo ) )', $statements[0]);
    }

    public function test_adding_integer()
    {
        $blueprint = new Blueprint($this->getConnection(), 'users');
        $blueprint->integer('foo');
        $statements = $blueprint->toSql();

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users add ( foo number(10,0) not null )', $statements[0]);

        $blueprint = new Blueprint($this->getConnection(), 'users');
        $blueprint->integer('foo', true);
        $statements = $blueprint->toSql();

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users add ( foo number(10,0) not null, constraint users_foo_primary primary key ( foo ) )', $statements[0]);
    }

    public function test_adding_increments_with_starting_values()
    {
        // calling ->startingValue() should have no effect on the generated sql because it hasn't been implemented

        $blueprint = new Blueprint($this->getConnection(), 'users');
        $blueprint->id()->startingValue(1000);
        $statements = $blueprint->toSql();

        $this->assertCount(1, $statements);
        $this->assertSame('alter table users add ( id number(19,0) not null, constraint users_id_primary primary key ( id ) )', $statements[0]);
    }

    public function test_adding_medium_integer()
    {
        $blueprint = new Blueprint($this->getConnection(), 'users');
        $blueprint->mediumInteger('foo');
        $statements = $blueprint->toSql();

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users add ( foo number(7,0) not null )', $statements[0]);
    }

    public function test_adding_small_integer()
    {
        $blueprint = new Blueprint($this->getConnection(), 'users');
        $blueprint->smallInteger('foo');
        $statements = $blueprint->toSql();

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users add ( foo number(5,0) not null )', $statements[0]);
    }

    public function test_adding_tiny_integer()
    {
        $blueprint = new Blueprint($this->getConnection(), 'users');
        $blueprint->tinyInteger('foo');
        $statements = $blueprint->toSql();

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users add ( foo number(3,0) not null )', $statements[0]);
    }

    public function test_adding_float()
    {
        $blueprint = new Blueprint($this->getConnection(), 'users');
        $blueprint->float('foo', 5);
        $statements = $blueprint->toSql();

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users add ( foo float(5) not null )', $statements[0]);
    }

    public function test_adding_double()
    {
        $blueprint = new Blueprint($this->getConnection(), 'users');
        $blueprint->double('foo');
        $statements = $blueprint->toSql();

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users add ( foo double precision not null )', $statements[0]);
    }

    public function test_adding_decimal()
    {
        $blueprint = new Blueprint($this->getConnection(), 'users');
        $blueprint->decimal('foo', 5, 2);
        $statements = $blueprint->toSql();

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users add ( foo number(5, 2) not null )', $statements[0]);
    }

    public function test_adding_boolean()
    {
        $blueprint = new Blueprint($this->getConnection(), 'users');
        $blueprint->boolean('foo');
        $statements = $blueprint->toSql();

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users add ( foo char(1) not null )', $statements[0]);
    }

    public function test_adding_enum()
    {
        $blueprint = new Blueprint($this->getConnection(), 'users');
        $blueprint->enum('foo', ['bar', 'baz']);
        $statements = $blueprint->toSql();

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users add ( foo varchar2(255) check(foo in (\'bar\', \'baz\')) not null )', $statements[0]);
    }

    public function test_adding_date()
    {
        $blueprint = new Blueprint($this->getConnection(), 'users');
        $blueprint->date('foo');
        $statements = $blueprint->toSql();

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users add ( foo date not null )', $statements[0]);
    }

    public function test_adding_date_time()
    {
        $blueprint = new Blueprint($this->getConnection(), 'users');
        $blueprint->dateTime('foo');
        $statements = $blueprint->toSql();

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users add ( foo date not null )', $statements[0]);
    }

    public function test_adding_time()
    {
        $blueprint = new Blueprint($this->getConnection(), 'users');
        $blueprint->time('foo');
        $statements = $blueprint->toSql();

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users add ( foo date not null )', $statements[0]);
    }

    public function test_adding_time_stamp()
    {
        $blueprint = new Blueprint($this->getConnection(), 'users');
        $blueprint->timestamp('foo');
        $statements = $blueprint->toSql();

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users add ( foo timestamp not null )', $statements[0]);
    }

    public function test_adding_timestamp_with_default()
    {
        $blueprint = new Blueprint($this->getConnection(), 'users');
        $blueprint->timestamp('created_at')->default(new Raw('CURRENT_TIMESTAMP'));
        $statements = $blueprint->toSql();
        $this->assertCount(1, $statements);
        $this->assertSame('alter table users add ( created_at timestamp default CURRENT_TIMESTAMP not null )', $statements[0]);
    }

    public function test_adding_time_stamps()
    {
        $blueprint = new Blueprint($this->getConnection(), 'users');
        $blueprint->timestamps();
        $statements = $blueprint->toSql();

        $this->assertEquals(2, count($statements));
        $this->assertSame([
            'alter table users add ( created_at timestamp null )',
            'alter table users add ( updated_at timestamp null )',
        ], $statements);
    }

    public function test_adding_binary()
    {
        $blueprint = new Blueprint($this->getConnection(), 'users');
        $blueprint->binary('foo');
        $statements = $blueprint->toSql();

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users add ( foo blob not null )', $statements[0]);
    }

    public function test_adding_comment()
    {
        // calling ->comment() on a column should have no effect on the generated sql because it hasn't been implemented

        $blueprint = new Blueprint($this->getConnection(), 'users');
        $blueprint->string('foo')->comment("Escape ' when using words like it's");
        $statements = $blueprint->toSql();

        $this->assertCount(1, $statements);
        $this->assertSame('alter table users add ( foo varchar2(255) not null )', $statements[0]);
    }

    public function test_basic_select_using_quotes()
    {
        $blueprint = new Blueprint($this->getConnection(quoting: true), 'users');
        $blueprint->create();
        $blueprint->increments('id');
        $blueprint->string('email');

        $statements = $blueprint->toSql();

        $this->assertEquals(1, count($statements));
        $this->assertEquals('create table "users" ( "id" number(10,0) not null, "email" varchar2(255) not null, constraint users_id_primary primary key ( "id" ) )', $statements[0]);
    }

    public function test_basic_select_not_using_quotes()
    {
        $blueprint = new Blueprint($this->getConnection(), 'users');
        $blueprint->create();
        $blueprint->increments('id');
        $blueprint->string('email');

        $statements = $blueprint->toSql();

        $this->assertEquals(1, count($statements));
        $this->assertEquals('create table users ( id number(10,0) not null, email varchar2(255) not null, constraint users_id_primary primary key ( id ) )', $statements[0]);
    }

    public function test_grammars_are_macroable()
    {
        // compileReplace macro.
        $this->getGrammar()::macro('compileReplace', function () {
            return true;
        });

        $c = $this->getGrammar()::compileReplace();

        $this->assertTrue($c);
    }

    protected function getConnection(
        ?OracleGrammar $grammar = null,
        ?OracleBuilder $builder = null,
        string $prefix = '',
        bool $quoting = false
    ) {
        $connection = m::mock(OracleConnection::class)
            ->shouldReceive('getTablePrefix')->andReturn($prefix)
            ->shouldReceive('getConfig')->with('prefix_indexes')->andReturn(null)
            ->shouldReceive('getConfig')->with('quoting')->andReturn($quoting)
            ->getMock();

        $grammar ??= $this->getGrammar($connection);
        $builder ??= $this->getBuilder();

        return $connection
            ->shouldReceive('getSchemaGrammar')->andReturn($grammar)
            ->shouldReceive('getSchemaBuilder')->andReturn($builder)
            ->getMock();
    }

    public function getGrammar(?OracleConnection $connection = null)
    {
        return new OracleGrammar($connection ?? $this->getConnection());
    }

    public function getBuilder()
    {
        return mock(OracleBuilder::class);
    }
}
