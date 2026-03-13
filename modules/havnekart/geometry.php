
<?php

function ah_distance($x1,$y1,$x2,$y2){

    return sqrt(($x2-$x1)*($x2-$x1)+($y2-$y1)*($y2-$y1));
}

function ah_direction($x1,$y1,$x2,$y2){

    $len = ah_distance($x1,$y1,$x2,$y2);

    return [
        "dx"=>($x2-$x1)/$len,
        "dy"=>($y2-$y1)/$len
    ];
}

function ah_normal($dx,$dy){

    return [
        "nx"=>-$dy,
        "ny"=>$dx
    ];
}
