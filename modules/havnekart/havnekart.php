
<?php
if (!defined('ABSPATH')) exit;

add_action('admin_menu','ah_havnekart_menu');

add_action('admin_enqueue_scripts', function(){

wp_enqueue_media();

wp_enqueue_script(
'ah-map-upload',
AH_PLUGIN_URL.'assets/map-upload.js',
['jquery'],
null,
true
);

});

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
function ah_admin_dashboard(){

echo "<h1>Admin Havn</h1>";
echo "<p>Administrasjon av småbåthavn.</p>";

}
function ah_havnekart_shortcode(){

    // hent valgt kart fra Media Library
    $image_id = get_option('admin_havn_map_image_id');

    if(!$image_id){
        return "<p>Ingen havnekart valgt i Admin Havn → Havnekart.</p>";
    }

    $map_url = wp_get_attachment_url($image_id);

    ob_start();
    ?>

    <div id="havnekart-wrapper" style="position:relative">

        <img 
            src="<?php echo esc_url($map_url); ?>"
            style="width:100%;display:block"
        >

        <svg 
            id="havnekart-layer"
            style="position:absolute;top:0;left:0;width:100%;height:100%">
        </svg>

    </div>

    <?php

    return ob_get_clean();
}

add_shortcode("havnekart","ah_havnekart_shortcode");
function ah_havnekart_admin(){

require_once AH_PLUGIN_PATH.'modules/havnekart/havnekart-admin.php';

}

