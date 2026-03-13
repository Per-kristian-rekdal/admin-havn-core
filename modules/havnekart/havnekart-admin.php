<?php

if (!defined('ABSPATH')) exit;

global $wpdb;

/* -------------------------------------------------------
   OPPRETT TABELL FOR PIRER
------------------------------------------------------- */

function tes_havnekart_create_table(){

    global $wpdb;

    $table = $wpdb->prefix . 'tes_havn_pir';

    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table (
        id INT NOT NULL AUTO_INCREMENT,
        pir VARCHAR(20) NOT NULL,
        start_x FLOAT,
        start_y FLOAT,
        end_x FLOAT,
        end_y FLOAT,
        pir_bredde FLOAT,
        pir_lengde FLOAT,
        PRIMARY KEY (id),
        UNIQUE KEY pir (pir)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);

}

tes_havnekart_create_table();


/* -------------------------------------------------------
   ADMIN SIDE
------------------------------------------------------- */

function tes_havnekart_admin_page(){

global $wpdb;

$pir_table = $wpdb->prefix . 'tes_havn_pir';


/* HENT PIRER FRA BÅTPLASS CPT */

$pirer = [1,2,3];


/* LAGRE DATA */

if(isset($_POST['save_pir'])){

    foreach($pirer as $pir){

        $start_key = 'start_'.$pir;
        $end_key = 'end_'.$pir;
        $bredde_key = 'bredde_'.$pir;
        $lengde_key = 'lengde_'.$pir;

        if(empty($_POST[$start_key]) || empty($_POST[$end_key])){
            continue;
        }

        $start = explode(',', $_POST[$start_key]);
        $end = explode(',', $_POST[$end_key]);

        $wpdb->replace(
            $pir_table,
            [
                'pir' => $pir,
                'start_x' => floatval($start[0]),
                'start_y' => floatval($start[1]),
                'end_x' => floatval($end[0]),
                'end_y' => floatval($end[1]),
                'pir_bredde' => floatval($_POST[$bredde_key]),
                'pir_lengde' => floatval($_POST[$lengde_key])
            ]
        );
    }

    echo "<div class='updated'><p>Pir-data lagret</p></div>";

}

?>


<div class="wrap">

<h1>Havnekart – Kalibrering av pirer</h1>

<p>Klikk start og slutt på pirene.</p>

<form method="post">

<div id="tes-map-container" style="position:relative;max-width:1000px">

<img id="tes-map"
src="<?php echo esc_url(get_option('tes_havnekart_bilde')); ?>"
style="width:100%;cursor:crosshair">

</div>


<?php foreach($pirer as $pir): ?>

<hr>

<h3>Pir <?php echo esc_html($pir); ?></h3>

<button type="button"
class="button set-start"
data-pir="<?php echo $pir; ?>">
Velg start
</button>

<button type="button"
class="button set-end"
data-pir="<?php echo $pir; ?>">
Velg slutt
</button>

<br><br>

Start:
<span id="start_label_<?php echo $pir; ?>"></span>

<br>

Slutt:
<span id="end_label_<?php echo $pir; ?>"></span>

<br><br>

<input type="hidden"
name="start_<?php echo $pir; ?>"
id="start_<?php echo $pir; ?>">

<input type="hidden"
name="end_<?php echo $pir; ?>"
id="end_<?php echo $pir; ?>">

Pir bredde (meter)

<input type="number"
step="0.1"
name="bredde_<?php echo $pir; ?>">

Pir lengde (meter)

<input type="number"
step="0.1"
name="lengde_<?php echo $pir; ?>">

<?php endforeach; ?>


<br><br>

<input type="submit"
name="save_pir"
class="button button-primary"
value="Lagre pirer">

</form>

</div>


<script>

let mode = null;
let activePir = null;

document.querySelectorAll('.set-start').forEach(btn => {

    btn.onclick = () => {

        mode = 'start';
        activePir = btn.dataset.pir;

    };

});


document.querySelectorAll('.set-end').forEach(btn => {

    btn.onclick = () => {

        mode = 'end';
        activePir = btn.dataset.pir;

    };

});


document.getElementById('tes-map').onclick = function(e){

    const rect = this.getBoundingClientRect();

    const x = (e.clientX - rect.left) / rect.width;
    const y = (e.clientY - rect.top) / rect.height;

    const coord = x.toFixed(5)+','+y.toFixed(5);

    if(mode === 'start'){

        document.getElementById('start_'+activePir).value = coord;
        document.getElementById('start_label_'+activePir).innerText = coord;

    }

    if(mode === 'end'){

        document.getElementById('end_'+activePir).value = coord;
        document.getElementById('end_label_'+activePir).innerText = coord;

    }

};

<script>

document.addEventListener("DOMContentLoaded", function(){

let mode = null;
let activePir = null;

const map = document.getElementById('tes-map');

document.querySelectorAll('.set-start').forEach(btn => {

    btn.addEventListener('click', function(){

        mode = 'start';
        activePir = this.dataset.pir;

    });

});

document.querySelectorAll('.set-end').forEach(btn => {

    btn.addEventListener('click', function(){

        mode = 'end';
        activePir = this.dataset.pir;

    });

});


map.addEventListener('click', function(e){

    if(!mode || !activePir) return;

    const rect = this.getBoundingClientRect();

    const x = (e.clientX - rect.left) / rect.width;
    const y = (e.clientY - rect.top) / rect.height;

    const coord = x.toFixed(5)+','+y.toFixed(5);

    if(mode === 'start'){

        document.getElementById('start_'+activePir).value = coord;
        document.getElementById('start_label_'+activePir).innerText = coord;

    }

    if(mode === 'end'){

        document.getElementById('end_'+activePir).value = coord;
        document.getElementById('end_label_'+activePir).innerText = coord;

    }

});

});

</script>


<?php

}