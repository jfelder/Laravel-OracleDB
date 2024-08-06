<?php

namespace Jfelder\OracleDB\Tests;

use Illuminate\Database\Query\Expression as Raw;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ForeignIdColumnDefinition;
use Jfelder\OracleDB\OracleConnection;
use Jfelder\OracleDB\Schema\Grammars\OracleGrammar;
use LogicException;
use Mockery as m;
use PHPUnit\Framework\TestCase;

include 'mocks/PDOMocks.php';

class OracleDBSchemaGrammarTest extends TestCase
{
    public function tearDown(): void
    {
        m::close();
    }

    public function testCreateDatabase()
    {
        $grammar = new class extends OracleGrammar {};

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('This database driver does not support creating databases.');

        $grammar->compileCreateDatabase('foo', m::mock(OracleConnection::class));
    }

    public function testDropDatabaseIfExists()
    {
        $grammar = new class extends OracleGrammar {};

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('This database driver does not support dropping databases.');

        $grammar->compileDropDatabaseIfExists('foo');
    }

    public function testBasicCreateTable()
    {
        $blueprint = new Blueprint('users');
        $blueprint->create();
        $blueprint->increments('id');
        $blueprint->string('email');

        $conn = $this->getConnection();
        $conn->shouldNotReceive('getConfig');

        $statements = $blueprint->toSql($conn, $this->getGrammar());

        $this->assertCount(1, $statements);
        $this->assertSame('create table users ( id number(10,0) not null, email varchar2(255) not null, constraint users_id_primary primary key ( id ) )', $statements[0]);
    }

    public function testBasicCreateTableWithPrimary()
    {
        $blueprint = new Blueprint('users');
        $blueprint->create();
        $blueprint->integer('id')->primary();
        $blueprint->string('email');

        $conn = $this->getConnection();

        $statements = $blueprint->toSql($conn, $this->getGrammar());

        $this->assertEquals(1, count($statements));
        $this->assertEquals('create table users ( id number(10,0) not null, email varchar2(255) not null, constraint users_id_primary primary key ( id ) )', $statements[0]);
    }

    public function testBasicCreateTableWithPrefix()
    {
        $blueprint = new Blueprint('users');
        $blueprint->create();
        $blueprint->increments('id');
        $blueprint->string('email');
        $grammar = $this->getGrammar();
        $grammar->setTablePrefix('prefix_');

        $conn = $this->getConnection();

        $statements = $blueprint->toSql($conn, $grammar);

        $this->assertEquals(1, count($statements));
        $this->assertEquals('create table prefix_users ( id number(10,0) not null, email varchar2(255) not null, constraint users_id_primary primary key ( id ) )', $statements[0]);
    }

    public function testBasicCreateTableWithDefaultValueAndIsNotNull()
    {
        $blueprint = new Blueprint('users');
        $blueprint->create();
        $blueprint->integer('id')->primary();
        $blueprint->string('email')->default('user@test.com');

        $conn = $this->getConnection();

        $statements = $blueprint->toSql($conn, $this->getGrammar());

        $this->assertEquals(1, count($statements));
        $this->assertEquals('create table users ( id number(10,0) not null, email varchar2(255) default \'user@test.com\' not null, constraint users_id_primary primary key ( id ) )', $statements[0]);
    }

    public function testBasicCreateTableWithPrefixAndPrimary()
    {
        $blueprint = new Blueprint('users');
        $blueprint->create();
        $blueprint->integer('id')->primary();
        $blueprint->string('email');
        $grammar = $this->getGrammar();
        $grammar->setTablePrefix('prefix_');

        $conn = $this->getConnection();

        $statements = $blueprint->toSql($conn, $grammar);

        $this->assertEquals(1, count($statements));
        $this->assertEquals('create table prefix_users ( id number(10,0) not null, email varchar2(255) not null, constraint users_id_primary primary key ( id ) )', $statements[0]);
    }

    public function testBasicCreateTableWithPrefixPrimaryAndForeignKeys()
    {
        $blueprint = new Blueprint('users');
        $blueprint->create();
        $blueprint->integer('id')->primary();
        $blueprint->string('email');
        $blueprint->integer('foo_id');
        $blueprint->foreign('foo_id')->references('id')->on('orders');
        $grammar = $this->getGrammar();
        $grammar->setTablePrefix('prefix_');

        $conn = $this->getConnection();

        $statements = $blueprint->toSql($conn, $grammar);

        $this->assertEquals(1, count($statements));
        $this->assertEquals('create table prefix_users ( id number(10,0) not null, email varchar2(255) not null, foo_id number(10,0) not null, constraint users_foo_id_foreign foreign key ( foo_id ) references prefix_orders ( id ), constraint users_id_primary primary key ( id ) )', $statements[0]);
    }

    public function testBasicCreateTableWithPrefixPrimaryAndForeignKeysWithCascadeDelete()
    {
        $blueprint = new Blueprint('users');
        $blueprint->create();
        $blueprint->integer('id')->primary();
        $blueprint->string('email');
        $blueprint->integer('foo_id');
        $blueprint->foreign('foo_id')->references('id')->on('orders')->onDelete('cascade');
        $grammar = $this->getGrammar();
        $grammar->setTablePrefix('prefix_');

        $conn = $this->getConnection();

        $statements = $blueprint->toSql($conn, $grammar);

        $this->assertEquals(1, count($statements));
        $this->assertEquals('create table prefix_users ( id number(10,0) not null, email varchar2(255) not null, foo_id number(10,0) not null, constraint users_foo_id_foreign foreign key ( foo_id ) references prefix_orders ( id ) on delete cascade, constraint users_id_primary primary key ( id ) )', $statements[0]);
    }

    public function testBasicAlterTable()
    {
        $blueprint = new Blueprint('users');
        $blueprint->increments('id');
        $blueprint->string('email');

        $conn = $this->getConnection();
        $conn->shouldNotReceive('getConfig');

        $statements = $blueprint->toSql($conn, $this->getGrammar());

        $this->assertCount(2, $statements);
        $this->assertSame([
            'alter table users add ( id number(10,0) not null, constraint users_id_primary primary key ( id ) )',
            'alter table users add ( email varchar2(255) not null )',
        ], $statements);
    }

    public function testBasicAlterTableWithPrefix()
    {
        $blueprint = new Blueprint('users');
        $blueprint->increments('id');
        $blueprint->string('email');
        $grammar = $this->getGrammar();
        $grammar->setTablePrefix('prefix_');

        $conn = $this->getConnection();

        $statements = $blueprint->toSql($conn, $grammar);

        // todo fix OracleGrammar.php code to name the constraint prefix_users_id_primary

        $this->assertCount(2, $statements);
        $this->assertSame([
            'alter table prefix_users add ( id number(10,0) not null, constraint users_id_primary primary key ( id ) )',
            'alter table prefix_users add ( email varchar2(255) not null )',
        ], $statements);
    }

    public function testDropTable()
    {
        $blueprint = new Blueprint('users');
        $blueprint->drop();
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertEquals(1, count($statements));
        $this->assertEquals('drop table users', $statements[0]);
    }

    public function testDropTableWithPrefix()
    {
        $blueprint = new Blueprint('users');
        $blueprint->drop();
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertEquals(1, count($statements));
        $this->assertEquals('drop table users', $statements[0]);
    }

    public function testDropColumn()
    {
        $blueprint = new Blueprint('users');
        $blueprint->dropColumn('foo');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users drop ( foo )', $statements[0]);

        $blueprint = new Blueprint('users');
        $blueprint->dropColumn(['foo', 'bar']);
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users drop ( foo, bar )', $statements[0]);

        $blueprint = new Blueprint('users');
        $blueprint->dropColumn('foo', 'bar');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertCount(1, $statements);
        $this->assertSame('alter table users drop ( foo, bar )', $statements[0]);
    }

    public function testDropPrimary()
    {
        $blueprint = new Blueprint('users');
        $blueprint->dropPrimary('users_pk');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users drop constraint users_pk', $statements[0]);
    }

    public function testDropUnique()
    {
        $blueprint = new Blueprint('users');
        $blueprint->dropUnique('foo');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users drop constraint foo', $statements[0]);
    }

    public function testDropIndex()
    {
        $blueprint = new Blueprint('users');
        $blueprint->dropIndex('foo');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertEquals(1, count($statements));
        $this->assertEquals('drop index foo', $statements[0]);
    }

    public function testDropForeign()
    {
        $blueprint = new Blueprint('users');
        $blueprint->dropForeign('foo');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users drop constraint foo', $statements[0]);
    }

    public function testDropTimestamps()
    {
        $blueprint = new Blueprint('users');
        $blueprint->dropTimestamps();
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users drop ( created_at, updated_at )', $statements[0]);
    }

    public function testDropTimestampsTz()
    {
        $blueprint = new Blueprint('users');
        $blueprint->dropTimestampsTz();
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertCount(1, $statements);
        $this->assertSame('alter table users drop ( created_at, updated_at )', $statements[0]);
    }

    public function testDropMorphs()
    {
        $blueprint = new Blueprint('photos');
        $blueprint->dropMorphs('imageable');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertCount(2, $statements);
        $this->assertSame('drop index photos_imageable_type_imageable_id_index', $statements[0]);
        $this->assertSame('alter table photos drop ( imageable_type, imageable_id )', $statements[1]);
    }

    public function testRenameTable()
    {
        $blueprint = new Blueprint('users');
        $blueprint->rename('foo');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users rename to foo', $statements[0]);
    }

    public function testRenameTableWithPrefix()
    {
        $blueprint = new Blueprint('users');
        $blueprint->rename('foo');
        $grammar = $this->getGrammar();
        $grammar->setTablePrefix('prefix_');

        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users rename to foo', $statements[0]);
    }

    public function testAddingPrimaryKey()
    {
        $blueprint = new Blueprint('users');
        $blueprint->primary('foo', 'bar');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users add constraint bar primary key (foo)', $statements[0]);
    }

    public function testAddingUniqueKey()
    {
        $blueprint = new Blueprint('users');
        $blueprint->unique('foo', 'bar');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users add constraint bar unique ( foo )', $statements[0]);
    }

    public function testAddingIndex()
    {
        $blueprint = new Blueprint('users');
        $blueprint->index(['foo', 'bar'], 'baz');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertEquals(1, count($statements));

        $this->assertEquals('create index baz on users ( foo, bar )', $statements[0]);
    }

    public function testAddingRawIndex()
    {
        $blueprint = new Blueprint('users');
        $blueprint->rawIndex('(function(column))', 'raw_index');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertCount(1, $statements);
        $this->assertSame('create index raw_index on users ( (function(column)) )', $statements[0]);
    }

    public function testAddingForeignKey()
    {
        $blueprint = new Blueprint('users');
        $blueprint->foreign('foo_id')->references('id')->on('orders');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users add constraint users_foo_id_foreign foreign key ( foo_id ) references orders ( id )', $statements[0]);

        $blueprint = new Blueprint('users');
        $blueprint->foreign('foo_id')->references('id')->on('orders')->cascadeOnDelete();
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertCount(1, $statements);
        $this->assertSame('alter table users add constraint users_foo_id_foreign foreign key ( foo_id ) references orders ( id ) on delete cascade', $statements[0]);

        $blueprint = new Blueprint('users');
        $blueprint->foreign('foo_id')->references('id')->on('orders')->cascadeOnUpdate();
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertCount(1, $statements);
        $this->assertSame('alter table users add constraint users_foo_id_foreign foreign key ( foo_id ) references orders ( id ) on update cascade', $statements[0]);
    }

    public function testAddingForeignKeyWithCascadeDelete()
    {
        $blueprint = new Blueprint('users');
        $blueprint->foreign('foo_id')->references('id')->on('orders')->onDelete('cascade');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users add constraint users_foo_id_foreign foreign key ( foo_id ) references orders ( id ) on delete cascade', $statements[0]);
    }

    public function testAddingIncrementingID()
    {
        $blueprint = new Blueprint('users');
        $blueprint->increments('id');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users add ( id number(10,0) not null, constraint users_id_primary primary key ( id ) )', $statements[0]);
    }

    public function testAddingSmallIncrementingID()
    {
        $blueprint = new Blueprint('users');
        $blueprint->smallIncrements('id');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertCount(1, $statements);
        $this->assertSame('alter table users add ( id number(5,0) not null, constraint users_id_primary primary key ( id ) )', $statements[0]);
    }

    public function testAddingID()
    {
        $blueprint = new Blueprint('users');
        $blueprint->id();
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertCount(1, $statements);
        $this->assertSame('alter table users add ( id number(19,0) not null, constraint users_id_primary primary key ( id ) )', $statements[0]);

        $blueprint = new Blueprint('users');
        $blueprint->id('foo');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertCount(1, $statements);
        $this->assertSame('alter table users add ( foo number(19,0) not null, constraint users_foo_primary primary key ( foo ) )', $statements[0]);
    }

    public function testAddingForeignID()
    {
        $blueprint = new Blueprint('users');
        $foreignId = $blueprint->foreignId('foo');
        $blueprint->foreignId('company_id')->constrained();
        $blueprint->foreignId('laravel_idea_id')->constrained();
        $blueprint->foreignId('team_id')->references('id')->on('teams');
        $blueprint->foreignId('team_column_id')->constrained('teams');

        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

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

    public function testAddingBigIncrementingID()
    {
        $blueprint = new Blueprint('users');
        $blueprint->bigIncrements('id');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertCount(1, $statements);
        $this->assertSame('alter table users add ( id number(19,0) not null, constraint users_id_primary primary key ( id ) )', $statements[0]);
    }

    public function testAddingString()
    {
        $blueprint = new Blueprint('users');
        $blueprint->string('foo');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users add ( foo varchar2(255) not null )', $statements[0]);

        $blueprint = new Blueprint('users');
        $blueprint->string('foo', 100);
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users add ( foo varchar2(100) not null )', $statements[0]);

        $blueprint = new Blueprint('users');
        $blueprint->string('foo', 100)->nullable()->default('bar');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users add ( foo varchar2(100) default \'bar\' null )', $statements[0]);

        $blueprint = new Blueprint('users');
        $blueprint->string('foo', 100)->nullable()->default(new Raw('CURRENT TIMESTAMP'));
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users add ( foo varchar2(100) default CURRENT TIMESTAMP null )', $statements[0]);
    }

    public function testAddingLongText()
    {
        $blueprint = new Blueprint('users');
        $blueprint->longText('foo');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users add ( foo clob not null )', $statements[0]);
    }

    public function testAddingMediumText()
    {
        $blueprint = new Blueprint('users');
        $blueprint->mediumText('foo');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users add ( foo clob not null )', $statements[0]);
    }

    public function testAddingText()
    {
        $blueprint = new Blueprint('users');
        $blueprint->text('foo');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users add ( foo varchar2(4000) not null )', $statements[0]);
    }

    public function testAddingBigInteger()
    {
        $blueprint = new Blueprint('users');
        $blueprint->bigInteger('foo');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users add ( foo number(19,0) not null )', $statements[0]);

        $blueprint = new Blueprint('users');
        $blueprint->bigInteger('foo', true);
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users add ( foo number(19,0) not null, constraint users_foo_primary primary key ( foo ) )', $statements[0]);
    }

    public function testAddingInteger()
    {
        $blueprint = new Blueprint('users');
        $blueprint->integer('foo');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users add ( foo number(10,0) not null )', $statements[0]);

        $blueprint = new Blueprint('users');
        $blueprint->integer('foo', true);
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users add ( foo number(10,0) not null, constraint users_foo_primary primary key ( foo ) )', $statements[0]);
    }

    public function testAddingIncrementsWithStartingValues()
    {
        // calling ->startingValue() should have no effect on the generated sql because it hasn't been implemented

        $blueprint = new Blueprint('users');
        $blueprint->id()->startingValue(1000);
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertCount(1, $statements);
        $this->assertSame('alter table users add ( id number(19,0) not null, constraint users_id_primary primary key ( id ) )', $statements[0]);
    }

    public function testAddingMediumInteger()
    {
        $blueprint = new Blueprint('users');
        $blueprint->mediumInteger('foo');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users add ( foo number(7,0) not null )', $statements[0]);
    }

    public function testAddingSmallInteger()
    {
        $blueprint = new Blueprint('users');
        $blueprint->smallInteger('foo');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users add ( foo number(5,0) not null )', $statements[0]);
    }

    public function testAddingTinyInteger()
    {
        $blueprint = new Blueprint('users');
        $blueprint->tinyInteger('foo');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users add ( foo number(3,0) not null )', $statements[0]);
    }

    public function testAddingFloat()
    {
        $blueprint = new Blueprint('users');
        $blueprint->float('foo', 5);
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users add ( foo float(5) not null )', $statements[0]);
    }

    public function testAddingDouble()
    {
        $blueprint = new Blueprint('users');
        $blueprint->double('foo');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users add ( foo double precision not null )', $statements[0]);
    }

    public function testAddingDecimal()
    {
        $blueprint = new Blueprint('users');
        $blueprint->decimal('foo', 5, 2);
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users add ( foo number(5, 2) not null )', $statements[0]);
    }

    public function testAddingBoolean()
    {
        $blueprint = new Blueprint('users');
        $blueprint->boolean('foo');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users add ( foo char(1) not null )', $statements[0]);
    }

    public function testAddingEnum()
    {
        $blueprint = new Blueprint('users');
        $blueprint->enum('foo', ['bar', 'baz']);
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users add ( foo varchar2(255) check(foo in (\'bar\', \'baz\')) not null )', $statements[0]);
    }

    public function testAddingDate()
    {
        $blueprint = new Blueprint('users');
        $blueprint->date('foo');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users add ( foo date not null )', $statements[0]);
    }

    public function testAddingDateTime()
    {
        $blueprint = new Blueprint('users');
        $blueprint->dateTime('foo');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users add ( foo date not null )', $statements[0]);
    }

    public function testAddingTime()
    {
        $blueprint = new Blueprint('users');
        $blueprint->time('foo');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users add ( foo date not null )', $statements[0]);
    }

    public function testAddingTimeStamp()
    {
        $blueprint = new Blueprint('users');
        $blueprint->timestamp('foo');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users add ( foo timestamp not null )', $statements[0]);
    }

    public function testAddingTimestampWithDefault()
    {
        $blueprint = new Blueprint('users');
        $blueprint->timestamp('created_at')->default(new Raw('CURRENT_TIMESTAMP'));
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());
        $this->assertCount(1, $statements);
        $this->assertSame('alter table users add ( created_at timestamp default CURRENT_TIMESTAMP not null )', $statements[0]);
    }

    public function testAddingTimeStamps()
    {
        $blueprint = new Blueprint('users');
        $blueprint->timestamps();
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertEquals(2, count($statements));
        $this->assertSame([
            'alter table users add ( created_at timestamp null )',
            'alter table users add ( updated_at timestamp null )',
        ], $statements);
    }

    public function testAddingBinary()
    {
        $blueprint = new Blueprint('users');
        $blueprint->binary('foo');
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertEquals(1, count($statements));
        $this->assertEquals('alter table users add ( foo blob not null )', $statements[0]);
    }

    public function testAddingComment()
    {
        // calling ->comment() on a column should have no effect on the generated sql because it hasn't been implemented

        $blueprint = new Blueprint('users');
        $blueprint->string('foo')->comment("Escape ' when using words like it's");
        $statements = $blueprint->toSql($this->getConnection(), $this->getGrammar());

        $this->assertCount(1, $statements);
        $this->assertSame('alter table users add ( foo varchar2(255) not null )', $statements[0]);
    }

    public function testBasicSelectUsingQuotes()
    {
        $blueprint = new Blueprint('users');
        $blueprint->create();
        $blueprint->increments('id');
        $blueprint->string('email');

        $conn = $this->getConnection();

        $statements = $blueprint->toSql($conn, $this->getGrammar(true));

        $this->assertEquals(1, count($statements));
        $this->assertEquals('create table "users" ( "id" number(10,0) not null, "email" varchar2(255) not null, constraint users_id_primary primary key ( "id" ) )', $statements[0]);
    }

    public function testBasicSelectNotUsingQuotes()
    {
        $blueprint = new Blueprint('users');
        $blueprint->create();
        $blueprint->increments('id');
        $blueprint->string('email');

        $conn = $this->getConnection();

        $statements = $blueprint->toSql($conn, $this->getGrammar(false));

        $this->assertEquals(1, count($statements));
        $this->assertEquals('create table users ( id number(10,0) not null, email varchar2(255) not null, constraint users_id_primary primary key ( id ) )', $statements[0]);
    }

    public function testGrammarsAreMacroable()
    {
        $this->getGrammar()::macro('compileReplace', function () {
            return true;
        });

        $c = $this->getGrammar()::compileReplace();

        $this->assertTrue($c);
    }

    protected function getConnection()
    {
        return m::mock(OracleConnection::class);
    }

    public function getGrammar($quote = false)
    {
        global $ConfigReturnValue;
        $ConfigReturnValue = $quote;

        return new OracleGrammar;
    }
}
