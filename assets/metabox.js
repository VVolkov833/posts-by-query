!function(){let a=setInterval(function(){let b=document.readyState;if(b!=='complete'&&b!=='interactive'||typeof jQuery==='undefined'||typeof FCP_Advisor==='undefined'){return}clearInterval(a);a=null;const $ = jQuery;

    const slug = 'fcppbk',
          prefix = slug + '-';

    // --------------- fetch data
    const fetch_data = async ( field_name, controller ) => {
        const query = $( `#${prefix}${field_name}` ).val().trim();
        const post_id = +$( '#post_ID' ).val(); // to exclude self
        return await fetch(
            `/wp-json/${slug}/v1/${field_name}/` + encodeURI( query ),
            {
                method: 'get',
                headers: { 'X-WP-Nonce' : $( `#${prefix}rest-nonce` ).val() },
                signal: controller?.signal || null,
            }
        )
        .then( response => response.status === 200 && response.json() || [] )
        .then( data => data?.filter( el => el.id !== post_id ) || [] )
        .catch( error => [] );
    };

    // --------------- tabs
    (() => {
        const tab_effects = () => {
            const active_tab = $( `.${prefix}tabs > input[type=radio][name=${prefix}variants]:checked` ).val();
            const effecting = { 'query' : 'posts-preview', 'list' : 'posts' };
            $( `#${prefix}tiles > fieldset` ).css( 'display', 'none' );
            $( `#${prefix}tiles > fieldset#${prefix}${effecting[active_tab]}` ).css( 'display', 'block' );
        };
        tab_effects();

        // switching tabs
        $( `#${prefix}query, #${prefix}list` ).focus( e => {
            const target_value = e.target.id.replace( prefix, '' );
            $( `.${prefix}tabs > input[type=radio][name=${prefix}variants][value=${target_value}]` ).prop( 'checked', true );
            tab_effects();
        });
    })();

    // --------------- the list field
    (() => {
        let store = {};
        const exclude = () => {
            return [ ...document.querySelectorAll( `#${prefix}posts input:checked + span` ) ].map( a => a.textContent );
        };

        FCP_Advisor( $( `#${prefix}list` ), async controller => {

            const clear_html = string => {
                const doc = document.implementation.createHTMLDocument( '' ),
                      a = doc.createElement( 'div' );
                a.innerHTML = string;
                return a.textContent;
            };

            const data = await fetch_data( 'list', controller || null );

            store = Object.values( data ).map( a => {
                a['title'] = clear_html( a['title'] ); 
                return a;
            } );
            return store.map( a => a['title'] );

        }, { // options
            lines: 10, // limit the print
            full: true, // the string can contain the search phrase, not only start with it
            //start_length: 3,
            exclude, // the list of array items to exclude
            all_correct: true, // all provided lines are correct, just range them and print even if no matches

        }, value => { // function on choose
            const key = Object.keys( store ).find( key => store[key]['title'] === value );
            $( `#${prefix}list` ).val( '' ); // ++keep the entered value
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
        const loading_status_remove = () => { // ++ uptimize for here and advisor as messy, including the controller
            $input[0].classList.remove( ...(() => { return [ ...$input[0].classList ].filter( a => a.includes('-status-') ) })() );
        }
        const loading_status_add = status => {
            loading_status_remove();
            if ( !status ) { return; }
            $input[0].classList.add( 'fcp-advisor-status-'+status );
        };
        const $input = $( `#${prefix}query` );
        const $target = $( `#${prefix}posts-preview` );
        let controller = new AbortController();
        const results = async () => {
            if ( $input.val().trim().length < 1 ) { return }
            let aborted = false;
            let fail = setTimeout(()=>{});
            loading_status_add( 'loading' );
            controller.abort();
            controller = new AbortController();
            controller.signal.onabort = () => aborted = true;
            $target.html( '' ); // or loader
            const data = await fetch_data( 'query', controller );
            fail = aborted && setTimeout(()=>{}) || setTimeout( () => loading_status_add( 'failed' ) );
            if ( data.length !== 0 ) {
                clearTimeout( fail );
                loading_status_add( 'success' );
            }
            $target.html( '' );
            [ ...Object.values( data ).slice( 0, 4 ), {title: '... ... ...'} ].forEach( value => {
                $target.append( `<label><input type="checkbox" disabled> <span>${value['date']?`(${value['date']})`:``} ${value['title']}</span></label>` );
            });
        };
        results();
        $input.on( 'focus', results );
        $input.on( 'input', results );
        $input.on( 'blur', loading_status_remove );
    })();

}, 300 )}();
// ++ no articles message as input??