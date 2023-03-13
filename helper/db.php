<?php

/**
 * DokuWiki Plugin sqlite (Helper Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Andreas Gohr, Michael Große <dokuwiki@cosmocode.de>
 */


class helper_plugin_sqlite_db extends DokuWiki_Plugin
{
    /** @var helper_plugin_sqlite */
    protected $sqlite;

    /**
     * helper_plugin_sqlite_db constructor.
     */
    public function __construct()
    {
        $this->init();
    }

    /**
     * Initialize the database
     *
     */
    protected function init()
    {
        /** @var helper_plugin_sqlite $sqlite */
        $this->sqlite = plugin_load('helper', 'sqlite');

        // initialize the database connection
        if (!$this->sqlite->init('sqlite', DOKU_PLUGIN . 'sqlite/db/')) {
            $this->sqlite = null;
        }
    }

    /**
     * @return helper_plugin_sqlite|null
     */
    public function getDB()
    {
        return $this->sqlite;
    }
}

// vim:ts=4:sw=4:et:
