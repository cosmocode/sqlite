<?php /** @noinspection SqlDialectInspection */

namespace dokuwiki\plugin\sqlite\test;

use DokuWikiTest;

/**
 * Test the helper plugin for backwards compatibility
 *
 * @group plugin_sqlite
 * @group plugins
 */
class HelperTest extends DokuWikiTest
{

    protected $res;

    /** @inheritdoc */
    public function setUp(): void
    {
        $this->pluginsEnabled[] = 'data';
        $this->pluginsEnabled[] = 'sqlite';
        parent::setUp();
    }

    /**
     * @return \helper_plugin_sqlite
     * @throws \Exception when databsse is not initialized
     */
    protected function getSqliteHelper()
    {
        /** @var $SqliteHelper \helper_plugin_sqlite */
        $SqliteHelper = plugin_load('helper', 'sqlite');
        if (!$SqliteHelper->init("testdb", DOKU_PLUGIN . "sqlite/_test/db")) {
            throw new \Exception('Initializing Sqlite Helper fails!');
        }
        return $SqliteHelper;
    }

    /**
     * @return \helper_plugin_sqlite
     */
    protected function getResultSelectquery()
    {
        $SqliteHelper = $this->getSqliteHelper();

        $sql = "SELECT * FROM testdata WHERE keyword='music'";
        $res = $SqliteHelper->query($sql);
        $this->res = $res;
        return $SqliteHelper;
    }

    /**
     * @return \helper_plugin_sqlite
     */
    protected function getResultInsertquery()
    {
        /** @var $SqliteHelper \helper_plugin_sqlite */
        $SqliteHelper = $this->getSqliteHelper();

        $sql = "INSERT INTO testdata VALUES(20,'glass','Purple')";
        $res = $SqliteHelper->query($sql);
        $this->res = $res;
        return $SqliteHelper;
    }

    public function testSQLstring2array()
    {
        $SqliteHelper = $this->getSqliteHelper();

        $sqlstring1 = "INSERT INTO data VALUES('text','text ;text')";
        $sqlarray1 = array("INSERT INTO data VALUES('text','text ;text')");

        $sqlstring2 = "INSERT INTO data VALUES('text','text ;text');INSERT INTO data VALUES('text','te''xt ;text');";
        $sqlarray2 = array(
            "INSERT INTO data VALUES('text','text ;text')",
            "INSERT INTO data VALUES('text','te''xt ;text')"
        );

        $this->assertEquals($sqlarray1, $SqliteHelper->SQLstring2array($sqlstring1));
        $this->assertEquals($sqlarray2, $SqliteHelper->SQLstring2array($sqlstring2));
    }

    public function testSQLstring2array_complex()
    {
        $SqliteHelper = $this->getSqliteHelper();

        $input = <<<EOF
-- This is test data for the SQLstring2array function

INSERT INTO foo SET bar = '
some multi''d line string
-- not a comment
';

SELECT * FROM bar;
SELECT * FROM bax;

SELECT * FROM bar; SELECT * FROM bax;
";
EOF;

        $statements = $SqliteHelper->SQLstring2array($input);

        $this->assertCount(6, $statements, 'number of detected statements');

        $this->assertStringContainsString('some multi\'\'d line string', $statements[0]);
        $this->assertStringContainsString('-- not a comment', $statements[0]);

        $this->assertEquals('SELECT * FROM bar', $statements[1]);
        $this->assertEquals('SELECT * FROM bax', $statements[2]);
        $this->assertEquals('SELECT * FROM bar', $statements[3]);
        $this->assertEquals('SELECT * FROM bax', $statements[4]);
    }

    public function testQuoteAndJoin()
    {
        /** @var $SqliteHelper \helper_plugin_sqlite */
        $SqliteHelper = $this->getSqliteHelper();

        $string = "Co'mpl''ex \"st'\"ring";
        $vals = array($string, $string);
        $quotedstring = "'Co''mpl''''ex \"st''\"ring','Co''mpl''''ex \"st''\"ring'";
        $this->assertEquals($quotedstring, $SqliteHelper->quote_and_join($vals));
    }

    public function testQuoteString()
    {
        /** @var $SqliteHelper \helper_plugin_sqlite */
        $SqliteHelper = $this->getSqliteHelper();

        $string = "Co'mpl''ex \"st'\"ring";
        $quotedstring = "'Co''mpl''''ex \"st''\"ring'";
        $this->assertEquals($quotedstring, $SqliteHelper->quote_string($string));
    }

    function testEscapeString()
    {
        /** @var $SqliteHelper \helper_plugin_sqlite */
        $SqliteHelper = $this->getSqliteHelper();

        $string = "Co'mpl''ex \"st'\"ring";
        $quotedstring = "Co''mpl''''ex \"st''\"ring";
        $this->assertEquals($quotedstring, $SqliteHelper->escape_string($string));
    }

    function testQuerySelect()
    {
        $SqliteHelper = $this->getResultSelectquery();
        $this->assertNotEquals(false, $this->res);

        //close cursor
        $SqliteHelper->res_close($this->res);
    }

    function testRes2arrAssoc()
    {
        $SqliteHelper = $this->getResultSelectquery();

        $resultassoc = array(
            0 => array('tid' => 3, 'keyword' => 'music', 'value' => 'happy'),
            1 => array('tid' => 4, 'keyword' => 'music', 'value' => 'Classic'),
            2 => array('tid' => 5, 'keyword' => 'music', 'value' => 'Pop'),
            3 => array('tid' => 8, 'keyword' => 'music', 'value' => 'Pink'),
            4 => array('tid' => 10, 'keyword' => 'music', 'value' => 'Boring')
        );

        $this->assertEquals($resultassoc, $SqliteHelper->res2arr($this->res, $assoc = true));
        $this->assertEquals(array(), $SqliteHelper->res2arr(false));
    }

    function testRes2arrNum()
    {
        $SqliteHelper = $this->getResultSelectquery();

        $resultnum = array(
            0 => array(0 => 3, 1 => 'music', 2 => 'happy'),
            1 => array(0 => 4, 1 => 'music', 2 => 'Classic'),
            2 => array(0 => 5, 1 => 'music', 2 => 'Pop'),
            3 => array(0 => 8, 1 => 'music', 2 => 'Pink'),
            4 => array(0 => 10, 1 => 'music', 2 => 'Boring')
        );

        $this->assertEquals($resultnum, $SqliteHelper->res2arr($this->res, $assoc = false));
    }

    function testRes2row()
    {
        $SqliteHelper = $this->getResultSelectquery();

        $result0 = array('tid' => 3, 'keyword' => 'music', 'value' => 'happy',);
        $result2 = array('tid' => 5, 'keyword' => 'music', 'value' => 'Pop',);

        $this->assertEquals(false, $SqliteHelper->res2row(false));
        $this->assertEquals($result0, $SqliteHelper->res2row($this->res));
        $SqliteHelper->res2row($this->res); // skip one row
        $this->assertEquals($result2, $SqliteHelper->res2row($this->res));

        //close cursor
        $SqliteHelper->res_close($this->res);
    }

    function testRes2single()
    {
        $SqliteHelper = $this->getResultSelectquery();

        $result1 = 3;
        $result2 = 4;

        $this->assertEquals(false, $SqliteHelper->res2single(false));
        $this->assertEquals($result1, $SqliteHelper->res2single($this->res));
        $this->assertEquals($result2, $SqliteHelper->res2single($this->res)); //next row

        //close cursor
        $SqliteHelper->res_close($this->res);
    }

    function testResFetchArray()
    {
        $SqliteHelper = $this->getResultSelectquery();

        $result0 = array(0 => 3, 1 => 'music', 2 => 'happy');
        $result1 = array(0 => 4, 1 => 'music', 2 => 'Classic');

        $this->assertEquals(false, $SqliteHelper->res_fetch_array(false));
        $this->assertEquals($result0, $SqliteHelper->res_fetch_array($this->res));
        $this->assertEquals($result1, $SqliteHelper->res_fetch_array($this->res)); //next row

        //close cursor
        $SqliteHelper->res_close($this->res);
    }

    function testFetchAssoc()
    {
        $SqliteHelper = $this->getResultSelectquery();

        $result0 = array('tid' => 3, 'keyword' => 'music', 'value' => 'happy',);
        $result1 = array('tid' => 4, 'keyword' => 'music', 'value' => 'Classic');

        $this->assertEquals(false, $SqliteHelper->res_fetch_assoc(false));
        $this->assertEquals($result0, $SqliteHelper->res_fetch_assoc($this->res));
        $this->assertEquals($result1, $SqliteHelper->res_fetch_assoc($this->res)); //next row

        //close cursor
        $SqliteHelper->res_close($this->res);
    }

    function testRes2count()
    {
        $SqliteHelper = $this->getResultSelectquery();

        $result = 5;

        $this->assertSame(0, $SqliteHelper->res2count(false));
        $this->assertEquals($result, $SqliteHelper->res2count($this->res));
    }

    function testCountChanges()
    {
        $SqliteHelper = $this->getResultInsertquery();

        $this->assertSame(0, $SqliteHelper->countChanges(false), 'Empty result');
        $this->assertEquals(1, $SqliteHelper->countChanges($this->res), 'Insert result');
    }

    function testSerialize()
    {
        $SqliteHelper = $this->getSqliteHelper();

        $res = $SqliteHelper->query('SELECT * FROM testdata');
        $this->assertNotFalse($res);
        $SqliteHelper->res_close($res);

        $obj = unserialize(serialize($SqliteHelper));

        $res = $obj->query('SELECT * FROM testdata');
        $this->assertNotFalse($res);
        $obj->res_close($res);
    }
}
