<?php

namespace Jfelder\OracleDB\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;

class OracleBlueprint extends Blueprint
{
    /**
     * {@inheritdoc}
     */
    public function float($column, $precision = 126): ColumnDefinition
    {
        return parent::float($column, $precision);
    }

    /**
     * {@inheritdoc}
     */
    public function id($column = 'id'): ColumnDefinition
    {
        return $this->addColumn('integer', $column, ['identity' => true, 'primary' => true]);
    }

    /**
     * {@inheritdoc}
     */
    public function increments($column): ColumnDefinition
    {
        return $this->id($column);
    }

    /**
     * {@inheritdoc}
     */
    public function smallIncrements($column): ColumnDefinition
    {
        return $this->addColumn('smallInteger', $column, ['identity' => true, 'primary' => true]);
    }

    /**
     * {@inheritdoc}
     */
    public function bigIncrements($column): ColumnDefinition
    {
        return $this->addColumn('bigInteger', $column, ['identity' => true, 'primary' => true]);
    }
}
