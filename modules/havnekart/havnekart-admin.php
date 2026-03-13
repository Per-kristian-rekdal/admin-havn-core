<?php

$map_id = get_option('ah_havnekart_image');
$map_url = $map_id ? wp_get_attachment_url($map_id) : '';
?>

<h2>Kartbilde</h2>

<input type="hidden" id="ah_havnekart_image" name="ah_havnekart_image" value="<?php echo esc_attr($map_id); ?>">

<button class="button" id="ah_select_map">Velg kart</button>

<br><br>

<?php if($map_url): ?>
<img src="<?php echo esc_url($map_url); ?>" style="max-width:100%;">
<?php endif; ?>
if (!defined('ABSPATH')) exit;
?>

<div class="wrap">

<h1>Havnekart – Kalibrering av pirer</h1>

<p>Klikk start og slutt på pirene.</p>

<div style="position:relative;max-width:1200px">

<img 
src="<?php echo AH_PLUGIN_URL.'assets/havnekart-base.png'; ?>" 
style="width:100%;display:block"
id="havnekart-base">

</div>

<hr>

<h2>Pir 1</h2>

<button class="button">Velg start</button>
<button class="button">Velg slutt</button>

<p>Start:</p>
<p>Slutt:</p>

<label>Pir bredde (meter)</label>
<input type="text">

<label>Pir lengde (meter)</label>
<input type="text>

<hr>

<h2>Pir 2</h2>

<button class="button">Velg start</button>
<button class="button">Velg slutt</button>

<hr>

<h2>Pir 3</h2>

<button class="button">Velg start</button>
<button class="button">Velg slutt</button>

<br><br>

<button class="button button-primary">Lagre pirer</button>

</div>
