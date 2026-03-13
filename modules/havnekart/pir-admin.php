<?php

function ah_havnekart_admin_page(){

$image_id = get_option('admin_havn_map_image_id');

$image_url = $image_id ? wp_get_attachment_url($image_id) : '';

?>

<div class="wrap">

<h1>Havnekart</h1>

<p>Velg kart fra Media Library</p>

<input type="hidden" id="map_image_id" value="<?php echo esc_attr($image_id); ?>">

<button id="upload_map_button" class="button button-primary">
Velg kart
</button>

<br><br>

<img id="map_preview" src="<?php echo esc_url($image_url); ?>" style="max-width:800px">

</div>

<?php
}
