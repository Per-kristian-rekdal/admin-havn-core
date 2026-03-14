<?php
if (!defined('ABSPATH')) exit;

$map_id = get_option('ah_havnekart_image');
$map_url = $map_id ? wp_get_attachment_url($map_id) : '';
?>

<div class="wrap">

<h1>Havnekart – Kalibrering av pirer</h1>

<h2>Kartbilde</h2>

<input type="hidden" id="ah_havnekart_image" value="<?php echo esc_attr($map_id); ?>">

<button class="button" id="ah_select_map">Velg kart</button>

<br><br>

<?php if($map_url): ?>

<div style="position:relative;max-width:1200px">

<img
src="<?php echo esc_url($map_url); ?>"
style="width:100%;display:block;cursor:crosshair"
id="havnekart-base">

</div>

<?php else: ?>

<p><b>Ingen kart valgt.</b></p>

<?php endif; ?>

<hr>

<h2>Pirer</h2>

<?php
$piers = ['1','2','3'];

foreach($piers as $pir){
?>

<h3>Pir <?php echo $pir; ?></h3>

<button class="button set-start" data-pir="<?php echo $pir; ?>">
Velg start
</button>

<button class="button set-end" data-pir="<?php echo $pir; ?>">
Velg slutt
</button>

<br><br>

<label>Start:</label><br>
<input 
type="text" 
id="pir-<?php echo $pir; ?>-start"
style="width:300px"
readonly
>

<br><br>

<label>Slutt:</label><br>
<input 
type="text" 
id="pir-<?php echo $pir; ?>-end"
style="width:300px"
readonly
>

<br><br>

<label>Pir bredde (meter)</label><br>
<input type="text" style="width:150px">

<br><br>

<label>Pir lengde (meter)</label><br>
<input type="text" style="width:150px">

<hr>

<?php
}
?>

<button class="button button-primary">
Lagre pirer
</button>

</div>
