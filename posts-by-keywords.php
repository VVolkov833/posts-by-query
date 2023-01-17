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

    

    $args = [
        'methods'  => 'GET',
        'callback' => function( \WP_REST_Request $request ) {


            $args = [
                //'post_type' => 'post', // looks like some conflict with some plugin.. added the post-type filter lower
                'post_status' => 'publish',
                's' => $request['query'],
                'orderby' => 'title',
                'order' => 'ASC',
                //'sentence' => true,
            ];

            $search = new \WP_Query( $args );
            if ( !$search->have_posts() ) {
                return new \WP_Error( 'nothing_found', 'No results found', [ 'status' => 404 ] );
            }

            $result = [];
            while ( $search->have_posts() ) {
                $search->the_post();
                if ( get_post_type() !== 'post' ) { continue; }
                $result[ get_the_ID() ] = get_the_title();
            }

            $result = new \WP_REST_Response( (object) $result, 200 );

            //nocache_headers();

            return $result;
        },
        'permission_callback' => function() {
            //if ( empty( $_SERVER['HTTP_REFERER'] ) ) { return false; }
            //if ( strtolower( parse_url( $_SERVER['HTTP_REFERER'], PHP_URL_HOST ) ) !== strtolower( $_SERVER['HTTP_HOST'] ) ) { return false; }
            //if ( !current_user_can( 'administrator' ) ) { return false; } // doesn't work - use nonce
            // ++add nonce header https://wordpress.stackexchange.com/questions/320487/how-to-use-current-user-can-in-register-rest-route
            return true;
        },
        'args' => [
            'query' => [
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

    register_rest_route( FCPPBK_SLUG.'/v1', '/posts/(?P<query>.{1,90})', $args );
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
            <p><strong>Search Query</strong></p>
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
                'name' => 'picker',
                'placeholder' => 'search query',
                'value' => get_post_meta( $post->ID, FCPPBK_PREF.'picker' )[0],
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
//print_r( [ $ids, $result, $search ] );
        checkboxes( (object) [
            'name' => 'posts',
            'options' => $result,
            'value' => $ids,
        ]);
        ?>
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
    if ( !current_user_can( 'administrator' ) ) { return; }

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

function sanitize_meta( $value, $field, $postID ) {

    return $value;

    $field = ( strpos( $field, FCPPBK_PREF ) === 0 ) ? substr( $field, strlen( FCPPBK_PREF ) ) : $field;

    $onoff = function($value) {
        return $value[0] === 'on' ? ['on'] : [];
    };

    switch( $field ) {
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

return;

// admin post type for css-s
add_action( 'init', function() {
    $shorter = [
        'name' => 'First Screen CSS',
        'plural' => 'First Screen CSS',
        'public' => false,
    ];
    $labels = [
        'name'                => $shorter['plural'],
        'singular_name'       => $shorter['name'],
        'menu_name'           => $shorter['plural'],
        'all_items'           => 'View All ' . $shorter['plural'],
        'archives'            => 'All ' . $shorter['plural'],
        'view_item'           => 'View ' . $shorter['name'],
        'add_new'             => 'Add New',
        'add_new_item'        => 'Add New ' . $shorter['name'],
        'edit_item'           => 'Edit ' . $shorter['name'],
        'update_item'         => 'Update ' . $shorter['name'],
        'search_items'        => 'Search ' . $shorter['name'],
        'not_found'           => $shorter['name'] . ' Not Found',
        'not_found_in_trash'  => $shorter['name'] . ' Not found in Trash',
    ];
    $args = [
        'label'               => $shorter['name'],
        'description'         => 'CSS to print before everything',
        'labels'              => $labels,
        'supports'            => ['title', 'editor'],
        'hierarchical'        => false,
        'public'              => $shorter['public'],
        'show_in_rest'        => false,
        'show_ui'             => true,
        'show_in_menu'        => true,
        'show_in_nav_menus'   => false,
        'show_in_admin_bar'   => true,
        'menu_position'       => 29,
        'menu_icon'           => 'dashicons-money-alt',
        'can_export'          => true,
        'has_archive'         => false,
        'exclude_from_search' => !$shorter['public'],
        'publicly_queryable'  => $shorter['public'],
        'capabilities'        => [ // only admins
            'edit_post'          => 'switch_themes',
            'read_post'          => 'switch_themes',
            'delete_post'        => 'switch_themes',
            'edit_posts'         => 'switch_themes',
            'edit_others_posts'  => 'switch_themes',
            'delete_posts'       => 'switch_themes',
            'publish_posts'      => 'switch_themes',
            'read_private_posts' => 'switch_themes'
        ]
    ];
    register_post_type( FCPPBK_SLUG, $args );
});

// admin controls
add_action( 'add_meta_boxes', function() {
    add_meta_box(
        'first-screen-css-bulk',
        'Bulk apply',
        'FCP\FirstScreenCSS\FCPPBK_meta_bulk_apply',
        FCPPBK_SLUG,
        'normal',
        'high'
    );
    add_meta_box(
        'first-screen-css-disable',
        'Disable enqueued',
        'FCP\FirstScreenCSS\FCPPBK_meta_disable_styles',
        FCPPBK_SLUG,
        'normal',
        'low'
    );
    add_meta_box(
        'first-screen-css-rest',
        'The rest of CSS, which is a not-first-screen',
        'FCP\FirstScreenCSS\FCPPBK_meta_rest_css',
        FCPPBK_SLUG,
        'normal',
        'low'
    );

    if ( !current_user_can( 'administrator' ) ) { return; }
    list( 'public' => $public_post_types ) = get_all_post_types();
    add_meta_box(
        'first-screen-css',
        'Select First Screen CSS',
        'FCP\FirstScreenCSS\anypost_meta_select_fsc',
        array_keys( $public_post_types ),
        'side',
        'low'
    );
});

// style meta boxes
add_action( 'admin_footer', function() {

    $screen = get_current_screen();
    if ( !isset( $screen ) || !is_object( $screen ) || !in_array( $screen->base, [ 'post' ] ) ) { return; } // ++to inline css & include

    ?>
    <style type="text/css">
    [id^=first-screen-css] select {
        width:100%;
        box-sizing:border-box;
    }
    [id^=first-screen-css] fieldset label {
        display:inline-block;
        min-width:90px;
        margin-right:16px;
        white-space:nowrap;
    }
    [id^=first-screen-css] input[type=text],
    [id^=first-screen-css] textarea {
        width:100%;
    }
    [id^=first-screen-css] p {
        margin:30px 0 10px;
    }
    [id^=first-screen-css] p + p {
        margin-top:10px;
    }
    </style>
    <?php
});

// codemirror editor instead of tinymce
add_filter( 'wp_editor_settings', function($settings, $editor_id) {

    if ( $editor_id !== 'content' ) { return $settings; }
    
    $screen = get_current_screen();
    if ( !isset( $screen ) || !is_object( $screen ) || $screen->post_type !== FCPPBK_SLUG ) { return $settings; }

    $settings['tinymce']   = false;
    $settings['quicktags'] = false;
    $settings['media_buttons'] = false;

    return $settings;
}, 10, 2 );

add_action( 'admin_enqueue_scripts', function( $hook ) {

    if ( !in_array( $hook, ['post.php', 'post-new.php'] ) ) { return; }

    $screen = get_current_screen();
    if ( !isset( $screen ) || !is_object( $screen ) || $screen->post_type !== FCPPBK_SLUG ) { return; }

    $cm_settings['codeEditor'] = wp_enqueue_code_editor( ['type' => 'text/css'] );
    wp_localize_script( 'jquery', 'cm_settings', $cm_settings );
    wp_enqueue_script( 'wp-theme-plugin-editor' );
    wp_add_inline_script( 'wp-theme-plugin-editor', file_get_contents( __DIR__ . '/assets/codemirror-init.js') );
    wp_enqueue_style( 'wp-codemirror' );
});

// filter css
add_filter( 'wp_insert_post_data', function($data, $postarr) {

    if ( $data['post_type'] !== FCPPBK_SLUG ) { return $data; }
    clear_errors( $postarr['ID'] );

    // empty is not an error
    if ( trim( $data['post_content'] ) === '' ) {
        $data['post_content_filtered'] = '';
        return $data;
    }

    $errors = [];

    list( $errors, $filtered ) = sanitize_css( wp_unslash( $data['post_content'] ) );

    // right
    if ( empty( $errors ) ) {
        $data['post_content_filtered'] = wp_slash( css_minify( $filtered ) ); // slashes are stripped again right after
        return $data;
    }

    // wrong
    $data['post_content_filtered'] = '';
    save_errors( $errors, $postarr['ID'], '#postdivrich' ); // ++set to draft on any error?
    return $data;

}, 10, 2 );

// message on errors in css
add_action( 'admin_notices', function () {

    $screen = get_current_screen();
    if ( !isset( $screen ) || !is_object( $screen ) || $screen->post_type !== FCPPBK_SLUG || $screen->base !== 'post' ) { return; }

    global $post;
    if ( empty( $errors = get_post_meta( $post->ID, FCPPBK_PREF.'_post_errors' )[0] ) ) { return; }

    array_unshift( $errors['errors'], '<strong>This CSS-post can not be published due to the following errors:</strong>' );
    ?>

    <div class="notice notice-error"><ul>
    <?php array_walk( $errors['errors'], function($a) {
        ?>
        <li><?php echo wp_kses( $a, ['strong' => [], 'em' => []] ) ?></li>
    <?php }) ?>
    </ul></div>

    <style type="text/css"><?php echo implode( ', ', $errors['selectors']) ?>{box-shadow:-3px 0px 0px 0px #d63638}</style>

    <?php
});


// functions -------------------------------------



function sanitize_css($css) {

    // try to escape tags inside svg with url-encoding
    if ( strpos( $css, '<' ) !== false && preg_match( '/<\/?\w+/', $css ) ) {
        // the idea is taken from https://github.com/yoksel/url-encoder/
        $svg_sanitized = preg_replace_callback( '/url\(\s*(["\']*)\s*data:\s*image\/svg\+xml(.*)\\1\s*\)/', function($m) {
            return 'url('.$m[1].'data:image/svg+xml'
                .preg_replace_callback( '/[\r\n%#\(\)<>\?\[\]\\\\^\`\{\}\|]+/', function($m) {
                    return urlencode( $m[0] );
                }, urldecode( $m[2] ) )
                .$m[1].')';
        }, $css );

        if ( $svg_sanitized !== null ) {
            $css = $svg_sanitized;
        }
    }
    // if tags still exist, forbid that
    // the idea is taken from WP_Customize_Custom_CSS_Setting::validate as well as the translation
    if ( strpos( $css, '<' ) !== false && preg_match( '/<\/?\w+/', $css ) ) {
        $errors['tags'] = 'HTML ' . __( 'Markup is not allowed in CSS.' );
    }

    // ++strip <?php, <!--??
    // ++maybe add parser sometime later
    // ++safecss_filter_attr($css)??

    return [$errors, $css];
}

function save_errors($errors, $postID, $selector = '') {
    static $errors_list = [ 'errors' => [], 'selectors' => [] ];    

    $errors = (array) $errors;

    $errors_list['errors'] = array_merge( $errors_list['errors'], $errors );
    $errors_list['selectors'][] = $selector; // errors override by associative key, numeric add, but selectors only add for now
    update_post_meta( $postID, FCPPBK_PREF.'_post_errors', $errors_list );
}
function clear_errors($postID) {
    delete_post_meta( $postID, FCPPBK_PREF.'_post_errors' );
}


function select($a) {
    ?>
    <select
        name="<?php echo esc_attr( FCPPBK_PREF . $a->name ) ?>"
        id="<?php echo esc_attr( FCPPBK_PREF . $a->name ) ?>"
        class="<?php echo isset( $a->className ) ? esc_attr( $a->className ) : '' ?>"><?php

        if ( isset( $a->placeholder ) ) { ?>
            <option value=""><?php echo esc_html( $a->placeholder ) ?></option>
        <?php } ?>

        <?php foreach ( $a->options as $k => $v ) { ?>
            <option
                value="<?php echo esc_attr( $k ) ?>"
                <?php echo isset( $a->value ) && $a->value == $k ? 'selected' : '' ?>
            ><?php echo esc_html( $v ) ?></option>
        <?php } ?>
    </select>
    <?php
}

function textarea($a) {
    ?>
    <textarea
        name="<?php echo esc_attr( FCPPBK_PREF . $a->name ) ?>"
        id="<?php echo esc_attr( FCPPBK_PREF . $a->name ) ?>"
        rows="<?php echo isset( $a->rows ) ? esc_attr( $a->rows ) : '10' ?>" cols="<?php echo isset( $a->cols ) ? esc_attr( $a->cols ) : '50' ?>"
        placeholder="<?php echo isset( $a->placeholder ) ? esc_attr( $a->placeholder ) : '' ?>"
        class="<?php echo isset( $a->className ) ? esc_attr( $a->className ) : '' ?>"
    ><?php
        echo esc_textarea( isset( $a->value ) ? $a->value : '' )
    ?></textarea>
    <?php
}

function filter_csss( $ids ) {

    if ( empty( $ids ) ) { return []; }

    global $wpdb;

    // filter by post_status & post_type
    $filtered_ids = $wpdb->get_col( $wpdb->prepare('

        SELECT `ID`
        FROM `'.$wpdb->posts.'`
        WHERE `post_status` = %s AND `post_type` = %s AND `ID` IN ( '.implode( ',', array_fill( 0, count( $ids ), '%s' ), ).' )

    ', array_merge( [ 'publish', FCPPBK_SLUG ], $ids ) ) );

    // filter by development mode
    if ( current_user_can( 'administrator' ) ) { return $filtered_ids; }

    $dev_mode = $wpdb->get_col( $wpdb->remove_placeholder_escape( $wpdb->prepare('

        SELECT `post_id`
        FROM `'.$wpdb->postmeta.'`
        WHERE `meta_key` = %s AND `meta_value` = %s AND `post_id` IN ( '.implode( ',', array_fill( 0, count( $ids ), '%s' ), ).' )

    ', array_merge( [ FCPPBK_PREF.'development-mode', serialize(['on']) ], $ids ) ) ) );


    return array_values( array_diff( $filtered_ids, $dev_mode ) );
}

function get_css_ids( $key, $type = 'post' ) {

    global $wpdb;

    $ids = $wpdb->get_col( $wpdb->remove_placeholder_escape( $wpdb->prepare('

        SELECT `post_id`
        FROM `'.$wpdb->postmeta.'`
        WHERE `meta_key` = %s AND `meta_value` LIKE %s

    ', $key, $wpdb->add_placeholder_escape( '%"'.$type.'"%' ) ) ) );

    return $ids;
}

function get_to_defer( $ids ) {

    global $wpdb;

    $defer_ids = $wpdb->get_col( $wpdb->remove_placeholder_escape( $wpdb->prepare('

        SELECT `post_id`
        FROM `'.$wpdb->postmeta.'`
        WHERE `meta_key` = %s AND `meta_value` = %s AND `post_id` IN ( '.implode( ',', array_fill( 0, count( $ids ), '%s' ), ).' )

    ', array_merge( [ FCPPBK_PREF.'rest-css-defer', serialize(['on']) ], $ids ) ) ) );


    return $defer_ids;
}

function get_css_contents_filtered( $ids ) { // ++add proper ordering

    if ( empty( $ids ) ) { return; }

    global $wpdb;

    $metas = $wpdb->get_col( $wpdb->prepare('

        SELECT `post_content_filtered`
        FROM `'.$wpdb->posts.'`
        WHERE `ID` IN ( '.implode( ',', array_fill( 0, count( $ids ), '%s' ), ).' )

    ', $ids ) );

    return implode( '', $metas );
}

function get_to_deregister( $ids ) {

    if ( empty( $ids ) ) { return []; }

    global $wpdb;

    $wpdb->query( $wpdb->remove_placeholder_escape( $wpdb->prepare('

        SELECT
            IF ( STRCMP( `meta_key`, %s ) = 0, `meta_value`, "" ) AS "styles",
            IF ( STRCMP( `meta_key`, %s ) = 0, `meta_value`, "" ) AS "scripts"
        FROM `'.$wpdb->postmeta.'`
        WHERE ( `meta_key` = %s OR `meta_key` = %s ) AND `post_id` IN ( '.implode( ',', array_fill( 0, count( $ids ), '%s' ), ).' )

    ', array_merge(
        [ FCPPBK_PREF.'deregister-style-names', FCPPBK_PREF.'deregister-script-names', FCPPBK_PREF.'deregister-style-names', FCPPBK_PREF.'deregister-script-names' ],
        $ids
    ) ) ) );

    $clear = function($a) { return array_values( array_unique( array_filter( array_map( 'trim', explode( ',', implode( ', ', $a ) ) ) ) ) ); };
    
    $styles = $clear( $wpdb->get_col( null, 0 ) );
    $scripts = $clear( $wpdb->get_col( null, 1 ) );

    return [ $styles, $scripts ];
}



function css_minify($css) {
    $css = preg_replace( '/\/\*(?:.*?)*\*\//', '', $css ); // remove comments
    $css = preg_replace( '/\s+/', ' ', $css ); // one-line & only single speces
    $css = preg_replace( '/ ?([\{\};:\>\~\+]) ?/', '$1', $css ); // remove spaces
    $css = preg_replace( '/\+(\d)/', ' + $1', $css ); // restore spaces in functions
    $css = preg_replace( '/(?:[^\}]*)\{\}/', '', $css ); // remove empty properties
    $css = str_replace( [';}', '( ', ' )'], ['}', '(', ')'], $css ); // remove last ; and spaces
    // ++ should also remove 0 from 0.5, but not from svg-s?
    // ++ try replacing ', ' with ','
    // ++ remove space between %3E %3C and before %3E and /%3E
    return trim( $css );
};

// meta boxes
function FCPPBK_meta_bulk_apply() {
    global $post;

    // get post types to print options
    list( 'public' => $public_post_types, 'archive' => $archives_post_types ) = get_all_post_types();

    ?><p><strong>Apply to the following post types</strong></p><?php

    checkboxes( (object) [
        'name' => 'post-types',
        'options' => $public_post_types,
        'value' => get_post_meta( $post->ID, FCPPBK_PREF.'post-types' )[0],
    ]);

    ?><p><strong>Apply to the Archive pages of the following post types</strong></p><?php

    checkboxes( (object) [
        'name' => 'post-archives',
        'options' => $archives_post_types,
        'value' => get_post_meta( $post->ID, FCPPBK_PREF.'post-archives' )[0],
    ]);

    ?>
    <p>You can apply this styling to a separate post. Every public post type has a special select box in the right sidebar to pick this or any other first-screen-css.</p>
    <p>You can grab the first screen css of a page with the script: <a href="https://github.com/VVolkov833/first-screen-css-grabber" target="_blank" rel="noopener">github.com/VVolkov833/first-screen-css-grabber</a></p>
    <?php

    checkboxes( (object) [
        'name' => 'development-mode',
        'options' => ['on' => 'Development mode (apply only if the post is visited as the admin)'],
        'value' => get_post_meta( $post->ID, FCPPBK_PREF.'development-mode' )[0],
    ]);

    wp_nonce_field( FCPPBK_PREF.'nounce-action', FCPPBK_PREF.'nounce-name' );
}

function FCPPBK_meta_disable_styles() {
    global $post;

    ?><p><strong>List the names of STYLES to deregister, separate by comma</strong></p><?php

    input( (object) [
        'name' => 'deregister-style-names',
        'placeholder' => 'my-theme-style, some-plugin-style',
        'value' => get_post_meta( $post->ID, FCPPBK_PREF.'deregister-style-names' )[0],
    ]);

    ?><p><strong>List the names of SCRIPTS to deregister, separate by comma</strong></p><?php

    input( (object) [
        'name' => 'deregister-script-names',
        'placeholder' => 'my-theme-script, some-plugin-script',
        'value' => get_post_meta( $post->ID, FCPPBK_PREF.'deregister-script-names' )[0],
    ]);

}

function FCPPBK_meta_rest_css() {
    global $post;

    textarea( (object) [
        'name' => 'rest-css',
        'placeholder' => '/* enter your css here */
* {
    border-left: 1px dotted green;
    box-sizing: border-box;
}',
        'value' => get_post_meta( $post->ID, FCPPBK_PREF.'rest-css' )[0],
    ]);

    checkboxes( (object) [
        'name' => 'rest-css-defer',
        'options' => ['on' => 'Defer the not-first-screen CSS (avoid render-blicking)'],
        'value' => get_post_meta( $post->ID, FCPPBK_PREF.'rest-css-defer' )[0],
    ]);
}

function anypost_meta_select_fsc() {
    global $post;

    // get css post types
    $css_posts0 = get_posts([
        'post_type' => FCPPBK_SLUG,
        'orderby' => 'post_title',
        'order'   => 'ASC',
        'post_status' => ['any', 'active'],
        'posts_per_page' => -1,
    ]);
    $css_posts = [];
    foreach( $css_posts0 as $v ){
        $css_posts[ $v->ID ] = $v->post_title ? $v->post_title : __( '(no title)' );
    }

    select( (object) [
        'name' => 'id',
        'placeholder' => '------',
        'options' => $css_posts,
        'value' => get_post_meta( $post->ID, FCPPBK_PREF.'id' )[0],
    ]);

    ?><p>&nbsp;</p><p><strong>Exclude CSS</strong></p><?php
    select( (object) [
        'name' => 'id-exclude',
        'placeholder' => '------',
        'options' => $css_posts,
        'value' => get_post_meta( $post->ID, FCPPBK_PREF.'id-exclude' )[0],
    ]);

    wp_nonce_field( FCPPBK_PREF.'nounce-action', FCPPBK_PREF.'nounce-name' );
}

function delete_the_plugin() {
    $dir = wp_upload_dir()['basedir'] . '/' . basename( __DIR__ );
    array_map( 'unlink', glob( $dir . '/*' ) );
    rmdir( $dir );
}

// new version set
// svn upload

// ++limit meta boxes to admins too!!!
// ++add formatting button like https://codemirror.net/2/demo/formatting.html
// ++add the bigger height button and save it
// ++switch selects to checkboxes or multiples
// ++maybe limit the id-exclude to the fitting post types
// ++don't show rest meta box if the storing dir is absent or is not writable or/and the permission error
// ++get the list of css to unload with jQuery.html() && regexp, or ?query in url to print loaded scripts
// ++!!??add small textarea to every public post along with css like for a unique background-image in hero
// ++list of styles to defer like with deregister
