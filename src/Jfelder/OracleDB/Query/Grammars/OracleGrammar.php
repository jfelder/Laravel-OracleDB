<?php

namespace Jfelder\OracleDB\Query\Grammars;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\Grammar as BaseGrammar;
use RuntimeException;

class OracleGrammar extends BaseGrammar
{
    /**
     * The components that make up a select clause.
     *
     * @var string[]
     */
    protected $selectComponents = [
        'aggregate',
        'columns',
        'from',
        'joins',
        'wheres',
        'groups',
        'havings',
        'orders',
        'offset',
        'limit',
        'lock',
    ];

    /**
     * Compile a date based where clause.
     *
     * @param  string  $type
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
            'time' => 'HH24:MI:SS',
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
        // An order by clause is required for Oracle offset to function...
        if ($query->offset && empty($query->orders)) {
            $query->orders[] = ['sql' => '(SELECT 0)'];
        }

        if (($query->unions || $query->havings) && $query->aggregate) {
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
        $sql = trim($this->concatenate(
            $this->compileComponents($query))
        );

        if ($query->unions) {
            $sql = $this->wrapUnion($sql).' '.$this->compileUnions($query);
        }

        $query->columns = $original;

        return $sql;
    }

    /**
     * Compile a "where like" clause.
     *
     * @param  array  $where
     * @return string
     */
    protected function whereLike(Builder $query, $where)
    {
        if (! $where['caseSensitive']) {
            throw new RuntimeException('This database engine does not support case insensitive like operations. The sql "UPPER(some_column) like ?" can accomplish insensitivity.');
        }

        $where['operator'] = $where['not'] ? 'not like' : 'like';

        return $this->whereBasic($query, $where);
    }

    /**
     * Compile an insert statement into SQL.
     *
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
     * @param  array  $values
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

        if (isset($query->unionOffset)) {
            $sql .= ' '.$this->compileOffset($query, $query->unionOffset);
        }

        if (isset($query->unionLimit)) {
            $sql .= ' '.$this->compileLimit($query, $query->unionLimit);
        }

        return ltrim($sql);
    }

    /**
     * Compile a delete statement into SQL.
     *
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
     * @return string
     */
    protected function compileDeleteWithJoinsOrLimit(Builder $query)
    {
        throw new RuntimeException('This database engine does not support delete statements that contain joins or limits.');
    }

    /**
     * Compile an exists statement into SQL.
     *
     * @return string
     */
    public function compileExists(Builder $query)
    {
        $select = $this->compileSelect($query);

        return "select case when exists({$select}) then 1 else 0 end as {$this->wrap('exists')} from dual";
    }

    /**
     * Compile the lock into SQL.
     *
     * @param  bool|string  $value
     * @return string
     */
    protected function compileLock(Builder $query, $value)
    {
        if (is_string($value)) {
            return $value;
        }

        return $value ? 'for update' : '';
    }

    /**
     * Compile the "limit" portions of the query.
     *
     * @param  int  $limit
     * @return string
     */
    protected function compileLimit(Builder $query, $limit)
    {
        $limit = (int) $limit;

        if ($limit) {
            return "fetch next {$limit} rows only";
        }

        return '';
    }

    /**
     * Compile the "offset" portions of the query.
     *
     * @param  int  $offset
     * @return string
     */
    protected function compileOffset(Builder $query, $offset)
    {
        $offset = (int) $offset;

        if ($offset) {
            return "offset {$offset} rows";
        }

        return '';
    }

    /**
     * Compile the random statement into SQL.
     *
     * @param  string|int  $seed
     * @return string
     */
    public function compileRandom($seed)
    {
        return 'DBMS_RANDOM.VALUE';
    }

    /**
     * Wrap a single string in keyword identifiers.
     *
     * @param  string  $value
     * @return string
     */
    protected function wrapValue($value)
    {
        if ($this->connection->getConfig('quoting') === true) {
            return parent::wrapValue($value);
        }

        return $value;
    }
}
