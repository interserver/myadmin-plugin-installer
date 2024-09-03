<?php
/**
 * Pre-Config Functions.   This stuff is called almost instantly
 * so it needs to be defined before any of the rest of the program can go.
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2019
 * @package MyAdmin
 * @category Config
 */

/**
 * register_module()
 * @param string     $module
 * @param array|bool $settings
 * @return void
 */
function register_module($module, $settings = false)
{
    if ($settings === false) {
        $settings = [];
    }
    if (!isset($GLOBALS['modules'])) {
        $GLOBALS['modules'] = [];
    }
    $modules = $GLOBALS['modules'];
    $modules[$module] = $settings;
    $GLOBALS['modules'] = $modules;
}

/**
 * get_module_stuff()
 * @param string $module
 * @return array
 */
function get_module_stuff($module = 'default')
{
    $module = get_module_name($module);
    return [
        get_module_db($module),
        $module,
        get_module_settings($module)
    ];
}

/**
 * get_module_name()
 * gets the name of a module, or makes sure that the given module exists, if not returns default
 *
 * @param string $module the module name your attempting to validate / get the name of
 * @return string the name of the module
 */
function get_module_name($module = 'default')
{
    if ($module != 'default') {
        if (isset($GLOBALS[$module.'_dbh'])) {
            return $module;
        }
        if (isset($GLOBALS['modules'][$module])) {
            return $module;
        } elseif (isset($_REQUEST['module']) && isset($GLOBALS['modules'][$_REQUEST['module']])) {
            return $_REQUEST['module'];
        }
    }
    $tkeys = array_keys($GLOBALS['modules']);
    if (count($tkeys) > 0) {
        foreach ($tkeys as $idx => $key) {
            if ($key != 'default') {
                return $key;
            }
        }
    }
    return 'default';
}

/**
 * get_module_settings()
 * gets the array of settings for a given module, or a specific setting for that module
 *
 * @param string $module
 * @param bool|string $setting optional parameter, false to return all settings, or a specific setting name to return that setting
 * @return array|false array of settings or false if no setting
 */
function get_module_settings($module = 'default', $setting = false)
{
    if (!isset($GLOBALS['modules'][$module])) {
        $keys = array_keys($GLOBALS['modules']);
        $module = $keys[0];
    }
    if ($setting !== false) {
        if (isset($GLOBALS['modules'][$module][$setting])) {
            return $GLOBALS['modules'][$module][$setting];
        } else {
            return false;
        }
    } else {
        return $GLOBALS['modules'][$module];
    }
}

/**
 * @param $service
 * @return mixed
 */
function get_service_define($service)
{
    return $GLOBALS['tf']->get_service_define($service);
}

/**
 * @param $module
 * @return bool
 */
function has_module_db($module)
{
    return isset($GLOBALS[$module.'_dbh']);
}

/**
 * gets the database handler for a given module
 *
 * @param string $module the name of the module to get the dbh for
 * @return Db the database handler resource
 */
function get_module_db($module)
{
    if ($module == 'powerdns') {
        if (!isset($GLOBALS['powerdns_dbh'])) {
            $GLOBALS['powerdns_dbh'] = new \MyDb\Mdb2\Db(POWERDNS_DB, POWERDNS_USER, POWERDNS_PASSWORD, POWERDNS_HOST);
            $GLOBALS['powerdns_dbh']->Type = 'mysqli';
        }
        return clone $GLOBALS['powerdns_dbh'];
    } elseif ($module == 'zonemta') {
        if (!isset($GLOBALS['zonemta_dbh'])) {
            $GLOBALS['zonemta_dbh'] = new \MyDb\Mysqli\Db(ZONEMTA_MYSQL_DB, ZONEMTA_MYSQL_USERNAME, ZONEMTA_MYSQL_PASSWORD, ZONEMTA_MYSQL_HOST);
            $GLOBALS['zonemta_dbh']->Type = 'mysqli';
        }
        return clone $GLOBALS['zonemta_dbh'];
    } else {
        if (isset($GLOBALS[$module.'_dbh'])) {
            return clone $GLOBALS[$module.'_dbh'];
        } else {
            if ($module != '' && $module != 'default') {
                myadmin_log('myadmin', 'info', "Tried to get_module_db({$module}) and GLOBALS[{$module}_dbh] does not exist, falling back to GLOBALS['tf']->db", __LINE__, __FILE__, $module);
            }
            return clone $GLOBALS['tf']->db;
        }
    }
}

/**
 * get_valid_module()
 * returns the module name if a valid module or default if not
 *
 * @param string $module
 * @return string the/a validated module name
 */
function get_valid_module($module = 'default')
{
    if (isset($GLOBALS['modules'][$module])) {
        return $module;
    } else {
        return 'default';
    }
}
