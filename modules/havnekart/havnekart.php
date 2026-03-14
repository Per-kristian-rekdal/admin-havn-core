<?php
if (!defined('ABSPATH')) exit;

/*
------------------------------------------------
ADMIN MENU
------------------------------------------------
*/

add_action('admin_menu','ah_havnekart_menu');

function ah_havnekart_menu(){

add_menu_page(
'Admin Havn',
'Admin Havn',
'manage_options',
'admin-havn',
'ah_admin_dashboard',
'dashicons-admin-site',
3
);

add_submenu_page(
'admin-havn',
'Havnekart',
'Havnekart',
'manage_options',
'admin-havn-havnekart',
'ah_havnekart_admin'
);

}


/*
------------------------------------------------
ADMIN DASHBOARD
------------------------------------------------
*/

function ah_admin_dashboard(){

echo "<div class='wrap'>";
echo "<h1>Admin Havn</h1>";
echo "<p>Administrasjon av småbåthavn.</p>";
echo "</div>";

}


/*
------------------------------------------------
LOAD ADMIN PAGE
------------------------------------------------
*/

function ah_havnekart_admin(){

include AH_PLUGIN_PATH.'modules/havnekart/havnekart-admin.php';

}


/*
------------------------------------------------
LOAD ADMIN SCRIPTS
------------------------------------------------
*/

add_action('admin_enqueue_scripts','ah_havnekart_scripts');

function ah_havnekart_scripts(){

wp_enqueue_media();

wp_enqueue_script(
'ah-map-upload',
AH_PLUGIN_URL.'assets/map-upload.js',
['jquery'],
null,
true
);

}


/*
------------------------------------------------
SAVE MAP IMAGE (AJAX)
------------------------------------------------
*/

add_action('wp_ajax_ah_save_map','ah_save_map');

function ah_save_map(){

if(!current_user_can('manage_options')) exit;

update_option('ah_havnekart_image', intval($_POST['map_id']));

wp_die();

}


/*
------------------------------------------------
SHORTCODE
------------------------------------------------
*/

function ah_havnekart_shortcode(){

$image_id = get_option('ah_havnekart_image');

if(!$image_id){
return "<p>Ingen havnekart valgt i Admin Havn → Havnekart.</p>";
}

$map_url = wp_get_attachment_url($image_id);

ob_start();
?>

<div id="havnekart-wrapper" style="position:relative;max-width:1200px;margin:auto;">

<img 
src="<?php echo esc_url($map_url); ?>"
style="width:100%;display:block"
>

<svg
id="havnekart-layer"
style="position:absolute;top:0;left:0;width:100%;height:100%;">
</svg>

</div>

<?php

return ob_get_clean();

}

add_shortcode("havnekart","ah_havnekart_shortcode");
