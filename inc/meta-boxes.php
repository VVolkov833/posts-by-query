<?php

// meta-boxes, on-page interface

namespace FCP\PostsByQuery;
defined( 'ABSPATH' ) || exit;

// admin interface for posts
add_action( 'add_meta_boxes', function() {
    if ( !current_user_can( 'administrator' ) ) { return; }
    if ( empty( get_types_to_apply_to() ) ) { return; }
    add_meta_box(
        FCPPBK_PREF.'posts-by-query',
        'Posts by Search Query',
        __NAMESPACE__.'\metabox_query',
        get_types_to_apply_to(),
        'normal',
        'low'
    );
});

// style meta boxes && settings
add_action( 'admin_enqueue_scripts', function() {

    if ( !current_user_can( 'administrator' ) ) { return; }
    $files = [ // $screen->base => [ files names ]
        'post' => [ 'metabox', 'advisor' ],
        'settings_page_posts-by-query' => [ 'settings', 'color', 'media', 'codemirror' ]
    ];


    $screen = get_current_screen();
    if ( !isset( $screen ) || !is_object( $screen ) || !isset( $files[ $screen->base ] ) ) { return; }

    $assets_path = FCPPBK_DIR.'assets';
    foreach ( scandir( $assets_path ) as $v ) {
        if ( !is_file( $assets_path.'/'.$v ) ) { continue; }
        $onthelist = array_reduce( $files[ $screen->base ], function( $result, $item ) use ( $v ) {
            $result = $result ?: ( strpos( $v, $item.'.' ) === 0 );
            return $result;
        }, false );
        if ( !$onthelist ) { continue; }

        $ext = substr( $v, strrpos( $v, '.' )+1 );
        $handle = FCPPBK_PREF.preg_replace( ['/\.(?:js|css)$/', '/[^a-z0-9\-_]/'], '', $v );
        $url = FCPPBK_URL.'assets'.'/'.$v;

        if ( $ext === 'css' ) { wp_enqueue_style( $handle, $url, [], FCPPBK_VER, 'all' ); }
        if ( $ext === 'js' ) { wp_enqueue_script( $handle, $url, [], FCPPBK_VER, false ); }
    }

    // wp color picker
    wp_enqueue_script( 'wp-color-picker' );
    wp_enqueue_style( 'wp-color-picker' );

    // css editor - default wp codemirror
    wp_localize_script( 'jquery', 'cm_settings', [ 'codeEditor' => wp_enqueue_code_editor( ['type' => 'text/css'] ) ] );
    wp_enqueue_script( 'wp-theme-plugin-editor' );
    wp_enqueue_style( 'wp-codemirror' );

    // the media popup main
    wp_enqueue_media();
});

// api to fetch the posts by search query or by id-s
// ++ show correct unfilled behavior!!!
add_action( 'rest_api_init', function () {

    $route_args = function($search_by) {

        if ( empty( get_types_to_search_among() ) ) {
            return new \WP_Error( 'post_type_not_selected', 'Post type not selected', [ 'status' => 404 ] );
        }

        $wp_query_args = [
            'post_type' => get_types_to_search_among(),
            'post_status' => 'publish',
            //'sentence' => true,
            'posts_per_page' => 20,
        ];

        $format_output = function( $p ) {
            return [ 'id' => $p->ID, 'title' => $p->post_title ]; // get_the_title() forces different quotes in different languages or so
        };

        switch ( $search_by ) {
            case ( 'list' ):
                // $wp_query_args += [ 'orderby' => 'title', 'order' => 'ASC' ]; // autocomplete orders by title anyways
            break;
            case ( 'query' ):
                $wp_query_args += [ 'orderby' => 'date', 'order' => 'DESC' ]; // ++ add option to settings. alt var = relevance
                $format_output = function( $p ) {
                    $date = wp_date( get_option( 'date_format', 'j F Y' ), strtotime( $p->post_date ) );
                    return [ 'id' => $p->ID, 'title' => $p->post_title . ' ('.get_public_post_types()[$p->post_type].', '.$date.')' ];
                };
            break;
        }

        return [
            'methods'  => 'GET',
            'callback' => function( \WP_REST_Request $request ) use ( $wp_query_args, $format_output ) {

                if ( FCPPBK_DEV ) { usleep( rand(0, 1000000) ); } // simulate server responce delay

                $wp_query_args['s'] = $request['search'];

                $search = new \WP_Query( $wp_query_args );
    
                if ( !$search->have_posts() ) {
                    return new \WP_REST_Response( [], 200 ); // new \WP_Error( 'nothing_found', 'No results found', [ 'status' => 404 ] );
                }
    
                $result = [];
                while ( $search->have_posts() ) {
                    $p = $search->next_post();
                    $result[] = $format_output( $p ); // not using the id as the key to keep the order in json
                }
    
                $result = new \WP_REST_Response( $result, 200 );
    
                if ( FCPPBK_DEV ) { nocache_headers(); }
    
                return $result;
            },
            'permission_callback' => function() {
                if ( empty( $_SERVER['HTTP_REFERER'] ) ) { return false; }
                if ( strtolower( parse_url( $_SERVER['HTTP_REFERER'], PHP_URL_HOST ) ) !== strtolower( $_SERVER['HTTP_HOST'] ) ) { return false; }
                if ( !current_user_can( 'administrator' ) ) { return false; } // works only with X-WP-Nonce header passed
                return true;
            },
            'args' => [
                'search' => [
                    'description' => 'The search query',
                    'type'        => 'string',
                    'required'    => true,
                    'validate_callback' => function($param) {
                        return trim( $param ) ? true : false;
                    },
                    'sanitize_callback' => function($param, \WP_REST_Request $request, $key) {
                        return sanitize_text_field( urldecode( $param ) ); // return htmlspecialchars( wp_unslash( urldecode( $param ) ) );
                    },
                ],
            ],
        ];
    };

    register_rest_route( FCPPBK_SLUG.'/v1', '/list/(?P<search>.{1,90})', $route_args( 'list' ) );
    register_rest_route( FCPPBK_SLUG.'/v1', '/query/(?P<search>.{1,90})', $route_args( 'query' ) );
});


function metabox_query() {
    global $post;

    ?>
    <div class="<?php echo esc_attr( FCPPBK_PREF ) ?>tabs">
        <?php
        radio( (object) [
            'name' => FCPPBK_PREF.'variants',
            'value' => 'query',
            'checked' => get_post_meta( $post->ID, FCPPBK_PREF.'variants' )[0] ?? false,
            'default' => true,
        ]);
        ?>
        <div>
            <p><strong>The Posts are ordered by the Relevance &amp; Date ASC</strong></p>
            <?php
            text( (object) [
                'name' => FCPPBK_PREF.'query',
                'placeholder' => 'search query',
                'value' => get_post_meta( $post->ID, FCPPBK_PREF.'query' )[0] ?? '',
            ]);
            ?>
        </div>

        <?php
        radio( (object) [
            'name' => FCPPBK_PREF.'variants',
            'value' => 'list',
            'checked' => get_post_meta( $post->ID, FCPPBK_PREF.'variants' )[0] ?? false,
        ]);
        ?>
        <div>
            <p><strong>Create the list of particular posts</strong></p>
            <?php
            text( (object) [
                'name' => FCPPBK_PREF.'list',
                'placeholder' => 'search query',
                'value' => get_post_meta( $post->ID, FCPPBK_PREF.'list' )[0] ?? '',
            ]);
            ?>
        </div>
    </div>

    <div id="<?php echo esc_attr( FCPPBK_PREF ) ?>tiles">
        <?php
        $ids = get_post_meta( $post->ID, FCPPBK_PREF.'posts' )[0] ?? [];
        $result = [];
        if ( !empty( $ids ) ) {

            $search = new \WP_Query( [
                //'post_type' => get_types_to_search_among(), // commented to keep on accident save. filter still works in shortcode
                'post_type' => 'any',
                //'post_status' => 'publish', // same
                'post__in' => $ids,
                'orderby' => 'post__in',
                'lang' => 'all', // ++ add the option to limit languages if needed
            ] );
            if ( $search->have_posts() ) {
                while ( $search->have_posts() ) {
                    $p = $search->next_post();
                    $result[ $p->ID ] = $p->post_title;
                }
            }
        }

        checkboxes( (object) [
            'name' => FCPPBK_PREF.'posts',
            'options' => $result,
            'value' => $ids,
        ]);
        ?>
        <fieldset id="<?php echo esc_attr( FCPPBK_PREF ) ?>posts-preview"></fieldset>
    </div>
    
    <input type="hidden" name="<?php echo esc_attr( FCPPBK_PREF ) ?>nonce" value="<?= esc_attr( wp_create_nonce( FCPPBK_PREF.'nonce' ) ) ?>">
    <input type="hidden" id="<?php echo esc_attr( FCPPBK_PREF ) ?>rest-nonce" value="<?= esc_attr( wp_create_nonce( 'wp_rest' ) ) ?>">

    <?php
}

// save meta data
add_action( 'save_post', function( $postID ) {

    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
    if ( empty( $_POST[ FCPPBK_PREF.'nonce' ] ) || !wp_verify_nonce( $_POST[ FCPPBK_PREF.'nonce' ], FCPPBK_PREF.'nonce' ) ) { return; }
    //if ( !current_user_can( 'edit_post', $postID ) ) { return; }
    if ( !current_user_can( 'administrator' ) ) { return; }

    $post = get_post( $postID );
    if ( $post->post_type === 'revision' ) { return; } // kama has a different solution

    $fields = [ 'variants', 'query', 'posts' ];

    foreach ( $fields as $f ) {
        $f = FCPPBK_PREF . $f;
        if ( empty( $_POST[ $f ] ) || empty( $new_value = sanitize_meta( $_POST[ $f ], $f, $postID ) ) ) {
            delete_post_meta( $postID, $f );
            continue;
        }
        update_post_meta( $postID, $f, $new_value );
    }
});

function sanitize_meta( $value, $field, $postID ) {

    $field = ( strpos( $field, FCPPBK_PREF ) === 0 ) ? substr( $field, strlen( FCPPBK_PREF ) ) : $field;

    switch ( $field ) {
        case ( 'variants' ):
            return in_array( $value, ['query', 'list'] ) ? $value : 'query';
        break;
        case ( 'query' ):
            return sanitize_text_field( $value );
        break;
        case ( 'posts' ):
            return array_values( array_filter( $value, 'is_numeric' ) );
            // post-type & is-published filters are applied before printing on the front-end to not ruin on save if global settings are changed
        break;
    }

    return '';
}