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

if (!defined('DOKU_EXT_SQLITE')) define('DOKU_EXT_SQLITE', 'sqlite');
if (!defined('DOKU_EXT_PDO')) define('DOKU_EXT_PDO', 'pdo');


class helper_plugin_sqlite extends DokuWiki_Plugin {
    var $db     = null;
    var $dbname = '';
    var $extension = null;
    function getInfo() {
        return confToHash(dirname(__FILE__).'plugin.info.txt');
    }

    /**
     * constructor
     */
    function helper_plugin_sqlite(){

      if(!$this->extension)
      {
        if (!extension_loaded('pdo_sqlite')) {
            $prefix = (PHP_SHLIB_SUFFIX === 'dll') ? 'php_' : '';
            if(function_exists('dl')) @dl($prefix . 'pdo_sqlite.' . PHP_SHLIB_SUFFIX);
        }

        if(class_exists('pdo')){
            $this->extension = DOKU_EXT_PDO;
        }
      }

      if(!$this->extension)
      {
        if (!extension_loaded('sqlite')) {
            $prefix = (PHP_SHLIB_SUFFIX === 'dll') ? 'php_' : '';
            if(function_exists('dl')) @dl($prefix . 'sqlite.' . PHP_SHLIB_SUFFIX);
        }

        if(function_exists('sqlite_open')){
           $this->extension = DOKU_EXT_SQLITE;
        }
      }

      if(!$this->extension)

      {
        msg('SQLite & PDO SQLite support missing in this PHP install - plugin will not work',-1);
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
            if($this->extension == DOKU_EXT_SQLITE)
            {
              sqlite_close($this->db);
            }
            else
            {
              $this->db->close();
            }
            $this->db     = null;
            $this->dbname = '';
        }

        $this->dbname = $dbname;

        $fileextension = '.sqlite';

        $this->dbfile = $conf['metadir'].'/'.$dbname.$fileextension;

        $init   = (!@file_exists($this->dbfile) || ((int) @filesize($this->dbfile)) < 3);

        if($this->extension == DOKU_EXT_SQLITE)
        {
          $error='';
          $this->db = sqlite_open($this->dbfile, 0666, $error);
          if(!$this->db){
              msg("SQLite: failed to open SQLite ".$this->dbname." database ($error)",-1);
              return false;
          }

          // register our custom aggregate function
          sqlite_create_aggregate($this->db,'group_concat',
                                  array($this,'_sqlite_group_concat_step'),
                                  array($this,'_sqlite_group_concat_finalize'), 2);
        }
        else
        {
          $dsn = 'sqlite:'.$this->dbfile;

          try {
              $this->db = new PDO($dsn);
          } catch (PDOException $e) {
            msg("SQLite: failed to open SQLite ".$this->dbname." database (".$e->getMessage().")",-1);
              return false;
          }
          $this->db->sqliteCreateAggregate('group_concat',
                                  array($this,'_pdo_group_concat_step'),
                                  array($this,'_pdo_group_concat_finalize'));
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
    function _updatedb($init,$updatedir)
    {
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
                  msg('SQLite: '.$this->dbname.' database upgrade failed for version ', -1);
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
              if($this->extension == DOKU_EXT_SQLITE)
              {
                sqlite_query($this->db, 'ROLLBACK TRANSACTION');
                return false;
              }
              else
              {
                return false;
              }
            }
        }

        return ($version == $this->_currentDBversion());
    }

    /**
     * Emulate ALTER TABLE
     *
     * The ALTER TABLE syntax is parsed and then emulated using a
     * temporary table
     *
     * @author <jon@jenseng.com>
     * @link   http://code.jenseng.com/db/
     * @author Andreas Gohr <gohr@cosmocode.de>
     */
    function _altertable($table,$alterdefs){

        // load original table definition SQL
        $result = $this->query("SELECT sql,name,type
                                  FROM sqlite_master
                                 WHERE tbl_name = '$table'
                                   AND type = 'table'");

        if(($result === false) || ($this->extension == DOKU_EXT_SQLITE && sqlite_num_rows($result)<=0)){
            msg("ALTER TABLE failed, no such table '".hsc($table)."'",-1);
            return false;
        }

        if($this->extension == DOKU_EXT_SQLITE )
        {
          $row = sqlite_fetch_array($result);
        }
        else
        {
           $row = $result->fetch(PDO::FETCH_ASSOC);
        }

        if($row === false){
            msg("ALTER TABLE failed, table '".hsc($table)."' had no master data",-1);
            return false;
        }


        // prepare temporary table SQL
        $tmpname = 't'.time();
        $origsql = trim(preg_replace("/[\s]+/"," ",
                        str_replace(",",", ",
                        preg_replace('/\)$/',' )',
                        preg_replace("/[\(]/","( ",$row['sql'],1)))));
        $createtemptableSQL = 'CREATE TEMPORARY '.substr(trim(preg_replace("'".$table."'",$tmpname,$origsql,1)),6);
        $createindexsql = array();

        // load indexes to reapply later
        $result = $this->query("SELECT sql,name,type
                                  FROM sqlite_master
                                 WHERE tbl_name = '$table'
                                   AND type = 'index'");
        if(!$result){
            $indexes = array();
        }else{
            $indexes = $this->res2arr($result);
        }


        $i = 0;
        $defs = preg_split("/[,]+/",$alterdefs,-1,PREG_SPLIT_NO_EMPTY);
        $prevword = $table;
        $oldcols = preg_split("/[,]+/",substr(trim($createtemptableSQL),strpos(trim($createtemptableSQL),'(')+1),-1,PREG_SPLIT_NO_EMPTY);
        $newcols = array();

        for($i=0;$i<count($oldcols);$i++){
            $colparts = preg_split("/[\s]+/",$oldcols[$i],-1,PREG_SPLIT_NO_EMPTY);
            $oldcols[$i] = $colparts[0];
            $newcols[$colparts[0]] = $colparts[0];
        }
        $newcolumns = '';
        $oldcolumns = '';
        reset($newcols);
        while(list($key,$val) = each($newcols)){
            $newcolumns .= ($newcolumns?', ':'').$val;
            $oldcolumns .= ($oldcolumns?', ':'').$key;
        }
        $copytotempsql = 'INSERT INTO '.$tmpname.'('.$newcolumns.') SELECT '.$oldcolumns.' FROM '.$table;
        $dropoldsql = 'DROP TABLE '.$table;
        $createtesttableSQL = $createtemptableSQL;

        foreach($defs as $def){
            $defparts = preg_split("/[\s]+/",$def,-1,PREG_SPLIT_NO_EMPTY);
            $action = strtolower($defparts[0]);
            switch($action){
                case 'add':
                    if(count($defparts) < 2){
                        msg('ALTER TABLE: not enough arguments for ADD statement',-1);
                        return false;
                    }
                    $createtesttableSQL = substr($createtesttableSQL,0,strlen($createtesttableSQL)-1).',';
                    for($i=1;$i<count($defparts);$i++)
                        $createtesttableSQL.=' '.$defparts[$i];
                    $createtesttableSQL.=')';
                    break;

                case 'change':
                    if(count($defparts) <= 3){
                        msg('ALTER TABLE: near "'.$defparts[0].($defparts[1]?' '.$defparts[1]:'').($defparts[2]?' '.$defparts[2]:'').'": syntax error',-1);
                        return false;
                    }

                    if($severpos = strpos($createtesttableSQL,' '.$defparts[1].' ')){
                        if($newcols[$defparts[1]] != $defparts[1]){
                            msg('ALTER TABLE: unknown column "'.$defparts[1].'" in "'.$table.'"',-1);
                            return false;
                        }
                        $newcols[$defparts[1]] = $defparts[2];
                        $nextcommapos = strpos($createtesttableSQL,',',$severpos);
                        $insertval = '';
                        for($i=2;$i<count($defparts);$i++)
                            $insertval.=' '.$defparts[$i];
                        if($nextcommapos)
                            $createtesttableSQL = substr($createtesttableSQL,0,$severpos).$insertval.substr($createtesttableSQL,$nextcommapos);
                        else
                            $createtesttableSQL = substr($createtesttableSQL,0,$severpos-(strpos($createtesttableSQL,',')?0:1)).$insertval.')';
                    } else {
                        msg('ALTER TABLE: unknown column "'.$defparts[1].'" in "'.$table.'"',-1);
                        return false;
                    }
                    break;
                case 'drop':
                    if(count($defparts) < 2){
                        msg('ALTER TABLE: near "'.$defparts[0].($defparts[1]?' '.$defparts[1]:'').'": syntax error',-1);
                        return false;
                    }
                    if($severpos = strpos($createtesttableSQL,' '.$defparts[1].' ')){
                        $nextcommapos = strpos($createtesttableSQL,',',$severpos);
                        if($nextcommapos)
                            $createtesttableSQL = substr($createtesttableSQL,0,$severpos).substr($createtesttableSQL,$nextcommapos + 1);
                        else
                            $createtesttableSQL = substr($createtesttableSQL,0,$severpos-(strpos($createtesttableSQL,',')?0:1) - 1).')';
                        unset($newcols[$defparts[1]]);
                    }else{
                        msg('ALTER TABLE: unknown column "'.$defparts[1].'" in "'.$table.'"',-1);
                        return false;
                    }
                    break;
                default:
                    msg('ALTER TABLE: near "'.$prevword.'": syntax error',-1);
                    return false;
            }
            $prevword = $defparts[count($defparts)-1];
        }

        // this block of code generates a test table simply to verify that the
        // columns specifed are valid in an sql statement
        // this ensures that no reserved words are used as columns, for example
        $res = $this->query($createtesttableSQL);
        if($res === false) return false;

        $droptempsql = 'DROP TABLE '.$tmpname;
        $res = $this->query($droptempsql);
        if($res === false) return false;


        $createnewtableSQL = 'CREATE '.substr(trim(preg_replace("'".$tmpname."'",$table,$createtesttableSQL,1)),17);
        $newcolumns = '';
        $oldcolumns = '';
        reset($newcols);
        while(list($key,$val) = each($newcols)){
            $newcolumns .= ($newcolumns?', ':'').$val;
            $oldcolumns .= ($oldcolumns?', ':'').$key;
        }

        $copytonewsql = 'INSERT INTO '.$table.'('.$newcolumns.') SELECT '.$oldcolumns.' FROM '.$tmpname;

        $res = $this->query($createtemptableSQL); //create temp table
        if($res === false) return false;
        $res = $this->query($copytotempsql); //copy to table
        if($res === false) return false;
        $res = $this->query($dropoldsql); //drop old table
        if($res === false) return false;

        $res = $this->query($createnewtableSQL); //recreate original table
        if($res === false) return false;
        $res = $this->query($copytonewsql); //copy back to original table
        if($res === false) return false;

        foreach($indexes as $index){ // readd indexes
            $res = $this->query($index['sql']);
            if($res === false) return false;
        }

        $res = $this->query($droptempsql); //drop temp table
        if($res === false) return false;

        return $res; // return a valid resource
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
        $sql  = rtrim($sql,';');

        if(!$sql){
            msg('No SQL statement given',-1);
            return false;
        }

        $argc = count($args);
        if($argc > 0 && is_array($args[0])) {
            $args = $args[0];
            $argc = count($args);
        }

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

        // intercept ALTER TABLE statements
        $match = null;
        if(preg_match('/^ALTER\s+TABLE\s+([\w\.]+)\s+(.*)/i',$sql,$match)){
            return $this->_altertable($match[1],$match[2]);
        }

        // execute query
        $err = '';
        if($this->extension == DOKU_EXT_SQLITE )
        {
          $res = @sqlite_query($this->db,$sql,SQLITE_ASSOC,$err);
          if($err){
              msg($err.':<br /><pre>'.hsc($sql).'</pre>',-1);
              return false;
          }elseif(!$res){
              msg(sqlite_error_string(sqlite_last_error($this->db)).
                  ':<br /><pre>'.hsc($sql).'</pre>',-1);
              return false;
          }
        }
        else
        {
          $result = false;

          $res = $this->db->query($sql);

          if(!$res){
            $err = $this->db->errorInfo();
              msg($err[2].':<br /><pre>'.hsc($sql).'</pre>',-1);
              return false;
          }
        }
        return $res;
    }

    /**
     * Returns a complete result set as array
     */
    function res2arr($res){
        $data = array();
        if($this->extension == DOKU_EXT_SQLITE )
        {
          if(!sqlite_num_rows($res)) return $data;
          sqlite_rewind($res);
          while(($row = sqlite_fetch_array($res, SQLITE_ASSOC)) !== false){
              $data[] = $row;
          }
        }
        else
        {
          $data = $res->fetchAll(PDO::FETCH_ASSOC);
          if(!count(data))
          {
            return false;
          }
        }
        return $data;
    }

    /**
     * Return the wanted row from a given result set as
     * associative array
     */
    function res2row($res,$rownum=0){
        if($this->extension == DOKU_EXT_SQLITE )
        {
          if(!@sqlite_seek($res,$rownum)){
              return false;
          }
          return sqlite_fetch_array($res, SQLITE_ASSOC);
        }
        else
        {
          //very dirty replication of the same functionality (really must look at cursors)
          $data = array();
          //do we need to rewind?
          $data = $res->fetchAll(PDO::FETCH_ASSOC);
          if(!count(data))
          {
            return false;
          }

          if(!isset($data[$rownum]))
          {
            return false;
          }
          else
          {
            return $data[$rownum];
          }
        }
    }

    /**
     * Return the first value from the first row.
     */
    function res2single($res)
    {
      if($this->extension == DOKU_EXT_SQLITE )
      {
        return sqlite_fetch_single($res);
      }
      else
      {
        $data = $res->fetchAll(PDO::FETCH_NUM);
        if(!count(data))
        {
          return false;
        }
        return $data[0][0];
      }
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
      if($this->extension == DOKU_EXT_SQLITE )
      {
        return "'".sqlite_escape_string($string)."'";
      }
      else
      {
        return $this->db->quote($string);
      }
    }


    /**
     * Escape string for sql
     */
    function escape_string($str)
    {
      if($this->extension == DOKU_EXT_SQLITE )
      {
        return sqlite_escape_string($str);
      }
      else
      {
        return trim($this->db->quote($str), "'");
      }
    }



    /**
    * Aggregation function for SQLite
    *
    * @link http://devzone.zend.com/article/863-SQLite-Lean-Mean-DB-Machine
    */
    function _sqlite_group_concat_step(&$context, $string, $separator = ',') {
         $context['sep'] = $separator;
         $context['data'][] = $string;
    }

    /**
    * Aggregation function for SQLite
    *
    * @link http://devzone.zend.com/article/863-SQLite-Lean-Mean-DB-Machine
    */
    function _sqlite_group_concat_finalize(&$context) {
         $context['data'] = array_unique($context['data']);
         return join($context['sep'],$context['data']);
    }


    /**
     * Aggregation function for SQLite via PDO
     *
     * @link http://devzone.zend.com/article/863-SQLite-Lean-Mean-DB-Machine
     */
    function _pdo_group_concat_step(&$context, $rownumber, $string, $separator = ',') {
         if(is_null($context))
         {
           $context = array(
             'sep'  => $separator,
             'data' => array()
             );
         }

         $context['data'][] = $string;
         return $context;
    }

     /**
     * Aggregation function for SQLite via PDO
     *
     * @link http://devzone.zend.com/article/863-SQLite-Lean-Mean-DB-Machine
     */
    function _pdo_group_concat_finalize(&$context, $rownumber)
    {
        if(!is_array($context))
        {
          return null;
        }
        $context['data'] = array_unique($context['data']);
        return join($context['sep'],$context['data']);
    }

    /**
     * Keep separate instances for every call to keep database connections
     */
    function isSingleton() {
         return false;
    }

     /**
     * fetch the next row as zero indexed array
     */
    function res_fetch_array($res)
    {
      if($this->extension == DOKU_EXT_SQLITE )
      {
        return sqlite_fetch_array($res, SQLITE_NUM);
      }
      else
      {
        return $res->fetch(PDO::FETCH_NUM);
      }
    }


    /**
     * fetch the next row as assocative array
     */
    function res_fetch_assoc($res)
    {
      if($this->extension == DOKU_EXT_SQLITE )
      {
        return sqlite_fetch_array($res, SQLITE_ASSOC);
      }
      else
      {
       return $res->fetch(PDO::FETCH_ASSOC);
      }
    }


    /**
    * Count the number of records in rsult
    */
    function res2count($res) {
      if($this->extension == DOKU_EXT_SQLITE )
      {
        return sqlite_num_rows($res);
      }
      else
      {
        $regex = '/^SELECT\s+(?:ALL\s+|DISTINCT\s+)?(?:.*?)\s+FROM\s+(.*)$/i';
        if (preg_match($regex, $res->queryString, $output) > 0) {
            $stmt = $this->db->query("SELECT COUNT(*) FROM {$output[1]}", PDO::FETCH_NUM);

            return $stmt->fetchColumn();
        }

        return false;
      }
    }


    /**
    * Count the number of records changed last time
    */
    function countChanges($db, $res)
    {
      if($this->extension == DOKU_EXT_SQLITE )
      {
        return sqlite_changes($db);
      }
      else
      {
        return $res->rowCount();
      }
    }
}

// vim:ts=4:sw=4:et:enc=utf-8:
