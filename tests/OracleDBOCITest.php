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

    public function tearDown(): void
    {
        m::close();
    }

    public function testConstructorSuccessWithPersistentConnection()
    {
        $oci = new OCI('dsn', null, null, [PDO::ATTR_PERSISTENT => 1]);
        $this->assertInstanceOf(OCI::class, $oci);
        $this->assertEquals(1, $oci->getAttribute(PDO::ATTR_PERSISTENT));
    }

    public function testConstructorSuccessWithoutPersistentConnection()
    {
        $oci = new OCI('dsn', null, null, [PDO::ATTR_PERSISTENT => 0]);
        $this->assertInstanceOf(OCI::class, $oci);
        $this->assertEquals(0, $oci->getAttribute(PDO::ATTR_PERSISTENT));
    }

    public function testConstructorFailWithPersistentConnection()
    {
        global $OCITransactionStatus;
        $OCITransactionStatus = false;
        $this->expectException(OCIException::class);
        $oci = new OCI('dsn', null, null, [PDO::ATTR_PERSISTENT => 1]);
    }

    public function testConstructorFailWithoutPersistentConnection()
    {
        global $OCITransactionStatus;
        $OCITransactionStatus = false;
        $this->expectException(OCIException::class);
        $oci = new OCI('dsn', null, null, [PDO::ATTR_PERSISTENT => 0]);
    }

    public function testDestructor()
    {
        global $OCITransactionStatus;

        $oci = new OCI('dsn', '', '');
        unset($oci);
        $this->assertFalse($OCITransactionStatus);
    }

    public function testBeginTransaction()
    {
        $result = $this->oci->beginTransaction();
        $this->assertTrue($result);

        $this->assertEquals(0, $this->oci->getExecuteMode());
    }

    public function testBeginTransactionAlreadyInTransaction()
    {
        $this->expectException(OCIException::class);
        $result = $this->oci->beginTransaction();
        $result = $this->oci->beginTransaction();
    }

    public function testCommitInTransactionPasses()
    {
        $this->oci->beginTransaction();
        $this->assertTrue($this->oci->commit());
    }

    public function testCommitInTransactionFails()
    {
        global $OCITransactionStatus;
        $OCITransactionStatus = false;
        $this->expectException(OCIException::class);
        $this->oci->beginTransaction();
        $this->oci->commit();
    }

    public function testCommitNotInTransaction()
    {
        $this->assertFalse($this->oci->commit());
    }

    public function testErrorCode()
    {
        $oci = new TestOCIStub();
        $this->assertNull($oci->errorCode());

        // use reflection to test values of protected properties
        $reflection = new \ReflectionClass($oci);

        // setErrorInfo
        $method = $reflection->getMethod('setErrorInfo');
        $method->setAccessible(true);
        $method->invoke($oci, '11111', '2222', 'Testing the errors');

        $this->assertEquals('11111', $oci->errorCode());
    }

    public function testErrorInfo()
    {
        $oci = new TestOCIStub();
        $this->assertEquals([0 => '', 1 => null, 2 => null], $oci->errorInfo());

        // use reflection to test values of protected properties
        $reflection = new \ReflectionClass($oci);

        // setErrorInfo
        $method = $reflection->getMethod('setErrorInfo');
        $method->setAccessible(true);
        $method->invoke($oci, '11111', '2222', 'Testing the errors');

        $this->assertEquals([0 => '11111', 1 => '2222', 2 => 'Testing the errors'], $oci->errorInfo());
    }

    public function testExec()
    {
        $sql = 'select * from table';
        $oci = new TestOCIStub();
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
        //conn property
        $property = $reflection->getProperty('conn');
        $property->setAccessible(true);
        $this->assertEquals($oci, $property->getValue($oci_stmt));

        //attributes property
        $property = $reflection->getProperty('attributes');
        $property->setAccessible(true);
        $this->assertEquals([], $property->getValue($oci_stmt));
    }

    public function testExecFails()
    {
        global $OCIExecuteStatus;
        $OCIExecuteStatus = false;
        $sql = 'select * from table';
        $oci = new TestOCIStub();
        $stmt = $oci->exec($sql);
        $this->assertFalse($stmt);
    }

    public function testGetAttributeForValidAttribute()
    {
        $this->assertEquals(1, $this->oci->getAttribute(PDO::ATTR_AUTOCOMMIT));
    }

    public function testGetAttributeForInvalidAttribute()
    {
        $nonExistantAttr = 12345;
        $this->assertEquals(null, $this->oci->getAttribute($nonExistantAttr));
    }

    public function testInTransactionWhileNotInTransaction()
    {
        $this->assertFalse($this->oci->inTransaction());
    }

    public function testInTransactionWhileInTransaction()
    {
        $this->oci->beginTransaction();
        $this->assertTrue($this->oci->inTransaction());
    }

    public function testLastInsertIDWithName()
    {
        $this->expectException(OCIException::class);
        $result = $this->oci->lastInsertID('foo');
    }

    public function testLastInsertIDWithoutName()
    {
        $this->expectException(OCIException::class);
        $result = $this->oci->lastInsertID();
    }

    public function testPrepareWithNonParameterQuery()
    {
        $sql = 'select * from table';
        $oci = new TestOCIStub();
        $stmt = $oci->prepare($sql);
        $this->assertInstanceOf(OCIStatement::class, $stmt);

        // use reflection to test values of protected properties
        $reflection = new \ReflectionClass($stmt);

        // stmt property
        $property = $reflection->getProperty('stmt');
        $property->setAccessible(true);
        $this->assertEquals('oci8 statement', $property->getValue($stmt));

        //conn property
        $property = $reflection->getProperty('conn');
        $property->setAccessible(true);
        $this->assertEquals($oci, $property->getValue($stmt));

        //attributes property
        $property = $reflection->getProperty('attributes');
        $property->setAccessible(true);
        $this->assertEquals([], $property->getValue($stmt));
    }

    public function testPrepareWithParameterQuery()
    {
        $sql = 'select * from table where id = ? and date = ?';
        $oci = new TestOCIStub();
        $stmt = $oci->prepare($sql);
        $this->assertInstanceOf(OCIStatement::class, $stmt);

        // use reflection to test values of protected properties
        $reflection = new \ReflectionClass($stmt);

        // stmt property
        $property = $reflection->getProperty('stmt');
        $property->setAccessible(true);
        $this->assertEquals('oci8 statement', $property->getValue($stmt));

        //conn property
        $property = $reflection->getProperty('conn');
        $property->setAccessible(true);
        $this->assertEquals($oci, $property->getValue($stmt));

        //attributes property
        $property = $reflection->getProperty('attributes');
        $property->setAccessible(true);
        $this->assertEquals([], $property->getValue($stmt));
    }

    public function testPrepareFail()
    {
        global $OCIStatementStatus;
        $OCIStatementStatus = false;
        $sql = 'select * from table where id = ? and date = ?';
        $oci = new TestOCIStub();
        $this->expectException(OCIException::class);
        $stmt = $oci->prepare($sql);
    }

    public function testQuery()
    {
        $sql = 'select * from table';
        $oci = new TestOCIStub();
        $stmt = $oci->query($sql);
        $this->assertInstanceOf(OCIStatement::class, $stmt);

        // use reflection to test values of protected properties
        $reflection = new \ReflectionClass($stmt);

        // stmt property
        $property = $reflection->getProperty('stmt');
        $property->setAccessible(true);
        $this->assertEquals('oci8 statement', $property->getValue($stmt));

        //conn property
        $property = $reflection->getProperty('conn');
        $property->setAccessible(true);
        $this->assertEquals($oci, $property->getValue($stmt));

        //attributes property
        $property = $reflection->getProperty('attributes');
        $property->setAccessible(true);
        $this->assertEquals([], $property->getValue($stmt));
    }

    public function testQueryWithModeParams()
    {
        $sql = 'select * from table';
        $oci = new TestOCIStub();
        $stmt = $oci->query($sql, PDO::FETCH_CLASS, 'stdClass', []);
        $this->assertInstanceOf(OCIStatement::class, $stmt);

        // use reflection to test values of protected properties
        $reflection = new \ReflectionClass($stmt);

        // stmt property
        $property = $reflection->getProperty('stmt');
        $property->setAccessible(true);
        $this->assertEquals('oci8 statement', $property->getValue($stmt));

        //conn property
        $property = $reflection->getProperty('conn');
        $property->setAccessible(true);
        $this->assertEquals($oci, $property->getValue($stmt));

        //attributes property
        $property = $reflection->getProperty('attributes');
        $property->setAccessible(true);
        $this->assertEquals([], $property->getValue($stmt));
    }

    public function testQueryFail()
    {
        global $OCIExecuteStatus;
        $OCIExecuteStatus = false;
        $sql = 'select * from table';
        $oci = new TestOCIStub();
        $stmt = $oci->query($sql);
        $this->assertFalse($stmt);
    }

    public function testQuote()
    {
        $this->assertFalse($this->oci->quote('String'));
        $this->assertFalse($this->oci->quote('String', PDO::PARAM_STR));
    }

    public function testRollBackInTransactionPasses()
    {
        $this->oci->beginTransaction();
        $this->assertTrue($this->oci->rollBack());
    }

    public function testRollBackInTransactionFails()
    {
        global $OCITransactionStatus;
        $OCITransactionStatus = false;
        $this->expectException(OCIException::class);
        $this->oci->beginTransaction();
        $this->oci->rollBack();
    }

    public function testRollBackNotInTransaction()
    {
        $this->assertFalse($this->oci->rollBack());
    }

    public function testSetAttribute()
    {
        $attr = 12345;

        $this->oci->setAttribute($attr, 'value');
        $this->assertEquals('value', $this->oci->getAttribute($attr));
        $this->oci->setAttribute($attr, 4);
        $this->assertEquals(4, $this->oci->getAttribute($attr));
    }

    public function testFlipExecuteMode()
    {
        $this->assertEquals(\OCI_COMMIT_ON_SUCCESS, $this->oci->getExecuteMode());
        $this->oci->flipExecuteMode();
        $this->assertEquals(\OCI_NO_AUTO_COMMIT, $this->oci->getExecuteMode());
    }

    public function testGetExecuteMode()
    {
        $this->assertEquals(\OCI_COMMIT_ON_SUCCESS, $this->oci->getExecuteMode());
    }

    public function testGetOCIResource()
    {
        $this->assertEquals('oci8', $this->oci->getOCIResource());
    }

    public function testSetExecuteModeWithValidMode()
    {
        $this->oci->setExecuteMode(\OCI_COMMIT_ON_SUCCESS);
        $this->assertEquals(\OCI_COMMIT_ON_SUCCESS, $this->oci->getExecuteMode());
        $this->oci->setExecuteMode(\OCI_NO_AUTO_COMMIT);
        $this->assertEquals(\OCI_NO_AUTO_COMMIT, $this->oci->getExecuteMode());
    }

    public function testSetExecuteModeWithInvalidMode()
    {
        $this->expectException(OCIException::class);
        $this->oci->setExecuteMode('foo');
    }
}
