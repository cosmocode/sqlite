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
        $this->assertInstanceOf(\PDO::class, $db->getPdo());
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

    public function testParameterHandling()
    {
        $db = new SQLiteDB('testdb', DOKU_PLUGIN . "sqlite/_test/db");

        $sql = "SELECT ? AS first, ? AS second, ? AS third";

        $result = $db->queryRecord($sql, ['one', 'two', 'three']);
        $this->assertEquals(['first' => 'one', 'second' => 'two', 'third' => 'three'], $result);

        $result = $db->queryRecord($sql, 'one', 'two', 'three');
        $this->assertEquals(['first' => 'one', 'second' => 'two', 'third' => 'three'], $result);

        $sql = "SELECT :first AS first, :second AS second, :third AS third";

        $result = $db->queryRecord($sql, ['first' => 'one', 'second' => 'two', 'third' => 'three']);
        $this->assertEquals(['first' => 'one', 'second' => 'two', 'third' => 'three'], $result);
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

    public function testQueryKeyValueList()
    {
        $db = new SQLiteDB('testdb', DOKU_PLUGIN . "sqlite/_test/db");

        // data has not unique keys, last entry should win
        $sql = "SELECT keyword, value FROM testdata ORDER BY tid";
        $result = $db->queryKeyValueList($sql);
        $this->assertArrayHasKey('music', $result);
        $this->assertEquals('Boring', $result['music']);

        // reverse is actually unique
        $sql = "SELECT value, keyword FROM testdata";
        $result = $db->queryKeyValueList($sql);
        $this->assertArrayHasKey('Boring', $result);
        $this->assertEquals('music', $result['Classic']);
    }
}
