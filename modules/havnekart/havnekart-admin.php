<?php
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
