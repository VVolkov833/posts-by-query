!function(){let a=setInterval(function(){let b=document.readyState;if(b!=='complete'&&b!=='interactive'||typeof jQuery==='undefined'||typeof FCP_Advisor==='undefined'){return}clearInterval(a);a=null;const $ = jQuery;

    const slug = 'fcppbk',
          prefix = slug + '-';

    // --------------- tabs
    const tab_effects = () => {
        const active_tab = $( `input[type=radio][name=${prefix}variants]:checked` ).val();
        const effecting = { 'query' : 'posts-preview', 'list' : 'posts' };
        $( `fieldset` ).css( 'display', 'none' );
        $( `fieldset#${prefix}${effecting[active_tab]}` ).css( 'display', 'block' );
    };
    tab_effects();

    // switching tabs
    $( `#${prefix}query, #${prefix}list` ).focus( e => {
        const target_value = e.target.id.replace( prefix, '' );
        $( `.${prefix}tabs > input[type=radio][name=${prefix}variants][value=${target_value}]` ).prop( 'checked', true );
        tab_effects();
    });

    // fetch data
    const fetch_data = async field_name => {
        return await fetch( `/wp-json/${slug}/v1/posts/` + encodeURI( $( `#${prefix}${field_name}` ).val() ) )
            .then( response => response.json() )
            .then( data => data.data?.status === 404 ? [] : data );

    };

    // --------------- the list field
    let store = {};
    const exclude = () => {
        return [ ...document.querySelectorAll( `#${prefix}posts input:checked + span` ) ].map( a => a.innerHTML );
    };

    FCP_Advisor( $( `#${prefix}list` ), async () => {

        const data = await fetch_data( 'list' );

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
        $( `#${prefix}list` ).val( '' );
        if ( !store[key] ) { return }
        $( `#${prefix}posts` ).append( `<label><input type="checkbox" name="${prefix}posts[]" value="${key}" checked> <span>${value}</span></label>` );
    });

    // delete on uncheck
    $( `#${prefix}posts input` ).change( e => {
        e.target.parentNode.remove();
    });
    
    // --------------- the query field
    (() => {
        const $input = $( `#${prefix}query` );
        const $target = $( `#${prefix}posts-preview` );
        const results = async () => {
            $target.html( '' );
            if ( $input.val().length < 1 ) { return }
            const data = await fetch_data( 'query' ); // ++ order by date!!!
            // ++foreach with limit and ...

        };
        if ( $input.is( ':focus' ) ) { results() }
        $input.on( 'focus', results );
        $input.on( 'input', results );
    })();

}, 300 )}();