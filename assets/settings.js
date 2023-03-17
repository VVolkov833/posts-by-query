jQuery( document ).ready( $ => {

    $( 'input[type=text], input[type=number], button.image' ).each( (a,b) => {

        const clear = e => {
            $self = $( e.target );
            $self.prevAll( 'input' ).val( '' ).focus();
            $self.prevAll( 'button' ).html( 'No' )?.focus();
        };
        $( b ).after( `<input type="button" value="+" class="clear" />` ).next().on( 'click', clear );

    });

});