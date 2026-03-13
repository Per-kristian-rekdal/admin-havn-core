<?php

function ah_register_berths(){

register_post_type('ah_berth',[
'label'=>'Båtplasser',
'public'=>true,
'show_in_menu'=>true,
'menu_icon'=>'dashicons-location',
'supports'=>['title']
]);

}

add_action('init','ah_register_berths');
