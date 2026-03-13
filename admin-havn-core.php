<?php
/*
Plugin Name: Admin Havn Core
Description: Administrasjon av småbåthavn (medlemmer, båtplasser, utleie, havnekart)
Version: 4.0
Author: Admin Havn
*/

if (!defined('ABSPATH')) exit;

define('AH_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('AH_PLUGIN_URL', plugin_dir_url(__FILE__));

/*
LOAD MODULES
*/

require_once AH_PLUGIN_PATH.'modules/members/members.php';
require_once AH_PLUGIN_PATH.'modules/berths/berths.php';
require_once AH_PLUGIN_PATH.'modules/rental/rental.php';
require_once AH_PLUGIN_PATH.'modules/havnekart/havnekart.php';
