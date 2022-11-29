<?php

namespace Jfelder\OracleDB\Tests;

use InvalidArgumentException;
use PDO;
use ReflectionClass;
use TestOCIStub;
use TestOCIStatementStub;
use Jfelder\OracleDB\OCI_PDO\OCIException;
use Jfelder\OracleDB\OCI_PDO\OCIStatement;
use Mockery as m;
use PHPUnit\Framework\TestCase;

include 'mocks/OCIMocks.php';
include 'mocks/OCIFunctions.php';

class OracleDBOCIStatementTest extends TestCase
{
    protected function setUp(): void
    {
        if (! extension_loaded('oci8')) {
            $this->markTestSkipped(
              'The oci8 extension is not available.'
            );
        } else {
            global $OCIStatementStatus, $OCIExecuteStatus, $OCIFetchStatus, $OCIFetchAllReturnEmpty, $OCIBindChangeStatus;

            $OCIStatementStatus = true;
            $OCIExecuteStatus = true;
            $OCIFetchStatus = true;
            $OCIFetchAllReturnEmpty = false;
            $OCIBindChangeStatus = false;

            $this->oci = m::mock(new TestOCIStub('', null, null, [PDO::ATTR_CASE => PDO::CASE_LOWER]));
            $this->stmt = m::mock(new TestOCIStatementStub('oci8 statement', $this->oci, '', [4321 => 'attributeValue']));

            // fake result sets for all the fetch calls
            $this->resultUpperArray = ['FNAME' => 'Test', 'LNAME' => 'Testerson', 'EMAIL' => 'tester@testing.com'];
            $this->resultUpperObject = (object) $this->resultUpperArray;
            $this->resultLowerArray = array_change_key_case($this->resultUpperArray, \CASE_LOWER);
            $this->resultLowerObject = (object) $this->resultLowerArray;

            $this->resultNumArray = [0 => 'Test', 1 => 'Testerson', 2 => 'tester@testing.com'];

            $this->resultBothUpperArray = [0 => 'Test', 1 => 'Testerson', 2 => 'tester@testing.com', 'FNAME' => 'Test', 'LNAME' => 'Testerson', 'EMAIL' => 'tester@testing.com'];
            $this->resultBothLowerArray = array_change_key_case($this->resultBothUpperArray, \CASE_LOWER);

            $this->resultAllUpperArray = [$this->resultUpperArray];
            $this->resultAllUpperObject = [$this->resultUpperObject];
            $this->resultAllLowerArray = [$this->resultLowerArray];
            $this->resultAllLowerObject = [$this->resultLowerObject];

            $this->resultAllNumArray = [$this->resultNumArray];

            $this->resultAllBothUpperArray = [$this->resultBothUpperArray];
            $this->resultAllBothLowerArray = [$this->resultBothLowerArray];
        }
    }

    public function tearDown(): void
    {
        m::close();
    }

    public function testConstructor()
    {
        $oci = new TestOCIStub();
        $ocistmt = new OCIStatement('oci8 statement', $oci);

        // use reflection to test values of protected properties
        $reflection = new ReflectionClass($ocistmt);

        // stmt property
        $property = $reflection->getProperty('stmt');
        $property->setAccessible(true);
        $this->assertEquals('oci8 statement', $property->getValue($ocistmt));

        //conn property
        $property = $reflection->getProperty('conn');
        $property->setAccessible(true);
        $this->assertEquals($oci, $property->getValue($ocistmt));

        //attributes property
        $property = $reflection->getProperty('attributes');
        $property->setAccessible(true);
        $this->assertEquals([], $property->getValue($ocistmt));
    }

    public function testConstructorWithoutValidStatementPassignIn()
    {
        global $OCIStatementStatus;
        $OCIStatementStatus = false;
        $this->expectException(OCIException::class);
        $ocistmt = new OCIStatement('oci8 statement', new TestOCIStub());
    }

    public function testDestructor()
    {
        global $OCIStatementStatus;
        $ocistmt = new OCIStatement('oci8 statement', new TestOCIStub());
        unset($ocistmt);
        $this->assertFalse($OCIStatementStatus);
    }

    public function testBindColumnWithColumnName()
    {
        $stmt = new TestOCIStatementStub('oci8 statement', $this->oci, 'sql', []);
        $holder = '';
        $this->expectException(InvalidArgumentException::class);
        $stmt->bindColumn('holder', $holder, PDO::PARAM_STR);
    }

    public function testBindColumnWithColumnNumberLessThanOne()
    {
        $stmt = new TestOCIStatementStub('oci8 statement', $this->oci, 'sql', []);
        $holder = '';
        $this->expectException(InvalidArgumentException::class);
        $stmt->bindColumn(0, $holder, PDO::PARAM_STR);
    }

    public function testBindColumnWithInvalidDataType()
    {
        $stmt = new TestOCIStatementStub('oci8 statement', $this->oci, 'sql', []);
        $holder = '';
        $nonExistantDataType = 12345;
        $this->expectException(InvalidArgumentException::class);
        $stmt->bindColumn(1, $holder, $nonExistantDataType);
    }

    public function testBindColumnSuccess()
    {
        $stmt = new TestOCIStatementStub('oci8 statement', $this->oci, 'sql', []);
        $holder = '';
        $this->assertTrue($stmt->bindColumn(1, $holder, PDO::PARAM_STR, 40));

        $reflection = new ReflectionClass($stmt);

        // bindings property
        $property = $reflection->getProperty('bindings');
        $property->setAccessible(true);
        $this->assertEquals([1 => ['var' => $holder, 'data_type' => PDO::PARAM_STR, 'max_length' => 40, 'driverdata' => null]], $property->getValue($stmt));
    }

    public function testBindParamWithValidDataType()
    {
        global $OCIBindChangeStatus;
        $OCIBindChangeStatus = true;
        $variable = '';

        $stmt = new TestOCIStatementStub(true, new TestOCIStub(), '', []);
        $this->assertTrue($stmt->bindParam('param', $variable));
        $this->assertEquals('oci_bind_by_name', $variable);
    }

    public function testBindParamWithInvalidDataType()
    {
        $variable = '';
        $nonExistantDataType = 12345;
        $this->expectException(InvalidArgumentException::class);

        $stmt = new TestOCIStatementStub(true, new TestOCIStub(), '', []);
        $stmt->bindParam('param', $variable, $nonExistantDataType);
    }

    public function testBindParamWithReturnDataType()
    {
        global $OCIBindChangeStatus;
        $OCIBindChangeStatus = true;
        $variable = '';

        $stmt = new TestOCIStatementStub(true, new TestOCIStub(), '', []);
        $this->assertTrue($stmt->bindParam('param', $variable, PDO::PARAM_INPUT_OUTPUT));
        $this->assertEquals('oci_bind_by_name', $variable);
    }

    public function testBindValueWithValidDataType()
    {
        $this->assertTrue($this->stmt->bindValue('param', 'hello'));
    }

    public function testBindValueWithNullDataType()
    {
        global $OCIBindByNameTypeReceived;
        $this->assertTrue($this->stmt->bindValue('param', null, PDO::PARAM_NULL));
        $this->assertSame(\SQLT_CHR, $OCIBindByNameTypeReceived);
    }

    public function testBindValueWithInvalidDataType()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->stmt->bindValue(0, 'hello', 8);
    }

    // todo update this test once this method has been implemented
    public function testCloseCursor()
    {
        $this->assertTrue($this->stmt->closeCursor());
    }

    public function testColumnCount()
    {
        $this->assertEquals(1, $this->stmt->columnCount());
    }

    public function testDebugDumpParamsWhenNothingHasBeenSet()
    {
        $expectedOutput = print_r(['sql' => '', 'params' => []], true);

        $this->expectOutputString($expectedOutput);

        $this->assertTrue($this->stmt->debugDumpParams());
    }

    public function testDebugDumpParamsWhenThingsHaveBeenSet()
    {
        global $OCIBindChangeStatus;
        $OCIBindChangeStatus = false;

        $sql = 'select * from table where id = :0 and name = :1';
        $var = 'Hello';
        $expectedOutput = print_r(
            [
                'sql' => $sql,
                'params' => [
                    [
                        'paramno' => 0,
                        'name' => ':0',
                        'value' => $var,
                        'is_param' => 1,
                        'param_type' => PDO::PARAM_INPUT_OUTPUT
                    ],
                    [
                        'paramno' => 1,
                        'name' => ':1',
                        'value' => 'hi',
                        'is_param' => 1,
                        'param_type' => PDO::PARAM_STR,
                    ],
                ]
            ],
            true
        );

        $stmt = new TestOCIStatementStub(true, true, $sql, []);
        $stmt->bindParam(0, $var, PDO::PARAM_INPUT_OUTPUT);
        $stmt->bindValue(1, 'hi');

        $this->expectOutputString($expectedOutput);

        $this->assertTrue($stmt->debugDumpParams());
    }

    public function testErrorCode()
    {
        $ocistmt = new TestOCIStatementStub(true, '', '', []);
        $this->assertNull($ocistmt->errorCode());

        // use reflection to test values of protected properties
        $reflection = new ReflectionClass($ocistmt);

        // setErrorInfo
        $method = $reflection->getMethod('setErrorInfo');
        $method->setAccessible(true);
        $method->invoke($ocistmt, '11111', '2222', 'Testing the errors');

        $this->assertEquals('11111', $ocistmt->errorCode());
    }

    public function testErrorInfo()
    {
        $ocistmt = new TestOCIStatementStub(true, '', '', []);
        $this->assertEquals([0 => '', 1 => null, 2 => null], $ocistmt->errorInfo());

        // use reflection to test values of protected properties
        $reflection = new ReflectionClass($ocistmt);

        // setErrorInfo
        $method = $reflection->getMethod('setErrorInfo');
        $method->setAccessible(true);
        $method->invoke($ocistmt, '11111', '2222', 'Testing the errors');

        $this->assertEquals([0 => '11111', 1 => '2222', 2 => 'Testing the errors'], $ocistmt->errorInfo());
    }

    public function testExecutePassesWithParameters()
    {
        $this->assertTrue($this->stmt->execute([0 => 1]));
    }

    public function testExecutePassesWithoutParameters()
    {
        $this->assertTrue($this->stmt->execute());
    }

    public function testExecuteFailesWithParameters()
    {
        global $OCIExecuteStatus;
        $OCIExecuteStatus = false;
        $this->assertFalse($this->stmt->execute([0 => 1]));
        $this->assertEquals('07000', $this->stmt->errorCode());
    }

    public function testExecuteFailesWithoutParameters()
    {
        global $OCIExecuteStatus;
        $OCIExecuteStatus = false;
        $this->assertFalse($this->stmt->execute());
        $this->assertEquals('07000', $this->stmt->errorCode());
    }

    public function testFetchWithBindColumn()
    {
        $this->oci->setAttribute(PDO::ATTR_CASE, PDO::CASE_LOWER);
        $stmt = new TestOCIStatementStub('oci8 statement', $this->oci, 'sql', []);
        $holder = 'dad';
        $this->assertTrue($stmt->bindColumn(1, $holder, PDO::PARAM_STR, 40));

        $reflection = new ReflectionClass($stmt);

        // bindings property
        $property = $reflection->getProperty('bindings');
        $property->setAccessible(true);
        $this->assertEquals([1 => ['var' => $holder, 'data_type' => PDO::PARAM_STR, 'max_length' => 40, 'driverdata' => null]], $property->getValue($stmt));

        $obj = $stmt->fetch(PDO::FETCH_CLASS);

        $this->assertEquals([1 => ['var' => $holder, 'data_type' => PDO::PARAM_STR, 'max_length' => 40, 'driverdata' => null]], $property->getValue($stmt));

        $this->assertEquals($obj->fname, $holder);
    }

    public function testFetchSuccessReturnArray()
    {
        // return lower case
        $this->oci->setAttribute(PDO::ATTR_CASE, PDO::CASE_LOWER);
        $this->assertEquals($this->resultLowerArray, $this->stmt->fetch(PDO::FETCH_ASSOC));
        $this->assertEquals($this->resultBothLowerArray, $this->stmt->fetch(PDO::FETCH_BOTH));

        // return upper cased keyed object
        $this->oci->setAttribute(PDO::ATTR_CASE, PDO::CASE_UPPER);
        $this->assertEquals($this->resultUpperArray, $this->stmt->fetch(PDO::FETCH_ASSOC));
        $this->assertEquals($this->resultBothUpperArray, $this->stmt->fetch(PDO::FETCH_BOTH));

        // return natural keyed object, in oracle that is upper case
        $this->oci->setAttribute(PDO::ATTR_CASE, PDO::CASE_NATURAL);
        $this->assertEquals($this->resultUpperArray, $this->stmt->fetch(PDO::FETCH_ASSOC));
        $this->assertEquals($this->resultBothUpperArray, $this->stmt->fetch(PDO::FETCH_BOTH));

        $this->assertEquals($this->resultNumArray, $this->stmt->fetch(PDO::FETCH_NUM));
    }

    public function testFetchSuccessReturnObject()
    {
        // return lower cased keyed object
        $this->oci->setAttribute(PDO::ATTR_CASE, PDO::CASE_LOWER);
        $this->assertEquals($this->resultLowerObject, $this->stmt->fetch(PDO::FETCH_CLASS));

        // return upper cased keyed object
        $this->oci->setAttribute(PDO::ATTR_CASE, PDO::CASE_UPPER);
        $this->assertEquals($this->resultUpperObject, $this->stmt->fetch(PDO::FETCH_CLASS));

        // return natural keyed object, in oracle that is upper case
        $this->oci->setAttribute(PDO::ATTR_CASE, PDO::CASE_NATURAL);
        $this->assertEquals($this->resultUpperObject, $this->stmt->fetch(PDO::FETCH_CLASS));
    }

    public function testFetchFail()
    {
        global $OCIFetchStatus;
        $OCIFetchStatus = false;
        $this->assertFalse($this->stmt->fetch());
        $this->assertEquals('07000', $this->stmt->errorCode());
    }

    public function testFetchAllWithNoArg()
    {
        // return lower cased keyed object
        $this->oci->setAttribute(PDO::ATTR_CASE, PDO::CASE_LOWER);
        $this->assertEquals($this->resultAllLowerObject, $this->stmt->fetchAll());

        // return upper cased keyed object
        $this->oci->setAttribute(PDO::ATTR_CASE, PDO::CASE_UPPER);
        $this->assertEquals($this->resultAllUpperObject, $this->stmt->fetchAll());

        // return natural keyed object, in oracle that is upper case
        $this->oci->setAttribute(PDO::ATTR_CASE, PDO::CASE_NATURAL);
        $this->assertEquals($this->resultAllUpperObject, $this->stmt->fetchAll());
    }

    public function testFetchAllReturnArray()
    {
        // return lower case
        $this->oci->setAttribute(PDO::ATTR_CASE, PDO::CASE_LOWER);
        $this->assertEquals($this->resultAllLowerArray, $this->stmt->fetchAll(PDO::FETCH_ASSOC));

        // return upper cased keyed object
        $this->oci->setAttribute(PDO::ATTR_CASE, PDO::CASE_UPPER);
        $this->assertEquals($this->resultAllUpperArray, $this->stmt->fetchAll(PDO::FETCH_ASSOC));

        // return natural keyed object, in oracle that is upper case
        $this->oci->setAttribute(PDO::ATTR_CASE, PDO::CASE_NATURAL);
        $this->assertEquals($this->resultAllUpperArray, $this->stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function testFetchAllReturnObject()
    {
        // return lower cased keyed object
        $this->oci->setAttribute(PDO::ATTR_CASE, PDO::CASE_LOWER);
        $this->assertEquals($this->resultAllLowerObject, $this->stmt->fetchAll(PDO::FETCH_CLASS));

        // return upper cased keyed object
        $this->oci->setAttribute(PDO::ATTR_CASE, PDO::CASE_UPPER);
        $this->assertEquals($this->resultAllUpperObject, $this->stmt->fetchAll(PDO::FETCH_CLASS));

        // return natural keyed object, in oracle that is upper case
        $this->oci->setAttribute(PDO::ATTR_CASE, PDO::CASE_NATURAL);
        $this->assertEquals($this->resultAllUpperObject, $this->stmt->fetchAll(PDO::FETCH_CLASS));
    }

    public function testFetchAllWhenEmptyResultSet()
    {
        global $OCIFetchAllReturnEmpty;
        $OCIFetchAllReturnEmpty = true;
        $this->assertSame([], $this->stmt->fetchAll());
    }

    public function testFetchAllFailWithInvalidFetchStyle()
    {
        $invalidMode = PDO::FETCH_BOTH;
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid fetch style requested: '.$invalidMode.'. Only PDO::FETCH_CLASS and PDO::FETCH_ASSOC suported.');
        $this->stmt->fetchAll($invalidMode);
    }

    public function testFetchColumnWithNoArg()
    {
        $this->assertEquals($this->resultNumArray[0], $this->stmt->fetchColumn());
    }

    public function testFetchColumnWithColumnNumber()
    {
        $this->assertEquals($this->resultNumArray[1], $this->stmt->fetchColumn(1));
    }

    public function testFetchObject()
    {
        // return lower cased keyed object
        $this->oci->setAttribute(PDO::ATTR_CASE, PDO::CASE_LOWER);
        $this->assertEquals($this->resultLowerObject, $this->stmt->fetchObject());

        // return upper cased keyed object
        $this->oci->setAttribute(PDO::ATTR_CASE, PDO::CASE_UPPER);
        $this->assertEquals($this->resultUpperObject, $this->stmt->fetchObject());

        // return natural keyed object, in oracle that is upper case
        $this->oci->setAttribute(PDO::ATTR_CASE, PDO::CASE_NATURAL);
        $this->assertEquals($this->resultUpperObject, $this->stmt->fetchObject());
    }

    public function testGetAttributeForValidAttribute()
    {
        $this->assertEquals('attributeValue', $this->stmt->getAttribute(4321));
    }

    public function testGetAttributeForInvalidAttribute()
    {
        $this->assertEquals(null, $this->stmt->getAttribute(12345));
    }

    public function testGetColumnMeta()
    {
        $expected = ['native_type' => 1, 'driver:decl_type' => 1,
            'name' => 1, 'len' => 1, 'precision' => 1, ];

        $result = $this->stmt->getColumnMeta(0);
        $this->assertEquals($expected, $result);
    }

    public function testNextRowset()
    {
        $this->assertTrue($this->stmt->nextRowset());
    }

    public function testRowCount()
    {
        $this->assertEquals(1, $this->stmt->rowCount());
    }

    public function testSetAttribute()
    {
        $attr = PDO::ATTR_DEFAULT_FETCH_MODE;
        $value = PDO::FETCH_CLASS;

        $this->assertTrue($this->stmt->setAttribute($attr, $value));
        $this->assertEquals($value, $this->stmt->getAttribute($attr));
    }

    public function testSetFetchMode()
    {
        $this->assertTrue($this->stmt->setFetchMode(PDO::FETCH_CLASS));
    }

    public function testGetOCIResource()
    {
        $this->assertEquals('oci8 statement', $this->stmt->getOCIResource());
    }
}
