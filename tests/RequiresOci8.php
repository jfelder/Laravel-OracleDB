<?php

namespace Jfelder\OracleDB\Tests;

trait RequiresOci8
{
    protected function requireOci8(): void
    {
        $requiredConstants = [
            'OCI_COMMIT_ON_SUCCESS',
            'OCI_NO_AUTO_COMMIT',
            'SQLT_INT',
            'SQLT_CHR',
            'SQLT_BLOB',
        ];

        if (! extension_loaded('oci8')) {
            $this->markTestSkipped('OCI8 extension is not available.');
        }

        foreach ($requiredConstants as $constant) {
            if (! defined($constant)) {
                $this->markTestSkipped("Required OCI constant [{$constant}] is not available.");
            }
        }
    }
}
