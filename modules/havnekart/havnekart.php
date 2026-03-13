
<?php

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


