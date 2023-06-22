<?php
/**
 * @noinspection PhpUndefinedMethodInspection
 * @noinspection PhpComposerExtensionStubsInspection
 */


namespace dokuwiki\plugin\sqlite;

/**
 * SQLite registered functions
 */
class Functions
{

    /**
     * Register all standard functions
     *
     * @param \PDO $pdo
     */
    public static function register($pdo)
    {
        $pdo->sqliteCreateAggregate(
            'GROUP_CONCAT_DISTINCT',
            [Functions::class, 'GroupConcatStep'],
            [Functions::class, 'GroupConcatFinalize']
        );

        $pdo->sqliteCreateFunction('GETACCESSLEVEL', [Functions::class, 'getAccessLevel'], 1);
        $pdo->sqliteCreateFunction('PAGEEXISTS', [Functions::class, 'pageExists'], 1);
        $pdo->sqliteCreateFunction('REGEXP', [Functions::class, 'regExp'], 2);
        $pdo->sqliteCreateFunction('CLEANID', 'cleanID', 1);
        $pdo->sqliteCreateFunction('RESOLVEPAGE', [Functions::class, 'resolvePage'], 1);
    }

    /**
     * Aggregation function for SQLite via PDO
     *
     * @link http://devzone.zend.com/article/863-SQLite-Lean-Mean-DB-Machine
     *
     * @param null|array &$context (reference) argument where processed data can be stored
     * @param int $rownumber current row number
     * @param string $string column value
     * @param string $separator separator added between values
     */
    public static function GroupConcatStep(&$context, $rownumber, $string, $separator = ',')
    {
        if (is_null($context)) {
            $context = [
                'sep' => $separator,
                'data' => []
            ];
        }

        $context['data'][] = $string;
        return $context;
    }

    /**
     * Aggregation function for SQLite via PDO
     *
     * @link http://devzone.zend.com/article/863-SQLite-Lean-Mean-DB-Machine
     *
     * @param null|array &$context (reference) data as collected in step callback
     * @param int $rownumber number of rows over which the aggregate was performed.
     * @return null|string
     */
    public static function GroupConcatFinalize(&$context, $rownumber)
    {
        if (!is_array($context)) {
            return null;
        }
        $context['data'] = array_unique($context['data']);
        if (empty($context['data'][0])) {
            return null;
        }
        return join($context['sep'], $context['data']);
    }


    /**
     * Callback checks the permissions for the current user
     *
     * This function is registered as a SQL function named GETACCESSLEVEL
     *
     * @param string $pageid page ID (needs to be resolved and cleaned)
     * @return int permission level
     */
    public static function getAccessLevel($pageid)
    {
        global $auth;
        if (!$auth) return AUTH_DELETE;

        static $aclcache = [];

        if (isset($aclcache[$pageid])) {
            return $aclcache[$pageid];
        }

        if (isHiddenPage($pageid)) {
            $acl = AUTH_NONE;
        } else {
            $acl = auth_quickaclcheck($pageid);
        }
        $aclcache[$pageid] = $acl;
        return $acl;
    }

    /**
     * Wrapper around page_exists() with static caching
     *
     * This function is registered as a SQL function named PAGEEXISTS
     *
     * @param string $pageid
     * @return int 0|1
     */
    public static function pageExists($pageid)
    {
        static $cache = [];
        if (!isset($cache[$pageid])) {
            $cache[$pageid] = page_exists($pageid);

        }
        return (int)$cache[$pageid];
    }

    /**
     * Match a regular expression against a value
     *
     * This function is registered as a SQL function named REGEXP
     *
     * @param string $regexp
     * @param string $value
     * @return bool
     */
    public static function regExp($regexp, $value)
    {
        $regexp = addcslashes($regexp, '/');
        return (bool)preg_match('/' . $regexp . '/u', $value);
    }

    /**
     * Resolves a page ID (relative namespaces, plurals etc)
     *
     * This function is registered as a SQL function named RESOLVEPAGE
     *
     * @param string $page The page ID to resolve
     * @param string $context The page ID (not namespace!) to resolve the page with
     * @return null|string
     */
    public static function resolvePage($page, $context)
    {
        if (is_null($page)) return null;
        if (is_null($context)) return cleanID($page);

        $ns = getNS($context);
        resolve_pageid($ns, $page, $exists);
        return $page;
    }

}
