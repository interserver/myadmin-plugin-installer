<?php
/**
 * @author Joe Huss <detain@interserver.net>
 * @copyright 2025
 * @package Plugins
 */

/**
 * loads the files needed to run the given class or function
 *
 * @param $function
 * @return bool whether or not it found the given function/class
 */
function function_requirements($function)
{
    return $GLOBALS['tf']->function_requirements($function);
}
