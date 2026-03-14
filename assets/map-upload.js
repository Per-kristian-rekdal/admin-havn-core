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

const map = document.getElementById('havnekart-base');

if(map){

map.onclick = function(e){

if(!mode) return;

const rect = this.getBoundingClientRect();

const x = (e.clientX - rect.left) / rect.width;
const y = (e.clientY - rect.top) / rect.height;

const coord = x.toFixed(5)+','+y.toFixed(5);

document.querySelector('#pir-'+activePir+'-'+mode).value = coord;

mode = null;

};

}
