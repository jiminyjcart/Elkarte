<?php

/**
 * This simply returns the manifest json info as generated by the manifest controller
 *
 * @package   ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause (see accompanying LICENSE.txt file)
 *
 * @version 2.0 dev
 *
 */

global $ssi_guest_access;

// Need to bootstrap to do much
use ElkArte\ManifestMinimus;

require_once(__DIR__ . '/bootstrap.php');
$ssi_guest_access = true;
new Bootstrap(true);

// Our Manifest controller
$controller = new ManifestMinimus();
$controller->create();

// Always exit as successful
exit(0);