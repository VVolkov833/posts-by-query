!function(){let a=setInterval(function(){let b=document.readyState;if(b!=='complete'&&b!=='interactive'||typeof jQuery==='undefined'||typeof FCP_Advisor==='undefined'){return}clearInterval(a);a=null;const $ = jQuery;

    // switching tabs

    // particular posts autocomplete
    let store = {};
    const exclude = () => {
        return [ ...document.querySelectorAll( '#fcppbk-posts input:checked + span' ) ].map( a => a.innerHTML );
    };

    FCP_Advisor( $( '#fcppbk-picker' ), async () => {

        const data = await fetch( '/wp-json/fcppbk/v1/posts/' + encodeURI( $( '#fcppbk-picker' ).val() ) )
            .then( response => response.json() )
            .then( data => data.data?.status === 404 ? [] : data );

        store = data;

        return Object.values( data );

    }, {
        cache: true,
        lines: 20,
        full: true,
        //start_length: 3,
        exclude,
    }, value => {
        const key = Object.keys( store ).find( key => store[key] === value );
        $( '#fcppbk-picker' ).val( '' );
        if ( !store[key] ) { return }
        $( '#fcppbk-posts' ).append( `<label><input type="checkbox" name="fcppbk-posts[]" value="${key}" checked> <span>${value}</span></label>` );
    });

    // delete on uncheck
    $( '#fcppbk-posts input' ).change( e => {
        e.target.parentNode.remove();
    });

}, 300 )}();