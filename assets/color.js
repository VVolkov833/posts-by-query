jQuery( document ).ready( $ => {

    $colors = $( 'input[type=color]' );

    $colors.each( (a, b) => { // a crutch to empty the type="color" value
        $b = $( b );
        $b.attr( 'type', 'text' );
        if ( $b.val() === '#000000' ) {
            $b.val( '' );
        }
    })

    $colors.wpColorPicker();

});