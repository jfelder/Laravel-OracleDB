<?php

namespace Jfelder\OracleDB\PDO;

use Doctrine\DBAL\Driver\AbstractOracleDriver;
use Illuminate\Database\PDO\Concerns\ConnectsToDatabase;

class OracleDriver extends AbstractOracleDriver
{
    use ConnectsToDatabase;

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return 'oci8';
    }
}
