<?php

namespace dokuwiki\plugin\sqlite;

class QuerySaver
{

    protected $db;
    protected $upstream;

    /**
     * @param string $dbname The database the queries are saved for
     */
    public function __construct($dbname)
    {
        $this->db = new SQLiteDB('sqlite', DOKU_PLUGIN . 'sqlite/db/');
        $this->upstream = $dbname;
    }

    /**
     * Save a query
     *
     * @param string $name
     * @param string $query
     */
    public function saveQuery($name, $query)
    {
        $sql = 'INSERT INTO queries (db, name, sql) VALUES (?, ?, ?)';
        $this->db->exec($sql, [$this->upstream, $name, $query]);
    }

    /**
     * Get a saved query
     *
     * @param string $name
     * @return string The SQL query
     */
    public function getQuery($name)
    {
        $sql = 'SELECT sql FROM queries WHERE db = ? AND name = ?';
        return $this->db->queryValue($sql, [$this->upstream, $name]);
    }

    /**
     * Delete a saved query
     *
     * @param string $name
     */
    public function deleteQuery($name)
    {
        $sql = 'DELETE FROM queries WHERE db = ? AND name = ?';
        $this->db->exec($sql, [$this->upstream, $name]);
    }

    /**
     * Get all saved queries
     *
     * @return array
     */
    public function getQueries()
    {
        $sql = 'SELECT name, sql FROM queries WHERE db = ?';
        return $this->db->queryAll($sql, [$this->upstream]);
    }
}
