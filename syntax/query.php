<?php
/**
 * DokuWiki Plugin sqlite (Syntax Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Szymon Olewniczak <it@rid.pl>
 */

// must be run within Dokuwiki
if (!defined('DOKU_INC')) {
    die();
}

class syntax_plugin_sqlite_query extends DokuWiki_Syntax_Plugin
{
    /**
     * @return string Syntax mode type
     */
    public function getType()
    {
        return 'substition';
    }

    /**
     * @return string Paragraph type
     */
    public function getPType()
    {
        return 'block';
    }

    /**
     * @return int Sort order - Low numbers go before high numbers
     */
    public function getSort()
    {
        return 32;
    }

    /**
     * Connect lookup pattern to lexer.
     *
     * @param string $mode Parser mode
     */
    public function connectTo($mode)
    {
        $this->Lexer->addSpecialPattern('<sqlite.*?>.*?</sqlite>',$mode,'plugin_sqlite_query');
    }

    /**
     * Handle matches of the sqlite syntax
     *
     * @param string       $match   The match of the syntax
     * @param int          $state   The state of the handler
     * @param int          $pos     The position in the document
     * @param Doku_Handler $handler The handler
     *
     * @return array Data for the renderer
     */
    public function handle($match, $state, $pos, Doku_Handler $handler)
    {
        $xml = simplexml_load_string($match);
        $attributes = [];
        foreach($xml[0]->attributes() as $a => $b) {
            $attributes[$a] = (string) $b;
        }
        $tag_value = (string) $xml[0];
        list($db, $name) = explode('.', $tag_value);


        $data = ['db' => $db, 'name' => $name, 'attributes' => $attributes];
        return $data;
    }

    /**
     * Render xhtml output or metadata
     *
     * @param string        $mode     Renderer mode (supported modes: xhtml)
     * @param Doku_Renderer $renderer The renderer
     * @param array         $data     The data from the handler() function
     *
     * @return bool If rendering was successful.
     */
    public function render($mode, Doku_Renderer $renderer, $data)
    {
        global $conf;

        if ($mode !== 'xhtml') {
            return false;
        }

        /** @var $DBI helper_plugin_sqlite */
        $DBI        = plugin_load('helper', 'sqlite');

        /** @var $helper helper_plugin_sqlite */
        $sqlite_db = plugin_load('helper', 'sqlite');
        $sqlite_db->init('sqlite', DOKU_PLUGIN . 'sqlite/db/');

        $db = $data['db'];
        $query_name = $data['name'];
        if(!$DBI->init($db, '')) {
            echo '<div class="error">unknown database: '.$db.'</div>';
            return false;
        }

        $res = $sqlite_db->query("SELECT sql FROM queries WHERE db=? AND name=?", $db, $query_name);
        $sql = $sqlite_db->res2single($res);
        if (empty($sql)) {
            echo '<div class="error">unknown query: '.$query_name.'</div>';
            return false;
        }

        $res = $DBI->query($sql);
        if(!$res) {
            echo '<div class="error">cannot execute query: '.$sql.'</div>';
            return false;
        }
        $result = $DBI->res2arr($res);

        $renderer->doc .= '<div>';
        $ths = array_keys($result[0]);
        $renderer->doc .= '<table class="inline">';
        $renderer->doc .= '<tr>';
        foreach($ths as $th) {
            $renderer->doc .= '<th>'.hsc($th).'</th>';
        }
        $renderer->doc .= '</tr>';
        foreach($result as $row) {
            $renderer->doc .= '<tr>';
            $tds = array_values($row);
            foreach($tds as $i => $td) {
                if($td === null) $td='␀';
                if (isset($data['attributes']["col$i-parser"]) && $data['attributes']["col$i-parser"] == 'wiki') {
                    $td = p_render($mode, p_get_instructions($td), $info);
                    $renderer->doc .= '<td>'.$td.'</td>';
                } else {
                    $renderer->doc .= '<td>'.hsc($td).'</td>';
                }
            }
            $renderer->doc .= '</tr>';
        }
        $renderer->doc .= '</table>';
        $renderer->doc .= '</div>';

        return true;
    }
}
