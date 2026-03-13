<?php

function ah_register_rental(){

register_post_type('ah_rental',[
'label'=>'Utleie',
'public'=>true,
'show_in_menu'=>true,
'menu_icon'=>'dashicons-calendar',
'supports'=>['title']
]);

}

add_action('init','ah_register_rental');
