jQuery(document).ready(function($){

var frame;

$('#upload_map_button').click(function(e){

e.preventDefault();

frame = wp.media({
title: 'Velg havnekart',
button: { text: 'Bruk kart' },
multiple: false
});

frame.on('select', function(){

var attachment = frame.state().get('selection').first().toJSON();

$('#map_image_id').val(attachment.id);
$('#map_preview').attr('src',attachment.url);

});

frame.open();

});

});
