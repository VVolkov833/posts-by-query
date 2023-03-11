;'use strict';
function FCP_Advisor($input, arr, options = {}, func = ()=>{}) {

    const $ = jQuery,
        css_class = 'fcp-advisor-holder';
    let init_val = '';

    if ( !$input || !$input instanceof $ || !arr ) { return }
    
    if ( $input.is( ':focus' ) ) {
        list_holder_fill();
    }

    $input.on( 'focus', function() {
        list_holder_fill();
    });
    $input.on( 'input', function() {
        list_holder_fill();
    });
    $input.on( 'keydown', function(e) {
        if ( ~['ArrowDown','ArrowUp'].indexOf( e.key ) ) {
            e.preventDefault();
        }
        if ( e.key === 'ArrowDown' ) {
            list_holder_next();
            return;
        }
        if ( e.key === 'ArrowUp' ) {
            list_holder_prev();
            return;
        }
        if ( !$holder().length ) {
            return;
        }
        if ( ~['Enter','Escape'].indexOf( e.key ) ) {
            e.preventDefault();
        }
        if ( e.key === 'Enter' || e.key === 'Tab' ) {
            list_holder_remove();
            func( $input.val() );
            return;
        }
        if ( e.key === 'Escape' ) {
            $input.val( init_val );
            list_holder_remove();
        }
    });

    function $holder() {
        return $input.next( '.' + css_class );
    }
    function $active() {
        return $holder().children( '.active' );
    }

    function list_holder_add() {
        if ( $holder().length ) { return }
        
        const width = $input.outerWidth(),
            height = $input.outerHeight(),
            position = $input.position();

        $input.after( $( '<div>', {
            'class': css_class,
            'style': 'left:' + position.left + 'px;' +
                     'top:' + ( position.top + height ) + 'px;' +
                     'width:' + width + 'px;'
        }) );

        document.addEventListener( 'click', list_holder_remove ); // blur event doesn't pass through the click
        
        // $input.next()[0].attachShadow( { mode: 'open' } ); // not sure I need this here, so it's not
        
    }
    
    function list_holder_remove(e) {
        if ( e && e.target === $input[0] ) { return }
        $holder().remove();
        document.removeEventListener( 'click', list_holder_remove, false );
    }

    function list_holder_fill() {
        if ( $input.val().trim().length < ( options?.start_length || 1 ) ) {
            list_holder_remove();
            return;
        }
        
        init_val = $input.val();

        list_holder_content();
    }

    let controller = new AbortController();
    async function list_holder_content() {

        list_holder_remove();

        const value = $input.val().trim().toLowerCase();
        let list = [];
        console.log( '~~'+value+'...' );
        if ( typeof arr === 'function' ) {
            controller.abort();
            controller = new AbortController();
            list = await arr( controller );
            console.log( '~~'+value+' !!!' );
        } else
        if ( Array.isArray( arr ) ) {
            list = arr;
        }
        
        if ( !list.length === 0 ) { return }

        let exclude = options?.exclude || [];
        exclude = typeof exclude === 'function' ? exclude() : exclude;
        exclude = typeof exclude === 'string' ? [ exclude ] : exclude;
        list = list.filter( a => {
            return !exclude.includes(a);
        });

        if ( !list.length === 0 ) { return }

        let arr_low = list.map( a => {
            return a
                .toLowerCase()
                .replace( /&amp;|&lt;|&gt;|&quot;|&#039;|&shy;/g, function(a) {
                    return { '&amp;': '&', '&lt;': '<', '&gt;': '>', '&quot;': '"', '&#039;': '\'', '&shy;': '' }[a];
                });
        });

        let primary = [], secondary = [], tertiary = [];
        for ( let i = 0, j = arr_low.length; i < j; i++ ) {
            if ( arr_low[i].indexOf( value ) === 0 ) { // the entry is the first words
                primary.push( '<button tabindex="-1">'+list[i]+'</button>' );
                continue;
            }
            if ( arr_low[i].indexOf( value ) > 0 && options?.full ) { // the entry is in the line
                secondary.push( '<button tabindex="-1">'+list[i]+'</button>' );
                continue;
            }

            if ( !options?.all_correct ) { break; } // all entries fit by default
            tertiary.push( '<button tabindex="-1">'+list[i]+'</button>' );
        }

        let content = [ ...primary, ...secondary, ...tertiary ].slice( 0, ( options?.lines || 5 ) );
        
        if ( !content.length ) { return }

        if ( !$holder().length ) {
            list_holder_add();
        }

        $holder().empty().append( content.join( '' ) );
        
        $holder().children().each( function() {
            $( this ).click( function() {
                $input.val( $( this ).text() );
                list_holder_remove();
                func( $input.val() );
            });
        });

    }

    function list_holder_next() {
        list_holder_select( 'next' );
    }
    function list_holder_prev() {
        list_holder_select( 'prev' );
    }
    function list_holder_select(a) {
        if ( !~['next','prev'].indexOf( a ) ) { return }
        if ( !$holder().length ) {
            list_holder_fill();
        }
        if ( $active().length && $active()[a]().length ) {
            $active().removeClass( 'active' )[a]().addClass( 'active' );
            list_holder_apply();
            return;
        }
        a = {
            'next': 'first',
            'prev': 'last'
        }[a];
        $holder().children().removeClass( 'active' )[a]().addClass( 'active' );
        list_holder_apply();
    }
    
    function list_holder_apply() {
        if ( !$active().length ) { return }
        $input.val( $active().text() );
    }

}
// ++ add cache to search by already entered phrase