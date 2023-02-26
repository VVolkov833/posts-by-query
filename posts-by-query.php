<?php
/*
Plugin Name: FCP Posts by Search Query
Description: Searches and prints the posts tiles by query
Version: 0.0.5
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

function get_search_post_types() { // ++replace with the option value
    return ['post', 'page', 'brustchirurgie', 'gesichtschirurgie', 'koerperchirurgie', 'haut' ];
}

// admin interface
add_action( 'add_meta_boxes', function() {
    if ( !current_user_can( 'administrator' ) ) { return; }
    list( 'public' => $public_post_types ) = get_all_post_types();
    add_meta_box(
        'fcp-posts-by-query',
        'Posts by Query',
        __NAMESPACE__.'\metabox_query',
        array_keys( $public_post_types ),
        'normal',
        'low'
    );
});
// style meta boxes
add_action( 'admin_enqueue_scripts', function() {

    if ( !current_user_can( 'administrator' ) ) { return; }
    $screen = get_current_screen();
    if ( !isset( $screen ) || !is_object( $screen ) || !in_array( $screen->base, [ 'post' ] ) ) { return; }

    $assets_path = __DIR__.'/assets';

    foreach ( scandir( $assets_path ) as $v ) {
        if ( !is_file( $assets_path.'/'.$v ) ) { continue; }
        $ext = substr( $v, strrpos( $v, '.' )+1 );
        $name = FCPPBK_PREF.preg_replace( ['/\.(?:js|css)$/', '/[^a-z0-9\-_]/'], '', $v );
        $url = plugin_dir_url(__FILE__).'assets/'.$v;
        if ( $ext === 'css' ) { wp_enqueue_style( $name, $url, [], FCPPBK_VER, 'all' ); }
        if ( $ext === 'js' ) { wp_enqueue_script( $name, $url, [], FCPPBK_VER, false ); }
    }

});

// api to fetch the posts
// api to fetch the query
add_action( 'rest_api_init', function () {

    $route_args = function($results_format) {

        $wp_query_args = [
            'post_type' => get_search_post_types(),
            'post_status' => 'publish',
            //'sentence' => true,
            'posts_per_page' => 20,
        ];

        $format_output = function( $p ) {
            return [ 'id' => $p->ID, 'title' => $p->post_title ]; // get_the_title() forces different quotes in different languages or so
        };


        switch ( $results_format ) {
            case ( 'list' ):
                // $wp_query_args += [ 'orderby' => 'title', 'order' => 'ASC' ]; // autocomplete orders by title anyways
            break;
            case ( 'query' ):
                $wp_query_args['post_type'] = ['post'];
                $wp_query_args += [ 'orderby' => 'date', 'order' => 'DESC' ];
                $format_output = function( $p ) {
                    $date = wp_date( get_option( 'date_format', 'j F Y' ), strtotime( $p->post_date ) );
                    return [ 'id' => $p->ID, 'title' => '('.$date.') ' . $p->post_title ];
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
                    $p = $search->next_post();
                    $result[] = $format_output( $p ); // not using the id as the key to keep the order in json
                }
    
                $result = new \WP_REST_Response( (object) $result, 200 );
    
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
                    'validate_callback' => function($param) {
                        return trim( $param ) ? true : false;
                    },
                    'sanitize_settings' => function($param, $request, $key) {
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
        radiobox( (object) [
            'name' => FCPPBK_PREF.'variants',
            'value' => 'query',
            'checked' => get_post_meta( $post->ID, FCPPBK_PREF.'variants' )[0],
            'default' => true,
        ]);
        ?>
        <div>
            <p><strong>Posts by Search Query &amp; Date</strong></p>
            <?php
            text( (object) [
                'name' => FCPPBK_PREF.'query',
                'placeholder' => 'search query',
                'value' => get_post_meta( $post->ID, FCPPBK_PREF.'query' )[0],
            ]);
            ?>
        </div>

        <?php
        radiobox( (object) [
            'name' => FCPPBK_PREF.'variants',
            'value' => 'list',
            'checked' => get_post_meta( $post->ID, FCPPBK_PREF.'variants' )[0],
        ]);
        ?>
        <div>
            <p><strong>Particular Posts</strong></p>
            <?php
            text( (object) [
                'name' => FCPPBK_PREF.'list',
                'placeholder' => 'search query',
                'value' => get_post_meta( $post->ID, FCPPBK_PREF.'list' )[0],
            ]);
            ?>
        </div>
    </div>

    <div id="<?php echo esc_attr( FCPPBK_PREF ) ?>tiles">
        <?php
        $ids = get_post_meta( $post->ID, FCPPBK_PREF.'posts' )[0];
        if ( !empty( $ids ) ) {

            $search = new \WP_Query( [
                'post_type' => get_search_post_types(),
                'post_status' => 'publish',
                'post__in' => $ids,
                'orderby' => 'post__in',
            ] );
            if ( $search->have_posts() ) {
                $result = [];
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
    if ( !wp_verify_nonce( $_POST[ FCPPBK_PREF.'nonce' ], FCPPBK_PREF.'nonce' ) ) { return; }
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

    switch ( $field ) {
        case ( 'variants' ):
            return in_array( $value, ['query', 'list'] ) ? $value : 'query';
        break;
        case ( 'query' ):
            return sanitize_text_field( $value );
        break;
        case ( 'posts' ):
            return array_values( array_filter( $value, 'is_numeric' ) ); // post-type & is-published filters are performed before printing on the front-end
        break;
    }

    return '';
}

add_shortcode( FCPPBK_SLUG, function($atts = []) {
    $allowed = [
        'layout' => 'default',
        'styles' => 'style-1',
        'headline' => 'Das könnte Sie auch interessieren', //++replace with a wrapper
    ];
    $atts = shortcode_atts( $allowed, $atts );
    
    $metas = array_map( function( $value ) {
        return $value[0];
    }, array_filter( get_post_custom(), function($key) {
        return strpos( $key, FCPPBK_PREF ) === 0;
    }, ARRAY_FILTER_USE_KEY ) );


    $wp_query_args = [
        'post_type' => get_search_post_types(),
        'post_status' => 'publish',
        'posts_per_page' => 3,
    ];

    $results_format = $metas[ FCPPBK_PREF.'variants' ];
    switch ( $results_format ) {
        case ( 'list' ):
            $ids = unserialize( $metas[ FCPPBK_PREF.'posts' ] ); // ++ filter by post-type & is-published
            if ( empty( $ids ) ) { return; }
            $wp_query_args += [ 'post__in' => $ids, 'orderby' => 'post__in' ];
        break;
        case ( 'query' ):
            $query = $metas[ FCPPBK_PREF.'query' ];
            if ( trim( $query ) === '' ) { return; }
            $wp_query_args += [ 'orderby' => 'date', 'order' => 'DESC', 's' => $query ];
        break;
        default:
            return;
    }

    $search = new \WP_Query( $wp_query_args );

    if ( !$search->have_posts() ) { return; }

    $template = file_get_contents( __DIR__ . '/templates/' . $atts['layout'] . '.html' );

    $result = [];
    while ( $search->have_posts() ) {
        //$search->the_post(); // fails somehow
        $p = $search->next_post();
        $excerpt = substr( get_the_excerpt( $p ), 0, 186 ); // ++value or 0
        $excerpt = rtrim( substr( $excerpt, 0, strrpos( $excerpt, ' ' ) ), ',.…!?&([{-_ "„“' ) . '…';
        $result[] = strtr( $template, [
            '%id' => get_the_ID( $p ),
            '%permalink' => get_permalink( $p ),
            '%title' => get_the_title( $p ),
            '%title_linked' => get_the_title( $p ),
            '%excerpt' => $excerpt,

            '%thumbnail' => get_the_post_thumbnail( $p, 'medium' ), // ++condition
            '%thumbnail_linked' => '', // ++condition
            '%date' => get_the_date( '', $p ), // ++format here && condition
            '%button' => '', // ++format here && condition
            '%category' => '', // ++format here && condition
        ]);
    }

    //wp_reset_postdata();

    wp_enqueue_style(
        'fcp-posts-by-query',
        plugins_url( '/' ,__FILE__ ) . 'styles/style-1.css',
        [],
        FCPPBK_DEV ? FCPPBK_VER : FCPPBK_VER.'.'.filemtime( __DIR__.'/styles/style-1.css' ),
    );

    return '<section class="'.FCPPBK_SLUG.' container"><h2>'.$atts['headline'].'</h2><div>' . implode( '', $result) . '</div></section>';
});


// settings page
add_action( 'admin_menu', function() {
	add_options_page( 'Posts by Queryuery settings', 'Posts by Queryuery', 'switch_themes', 'posts-by-query', __NAMESPACE__.'\settings_print' );
});

function settings_print(){
	?>
	<div class="wrap">
		<h2><?php echo get_admin_page_title() ?></h2>

		<form action="options.php" method="POST">
			<?php
				do_settings_sections( FCPPBK_PREF.'settings-page' ); // print fields of the page / tab
				submit_button();
                settings_fields( FCPPBK_PREF.'settings-group1' ); // nonce
			?>
		</form>
	</div>
	<?php
}

add_action( 'admin_init', function() {

    $settings = (object) [
        'page' => FCPPBK_PREF.'settings-page',
        'section' => 'styling-settings',
        'varname' => FCPPBK_PREF.'settings',
    ];
    $settings->values = get_option( $settings->varname );

    $title2slug = function($a) {
        return sanitize_title( $a );
    };
    $asd = function( $title, $type = '', $atts = [] ) use ( $settings, $title2slug ) { // $atts: placeholder, options

        $type = empty( $type ) ? 'text' : $type; //++in array of existing functions
        $slug = empty( $atts['slug'] ) ? $title2slug( $title ) : $atts['slug'];

        $attributes = (object) [
            'name' => $settings->varname.'['.$slug.']',
            'id' => $settings->varname . '--' . $slug,
            'value' => $settings->values[ $slug ],
            'placeholder' => empty( $atts['placeholder'] ) ? '' : $atts['placeholder'],
            'options' => empty( $atts['options'] ) ? '' : $atts['options'],
        ];

        add_settings_field(
            $slug,
            $title,
            function() use ( $attributes ) {
                text( $attributes );
            },
            $settings->page,
            $settings->section
        );
    };


    // structure of fields
	add_settings_section( $settings->section, 'Styling', '', $settings->page );
        $asd( 'Read-more text', 'text' );
        $asd( 'Read-more text 1', 'text1' );
/*
	    add_settings_field( 'main-color', 'Main color', function()use($val){}, FCPPBK_PREF.'settings-page', 'styling-settings' );
        add_settings_field( 'secondary-color', 'Secondary color', function()use($val){}, FCPPBK_PREF.'settings-page', 'styling-settings' );
        add_settings_field( 'layout', 'Layout', function()use($val){}, FCPPBK_PREF.'settings-page', 'styling-settings' );
        add_settings_field( 'thumbnail-size', 'Thumbnail size', function()use($val){}, FCPPBK_PREF.'settings-page', 'styling-settings' );
        add_settings_field( 'show-date', 'Show date', function()use($val){}, FCPPBK_PREF.'settings-page', 'styling-settings' );
        add_settings_field( 'show-excerpt', 'Show excerpt', function()use($val){}, FCPPBK_PREF.'settings-page', 'styling-settings' );
            add_settings_field( 'excerpt-length', 'Excerpt-length', function()use($val){}, FCPPBK_PREF.'settings-page', 'styling-settings' );
        add_settings_field( 'show-readmore', 'Show the Read-more button', function()use($val){}, FCPPBK_PREF.'settings-page', 'styling-settings' );
            add_settings_field( 'readmore-text', 'Read-more button text', function()use($val){}, FCPPBK_PREF.'settings-page', 'styling-settings' );
        add_settings_field( 'show-category', 'Show main category', function()use($val){}, FCPPBK_PREF.'settings-page', 'styling-settings' );

	add_settings_section( 'other-settings', 'Other Settings', '', FCPPBK_PREF.'settings-page' );
	    add_settings_field( 'apply-to', 'Post types to apply to', function()use($val){}, FCPPBK_PREF.'settings-page', 'other-settings' );
	    add_settings_field( 'select-from', 'Post types to search from', function()use($val){}, FCPPBK_PREF.'settings-page', 'other-settings' );
        add_settings_field( 'defer-style', 'Defer the style loading', function()use($val){}, FCPPBK_PREF.'settings-page', 'other-settings' );
//*/

/* admin
    Apply to:
        post types to apply the meta
        post types to grab the titles

    Style:
        main color
        secondary color
        show excerpt
        show date
        show button
            button text
        show main category
        show the image / size
        excerpt length
        layout
            x3-v1
            x3-v2
            x2+list
            list
    Preview

    SEO:
        defer styles checkbox

    Later:
    only admin checkbox (or anyone, who can edit the post)
    get the first image if no featured
    tiles per row
*/
    register_setting( FCPPBK_PREF.'settings-group1', $settings->varname, __NAMESPACE__.'\sanitize_settings' ); // register, save, nonce
});

function text($a) {
    ?>
    <input type="text"
        name="<?php echo esc_attr( $a->name ) ?>"
        id="<?php echo esc_attr( isset( $a->id ) ? $a->id : $a->name ) ?>"
        placeholder="<?php echo isset( $a->placeholder ) ? esc_attr( $a->placeholder )  : '' ?>"
        value="<?php echo isset( $a->value ) ? esc_attr( $a->value ) : '' ?>"
        class="<?php echo isset( $a->className ) ? esc_attr( $a->className ) : '' ?>"
    />
    <?php
}
function checkboxes($a) {
    ?>
    <fieldset
        id="<?php echo esc_attr( isset( $a->id ) ? $a->id : $a->name ) ?>"
        class="<?php echo isset( $a->className ) ? esc_attr( $a->className ) : '' ?>"
    >
    <?php foreach ( $a->options as $k => $v ) { ?>
        <?php $checked = is_array( $a->value ) && in_array( $k, $a->value ) ?>
        <label>
            <input type="checkbox"
                name="<?php echo esc_attr( $a->name ) ?>[]"
                value="<?php echo esc_attr( $k ) ?>"
                <?php echo esc_attr( $checked ? 'checked' : '' ) ?>
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
        name="<?php echo esc_attr( $a->name ) ?>"
        value="<?php echo esc_attr( $a->value ) ?>"
        <?php echo esc_attr( ( $a->checked === $a->value || $a->default && !$checked ) ? 'checked' : '' ) ?>
    >
    <?php
}

// Заполняем опцию 1
function fill_primer_field1(){

	$val = get_option(FCPPBK_PREF.'settings');
	$val = $val ? $val['input'] : null;
	?>
	<input type="text" name="<?php echo FCPPBK_PREF.'settings' ?>[input]" value="<?php echo esc_attr( $val ) ?>" />
	<?php
}

// Заполняем опцию 2
function fill_primer_field2(){

	$val = get_option(FCPPBK_PREF.'settings');
	$val = $val ? $val['checkbox'] : null;
	?>
	<label><input type="checkbox" name="<?php echo FCPPBK_PREF.'settings' ?>[checkbox]" value="1" <?php checked( 1, $val ) ?> /> отметить</label>
	<?php
}

// Очистка данных
function sanitize_settings( $options ){

	foreach( $options as $name => & $val ){
		if( $name == 'input' )
			$val = strip_tags( $val );

		if( $name == 'checkbox' )
			$val = intval( $val );
	}

	//die(print_r( $options )); // Array ( [input] => aaaa [checkbox] => 1 )

	return $options;
}


// the plugin settings page
/*
add_action( 'admin_menu', function () {
	add_submenu_page( 'options-general.php', 'Posts by Queryuery settings', 'Posts by Queryuery', 'switch_themes', 'posts-by-query', 'FCP\PostsByQuery\settings_page', 'dashicons-clipboard', 99 );
} );

function settings_page() {
    ?>
	<form method="post" action="options.php">

		<?php settings_fields( 'theme-fields' ); ?><br />
		
		<h3>Footer fields:</h3>

		<?php do_settings_sections( 'theme-work-hours' ); ?>
		<?php do_settings_sections( 'theme-additional-info' ); ?>

		<div class="theme-inputs">
			<p>
				<span class="theme-iconed">#</span> <input type="text" name="theme-work-hours" value="<?php echo get_option( 'theme-work-hours' ); ?>"/>
			</p>
			<p>
				<span class="theme-iconed">!</span> <input type="text" name="theme-additional-info" value="<?php echo get_option( 'theme-additional-info' ); ?>"/>
			</p>
		</div>

		<?php submit_button(); ?>
	</form>

	<div class="wrap">
		<h2><?php echo get_admin_page_title() ?></h2>

		<?php
		// settings_errors() не срабатывает автоматом на страницах отличных от опций
		if( get_current_screen()->parent_base !== 'options-general' )
			settings_errors('название_опции');
		?>

		<form action="options.php" method="POST">
			<?php
				settings_fields("opt_group");     // скрытые защитные поля
				do_settings_sections("opt_page"); // секции с настройками (опциями).
				submit_button();
			?>
		</form>
	</div>

    <?php
}

/*
// adding theme settings
function theme_settings_menu() {
	$page_title = 'Common settings';
	$menu_title = 'Common';
	$capability = 'edit_pages';
	$menu_slug  = 'theme-settings';
	$function   = 'theme_settings_page';
	$icon_url   = 'dashicons-clipboard';
	$position   = 2;
	add_menu_page( $page_title, $menu_title, $capability, $menu_slug, $function, $icon_url, $position );
}
add_action( 'admin_menu', 'theme_settings_menu' );


function theme_settings_page() {
	theme_clear_cache();
    // ++can use example from here https://wp-kama.ru/function/add_menu_page
?>
	<h1>Common Settings</h1>
	
	<form method="post" action="options.php" class="theme-form">
		<?php settings_fields( 'theme-fields' ); ?><br />
		
		<h3>Footer fields:</h3>
		<?php do_settings_sections( 'theme-work-hours' ); ?>
		<?php do_settings_sections( 'theme-additional-info' ); ?>
		<div class="theme-inputs">
			<p>
				<span class="theme-iconed">#</span> <input type="text" name="theme-work-hours" value="<?php echo get_option( 'theme-work-hours' ); ?>"/>
			</p>
			<p>
				<span class="theme-iconed">!</span> <input type="text" name="theme-additional-info" value="<?php echo get_option( 'theme-additional-info' ); ?>"/>
			</p>
		</div>

		<?php submit_button(); ?>
	</form>

	<style>
		.theme-inputs > p {
			display:flex;
			align-items:center;
			font-size:20px;
		}
		.theme-inputs > p > span {
			margin-right:10px;
		}
		.theme-inputs > p > input {
			flex:1;
		}
		@font-face {
		  font-family: 'Icons';
		  src: url('<?php echo get_template_directory_uri(); ?>/fonts/icons.eot');
		  src: url('<?php echo get_template_directory_uri(); ?>/fonts/icons.woff2') format('woff2'),
			   url('<?php echo get_template_directory_uri(); ?>/fonts/icons.eot?#iefix') format('embedded-opentype');
		}
		.theme-iconed {
			font-family:Icons;
		}
	</style>
<?php
}

function theme_settings_page_capability( $capability ) {
	return 'edit_pages';
}
add_filter( 'option_page_capability_'.'theme-fields', 'theme_settings_page_capability' );

function theme_settings_save() {
	register_setting( 'theme-fields', 'theme-work-hours' );
	register_setting( 'theme-fields', 'theme-additional-info' );
}
add_action( 'admin_init', 'theme_settings_save' );
*/

// ++admin
// ++polish for publishing
    // excape everything before printing
// ++add a global function to print?
// ++option to print automatically?
    // ++is it allowed to make the gutenberg block??
// ++check the plugins on reis - what minifies the jss?
// ++array_unique before saving.. just so is
// ++2 more styles? check the todo
// ++maybe an option with schema?
// ++abort previous fetch if new one is here
// ++some hints how it will work
/* admin
    post types to apply the meta
    maybe some will go to the on-page interface??
    post types
        separate for query and for list
    main color
    secondary color
    show date
    show button
    show category
    show the image / size
    tiles per row
    excerpt length
    layout
        x3-v1
        x3-v2
        x2+list
        list
    defer styles checkbox
    only admin checkbox (or anyone, who can edit the post)
    preview
    get the first image if no featured
    read-more text ++ translation
*/
// ++ drag and drop to change the order of particular posts