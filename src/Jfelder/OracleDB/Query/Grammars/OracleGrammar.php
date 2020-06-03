<?php

namespace Jfelder\OracleDB\Query\Grammars;

use Config;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\Grammar as BaseGrammar;
use RuntimeException;

class OracleGrammar extends BaseGrammar
{
    /**
     * Compile a date based where clause.
     *
     * @param  string  $type
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $where
     * @return string
     */
    protected function dateBasedWhere($type, Builder $query, $where)
    {
        $date_parts = [
            'date' => 'YYYY-MM-DD',
            'day' => 'DD',
            'month' => 'MM',
            'year' => 'YYYY',
            'time' => 'HH24:MI:SS'
        ];
        $value = $this->parameter($where['value']);

        return 'TO_CHAR('.$this->wrap($where['column']).', \''.$date_parts[$type].'\') '.$where['operator'].' '.$value;
    }

    /**
     * Compile a select query into SQL.
     *
     * @param  \Illuminate\Database\Query\Builder
     * @return string
     */
    public function compileSelect(Builder $query)
    {
        if ($query->unions && $query->aggregate) {
            return $this->compileUnionAggregate($query);
        }

        // If the query does not have any columns set, we'll set the columns to the
        // * character to just get all of the columns from the database. Then we
        // can build the query and concatenate all the pieces together as one.
        $original = $query->columns;

        if (is_null($query->columns)) {
            $query->columns = ['*'];
        }

        // To compile the query, we'll spin through each component of the query and
        // see if that component exists. If it does we'll just call the compiler
        // function for the component which is responsible for making the SQL.
        $components = $this->compileComponents($query);
        $sql = trim($this->concatenate($components));

        if ($query->unions) {
            $sql = $this->wrapUnion($sql).' '.$this->compileUnions($query);
        }

        if (isset($query->limit) || isset($query->offset)) {
            $sql = $this->compileAnsiOffset($query, $components);
        }

        $query->columns = $original;

        return $sql;
    }

    /**
     * Compile an insert statement into SQL.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $values
     * @return string
     */
    public function compileInsert(Builder $query, array $values)
    {
        // Essentially we will force every insert to be treated as a batch insert which
        // simply makes creating the SQL easier for us since we can utilize the same
        // basic routine regardless of an amount of records given to us to insert.
        $table = $this->wrapTable($query->from);

        if (! is_array(reset($values))) {
            $values = [$values];
        }

        // If there is only one record being inserted, we will just use the usual query
        // grammar insert builder because no special syntax is needed for the single
        // row inserts in Oracle. However, if there are multiples, we'll continue.
        $count = count($values);

        if ($count == 1) {
            return parent::compileInsert($query, reset($values));
        }

        $columns = $this->columnize(array_keys(reset($values)));

        $rows = [];

        // Oracle requires us to build the multi-row insert as multiple inserts with
        // a select statement at the end. So we'll build out this list of columns
        // and then join them all together with select to complete the queries.
        $parameters = $this->parameterize(reset($values));

        for ($i = 0; $i < $count; $i++) {
            $rows[] = "into {$table} ({$columns}) values ({$parameters}) ";
        }

        return 'insert all '.implode($rows).' select 1 from dual';
    }

    /**
     * Compile an insert and get ID statement into SQL.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array   $values
     * @param  string  $sequence
     * @return string
     */
    public function compileInsertGetId(Builder $query, $values, $sequence)
    {
        if (empty($values)) {
            throw new RuntimeException('This database engine does not support calling the insertGetId method with empty values.');
        }

        if (is_null($sequence)) {
            $sequence = 'id';
        }

        return $this->compileInsert($query, $values).' returning '.$this->wrap($sequence).' into ?';
    }

    /**
     * Compile the "union" queries attached to the main query.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return string
     */
    protected function compileUnions(Builder $query)
    {
        $sql = '';

        foreach ($query->unions as $union) {
            $sql .= $this->compileUnion($union);
        }

        if (isset($query->unionOrders)) {
            $sql .= ' '.$this->compileOrders($query, $query->unionOrders);
        }

        if (isset($query->unionLimit)) {
            $query->limit = $query->unionLimit;
        }

        if (isset($query->unionOffset)) {
            $query->offset = $query->unionOffset;
        }

        return ltrim($sql);
    }

    /**
     * Compile a delete statement into SQL.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return string
     */
    public function compileDelete(Builder $query)
    {
        if (isset($query->joins) || isset($query->limit)) {
            return $this->compileDeleteWithJoinsOrLimit($query);
        }

        return parent::compileDelete($query);
    }   

    /**
     * Compile a delete statement with joins or limit into SQL.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return string
     */
    protected function compileDeleteWithJoinsOrLimit(Builder $query)
    {
        throw new RuntimeException('This database engine does not support delete statements that contain joins or limits.');
    }

    /**
     * Compile an exists statement into SQL.
     *
     * @param \Illuminate\Database\Query\Builder $query
     * @return string
     */
    public function compileExists(Builder $query)
    {
        $select = $this->compileSelect($query);

        return "select t2.\"rn\" as {$this->wrap('exists')} from ( select rownum AS \"rn\", t1.* from ({$select}) t1 ) t2 where t2.\"rn\" between 1 and 1";
    }

    /**
     * Compile the lock into SQL.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  bool|string  $value
     * @return string
     */
    protected function compileLock(Builder $query, $value)
    {
        if (is_string($value)) {
            return $value;
        }

        return $value ? 'for update' : 'lock in share mode';
    }

    /**
     * Create a full ANSI offset clause for the query.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $components
     * @return string
     */
    protected function compileAnsiOffset(Builder $query, $components)
    {
        $constraint = $this->compileRowConstraint($query);

        $sql = $this->concatenate($components);

        if ($query->unions) {
            $sql = $this->wrapUnion($sql).' '.$this->compileUnions($query);
        }

        // We are now ready to build the final SQL query so we'll create a common table
        // expression from the query and get the records with row numbers within our
        // given limit and offset value that we just put on as a query constraint.
        $temp = $this->compileTableExpression($sql, $constraint, $query);

        return $temp;
    }

    /**
     * Compile the limit / offset row constraint for a query.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return string
     */
    protected function compileRowConstraint($query)
    {
        if (isset($query->limit) && $query->limit < 1) {
            return "< 1";
        }

        $start = $query->offset + 1;

        if ($query->limit > 0) {
            $finish = $query->offset + $query->limit;

            return "between {$start} and {$finish}";
        }

        return ">= {$start}";
    }

    /**
     * Compile a common table expression for a query.
     *
     * @param  string  $sql
     * @param  string  $constraint
     * @return string
     */
    protected function compileTableExpression($sql, $constraint, $query)
    {
        if ($query->limit > 0) {
            return "select t2.* from ( select rownum AS \"rn\", t1.* from ({$sql}) t1 ) t2 where t2.\"rn\" {$constraint}";
        } else {
            return "select * from ({$sql}) where rownum {$constraint}";
        }
    }

    /**
     * Compile the "limit" portions of the query.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  int  $limit
     * @return string
     */
    protected function compileLimit(Builder $query, $limit)
    {
        return '';
    }

    /**
     * Compile the "offset" portions of the query.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  int  $offset
     * @return string
     */
    protected function compileOffset(Builder $query, $offset)
    {
        return '';
    }

    /**
     * Wrap a single string in keyword identifiers.
     *
     * @param  string  $value
     * @return string
     */
    protected function wrapValue($value)
    {
        if (Config::get('oracledb::database.quoting') === true) {
            return parent::wrapValue($value);
        }

        return $value;
    }
}
