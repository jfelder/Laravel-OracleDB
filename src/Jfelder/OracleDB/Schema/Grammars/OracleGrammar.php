<?php namespace Jfelder\OracleDB\Schema\Grammars;

use \Illuminate\Support\Fluent;
use \Illuminate\Database\Connection;
use \Illuminate\Database\Schema\Blueprint;

class OracleGrammar extends \Illuminate\Database\Schema\Grammars\Grammar {

	/**
	 * The keyword identifier wrapper format.
	 *
	 * @var string
	 */
	protected $wrapper = '%s';

	/**
	 * The possible column modifiers.
	 *
	 * @var array
	 */
	protected $modifiers = array('Increment', 'Nullable', 'Default');

   /**
    * Compile the query to determine if a table exists.
    *
    * @return string
    */
   public function compileTableExists()
   {
      return 'select * from user_tables where upper(table_name) = upper(?)';
   }

   /**
	 * Get the primary key syntax for a table creation statement.
	 *
	 * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
	 * @return string|null
	 */
	protected function addPrimaryKeys(Blueprint $blueprint)
	{
		$primary = $this->getCommandByName($blueprint, 'primary');

		if ( ! is_null($primary))
		{
                        $table = $this->wrapTable($blueprint);
			$columns = $this->columnize($primary->columns);

			return ", constraint {$primary->index} primary key ( {$columns} )";
		}
	}

	/**
	 * Get the foreign key syntax for a table creation statement.
	 *
	 * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
	 * @return string|null
	 */
	protected function addForeignKeys(Blueprint $blueprint)
	{
		$sql = '';

		$foreigns = $this->getCommandsByName($blueprint, 'foreign');

		// Once we have all the foreign key commands for the table creation statement
		// we'll loop through each of them and add them to the create table SQL we
		// are building
		foreach ($foreigns as $foreign)
		{
                        $table = $this->wrapTable($blueprint);
                        $table = $foreign->index;

                        $on = $this->wrapTable($foreign->on);

			$columns = $this->columnize($foreign->columns);

			$onColumns = $this->columnize((array) $foreign->references);

			$sql .= ", constraint {$foreign->index} foreign key ( {$columns} ) references {$on} ( {$onColumns} )";

                        // Once we have the basic foreign key creation statement constructed we can
                        // build out the syntax for what should happen on an update or delete of
                        // the affected columns, which will get something like "cascade", etc.
                        if ( ! is_null($foreign->onDelete))
                        {
                            $sql .= " on delete {$foreign->onDelete}";
                        }
		}

		return $sql;
	}

	/**
	 * Compile a create table command.
	 *
	 * @param  Illuminate\Database\Schema\Blueprint  $blueprint
	 * @param  Illuminate\Support\Fluent  $command
	 * @return string
	 */
	public function compileCreate(Blueprint $blueprint, Fluent $command)
	{
		$columns = implode(', ', $this->getColumns($blueprint));

		$sql = 'create table '.$this->wrapTable($blueprint)." ( $columns";

		// To be able to name the primary/foreign keys when the table is
		// initially created we will need to check for a primary/foreign
                // key commands and add the columns to the table's declaration
                // here so they can be created on the tables.

		$sql .= (string) $this->addForeignKeys($blueprint);

		$sql .= (string) $this->addPrimaryKeys($blueprint);

		return $sql .= ' )';
	}

	/**
	 * Compile a create table command.
	 *
	 * @param  Illuminate\Database\Schema\Blueprint  $blueprint
	 * @param  Illuminate\Support\Fluent  $command
	 * @return string
	 */
	public function compileAdd(Blueprint $blueprint, Fluent $command)
	{
		$columns = implode(', ', $this->getColumns($blueprint));

		$sql = 'alter table '.$this->wrapTable($blueprint)." add ( $columns";

		// SQLite forces primary keys to be added when the table is initially created
		// so we will need to check for a primary key commands and add the columns
		// to the table's declaration here so they can be created on the tables.
		//$sql .= (string) $this->addForeignKeys($blueprint);

		$sql .= (string) $this->addPrimaryKeys($blueprint);

		return $sql .= ' )';
	}

	/**
	 * Compile a primary key command.
	 *
	 * @param  Illuminate\Database\Schema\Blueprint  $blueprint
	 * @param  Illuminate\Support\Fluent  $command
	 * @return string
	 */
	public function compilePrimary(Blueprint $blueprint, Fluent $command)
	{
            $create = $this->getCommandByName($blueprint, 'create');

            if (is_null($create))
            {
		$columns = $this->columnize($command->columns);

		$table = $this->wrapTable($blueprint);

		return "alter table {$table} add constraint {$command->index} primary key ({$columns})";
            }
        }

	/**
	 * Compile a foreign key command.
	 *
	 * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
	 * @param  \Illuminate\Support\Fluent  $command
	 * @return string
	 */
	public function compileForeign(Blueprint $blueprint, Fluent $command)
	{
            $create = $this->getCommandByName($blueprint, 'create');

            if (is_null($create))
            {
                $table = $this->wrapTable($blueprint);

                $on = $this->wrapTable($command->on);

                // We need to prepare several of the elements of the foreign key definition
                // before we can create the SQL, such as wrapping the tables and convert
                // an array of columns to comma-delimited strings for the SQL queries.
                $columns = $this->columnize($command->columns);

                $onColumns = $this->columnize((array) $command->references);

                $sql = "alter table {$table} add constraint {$command->index} ";

                $sql .= "foreign key ( {$columns} ) references {$on} ( {$onColumns} )";

                // Once we have the basic foreign key creation statement constructed we can
                // build out the syntax for what should happen on an update or delete of
                // the affected columns, which will get something like "cascade", etc.
                if ( ! is_null($command->onDelete))
                {
                    $sql .= " on delete {$command->onDelete}";
                }

                return $sql;
            }
        }

        /**
	 * Compile a unique key command.
	 *
	 * @param  Illuminate\Database\Schema\Blueprint  $blueprint
	 * @param  Illuminate\Support\Fluent  $command
	 * @return string
	 */
	public function compileUnique(Blueprint $blueprint, Fluent $command)
	{
		$columns = $this->columnize($command->columns);

		$table = $this->wrapTable($blueprint);

		return "alter table {$table} add constraint {$command->index} unique ( {$columns} )";
	}

	/**
	 * Compile a plain index key command.
	 *
	 * @param  Illuminate\Database\Schema\Blueprint  $blueprint
	 * @param  Illuminate\Support\Fluent  $command
	 * @return string
	 */
	public function compileIndex(Blueprint $blueprint, Fluent $command)
	{
		$columns = $this->columnize($command->columns);

		$table = $this->wrapTable($blueprint);

		return "create index {$command->index} on {$table} ( {$columns} )";
	}

	/**
	 * Compile a drop table command.
	 *
	 * @param  Illuminate\Database\Schema\Blueprint  $blueprint
	 * @param  Illuminate\Support\Fluent  $command
	 * @return string
	 */
	public function compileDrop(Blueprint $blueprint, Fluent $command)
	{
		return 'drop table '.$this->wrapTable($blueprint);
	}

	/**
	 * Compile a drop column command.
	 *
	 * @param  Illuminate\Database\Schema\Blueprint  $blueprint
	 * @param  Illuminate\Support\Fluent  $command
	 * @return string
	 */
	public function compileDropColumn(Blueprint $blueprint, Fluent $command)
	{
		$columns = $this->wrapArray($command->columns);

		$table = $this->wrapTable($blueprint);

		return 'alter table '.$table.' drop column ( '.implode(', ', $columns) . ' )';
	}

	/**
	 * Compile a drop primary key command.
	 *
	 * @param  Illuminate\Database\Schema\Blueprint  $blueprint
	 * @param  Illuminate\Support\Fluent  $command
	 * @return string
	 */
	public function compileDropPrimary(Blueprint $blueprint, Fluent $command)
	{
		$table = $blueprint->getTable();

		$table = $this->wrapTable($blueprint);

		return "alter table {$table} drop constraint {$command->index}";
	}

	/**
	 * Compile a drop unique key command.
	 *
	 * @param  Illuminate\Database\Schema\Blueprint  $blueprint
	 * @param  Illuminate\Support\Fluent  $command
	 * @return string
	 */
	public function compileDropUnique(Blueprint $blueprint, Fluent $command)
	{
		$table = $this->wrapTable($blueprint);

		return "alter table {$table} drop constraint {$command->index}";
	}

	/**
	 * Compile a drop index command.
	 *
	 * @param  Illuminate\Database\Schema\Blueprint  $blueprint
	 * @param  Illuminate\Support\Fluent  $command
	 * @return string
	 */
	public function compileDropIndex(Blueprint $blueprint, Fluent $command)
	{
		$table = $this->wrapTable($blueprint);

		return "drop index {$command->index}";
	}

	/**
	 * Compile a drop foreign key command.
	 *
	 * @param  Illuminate\Database\Schema\Blueprint  $blueprint
	 * @param  Illuminate\Support\Fluent  $command
	 * @return string
	 */
	public function compileDropForeign(Blueprint $blueprint, Fluent $command)
	{
		$table = $this->wrapTable($blueprint);

		return "alter table {$table} drop constraint {$command->index}";
	}

	/**
	 * Compile a rename table command.
	 *
	 * @param  Illuminate\Database\Schema\Blueprint  $blueprint
	 * @param  Illuminate\Support\Fluent  $command
	 * @return string
	 */
	public function compileRename(Blueprint $blueprint, Fluent $command)
	{
		$from = $this->wrapTable($blueprint);

		return "alter table {$from} rename to ".$this->wrapTable($command->to);
	}

        /**
	 * Compile a rename column command.
	 *
	 * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
	 * @param  \Illuminate\Support\Fluent  $command
	 * @param  \Illuminate\Database\Connection  $connection
	 * @return array
	 */
	public function compileRenameColumn(Blueprint $blueprint, Fluent $command, Connection $connection)
	{
		$table = $this->wrapTable($blueprint);

                $rs[0] = 'alter table '.$table.' rename column '.$command->from.' to '.$command->to;

                return (array) $rs;
	}

	/**
	 * Create the column definition for a string type.
	 *
	 * @param  Illuminate\Support\Fluent  $column
	 * @return string
	 */
	protected function typeString(Fluent $column)
	{
		return "varchar2({$column->length})";
	}

	/**
	 * Create the column definition for a text type.
	 *
	 * @param  Illuminate\Support\Fluent  $column
	 * @return string
	 */
	protected function typeText(Fluent $column)
	{
		return "varchar2(4000)";
	}

	/**
	 * Create the column definition for a integer type.
	 *
	 * @param  Illuminate\Support\Fluent  $column
	 * @return string
	 */
	protected function typeInteger(Fluent $column)
	{
		return 'integer';
	}

	/**
	 * Create the column definition for a tiny integer type.
	 *
	 * @param  \Illuminate\Support\Fluent  $column
	 * @return string
	 */
	protected function typeTinyInteger(Fluent $column)
	{
		return 'number(1)';
	}

	/**
	 * Create the column definition for a float type.
	 *
	 * @param  Illuminate\Support\Fluent  $column
	 * @return string
	 */
	protected function typeFloat(Fluent $column)
	{
		return "number({$column->total}, {$column->places})";
	}

	/**
	 * Create the column definition for a decimal type.
	 *
	 * @param  Illuminate\Support\Fluent  $column
	 * @return string
	 */
	protected function typeDecimal(Fluent $column)
	{
		return "number({$column->total}, {$column->places})";
	}

	/**
	 * Create the column definition for a boolean type.
	 *
	 * @param  Illuminate\Support\Fluent  $column
	 * @return string
	 */
	protected function typeBoolean(Fluent $column)
	{
		return 'char(1)';
	}

	/**
	 * Create the column definition for a enum type.
	 *
	 * @param  Illuminate\Support\Fluent  $column
	 * @return string
	 */
	protected function typeEnum(Fluent $column)
	{
		return "varchar2(255)";
	}

	/**
	 * Create the column definition for a date type.
	 *
	 * @param  Illuminate\Support\Fluent  $column
	 * @return string
	 */
	protected function typeDate(Fluent $column)
	{
		return 'date';
	}

	/**
	 * Create the column definition for a date-time type.
	 *
	 * @param  Illuminate\Support\Fluent  $column
	 * @return string
	 */
	protected function typeDateTime(Fluent $column)
	{
		return 'date';
	}

	/**
	 * Create the column definition for a time type.
	 *
	 * @param  Illuminate\Support\Fluent  $column
	 * @return string
	 */
	protected function typeTime(Fluent $column)
	{
		return 'date';
	}

	/**
	 * Create the column definition for a timestamp type.
	 *
	 * @param  Illuminate\Support\Fluent  $column
	 * @return string
	 */
	protected function typeTimestamp(Fluent $column)
	{
		return 'timestamp';
	}

	/**
	 * Create the column definition for a binary type.
	 *
	 * @param  Illuminate\Support\Fluent  $column
	 * @return string
	 */
	protected function typeBinary(Fluent $column)
	{
		return 'blob';
	}

	/**
	 * Get the SQL for a nullable column modifier.
	 *
	 * @param  Illuminate\Database\Schema\Blueprint  $blueprint
	 * @param  Illuminate\Support\Fluent  $column
	 * @return string|null
	 */
	protected function modifyNullable(Blueprint $blueprint, Fluent $column)
	{
		return $column->nullable ? ' null' : ' not null';
	}

	/**
	 * Get the SQL for a default column modifier.
	 *
	 * @param  Illuminate\Database\Schema\Blueprint  $blueprint
	 * @param  Illuminate\Support\Fluent  $column
	 * @return string|null
	 */
	protected function modifyDefault(Blueprint $blueprint, Fluent $column)
	{
		if ( ! is_null($column->default))
		{
			return " default ".$this->getDefaultValue($column->default)."";
		}
	}

	/**
	 * Get the SQL for an auto-increment column modifier.
	 *
	 * @param  Illuminate\Database\Schema\Blueprint  $blueprint
	 * @param  Illuminate\Support\Fluent  $column
	 * @return string|null
	 */
	protected function modifyIncrement(Blueprint $blueprint, Fluent $column)
	{
		if ($column->type == 'integer' and $column->autoIncrement)
		{
                        $blueprint->primary($column->name);
		}
	}


}
