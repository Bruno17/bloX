<?php
/**
 * bloX
 *
 * @package blox
 * @subpackage snippet
 *
 * @var modX $modx
 * @var array $scriptProperties
 */
$output = '';

//include snippet file
include($modx->getOption('blox.core_path', null, $modx->getOption('core_path') . 'components/blox/blox.php'));

return $output;
