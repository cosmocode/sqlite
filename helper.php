<?php
/**
 * DokuWiki Plugin sqlite (Helper Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Andreas Gohr <gohr@cosmocode.de>
 */

// must be run within Dokuwiki
if (!defined('DOKU_INC')) die();

if (!defined('DOKU_LF')) define('DOKU_LF', "\n");
if (!defined('DOKU_TAB')) define('DOKU_TAB', "\t");
if (!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');

class helper_plugin_sqlite extends DokuWiki_Plugin {
    var $db     = null;
    var $dbname = '';

    function getInfo() {
        return confToHash(dirname(__FILE__).'plugin.info.txt');
    }

    /**
     * constructor
     */
    function helper_plugin_sqlite(){
        if (!extension_loaded('sqlite')) {
            $prefix = (PHP_SHLIB_SUFFIX === 'dll') ? 'php_' : '';
            if(function_exists('dl')) @dl($prefix . 'sqlite.' . PHP_SHLIB_SUFFIX);
        }

        if(!function_exists('sqlite_open')){
            msg('SQLite support missing in this PHP install - plugin will not work',-1);
        }
    }

    /**
     * Initializes and opens the database
     *
     * Needs to be called right after loading this helper plugin
     */
    function init($dbname,$updatedir){
        global $conf;

        // check for already open DB
        if($this->db){
            if($this->dbname == $dbname){
                // db already open
                return true;
            }
            // close other db
            sqlite_close($this->db);
            $this->db     = null;
            $this->dbname = '';
        }

        $this->dbname = $dbname;
        $dbfile = $conf['metadir'].'/'.$dbname.'.sqlite';
        $init   = (!@file_exists($dbfile) || ((int) @filesize($dbfile)) < 3);

        $error='';
        $this->db = sqlite_open($dbfile, 0666, $error);
        if(!$this->db){
            msg("SQLite: failed to open SQLite ".$this->dbname." database ($error)",-1);
            return false;
        }

        $this->_updatedb($init,$updatedir);
        return true;
    }

    /**
     * Return the current Database Version
     */
    function _currentDBversion(){
        $sql = "SELECT val FROM opts WHERE opt = 'dbversion';";
        $res = $this->query($sql);
        if(!$res) return false;
        $row = $this->res2row($res,0);
        return (int) $row['val'];
    }
    /**
     * Update the database if needed
     *
     * @param bool   $init      - true if this is a new database to initialize
     * @param string $updatedir - Database update infos
     */
    function _updatedb($init,$updatedir){
        if($init){
            $current = 0;
        }else{
            $current = $this->_currentDBversion();
            if(!$current){
                msg('SQLite: no DB version found. '.$this->dbname.' DB probably broken.',-1);
                return false;
            }
        }

        // in case of init, add versioning table
        if($init){
            if(!$this->_runupdatefile(dirname(__FILE__).'/db.sql',0)){
                msg('SQLite: '.$this->dbname.' database upgrade failed for version '.$i, -1);
                return false;
            }
        }

        $latest  = (int) trim(io_readFile($updatedir.'/latest.version'));

        // all up to date?
        if($current >= $latest) return true;
        for($i=$current+1; $i<=$latest; $i++){
            $file = sprintf($updatedir.'/update%04d.sql',$i);
            if(file_exists($file)){
                if(!$this->_runupdatefile($file,$i)){
                    msg('SQLite: '.$this->dbname.' database upgrade failed for version '.$i, -1);


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
    function _runupdatefile($file,$version){
        $sql  = io_readFile($file,false);

        $sql = explode(";",$sql);
        array_unshift($sql,'BEGIN TRANSACTION');
        array_push($sql,"INSERT OR REPLACE INTO opts (val,opt) VALUES ($version,'dbversion')");
        array_push($sql,"COMMIT TRANSACTION");

        foreach($sql as $s){
            $s = preg_replace('!^\s*--.*$!m', '', $s);
            $s = trim($s);
            if(!$s) continue;
            $res = $this->query("$s;");
            if ($res === false) {
                sqlite_query($this->db, 'ROLLBACK TRANSACTION');
                return false;
            }
        }

        return ($version == $this->_currentDBversion());
    }

    /**
     * Execute a query with the given parameters.
     *
     * Takes care of escaping
     *
     * @param string $sql - the statement
     * @param arguments...
     */
    function query(){
        if(!$this->db) return false;

        // get function arguments
        $args = func_get_args();
        $sql  = trim(array_shift($args));

        if(!$sql){
            msg('No SQL statement given',-1);
            return false;
        }

        if(is_array($args[0])) $args = $args[0];
        $argc = count($args);

        // check number of arguments
        if($argc < substr_count($sql,'?')){
            msg('Not enough arguments passed for statement. '.
                'Expected '.substr_count($sql,'?').' got '.
                $argc.' - '.hsc($sql),-1);
            return false;
        }

        // explode at wildcard, then join again
        $parts = explode('?',$sql,$argc+1);
        $args  = array_map(array($this,'quote_string'),$args);
        $sql   = '';

        while( ($part = array_shift($parts)) !== null ){
            $sql .= $part;
            $sql .= array_shift($args);
        }

        // execute query
        $err = '';
        $res = @sqlite_query($this->db,$sql,SQLITE_ASSOC,$err);
        if($err){
            msg($err.' - '.hsc($sql),-1);
            return false;
        }elseif(!$res){
            msg(sqlite_error_string(sqlite_last_error($this->db)).
                ' - '.hsc($sql),-1);
            return false;
        }

        return $res;
    }

    /**
     * Returns a complete result set as array
     */
    function res2arr($res){
        $data = array();
        if(!sqlite_num_rows($res)) return $data;
        sqlite_rewind($res);
        while(($row = sqlite_fetch_array($res)) !== false){
            $data[] = $row;
        }
        return $data;
    }

    /**
     * Return the wanted row from a given result set as
     * associative array
     */
    function res2row($res,$rownum=0){
        if(!@sqlite_seek($res,$rownum)){
            return false;
        }
        return sqlite_fetch_array($res);
    }


    /**
     * Join the given values and quote them for SQL insertion
     */
    function quote_and_join($vals,$sep=',') {
        $vals = array_map(array('helper_plugin_sqlite','quote_string'),$vals);
        return join($sep,$vals);
    }

    /**
     * Run sqlite_escape_string() on the given string and surround it
     * with quotes
     */
    function quote_string($string){
        return "'".sqlite_escape_string($string)."'";
    }


}

// vim:ts=4:sw=4:et:enc=utf-8:
