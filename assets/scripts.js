!function(){let a=setInterval(function(){let b=document.readyState;if(b!=='complete'&&b!=='interactive'||typeof jQuery==='undefined'||typeof FCP_Advisor==='undefined'){return}clearInterval(a);a=null;const $ = jQuery;

    const slug = 'fcppbk',
          prefix = slug + '-';

    // --------------- fetch data
    const fetch_data = async field_name => {
        return await fetch( `/wp-json/${slug}/v1/${field_name}/` + encodeURI( $( `#${prefix}${field_name}` ).val() ) )
            .then( response => response.status === 200 && response.json() || [] )
            .then( data => data || [] );

    };

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

    // --------------- the list field
    (() => {
        let store = {};
        const exclude = () => {
            return [ ...document.querySelectorAll( `#${prefix}posts input:checked + span` ) ].map( a => a.innerHTML );
        };

        FCP_Advisor( $( `#${prefix}list` ), async () => {

            const data = await fetch_data( 'list' );
            store = data;
            return Object.values( data ).map( a => a['title'] );

        }, { // options
            cache: true,
            lines: 20,
            full: true,
            //start_length: 3,
            exclude,

        }, value => { // function on choose
            const key = Object.keys( store ).find( key => store[key]['title'] === value );
            $( `#${prefix}list` ).val( '' );
            if ( !key ) { return }
            $( `#${prefix}posts` ).append( `<label><input type="checkbox" name="${prefix}posts[]" value="${store[key]['id']}" checked> <span>${value}</span></label>` );
            reset_checkboxes_event();
        });

        // delete on uncheck
        const reset_checkboxes_event = () => {
            const func = e => {
                e.target.parentNode.remove();
            };
            $( `#${prefix}posts input` ).off( 'change', func ).on( 'change', func );
        };
        reset_checkboxes_event();
    })();
    
    // --------------- the query field
    (() => {
        const $input = $( `#${prefix}query` );
        const $target = $( `#${prefix}posts-preview` );
        const results = async () => {
            $target.html( '' );
            if ( $input.val().length < 1 ) { return }
            const data = await fetch_data( 'query' );
            [ ...Object.values( data ).slice( 0, 4 ), {title: '... ... ...'} ].forEach( value => {
                $target.append( `<label><input type="checkbox" disabled> <span>${value['date']?`(${value['date']})`:``} ${value['title']}</span></label>` );
            });
        };
        results();
        $input.on( 'focus', results );
        $input.on( 'input', results );

    })();

}, 300 )}();

// ++ drag and drop to change the order of particular posts