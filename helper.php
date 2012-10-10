<?php
/**
 * DokuWiki Plugin sqlite (Helper Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Andreas Gohr <gohr@cosmocode.de>
 */

// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

if(!defined('DOKU_LF')) define('DOKU_LF', "\n");
if(!defined('DOKU_TAB')) define('DOKU_TAB', "\t");
if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN', DOKU_INC.'lib/plugins/');

if(!defined('DOKU_EXT_SQLITE')) define('DOKU_EXT_SQLITE', 'sqlite');
if(!defined('DOKU_EXT_PDO')) define('DOKU_EXT_PDO', 'pdo');

require_once(DOKU_PLUGIN.'sqlite/classes/adapter.php');

class helper_plugin_sqlite extends DokuWiki_Plugin {
    var $adapter = null;

    public function getInfo() {
        return confToHash(dirname(__FILE__).'plugin.info.txt');
    }

    /**
     * Keep separate instances for every call to keep database connections
     */
    public function isSingleton() {
        return false;
    }

    /**
     * constructor
     */
    public function helper_plugin_sqlite() {

        if(!$this->adapter) {
            if($this->existsPDOSqlite()) {
                require_once(DOKU_PLUGIN.'sqlite/classes/adapter_pdosqlite.php');
                $this->adapter = new helper_plugin_sqlite_adapter_pdosqlite();
            }
        }

        if(!$this->adapter) {
            if($this->existsSqlite2()) {
                require_once(DOKU_PLUGIN.'sqlite/classes/adapter_sqlite2.php');
                $this->adapter = new helper_plugin_sqlite_adapter_sqlite2();
            }
        }

        if(!$this->adapter) {
            msg('SQLite & PDO SQLite support missing in this PHP install - plugin will not work', -1);
        }
    }

    /**
     * check availabilty of PHPs sqlite extension (for sqlite2 support)
     */
    public function existsSqlite2() {
        if(!extension_loaded('sqlite')) {
            $prefix = (PHP_SHLIB_SUFFIX === 'dll') ? 'php_' : '';
            if(function_exists('dl')) @dl($prefix.'sqlite.'.PHP_SHLIB_SUFFIX);
        }

        return function_exists('sqlite_open');
    }

    /**
     * check availabilty of PHP PDO sqlite3
     */
    public function existsPDOSqlite() {
        if(!extension_loaded('pdo_sqlite')) {
            $prefix = (PHP_SHLIB_SUFFIX === 'dll') ? 'php_' : '';
            if(function_exists('dl')) @dl($prefix.'pdo_sqlite.'.PHP_SHLIB_SUFFIX);
        }

        if(class_exists('pdo')) {
            foreach(PDO::getAvailableDrivers() as $driver) {
                if($driver == 'sqlite') {
                    return true;
                }
            }
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
    public function init($dbname, $updatedir) {

        $init = null; // set by initdb()
        if(!$this->adapter OR !$this->adapter->initdb($dbname, $init)) return false;

        return $this->_updatedb($init, $updatedir);
    }

    /**
     * Return the current Database Version
     */
    private function _currentDBversion() {
        $sql = "SELECT val FROM opts WHERE opt = 'dbversion';";
        $res = $this->query($sql);
        if(!$res) return false;
        $row = $this->res2row($res, 0);
        return (int) $row['val'];
    }

    /**
     * Update the database if needed
     *
     * @param bool   $init      - true if this is a new database to initialize
     * @param string $updatedir - Database update infos
     * @return bool
     */
    private function _updatedb($init, $updatedir) {
        if($init) {

            $current = 0;
        } else {
            $current = $this->_currentDBversion();
            if(!$current) {
                msg("SQLite: no DB version found. '".$this->adapter->getDbname()."' DB probably broken.", -1);
                return false;
            }
        }

        // in case of init, add versioning table
        if($init) {
            if(!$this->_runupdatefile(dirname(__FILE__).'/db.sql', 0)) {
                msg("SQLite: '".$this->adapter->getDbname()."' database upgrade failed for version ", -1);
                return false;
            }
        }

        $latest = (int) trim(io_readFile($updatedir.'/latest.version'));

        // all up to date?
        if($current >= $latest) return true;
        for($i = $current + 1; $i <= $latest; $i++) {
            $file = sprintf($updatedir.'/update%04d.sql', $i);
            if(file_exists($file)) {
                if(!$this->_runupdatefile($file, $i)) {
                    msg("SQLite: '".$this->adapter->getDbname()."' database upgrade failed for version ".$i, -1);
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Updates the database structure using the given file to
     * the given version.
     */
    private function _runupdatefile($file, $version) {
        $sql = io_readFile($file, false);

        $sql = explode(";", $sql);
        array_unshift($sql, 'BEGIN TRANSACTION');
        array_push($sql, "INSERT OR REPLACE INTO opts (val,opt) VALUES ($version,'dbversion')");
        array_push($sql, "COMMIT TRANSACTION");

        foreach($sql as $s) {
            $s = preg_replace('!^\s*--.*$!m', '', $s);
            $s = trim($s);
            if(!$s) continue;

            $res = $this->query("$s;");
            if($res === false) {
                if($this->adapter->getName() == DOKU_EXT_SQLITE) {
                    sqlite_query($this->db, 'ROLLBACK TRANSACTION');
                }
                return false;
            }
        }

        return ($version == $this->_currentDBversion());
    }

    /**
     * Registers a User Defined Function for use in SQL statements
     */
    public function create_function($function_name, $callback, $num_args) {
        $this->adapter->create_function($function_name, $callback, $num_args);
    }

    /**
     * Execute a query with the given parameters.
     *
     * Takes care of escaping
     *
     * @internal param string $sql - the statement
     * @internal param $arguments ...
     * @return bool|\SQLiteResult
     */
    public function query() {
        // get function arguments
        $args = func_get_args();

        return $this->adapter->query($args);
    }

    /**
     * Join the given values and quote them for SQL insertion
     */
    public function quote_and_join($vals, $sep = ',') {
        return $this->adapter->quote_and_join($vals, $sep);
    }

    /**
     * Run sqlite_escape_string() on the given string and surround it
     * with quotes
     */
    public function quote_string($string) {
        return $this->adapter->quote_string($string);
    }

    /**
     * Escape string for sql
     */
    public function escape_string($str) {
        return $this->adapter->escape_string($str);
    }

    /**
     * Returns a complete result set as array
     */
    public function res2arr($res, $assoc = true) {
        return $this->adapter->res2arr($res, $assoc);
    }

    /**
     * Return the wanted row from a given result set as
     * associative array
     */
    public function res2row($res, $rownum = 0) {
        return $this->adapter->res2row($res, $rownum);
    }

    /**
     * Return the first value from the first row.
     */
    public function res2single($res) {
        return $this->adapter->res2single($res);
    }

    /**
     * fetch the next row as zero indexed array
     */
    public function res_fetch_array($res) {
        return $this->adapter->res_fetch_array($res);
    }

    /**
     * fetch the next row as assocative array
     */
    public function res_fetch_assoc($res) {
        return $this->adapter->res_fetch_assoc($res);
    }

    /**
     * Count the number of records in result
     *
     * This function is really inperformant in PDO and should be avoided!
     */
    public function res2count($res) {
        return $this->adapter->res2count($res);
    }

    /**
     * Count the number of records changed last time
     */
    public function countChanges($db, $res) {
        return $this->adapter->countChanges($db, $res);
    }

}
