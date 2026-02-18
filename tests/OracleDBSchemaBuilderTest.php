<?php

namespace Jfelder\OracleDB\Tests\Unit;

use InvalidArgumentException;
use Jfelder\OracleDB\OracleConnection;
use Jfelder\OracleDB\Schema\Grammars\OracleGrammar;
use Jfelder\OracleDB\Schema\OracleBlueprint;
use Jfelder\OracleDB\Schema\OracleBuilder;
use Mockery as m;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use TypeError;

class OracleDBSchemaBuilderTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
    }

    public function test_create_blueprint_uses_custom_class()
    {
        $connection = m::mock(OracleConnection::class);
        $grammar = m::mock(OracleGrammar::class);
        $connection->shouldReceive('getSchemaGrammar')->andReturn($grammar);
        $builder = new OracleBuilder($connection);

        $reflection = new ReflectionClass($builder);
        $method = $reflection->getMethod('createBlueprint');

        $blueprint = $method->invoke($builder, 'test_table', null);

        $this->assertSame(OracleBlueprint::class, get_class($blueprint));
    }

    public function test_resolver_still_resolves_in_custom_class()
    {
        $connection = m::mock(OracleConnection::class);
        $grammar = m::mock(OracleGrammar::class);
        $connection->shouldReceive('getSchemaGrammar')->andReturn($grammar);
        $builder = new OracleBuilder($connection);

        $builder->blueprintResolver(function () {
            return 'Testing resolver functionality!';
        });

        $reflection = new ReflectionClass($builder);
        $method = $reflection->getMethod('createBlueprint');

        $this->expectException(TypeError::class);
        $this->expectExceptionMessage('Jfelder\OracleDB\Schema\OracleBuilder::createBlueprint(): Return value must be of type Jfelder\OracleDB\Schema\OracleBlueprint, string returned');

        $blueprint = $method->invoke($builder, 'test_table', null);
    }

    public function test_parse_schema_and_table_with_only_table()
    {
        $connection = m::mock(OracleConnection::class);
        $grammar = m::mock(OracleGrammar::class);
        $connection->shouldReceive('getSchemaGrammar')->andReturn($grammar);
        $connection->shouldReceive('getConfig')->with('username')->andReturn('schema');
        $builder = new OracleBuilder($connection);

        $this->assertSame(['schema', 'table'], $builder->parseSchemaAndTable('table'));
    }

    public function test_parse_schema_and_table_with_schema_and_table()
    {
        $connection = m::mock(OracleConnection::class);
        $grammar = m::mock(OracleGrammar::class);
        $connection->shouldReceive('getSchemaGrammar')->andReturn($grammar);
        $builder = new OracleBuilder($connection);

        $this->assertSame(['schema', 'table'], $builder->parseSchemaAndTable('schema.table'));
    }

    public function test_parse_schema_and_table_with_more_than_schema_and_table()
    {
        $connection = m::mock(OracleConnection::class);
        $grammar = m::mock(OracleGrammar::class);
        $connection->shouldReceive('getSchemaGrammar')->andReturn($grammar);
        $builder = new OracleBuilder($connection);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Using three-part references is not supported, you may use `Schema::connection(\'database\')` instead.');

        $builder->parseSchemaAndTable('database.schema.table');
    }

    public function test_parse_schema_and_table_with_table_and_default_schema_string()
    {
        $connection = m::mock(OracleConnection::class);
        $grammar = m::mock(OracleGrammar::class);
        $connection->shouldReceive('getSchemaGrammar')->andReturn($grammar);
        $builder = new OracleBuilder($connection);

        $this->assertSame(['schema', 'table'], $builder->parseSchemaAndTable('table', 'schema'));
    }

    public function test_parse_schema_and_table_with_table_and_default_schema_bool()
    {
        $connection = m::mock(OracleConnection::class);
        $grammar = m::mock(OracleGrammar::class);
        $connection->shouldReceive('getSchemaGrammar')->andReturn($grammar);
        $connection->shouldReceive('getConfig')->andReturn('schema');
        $builder = new OracleBuilder($connection);

        $this->assertSame(['schema', 'table'], $builder->parseSchemaAndTable('table', true));
    }

    public function test_get_column_listing()
    {
        $connection = m::mock(OracleConnection::class);
        $grammar = m::mock(OracleGrammar::class);
        $connection->shouldReceive('getSchemaGrammar')->andReturn($grammar);

        $builder = new OracleBuilder($connection);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('This database engine does not support column listing operations. Eloquent models must set $guarded to [] or not define it at all.');

        $this->assertEquals(['column'], $builder->getColumnListing('table'));
    }
}
