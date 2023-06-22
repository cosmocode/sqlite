<?php

use dokuwiki\Form\Form;
use dokuwiki\Form\InputElement;
use dokuwiki\plugin\sqlite\QuerySaver;
use dokuwiki\plugin\sqlite\SQLiteDB;
use dokuwiki\plugin\sqlite\Tools;

/**
 * DokuWiki Plugin sqlite (Admin Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Andreas Gohr <andi@splitbrain.org>
 */
class admin_plugin_sqlite extends DokuWiki_Admin_Plugin
{
    /** @var SQLiteDB */
    protected $db = null;

    /** @var QuerySaver */
    protected $querySaver = null;

    /** @inheritdoc */
    public function getMenuSort()
    {
        return 500;
    }

    /** @inheritdoc */
    public function forAdminOnly()
    {
        return true;
    }

    /** @inheritdoc */
    public function handle()
    {
        global $conf;
        global $INPUT;

        // load database if given and security token is valid
        if ($INPUT->str('db') && checkSecurityToken()) {
            try {
                $this->db = new SQLiteDB($INPUT->str('db'), '');
                $this->querySaver = new QuerySaver($this->db->getDBName());
            } catch (Exception $e) {
                msg($e->getMessage(), -1);
                return;
            }
        }

        $cmd = $INPUT->extract('cmd')->str('cmd');
        switch ($cmd) {
            case 'export':
                $exportfile = $conf['tmpdir'] . '/' . $this->db->getDBName() . '.sql';
                $this->db->dumpToFile($exportfile);
                header('Content-Type: text/sql');
                header('Content-Disposition: attachment; filename="' . $this->db->getDbName() . '.sql";');
                readfile($exportfile);
                unlink($exportfile);
                exit(0);
            case 'import':
                $importfile = $_FILES['importfile']['tmp_name'];

                if (empty($importfile)) {
                    msg($this->getLang('import_no_file'), -1);
                    return;
                }

                $sql = Tools::SQLstring2array(file_get_contents($importfile));
                try {
                    $this->db->getPdo()->beginTransaction();
                    foreach ($sql as $s) {
                        $this->db->exec($s);
                    }
                    $this->db->getPdo()->commit();
                    msg($this->getLang('import_success'), 1);
                } catch (Exception $e) {
                    $this->db->getPdo()->rollBack();
                    msg(hsc($e->getMessage()), -1);
                }
                break;
            case 'save_query':
                $this->querySaver->saveQuery($INPUT->str('name'), $INPUT->str('sql'));
                break;
            case 'delete_query':
                $this->querySaver->deleteQuery($INPUT->str('name'));
                break;
        }
    }

    /** @inheritdoc */
    public function html()
    {
        global $INPUT;

        echo '<div class="plugin_sqlite_admin">';
        echo $this->locale_xhtml('intro');

        if ($this->db) {
            echo '<h2>' . $this->getLang('db') . ' "' . hsc($this->db->getDbName()) . '"</h2>';

            echo '<div class="commands">';
            $this->showCommands();
            $this->showSavedQueries();
            echo '</div>';

            // query form
            $form = new Form(['action' => $this->selfLink()]);
            $form->addClass('sqliteplugin');
            $form->addFieldsetOpen('SQL Command');
            $form->addTextarea('sql')->addClass('edit');
            $form->addButton('submit', $this->getLang('btn_execute'))->attr('type', 'submit');
            $form->addTextInput('name', $this->getLang('query_name'));
            $form->addButton('cmd[save_query]', $this->getLang('save_query'))->attr('type', 'submit');
            $form->addFieldsetClose();
            echo $form->toHTML();

            // results
            if ($INPUT->has('sql')) $this->showQueryResults($INPUT->str('sql'));
        }
        echo '</div>';
    }

    /**
     * List all available databases in the TOC
     *
     * @inheritdoc
     */
    public function getTOC()
    {
        global $conf;
        global $ID;

        $toc = [];
        $toc[] = [
            'link' => wl($ID, ['do' => 'admin', 'page' => 'sqlite']),
            'title' => $this->getLang('db') . ':',
            'level' => 1,
            'type' => 'ul',
        ];
        $dbfiles = glob($conf['metadir'] . '/*.sqlite3');
        if (is_array($dbfiles)) foreach ($dbfiles as $file) {
            $db = basename($file, '.sqlite3');
            $toc[] = array(
                'link' => wl($ID, array('do' => 'admin', 'page' => 'sqlite', 'db' => $db, 'sectok' => getSecurityToken())),
                'title' => $db,
                'level' => 2,
                'type' => 'ul',
            );
        }

        return $toc;
    }

    /**
     * Execute and display the results of the given SQL query
     *
     * multiple queries can be given separated by semicolons
     *
     * @param string $sql
     */
    protected function showQueryResults($sql)
    {
        echo '<h3 id="scroll__here">Query results</h3>';

        $sql = Tools::SQLstring2array($sql);
        foreach ($sql as $s) {
            try {
                $time_start = microtime(true);
                $result = $this->db->queryAll($s);
                $time_end = microtime(true);
            } catch (Exception $e) {
                msg($e->getMessage(), -1);
                continue;
            }

            $time = $time_end - $time_start;
            $cnt = count($result);
            msg($cnt . ' affected rows in ' . $this->microtimeToSeconds($time) . ' seconds', 1);
            if (!$cnt) continue;

            echo '<div>';
            $ths = array_keys($result[0]);
            echo '<table class="inline">';
            echo '<tr>';
            foreach ($ths as $th) {
                echo '<th>' . hsc($th) . '</th>';
            }
            echo '</tr>';
            foreach ($result as $row) {
                echo '<tr>';
                $tds = array_values($row);
                foreach ($tds as $td) {
                    if ($td === null) $td = '␀';
                    echo '<td>' . hsc($td) . '</td>';
                }
                echo '</tr>';
            }
            echo '</table>';
            echo '</div>';
        }
    }


    /**
     * Convert a microtime() value to a string in seconds
     *
     * @param float $time
     * @return string
     */
    protected function microtimeToSeconds($time)
    {
        return ($time < 0.0001 ? substr($time, 0, 5) . substr($time, -3) : substr($time, 0, 7));
    }

    /**
     * Construct a link to the sqlite admin page with the given additional parameters
     *
     * Basically a wrapper around wl() with some defaults
     *
     * @param string[] $params
     * @param bool $form for use in form action?
     * @return string
     */
    protected function selfLink($form = true, $params = [])
    {
        global $ID;
        $params = array_merge(
            [
                'do' => 'admin',
                'page' => 'sqlite',
                'db' => $this->db ? $this->db->getDBName() : '',
                'sectok' => getSecurityToken(),
            ], $params
        );

        return wl($ID, $params, false, $form ? '&' : '&amp;');
    }

    /**
     * Display the standard actions for a database
     */
    protected function showCommands()
    {
        $commands = [
            'dbversion' => [
                'sql' => 'SELECT val FROM opts WHERE opt=\'dbversion\'',
            ],
            'table' => [
                'sql' => 'SELECT name,sql FROM sqlite_master WHERE type=\'table\' ORDER BY name',
            ],
            'index' => [
                'sql' => 'SELECT name,sql FROM sqlite_master WHERE type=\'index\' ORDER BY name',
            ],
            'export' => [
                'cmd' => 'export'
            ],
        ];

        // import form
        $form = new Form(['action' => $this->selfLink(), 'enctype' => 'multipart/form-data', 'method' => 'post']);
        $form->addElement(
            (new InputElement('file', 'importfile'))
                ->attr('required', 'required')
                ->attr('accept', '.sql')
        );
        $form->addButton('cmd[import]', $this->getLang('import'));

        // output as a list
        echo '<ul>';
        foreach ($commands as $label => $command) {
            echo '<li><div class="li">';
            echo '<a href="' . $this->selfLink(false, $command) . '">' . $this->getLang($label) . '</a>';
            echo '</div></li>';
        }
        echo '<li><div class="li">';
        echo $form->toHTML();
        echo '</div></li>';
        echo '</ul>';
    }

    /**
     * Display the saved queries for this database
     */
    public function showSavedQueries()
    {
        $queries = $this->querySaver->getQueries();
        if (!$queries) return;

        echo '<ul>';
        foreach ($queries as $query) {
            $link = $this->selfLink(false, ['sql' => $query['sql']]);
            $del = $this->selfLink(false, ['cmd' => 'delete_query', 'name' => $query['name']]);

            echo '<li><div class="li">';
            echo '<a href="' . $link . '">' . hsc($query['name']) . '</a>';
            echo ' [<a href="' . $del . '">' . $this->getLang('delete_query') . '</a>]';
            echo '</div></li>';
        }
        echo '</ul>';
    }
}
