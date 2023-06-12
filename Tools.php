<?php

namespace dokuwiki\plugin\sqlite;

class Tools {

    /**
     * Split sql queries on semicolons, unless when semicolons are quoted
     *
     * Usually you don't need this. It's only really needed if you need individual results for
     * multiple queries. For example in the admin interface.
     *
     * @param string $sql
     * @return string[] sql queries
     */
    public static function SQLstring2array($sql) {
        $statements = array();
        $len = strlen($sql);

        // Simple state machine to "parse" sql into single statements
        $in_str = false;
        $in_com = false;
        $statement = '';
        for($i=0; $i<$len; $i++){
            $prev = $i ? $sql[$i-1] : "\n";
            $char = $sql[$i];
            $next = $i < ($len - 1) ? $sql[$i+1] : '';

            // in comment? ignore everything until line end
            if($in_com){
                if($char == "\n"){
                    $in_com = false;
                }
                continue;
            }

            // handle strings
            if($in_str){
                if($char == "'"){
                    if($next == "'"){
                        // current char is an escape for the next
                        $statement .= $char . $next;
                        $i++;
                        continue;
                    }else{
                        // end of string
                        $statement .= $char;
                        $in_str = false;
                        continue;
                    }
                }
                // still in string
                $statement .= $char;
                continue;
            }

            // new comment?
            if($char == '-' && $next == '-' && $prev == "\n"){
                $in_com = true;
                continue;
            }

            // new string?
            if($char == "'"){
                $in_str = true;
                $statement .= $char;
                continue;
            }

            // the real delimiter
            if($char == ';'){
                $statements[] = trim($statement);
                $statement = '';
                continue;
            }

            // some standard query stuff
            $statement .= $char;
        }
        if($statement) $statements[] = trim($statement);

        return array_filter($statements); // remove empty statements
    }
}
