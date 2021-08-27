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
        list($db, $query_name) = explode('.', $tag_value);

        $parsers = [];
        $needle = 'parser_';
        foreach ($attributes as $name => $value) {
            $length = strlen($needle);
            if (substr($name, 0, $length) === $needle) {
                list($_, $col) = explode('_', $name);
                $parsers[$col] = $value;
            }
        }

        $data = ['db' => $db, 'query_name' => $query_name, 'parsers' => $parsers];
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
        $DBI = plugin_load('helper', 'sqlite');

        /** @var $helper helper_plugin_sqlite */
        $sqlite_db = plugin_load('helper', 'sqlite');
        $sqlite_db->init('sqlite', DOKU_PLUGIN . 'sqlite/db/');

        $db = $data['db'];
        $query_name = $data['query_name'];

        $res = $sqlite_db->query("SELECT sql FROM queries WHERE db=? AND name=?", $db, $query_name);
        $sql = $sqlite_db->res2single($res);
        if (empty($sql)) {
            msg('unknown database: ' . $db . ' or query name: '.$query_name, -1);
            return false;
        }

        if(!$DBI->init($db, '')) {
            msg('cannot initialize db: '.$db, -1);
            return false;
        }

        $res = $DBI->query($sql);
        if(!$res) {
            msg('cannot execute query: '.$sql, -1);
            return false;
        }
        $result = $DBI->res2arr($res);

        // check if we use any parsers
        if (count($data['parsers']) > 0) {
            $class_name = '\dokuwiki\plugin\struct\meta\Column';
            if (!class_exists($class_name)) {
                msg('install struct plugin to use parsers', -1);
                return false;
            }
            $parser_types = $class_name::allTypes();
        }

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
                if (isset($data['parsers'][$i])) {
                    $parser = $data['parsers'][$i];
                    if (!isset($parser_types[$parser])) {
                        msg('unknown parser: ' . $parser, -1);
                        $renderer->doc .= '<td>'.hsc($td).'</td>';
                    } else {
                        /** @var \dokuwiki\plugin\struct\types\AbstractBaseType $parser */
                        $parser = new $parser_types[$parser]();
                        $renderer->doc .= '<td>';
                        $parser->renderValue($td, $renderer, $mode);
                        $renderer->doc .= '</td>';
                    }
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
