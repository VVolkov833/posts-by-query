<?php
/*
Plugin Name: FCP Posts by Search Query
Description: Searches and prints the posts tiles by query
Version: 0.0.1
Requires at least: 5.8
Tested up to: 6.1
Requires PHP: 7.4
Author: Firmcatalyst, Vadim Volkov
Author URI: https://firmcatalyst.com
License: GPL v3 or later
License URI: http://www.gnu.org/licenses/gpl-3.0.html
*/

namespace FCP\PostsByQuery;

defined( 'ABSPATH' ) || exit;

define( 'FCPPBK_SLUG', 'fcppbk' );
define( 'FCPPBK_PREF', FCPPBK_SLUG.'-' );

define( 'FCPPBK_DEV', false );
define( 'FCPPBK_VER', get_file_data( __FILE__, [ 'ver' => 'Version' ] )[ 'ver' ] . ( FCPPBK_DEV ? time() : '' ) );


// print the styles
add_action( 'wp_enqueue_scripts', function() {

    wp_enqueue_style(
        'fcp-posts-by-query',
        plugin_dir_url(__FILE__).'/styles/style-1.css',
        [],
        FCPPBK_VER . filemtime( __DIR__.'/styles/style-1.css' ),
        'all'
    );

});

// admin interface
add_action( 'add_meta_boxes', function() {
    if ( !current_user_can( 'administrator' ) ) { return; }
    list( 'public' => $public_post_types ) = get_all_post_types();
    add_meta_box(
        'fcp-posts-by-query',
        'Posts by Query',
        'FCP\PostsByQuery\metabox_query',
        array_keys( $public_post_types ),
        'normal',
        'low'
    );
});
// style meta boxes
add_action( 'admin_enqueue_scripts', function() {

    $screen = get_current_screen();
    if ( !isset( $screen ) || !is_object( $screen ) || !in_array( $screen->base, [ 'post' ] ) ) { return; }

    foreach ( scandir( __DIR__.'/assets' ) as $v ) {
        $path = __DIR__.'/assets/'.$v;
        if ( !is_file( $path ) ) { continue; }
        $ext = substr( $v, strrpos( $v, '.' )+1 );
        $name = FCPPBK_PREF.preg_replace( ['/\.(?:js|css)$/', '/[^a-z0-9\-_]/'], '', $v );
        $url = plugin_dir_url(__FILE__).'assets/'.$v;
        if ( $ext === 'css' ) {
            wp_enqueue_style( $name, $url, [], FCPPBK_VER . filemtime( $path ), 'all' );
        }
        if ( $ext === 'js' ) {
            wp_enqueue_script( $name, $url, [], FCPPBK_VER, false );
        }
    }

});

// api to fetch the posts
// api to fetch the query
add_action( 'rest_api_init', function () {

    $route_args = function($results_format) {

        $wp_query_args = [
            'post_type' => ['post'],
            'post_status' => 'publish',
            //'sentence' => true,
            //'posts_per_page' => 20,
        ];

        $format_output = function() {
            return get_the_title();
        };


        switch ( $results_format ) {
            case ( 'list' ):
                //$wp_query_args += [ 'orderby' => 'title', 'order' => 'ASC' ];
            break;
            case ( 'query' ):
                $wp_query_args += [ 'orderby' => 'date', 'order' => 'DESC' ];
                $format_output = function() {
                    return '(' . get_the_date() . ') ' . get_the_title();
                };
            break;
        }

        return [
            'methods'  => 'GET',
            'callback' => function( \WP_REST_Request $request ) use ( $wp_query_args, $format_output ) {

                $wp_query_args['s'] = $request['search'];

                $search = new \WP_Query( $wp_query_args );
    
                if ( !$search->have_posts() ) {
                    return new \WP_Error( 'nothing_found', 'No results found', [ 'status' => 404 ] );
                }
    
                $result = [];
                while ( $search->have_posts() ) {
                    $search->the_post();
                    $result[ get_the_ID() ] = $format_output();
                }
    
                $result = new \WP_REST_Response( (object) $result, 200 );
    
                if ( FCPPBK_DEV ) { nocache_headers(); }
    
                return $result;
            },
            'permission_callback' => function() {
                //if ( empty( $_SERVER['HTTP_REFERER'] ) ) { return false; }
                //if ( strtolower( parse_url( $_SERVER['HTTP_REFERER'], PHP_URL_HOST ) ) !== strtolower( $_SERVER['HTTP_HOST'] ) ) { return false; }
                //if ( !current_user_can( 'administrator' ) ) { return false; } // doesn't work - use nonce
                // ++!!add nonce header https://wordpress.stackexchange.com/questions/320487/how-to-use-current-user-can-in-register-rest-route
                return true;
            },
            'args' => [
                'search' => [
                    'description' => 'The search query',
                    'type'        => 'string',
                    'validate_callback' => function($param) {
                        return true;//preg_match( '/^[\w\d\- ]+$/i', $param ) ? true : false;
                    },
                    'sanitize_callback' => function($param, $request, $key) {
                        return $param;//return htmlspecialchars( wp_unslash( urldecode( $param ) ) );
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
    <div class="<?php echo FCPPBK_PREF ?>tabs">
        <?php
        radiobox( (object) [
            'name' => 'variants',
            'value' => 'query',
            'checked' => get_post_meta( $post->ID, FCPPBK_PREF.'variants' )[0],
            'default' => true,
        ]);
        ?>
        <div>
            <p><strong>Posts by Search Query &amp; Date</strong></p>
            <?php
            input( (object) [
                'name' => 'query',
                'placeholder' => 'search query',
                'value' => get_post_meta( $post->ID, FCPPBK_PREF.'query' )[0],
            ]);
            ?>
        </div>

        <?php
        radiobox( (object) [
            'name' => 'variants',
            'value' => 'list',
            'checked' => get_post_meta( $post->ID, FCPPBK_PREF.'variants' )[0],
        ]);
        ?>
        <div>
            <p><strong>Particular Posts</strong></p>
            <?php
            input( (object) [
                'name' => 'list',
                'placeholder' => 'search query',
                'value' => get_post_meta( $post->ID, FCPPBK_PREF.'list' )[0],
            ]);
            ?>
        </div>
    </div>

    <div id="<?php echo FCPPBK_PREF ?>tiles">
        <?php
        $ids = get_post_meta( $post->ID, FCPPBK_PREF.'posts' )[0];
        if ( !empty( $ids ) ) {

            $search = new \WP_Query( [
                'post_type' => 'post',
                'post_status' => 'publish',
                'post__in' => $ids,
            ] );
            if ( $search->have_posts() ) {
                $result = [];
                while ( $search->have_posts() ) {
                    $search->the_post();
                    $result[ get_the_ID() ] = get_the_title();
                }
            }
        }

        checkboxes( (object) [
            'name' => 'posts',
            'options' => $result,
            'value' => $ids,
        ]);
        ?>
        <fieldset id="<?php echo FCPPBK_PREF ?>posts-preview"></fieldset>
    </div>
    
    <?php
}

function input($a) {
    ?>
    <input type="text"
        name="<?php echo esc_attr( FCPPBK_PREF . $a->name ) ?>"
        id="<?php echo esc_attr( FCPPBK_PREF . $a->name ) ?>"
        placeholder="<?php echo isset( $a->placeholder ) ? esc_attr( $a->placeholder )  : '' ?>"
        value="<?php echo isset( $a->value ) ? esc_attr( $a->value ) : '' ?>"
        class="<?php echo isset( $a->className ) ? esc_attr( $a->className ) : '' ?>"
    />
    <?php
}
function checkboxes($a) {
    ?>
    <fieldset
        id="<?php echo esc_attr( FCPPBK_PREF . $a->name ) ?>"
        class="<?php echo isset( $a->className ) ? esc_attr( $a->className ) : '' ?>"
    >
    <?php foreach ( $a->options as $k => $v ) { ?>
        <?php $checked = is_array( $a->value ) && in_array( $k, $a->value ) ?>
        <label>
            <input type="checkbox"
                name="<?php echo esc_attr( FCPPBK_PREF . $a->name ) ?>[]"
                value="<?php echo esc_attr( $k ) ?>"
                <?php echo $checked ? 'checked' : '' ?>
            >
            <span><?php echo esc_html( $v ) ?></span>
        </label>
    <?php } ?>
    </fieldset>
    <?php
}
function radiobox($a) {
    static $checked = false;
    $checked = $checked ? true : $a->checked === $a->value;
    ?>
    <input type="radio"
        name="<?php echo esc_attr( FCPPBK_PREF . $a->name ) ?>"
        value="<?php echo esc_attr( $a->value ) ?>"
        <?php echo ( $a->checked === $a->value || $a->default && !$checked ) ? 'checked' : '' ?>
    >
    <?php
}

function get_all_post_types() {
    static $all = [], $public = [], $archive = [];

    if ( !empty( $public ) ) { return [ 'all' => $all, 'public' => $public, 'archive' => $archive ]; }

    $all = get_post_types( [], 'objects' );
    $public = [];
    $archive = [];
    $archive[ 'blog' ] = 'Blog';
    usort( $all, function($a,$b) { return strcasecmp( $a->label, $b->label ); });
    foreach ( $all as $type ) {
        $type->name = isset( $type->rewrite->slug ) ? $type->rewrite->slug : $type->name;
        if ( $type->has_archive ) {
            $archive[ $type->name ] = $type->label;
        }
        if ( $type->public ) {
            if ( $type->name === 'page' ) { $type->label .= ' (except Front Page)'; }
            $public[ $type->name ] = $type->label;
        }
    }

    return [ 'all' => $all, 'public' => $public, 'archive' => $archive ];

}

// save meta data
add_action( 'save_post', function( $postID ) {

    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
    //if ( !wp_verify_nonce( $_POST[ FCPPBK_PREF.'nounce-name' ], FCPPBK_PREF.'nounce-action' ) ) { return; }
    //if ( !current_user_can( 'edit_post', $postID ) ) { return; }
    if ( !current_user_can( 'administrator' ) ) { return; } // ++ maybe allow the editors too?

    $post = get_post( $postID );
    if ( $post->post_type === 'revision' ) { return; } // update_post_meta fixes the id to the parent, but id can be used before

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

function sanitize_meta( $value, $field, $postID ) { // ++ properly before publishing

    return $value;

    $field = ( strpos( $field, FCPPBK_PREF ) === 0 ) ? substr( $field, strlen( FCPPBK_PREF ) ) : $field;

    $onoff = function($value) {
        return $value[0] === 'on' ? ['on'] : [];
    };

    switch ( $field ) {
        case ( 'post-types' ):
            return array_intersect( $value, array_keys( get_all_post_types()['public'] ) );
        break;
        case ( 'post-archives' ):
            return array_intersect( $value, array_keys( get_all_post_types()['archive'] ) );
        break;
        case ( 'development-mode' ):
            return $onoff( $value );
        break;
        case ( 'deregister-style-names' ):
            return $value; // ++preg_replace not letters ,space-_, lowercase?, 
        break;
        case ( 'deregister-script-names' ):
            return $value; // ++preg_replace not letters ,space-_, lowercase?, 
        break;
        case ( 'rest-css' ):

            list( $errors, $filtered ) = sanitize_css( wp_unslash( $value ) ); //++ move it all to a separate filter / actions, organize better with errors?
            $file = wp_upload_dir()['basedir'] . '/' . basename( __DIR__ ) . '/style-'.$postID.'.css';
            // correct
            if ( empty( $errors ) ) {
                file_put_contents( $file, css_minify( $filtered ) ); //++ add the permission error
                return $value;
            }
            // wrong
            unlink( $file );
            save_errors( $errors, $postID, '#first-screen-css-rest > .inside' );
            return $value;
        break;
        case ( 'rest-css-defer' ):
            return $onoff( $value );
        break;
        case ( 'id' ):
            if ( !is_numeric( $value ) ) { return ''; } // ++to a function
            if ( !( $post = get_post( $value ) ) || $post->post_type !== FCPPBK_SLUG ) { return ''; }
            return $value;
        break;
        case ( 'id-exclude' ):
            if ( !is_numeric( $value ) ) { return ''; }
            if ( !( $post = get_post( $value ) ) || $post->post_type !== FCPPBK_SLUG ) { return ''; }
            return $value;
        break;
    }

    return '';
}