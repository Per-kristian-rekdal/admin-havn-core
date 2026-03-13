<?php

require_once __DIR__.'/geometry.php';

function ah_render_map(){

    $slots = get_posts([
        "post_type"=>"batplass",
        "numberposts"=>-1
    ]);

    foreach($slots as $slot){

        $plassnr = intval(get_post_meta($slot->ID,"plassnr",true));
        $bredde  = floatval(get_post_meta($slot->ID,"bredde",true));
        $status  = get_post_meta($slot->ID,"status",true);

        $index = floor(($plassnr-1)/2);

        $x = 0.5;
        $y = 0.1 + ($index*0.04);

        if($plassnr % 2 == 1){

            $x1 = $x-0.05;
            $x2 = $x;

        }else{

            $x1 = $x;
            $x2 = $x+0.05;

        }

        echo '<line
        x1="'.($x1*100).'%"
        y1="'.($y*100).'%"
        x2="'.($x2*100).'%"
        y2="'.($y*100).'%"
        stroke="'.ah_status_color($status).'"
        stroke-width="3"
        />';

        echo '<text
        x="'.($x*100).'%"
        y="'.($y*100-1).'%"
        font-size="12"
        text-anchor="middle">'.$plassnr.'</text>';
    }
}

function ah_status_color($status){

    switch($status){

        case "sperret":
            return "#ff4444";

        case "til salgs":
            return "#ffcc00";

        case "til leie":
            return "#33cc66";

        default:
            return "#4488ff";
    }
}
