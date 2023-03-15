<?php
/**
 * @noinspection SqlNoDataSourceInspection
 * @noinspection SqlDialectInspection
 */

namespace dokuwiki\plugin\sqlite;

class Update {

    /** @var Adapter */
    protected $adapter;

    /**
     * Update the database if needed
     *
     * @param $adapter Adapter
     */
    public function __construct($adapter) {
        $this->adapter = $adapter;
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
            if($current === false) {
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
                // prepare Event data
                $data = array(
                    'from' => $current,
                    'to' => $i,
                    'file' => &$file,
                    'sqlite' => $this
                );
                $event = new Doku_Event('PLUGIN_SQLITE_DATABASE_UPGRADE', $data);
                if($event->advise_before()) {
                    // execute the migration
                    if(!$this->_runupdatefile($file, $i)) {
                        msg("SQLite: '".$this->adapter->getDbname()."' database upgrade failed for version ".$i, -1);
                        return false;
                    }
                } else {
                    if($event->result) {
                        $this->query("INSERT OR REPLACE INTO opts (val,opt) VALUES (?,'dbversion')", $i);
                    } else {
                        return false;
                    }
                }
                $event->advise_after();

            } else {
                msg("SQLite: update file $file not found, skipped.", -1);
            }
        }
        return true;
    }

    /**
     * Updates the database structure using the given file to
     * the given version.
     */
    private function _runupdatefile($file, $version) {
        if(!file_exists($file)) {
            msg("SQLite: Failed to find DB update file $file");
            return false;
        }
        $sql = io_readFile($file, false);

        $sql = $this->SQLstring2array($sql);
        array_unshift($sql, 'BEGIN TRANSACTION');
        array_push($sql, "INSERT OR REPLACE INTO opts (val,opt) VALUES ($version,'dbversion')");
        array_push($sql, "COMMIT TRANSACTION");

        if(!$this->doTransaction($sql)) {
            return false;
        }
        return ($version == $this->_currentDBversion());
    }

}
