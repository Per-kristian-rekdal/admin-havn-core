jQuery(document).ready(function($){

let frame;

$('#ah_select_map').on('click', function(e){

e.preventDefault();

if(frame){
frame.open();
return;
}

frame = wp.media({
title: 'Velg havnekart',
button: { text: 'Bruk kart' },
multiple: false
});

frame.on('select', function(){

let attachment = frame.state().get('selection').first().toJSON();

$.post(ajaxurl, {
action: 'ah_save_map',
map_id: attachment.id
}, function(){

location.reload();

});

});

frame.open();

});

});
