<?php /** @noinspection SqlDialectInspection */

namespace dokuwiki\plugin\sqlite\test;

use dokuwiki\plugin\sqlite\SQLiteDB;
use DokuWikiTest;

/**
 * Test the new SQLiteDB class
 *
 * @group plugin_sqlite
 * @group plugins
 */
class SQLiteDBTest extends DokuWikiTest
{

    protected $res;

    /** @inheritdoc */
    public function setUp(): void
    {
        global $conf;
        $this->pluginsEnabled[] = 'sqlite';

        // reset database before each test
        if (file_exists($conf['metadir'] . '/testdb.sqlite3')) {
            unlink($conf['metadir'] . '/testdb.sqlite3');
        }

        parent::setUp();
    }

    public function testDB()
    {
        $db = new SQLiteDB('testdb', DOKU_PLUGIN . "sqlite/_test/db");
        $this->assertInstanceOf(\PDO::class, $db->pdo());
    }

    public function testQuery()
    {
        $db = new SQLiteDB('testdb', DOKU_PLUGIN . "sqlite/_test/db");
        $sql = "SELECT * FROM testdata WHERE keyword=?";

        $stmt = $db->query($sql, ['music']);
        $this->assertInstanceOf(\PDOStatement::class, $stmt);
        $result = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(5, $result);
        $stmt->closeCursor();
    }

    public function testExec()
    {
        $db = new SQLiteDB('testdb', DOKU_PLUGIN . "sqlite/_test/db");

        $sql = "INSERT INTO testdata (keyword, value) VALUES (?, ?)";
        $insid = $db->exec($sql, ['test', 'test']);
        $this->assertEquals(11, $insid);

        $sql = "UPDATE testdata SET value=? WHERE keyword=?";
        $affected = $db->exec($sql, ['test2', 'music']);
        $this->assertEquals(5, $affected);

    }

    public function testQueryAll()
    {
        $db = new SQLiteDB('testdb', DOKU_PLUGIN . "sqlite/_test/db");
        $sql = "SELECT * FROM testdata WHERE keyword=?";

        $result = $db->queryAll($sql, ['music']);
        $this->assertCount(5, $result);
        $this->assertArrayHasKey('keyword', $result[0]);
        $this->assertArrayHasKey('value', $result[0]);
    }

    public function testQueryRecord()
    {
        $db = new SQLiteDB('testdb', DOKU_PLUGIN . "sqlite/_test/db");
        $sql = "SELECT * FROM testdata WHERE tid=?";

        $result = $db->queryRecord($sql, [4]);
        $this->assertEquals(['tid' => 4, 'keyword' => 'music', 'value' => 'Classic'], $result);
    }

    public function testSaveRecord()
    {
        $db = new SQLiteDB('testdb', DOKU_PLUGIN . "sqlite/_test/db");

        $record = [
            'tid' => 4,
            'keyword' => 'music',
            'value' => 'New Classic',
        ];

        $newrecord = $db->saveRecord('testdata', $record, false);
        $this->assertNull($newrecord);

        $newrecord = $db->saveRecord('testdata', $record, true);
        $this->assertEquals($record, $newrecord);

        $another = [
            'keyword' => 'music',
            'value' => 'Alternative Rock',
        ];
        $newrecord = $db->saveRecord('testdata', $another, false);
        $this->assertEquals(11, $newrecord['tid']);
    }

    public function testQueryValue()
    {
        $db = new SQLiteDB('testdb', DOKU_PLUGIN . "sqlite/_test/db");
        $sql = "SELECT value FROM testdata WHERE tid=?";

        $result = $db->queryValue($sql, [4]);
        $this->assertEquals('Classic', $result);
    }

}
