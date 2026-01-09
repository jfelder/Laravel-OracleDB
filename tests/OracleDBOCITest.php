<?php

namespace Jfelder\OracleDB\Tests;

use Jfelder\OracleDB\OCI_PDO\OCI;
use Jfelder\OracleDB\OCI_PDO\OCIException;
use Jfelder\OracleDB\OCI_PDO\OCIStatement;
use Mockery as m;
use PDO;
use PHPUnit\Framework\TestCase;
use TestOCIStub;

include 'mocks/OCIMocks.php';
include 'mocks/OCIFunctions.php';

class OracleDBOCITest extends TestCase
{
    private $oci;

    protected function setUp(): void
    {
        if (! extension_loaded('oci8')) {
            $this->markTestSkipped(
                'The oci8 extension is not available.'
            );
        } else {
            global $OCITransactionStatus, $OCIStatementStatus, $OCIExecuteStatus;

            $OCITransactionStatus = true;
            $OCIStatementStatus = true;
            $OCIExecuteStatus = true;

            $this->oci = m::mock(new TestOCIStub('', null, null, [PDO::ATTR_CASE => PDO::CASE_LOWER]));
        }
    }

    protected function tearDown(): void
    {
        m::close();
    }

    public function test_constructor_success_with_persistent_connection()
    {
        $oci = new OCI('dsn', null, null, [PDO::ATTR_PERSISTENT => 1]);
        $this->assertInstanceOf(OCI::class, $oci);
        $this->assertEquals(1, $oci->getAttribute(PDO::ATTR_PERSISTENT));
    }

    public function test_constructor_success_without_persistent_connection()
    {
        $oci = new OCI('dsn', null, null, [PDO::ATTR_PERSISTENT => 0]);
        $this->assertInstanceOf(OCI::class, $oci);
        $this->assertEquals(0, $oci->getAttribute(PDO::ATTR_PERSISTENT));
    }

    public function test_constructor_fail_with_persistent_connection()
    {
        global $OCITransactionStatus;
        $OCITransactionStatus = false;
        $this->expectException(OCIException::class);
        $oci = new OCI('dsn', null, null, [PDO::ATTR_PERSISTENT => 1]);
    }

    public function test_constructor_fail_without_persistent_connection()
    {
        global $OCITransactionStatus;
        $OCITransactionStatus = false;
        $this->expectException(OCIException::class);
        $oci = new OCI('dsn', null, null, [PDO::ATTR_PERSISTENT => 0]);
    }

    public function test_destructor()
    {
        global $OCITransactionStatus;

        $oci = new OCI('dsn', '', '');
        unset($oci);
        $this->assertFalse($OCITransactionStatus);
    }

    public function test_begin_transaction()
    {
        $result = $this->oci->beginTransaction();
        $this->assertTrue($result);

        $this->assertEquals(0, $this->oci->getExecuteMode());
    }

    public function test_begin_transaction_already_in_transaction()
    {
        $this->expectException(OCIException::class);
        $result = $this->oci->beginTransaction();
        $result = $this->oci->beginTransaction();
    }

    public function test_commit_in_transaction_passes()
    {
        $this->oci->beginTransaction();
        $this->assertTrue($this->oci->commit());
    }

    public function test_commit_in_transaction_fails()
    {
        global $OCITransactionStatus;
        $OCITransactionStatus = false;
        $this->expectException(OCIException::class);
        $this->oci->beginTransaction();
        $this->oci->commit();
    }

    public function test_commit_not_in_transaction()
    {
        $this->assertFalse($this->oci->commit());
    }

    public function test_error_code()
    {
        $oci = new TestOCIStub;
        $this->assertNull($oci->errorCode());

        // use reflection to test values of protected properties
        $reflection = new \ReflectionClass($oci);

        // setErrorInfo
        $method = $reflection->getMethod('setErrorInfo');
        $method->setAccessible(true);
        $method->invoke($oci, '11111', '2222', 'Testing the errors');

        $this->assertEquals('11111', $oci->errorCode());
    }

    public function test_error_info()
    {
        $oci = new TestOCIStub;
        $this->assertEquals([0 => '', 1 => null, 2 => null], $oci->errorInfo());

        // use reflection to test values of protected properties
        $reflection = new \ReflectionClass($oci);

        // setErrorInfo
        $method = $reflection->getMethod('setErrorInfo');
        $method->setAccessible(true);
        $method->invoke($oci, '11111', '2222', 'Testing the errors');

        $this->assertEquals([0 => '11111', 1 => '2222', 2 => 'Testing the errors'], $oci->errorInfo());
    }

    public function test_exec()
    {
        $sql = 'select * from table';
        $oci = new TestOCIStub;
        $stmt = $oci->exec($sql);
        $this->assertEquals(1, $stmt);

        // use reflection to test values of protected properties of OCI object
        $reflection = new \ReflectionClass($oci);

        // stmt property
        $property = $reflection->getProperty('stmt');
        $property->setAccessible(true);
        $oci_stmt = $property->getValue($oci);
        $this->assertInstanceOf(OCIStatement::class, $oci_stmt);

        // use reflection to test values of protected properties of OCIStatement object
        $reflection = new \ReflectionClass($oci_stmt);
        // conn property
        $property = $reflection->getProperty('conn');
        $property->setAccessible(true);
        $this->assertEquals($oci, $property->getValue($oci_stmt));

        // attributes property
        $property = $reflection->getProperty('attributes');
        $property->setAccessible(true);
        $this->assertEquals([], $property->getValue($oci_stmt));
    }

    public function test_exec_fails()
    {
        global $OCIExecuteStatus;
        $OCIExecuteStatus = false;
        $sql = 'select * from table';
        $oci = new TestOCIStub;
        $stmt = $oci->exec($sql);
        $this->assertFalse($stmt);
    }

    public function test_get_attribute_for_valid_attribute()
    {
        $this->assertEquals(1, $this->oci->getAttribute(PDO::ATTR_AUTOCOMMIT));
    }

    public function test_get_attribute_for_invalid_attribute()
    {
        $nonExistantAttr = 12345;
        $this->assertEquals(null, $this->oci->getAttribute($nonExistantAttr));
    }

    public function test_in_transaction_while_not_in_transaction()
    {
        $this->assertFalse($this->oci->inTransaction());
    }

    public function test_in_transaction_while_in_transaction()
    {
        $this->oci->beginTransaction();
        $this->assertTrue($this->oci->inTransaction());
    }

    public function test_last_insert_id_with_name()
    {
        $this->expectException(OCIException::class);
        $result = $this->oci->lastInsertID('foo');
    }

    public function test_last_insert_id_without_name()
    {
        $this->expectException(OCIException::class);
        $result = $this->oci->lastInsertID();
    }

    public function test_prepare_with_non_parameter_query()
    {
        $sql = 'select * from table';
        $oci = new TestOCIStub;
        $stmt = $oci->prepare($sql);
        $this->assertInstanceOf(OCIStatement::class, $stmt);

        // use reflection to test values of protected properties
        $reflection = new \ReflectionClass($stmt);

        // stmt property
        $property = $reflection->getProperty('stmt');
        $property->setAccessible(true);
        $this->assertEquals('oci8 statement', $property->getValue($stmt));

        // conn property
        $property = $reflection->getProperty('conn');
        $property->setAccessible(true);
        $this->assertEquals($oci, $property->getValue($stmt));

        // attributes property
        $property = $reflection->getProperty('attributes');
        $property->setAccessible(true);
        $this->assertEquals([], $property->getValue($stmt));
    }

    public function test_prepare_with_parameter_query()
    {
        $sql = 'select * from table where id = ? and date = ?';
        $oci = new TestOCIStub;
        $stmt = $oci->prepare($sql);
        $this->assertInstanceOf(OCIStatement::class, $stmt);

        // use reflection to test values of protected properties
        $reflection = new \ReflectionClass($stmt);

        // stmt property
        $property = $reflection->getProperty('stmt');
        $property->setAccessible(true);
        $this->assertEquals('oci8 statement', $property->getValue($stmt));

        // conn property
        $property = $reflection->getProperty('conn');
        $property->setAccessible(true);
        $this->assertEquals($oci, $property->getValue($stmt));

        // attributes property
        $property = $reflection->getProperty('attributes');
        $property->setAccessible(true);
        $this->assertEquals([], $property->getValue($stmt));
    }

    public function test_prepare_fail()
    {
        global $OCIStatementStatus;
        $OCIStatementStatus = false;
        $sql = 'select * from table where id = ? and date = ?';
        $oci = new TestOCIStub;
        $this->expectException(OCIException::class);
        $stmt = $oci->prepare($sql);
    }

    public function test_query()
    {
        $sql = 'select * from table';
        $oci = new TestOCIStub;
        $stmt = $oci->query($sql);
        $this->assertInstanceOf(OCIStatement::class, $stmt);

        // use reflection to test values of protected properties
        $reflection = new \ReflectionClass($stmt);

        // stmt property
        $property = $reflection->getProperty('stmt');
        $property->setAccessible(true);
        $this->assertEquals('oci8 statement', $property->getValue($stmt));

        // conn property
        $property = $reflection->getProperty('conn');
        $property->setAccessible(true);
        $this->assertEquals($oci, $property->getValue($stmt));

        // attributes property
        $property = $reflection->getProperty('attributes');
        $property->setAccessible(true);
        $this->assertEquals([], $property->getValue($stmt));
    }

    public function test_query_with_mode_params()
    {
        $sql = 'select * from table';
        $oci = new TestOCIStub;
        $stmt = $oci->query($sql, PDO::FETCH_CLASS, 'stdClass', []);
        $this->assertInstanceOf(OCIStatement::class, $stmt);

        // use reflection to test values of protected properties
        $reflection = new \ReflectionClass($stmt);

        // stmt property
        $property = $reflection->getProperty('stmt');
        $property->setAccessible(true);
        $this->assertEquals('oci8 statement', $property->getValue($stmt));

        // conn property
        $property = $reflection->getProperty('conn');
        $property->setAccessible(true);
        $this->assertEquals($oci, $property->getValue($stmt));

        // attributes property
        $property = $reflection->getProperty('attributes');
        $property->setAccessible(true);
        $this->assertEquals([], $property->getValue($stmt));
    }

    public function test_query_fail()
    {
        global $OCIExecuteStatus;
        $OCIExecuteStatus = false;
        $sql = 'select * from table';
        $oci = new TestOCIStub;
        $stmt = $oci->query($sql);
        $this->assertFalse($stmt);
    }

    public function test_quote()
    {
        $this->assertFalse($this->oci->quote('String'));
        $this->assertFalse($this->oci->quote('String', PDO::PARAM_STR));
    }

    public function test_roll_back_in_transaction_passes()
    {
        $this->oci->beginTransaction();
        $this->assertTrue($this->oci->rollBack());
    }

    public function test_roll_back_in_transaction_fails()
    {
        global $OCITransactionStatus;
        $OCITransactionStatus = false;
        $this->expectException(OCIException::class);
        $this->oci->beginTransaction();
        $this->oci->rollBack();
    }

    public function test_roll_back_not_in_transaction()
    {
        $this->assertFalse($this->oci->rollBack());
    }

    public function test_set_attribute()
    {
        $attr = 12345;

        $this->oci->setAttribute($attr, 'value');
        $this->assertEquals('value', $this->oci->getAttribute($attr));
        $this->oci->setAttribute($attr, 4);
        $this->assertEquals(4, $this->oci->getAttribute($attr));
    }

    public function test_flip_execute_mode()
    {
        $this->assertEquals(\OCI_COMMIT_ON_SUCCESS, $this->oci->getExecuteMode());
        $this->oci->flipExecuteMode();
        $this->assertEquals(\OCI_NO_AUTO_COMMIT, $this->oci->getExecuteMode());
    }

    public function test_get_execute_mode()
    {
        $this->assertEquals(\OCI_COMMIT_ON_SUCCESS, $this->oci->getExecuteMode());
    }

    public function test_get_oci_resource()
    {
        $this->assertEquals('oci8', $this->oci->getOCIResource());
    }

    public function test_set_execute_mode_with_valid_mode()
    {
        $this->oci->setExecuteMode(\OCI_COMMIT_ON_SUCCESS);
        $this->assertEquals(\OCI_COMMIT_ON_SUCCESS, $this->oci->getExecuteMode());
        $this->oci->setExecuteMode(\OCI_NO_AUTO_COMMIT);
        $this->assertEquals(\OCI_NO_AUTO_COMMIT, $this->oci->getExecuteMode());
    }

    public function test_set_execute_mode_with_invalid_mode()
    {
        $this->expectException(OCIException::class);
        $this->oci->setExecuteMode('foo');
    }
}
