<?php

require_once __DIR__.'/renderer.php';

function ah_havnekart_shortcode(){

    ob_start();

    ?>

    <div id="havnekart-wrapper" style="position:relative">

        <img 
        src="<?php echo plugin_dir_url(__FILE__).'/../../assets/havnekart-base.png'; ?>"
        style="width:100%;display:block">

        <svg id="havnekart-layer"
        style="position:absolute;top:0;left:0;width:100%;height:100%">

        <?php ah_render_map(); ?>

        </svg>

    </div>

    <?php

    return ob_get_clean();
}

add_shortcode("havnekart","ah_havnekart_shortcode");
