<?php

if (!defined('ABSPATH')) {
    exit;
}

/* -------------------------
   ADMIN INNSTILLING
--------------------------*/

add_action('admin_menu', function () {

    add_options_page(
        'Havnekart',
        'Havnekart',
        'manage_options',
        'tes-havnekart',
        'tes_havnekart_settings'
    );

});

function tes_havnekart_settings(){

    if(isset($_POST['kart_bilde'])){
        update_option('tes_havnekart_bilde', esc_url($_POST['kart_bilde']));
    }

    $img = get_option('tes_havnekart_bilde');

?>

<div class="wrap">
<h1>Havnekart</h1>

<form method="post">

<input type="text" name="kart_bilde" value="<?php echo esc_attr($img); ?>" style="width:60%">
<button class="button button-primary">Lagre</button>

<p>Lim inn URL til bakgrunnsbilde fra Media.</p>

</form>

</div>

<?php
}

function tes_generate_plasser($startX,$startY,$endX,$endY,$count,$offset){

    $html = '';

    for($i=0;$i<$count;$i++){

        $t = $i/($count-1);

        $x = $startX + ($endX-$startX)*$t;
        $y = $startY + ($endY-$startY)*$t;

        $nr = str_pad($i+1,2,'0',STR_PAD_LEFT);

        $left = ($x-$offset)*100;
        $right = ($x+$offset)*100;

        $top = $y*100;

        $html .= '<div class="tes-plass" style="left:'.$left.'%;top:'.$top.'%">'.$nr.'</div>';
        $html .= '<div class="tes-plass" style="left:'.$right.'%;top:'.$top.'%">'.$nr.'</div>';
    }

    return $html;
}
/* -------------------------
   SHORTCODE
--------------------------*/

function tes_havnekart_shortcode() {

$img = get_option('tes_havnekart_bilde');

ob_start();
?>

<div class="tes-havnekart">

<img src="<?php echo esc_url($img); ?>" class="tes-havnekart-bg">

<?php

echo tes_generate_plasser(0.36,0.60,0.36,0.28,28,0.035); //Pir1
echo tes_generate_plasser(0.56,0.60,0.56,0.27,31,0.035); //PIR2
echo tes_generate_plasser(0.67,0.60,0.67,0.27,36,0.035); //Pir3

?>

</div>

<style>

.tes-havnekart{
position:relative;
max-width:1200px;
margin:auto;
}

.tes-havnekart-bg{
width:100%;
border-radius:8px;
}

.tes-plass{

position:absolute;
transform:translate(-50%,-50%);

background:#2563eb;
color:white;

font-size:10px;
padding:2px 4px;

border-radius:4px;

}

</style>

<?php

return ob_get_clean();

}

add_shortcode('tes_havnekart','tes_havnekart_shortcode');