<?php

/**
 * @noinspection SqlNoDataSourceInspection
 * @noinspection SqlDialectInspection
 * @noinspection PhpComposerExtensionStubsInspection
 */

use dokuwiki\Extension\Plugin;
use dokuwiki\plugin\sqlite\SQLiteDB;
use dokuwiki\plugin\sqlite\Tools;

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols, PSR1.Classes.ClassDeclaration.MultipleClasses
// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps

/**
 * For compatibility with previous adapter implementation.
 */
if (!defined('DOKU_EXT_PDO')) define('DOKU_EXT_PDO', 'pdo');
class helper_plugin_sqlite_adapter_dummy
{
    public function getName()
    {
        return DOKU_EXT_PDO;
    }

    public function setUseNativeAlter($set)
    {
    }
}

/**
 * DokuWiki Plugin sqlite (Helper Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Andreas Gohr <gohr@cosmocode.de>
 * @deprecated 2023-03-15
 */
class helper_plugin_sqlite extends Plugin
{
    /** @var SQLiteDB|null */
    protected $adapter;

    /** @var array result cache */
    protected $data;

    /**
     * constructor
     */
    public function __construct()
    {
        if (!$this->existsPDOSqlite()) {
            msg('PDO SQLite support missing in this PHP install - The sqlite plugin will not work', -1);
        }
        $this->adapter = new helper_plugin_sqlite_adapter_dummy();
    }

    /**
     * Get the current Adapter
     * @return SQLiteDB|null
     */
    public function getAdapter()
    {
        return $this->adapter;
    }

    /**
     * Keep separate instances for every call to keep database connections
     */
    public function isSingleton()
    {
        return false;
    }

    /**
     * check availabilty of PHP PDO sqlite3
     */
    public function existsPDOSqlite()
    {
        if (class_exists('pdo')) {
            return in_array('sqlite', \PDO::getAvailableDrivers());
        }
        return false;
    }

    /**
     * Initializes and opens the database
     *
     * Needs to be called right after loading this helper plugin
     *
     * @param string $dbname
     * @param string $updatedir - Database update infos
     * @return bool
     */
    public function init($dbname, $updatedir)
    {
        if (!defined('DOKU_UNITTEST')) { // for now we don't want to trigger the deprecation warning in the tests
            dbg_deprecated(SQLiteDB::class);
        }

        try {
            $this->adapter = new SQLiteDB($dbname, $updatedir, $this);
        } catch (Exception $e) {
            msg('SQLite: ' . $e->getMessage(), -1);
            return false;
        }
        return true;
    }

    /**
     * This is called from the adapter itself for backwards compatibility
     *
     * @param SQLiteDB $adapter
     * @return void
     */
    public function setAdapter($adapter)
    {
        $this->adapter = $adapter;
    }

    /**
     * Registers a User Defined Function for use in SQL statements
     */
    public function create_function($function_name, $callback, $num_args)
    {
        $this->adapter->getPdo()->sqliteCreateFunction($function_name, $callback, $num_args);
    }

    // region query and result handling functions

    /**
     * Convenience function to run an INSERT OR REPLACE operation
     *
     * The function takes a key-value array with the column names in the key and the actual value in the value,
     * build the appropriate query and executes it.
     *
     * @param string $table the table the entry should be saved to (will not be escaped)
     * @param array $entry A simple key-value pair array (only values will be escaped)
     * @return bool
     */
    public function storeEntry($table, $entry)
    {
        try {
            $this->adapter->saveRecord($table, $entry);
        } catch (\Exception $e) {
            msg('SQLite: ' . $e->getMessage(), -1);
            return false;
        }

        return true;
    }

    /**
     * Execute a query with the given parameters.
     *
     * Takes care of escaping
     *
     *
     * @param string ...$args - the arguments of query(), the first is the sql and others are values
     */
    public function query(...$args)
    {
        // clear the cache
        $this->data = null;

        try {
            $sql = $this->prepareSql($args);
            return $this->adapter->query($sql);
        } catch (\Exception $e) {
            msg('SQLite: ' . $e->getMessage(), -1);
            return false;
        }
    }

    /**
     * Prepare a query with the given arguments.
     *
     * Takes care of escaping
     *
     * @param array $args
     *    array of arguments:
     *      - string $sql - the statement
     *      - arguments...
     * @return bool|string
     * @throws Exception
     */
    public function prepareSql($args)
    {

        $sql = trim(array_shift($args));
        $sql = rtrim($sql, ';');

        if (!$sql) {
            throw new \Exception('No SQL statement given', -1);
        }

        $argc = count($args);
        if ($argc > 0 && is_array($args[0])) {
            $args = $args[0];
            $argc = count($args);
        }

        // check number of arguments
        $qmc = substr_count($sql, '?');
        if ($argc < $qmc) {
            throw new \Exception('Not enough arguments passed for statement. ' .
                'Expected ' . $qmc . ' got ' . $argc . ' - ' . hsc($sql));
        } elseif ($argc > $qmc) {
            throw new \Exception('Too much arguments passed for statement. ' .
                'Expected ' . $qmc . ' got ' . $argc . ' - ' . hsc($sql));
        }

        // explode at wildcard, then join again
        $parts = explode('?', $sql, $argc + 1);
        $args  = array_map([$this->adapter->getPdo(), 'quote'], $args);
        $sql   = '';

        while (($part = array_shift($parts)) !== null) {
            $sql .= $part;
            $sql .= array_shift($args);
        }

        return $sql;
    }


    /**
     * Closes the result set (and it's cursors)
     *
     * If you're doing SELECT queries inside a TRANSACTION, be sure to call this
     * function on all your results sets, before COMMITing the transaction.
     *
     * Also required when not all rows of a result are fetched
     *
     * @param \PDOStatement $res
     * @return bool
     */
    public function res_close($res)
    {
        if (!$res) return false;

        return $res->closeCursor();
    }

    /**
     * Returns a complete result set as array
     *
     * @param \PDOStatement $res
     * @return array
     */
    public function res2arr($res, $assoc = true)
    {
        if (!$res) return [];

        // this is a bullshit workaround for having res2arr and res2count work on one result
        if (!$this->data) {
            $mode = $assoc ? PDO::FETCH_ASSOC : PDO::FETCH_NUM;
            $this->data = $res->fetchAll($mode);
        }
        return $this->data;
    }

    /**
     * Return the next row from the result set as associative array
     *
     * @param \PDOStatement $res
     * @param int $rownum will be ignored
     */
    public function res2row($res, $rownum = 0)
    {
        if (!$res) return false;

        return $res->fetch(\PDO::FETCH_ASSOC);
    }

    /**
     * Return the first value from the next row.
     *
     * @param \PDOStatement $res
     * @return mixed
     */
    public function res2single($res)
    {
        if (!$res) return false;

        $data = $res->fetch(PDO::FETCH_NUM, PDO::FETCH_ORI_ABS, 0);
        if (empty($data)) {
            return false;
        }
        return $data[0];
    }

    /**
     * fetch the next row as zero indexed array
     *
     * @param \PDOStatement $res
     * @return array|bool
     */
    public function res_fetch_array($res)
    {
        if (!$res) return false;

        return $res->fetch(PDO::FETCH_NUM);
    }

    /**
     * fetch the next row as assocative array
     *
     * @param \PDOStatement $res
     * @return array|bool
     */
    public function res_fetch_assoc($res)
    {
        if (!$res) return false;

        return $res->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Count the number of records in result
     *
     * This function is really inperformant in PDO and should be avoided!
     *
     * @param \PDOStatement $res
     * @return int
     */
    public function res2count($res)
    {
        if (!$res) return 0;

        // this is a bullshit workaround for having res2arr and res2count work on one result
        if (!$this->data) {
            $this->data = $this->res2arr($res);
        }

        return count($this->data);
    }

    /**
     * Count the number of records changed last time
     *
     * @param \PDOStatement $res
     * @return int
     */
    public function countChanges($res)
    {
        if (!$res) return 0;

        return $res->rowCount();
    }

    // endregion

    // region quoting/escaping functions

    /**
     * Join the given values and quote them for SQL insertion
     */
    public function quote_and_join($vals, $sep = ',')
    {
        $vals = array_map([$this->adapter->getPdo(), 'quote'], $vals);
        return implode($sep, $vals);
    }

    /**
     * Quotes a string, by escaping it and adding quotes
     */
    public function quote_string($string)
    {
        return $this->adapter->getPdo()->quote($string);
    }

    /**
     * Similar to quote_string, but without the quotes, useful to construct LIKE patterns
     */
    public function escape_string($str)
    {
        return trim($this->adapter->getPdo()->quote($str), "'");
    }

    // endregion

    // region speciality functions

    /**
     * Split sql queries on semicolons, unless when semicolons are quoted
     *
     * Usually you don't need this. It's only really needed if you need individual results for
     * multiple queries. For example in the admin interface.
     *
     * @param string $sql
     * @return array sql queries
     * @deprecated
     */
    public function SQLstring2array($sql)
    {
        if (!DOKU_UNITTEST) { // for now we don't want to trigger the deprecation warning in the tests
            dbg_deprecated(Tools::class . '::SQLstring2array');
        }
        return Tools::SQLstring2array($sql);
    }

    /**
     * @deprecated needs to be fixed in stuct and structpublish
     */
    public function doTransaction($sql, $sqlpreparing = true)
    {
        throw new \Exception(
            'This method seems to never have done what it suggests. Please use the query() function instead.'
        );
    }

    // endregion
}
