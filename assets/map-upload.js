jQuery(document).ready(function($){

var frame;

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

var attachment = frame.state().get('selection').first().toJSON();

$('#ah_havnekart_image').val(attachment.id);

});

frame.open();

});

});
