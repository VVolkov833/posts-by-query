<?php
/*
Plugin Name: FCP Posts by Search Query
Description: Implement a list of relevant posts to particular pages with a search query or exact list of posts. Easy pick and add.
Version: 1.0.0
Requires at least: 5.8
Tested up to: 6.1
Requires PHP: 7.4
Author: Firmcatalyst, Vadim Volkov, Aude Jamier
Author URI: https://firmcatalyst.com
License: GPL v3 or later
License URI: http://www.gnu.org/licenses/gpl-3.0.html
*/

namespace FCP\PostsByQuery;
defined( 'ABSPATH' ) || exit;


define( 'FCPPBK_DEV', false );
define( 'FCPPBK_VER', get_file_data( __FILE__, [ 'ver' => 'Version' ] )[ 'ver' ] . ( FCPPBK_DEV ? time() : '' ) );

define( 'FCPPBK_URL', plugin_dir_url( __FILE__ ) );
define( 'FCPPBK_DIR', plugin_dir_path( __FILE__ ) );


require FCPPBK_DIR . 'inc/config.php';
require FCPPBK_DIR . 'inc/functions.php';
require FCPPBK_DIR . 'inc/form-fields.php';
require FCPPBK_DIR . 'inc/settings-page.php';
require FCPPBK_DIR . 'inc/meta-boxes.php';
require FCPPBK_DIR . 'inc/shortcode.php';


// fill in the initial settings
register_activation_hook( __FILE__, function() {
    add_option( FCPPBK_SETT, get_default_values() );
});



/*
 * FUTURE IMPROVEMENTS
*/

// ++polish for publishing
    // the about-the-plugin texts
    // !!title and description must be more like for printing to understans blah blah check out the competitors
// after publishing add thumbnails and the preview page

// ++ if Posts by Search Query & Date become empty, the old query still prints what was found
// ++ turn posts-by-query into a constant and avoid conflict with FCPPBK_SETT
// ++option to print automatically?
    // ++is it allowed to make the gutenberg block??
// ++maybe an option with schema?
// ++some hints how it will work
/* admin settings
    only admin checkbox (or anyone, who can edit the post)
    get the first image if no featured
    mode for preview only
    // settings for empty behavior (nothing selected or nothing found - think about it)
    // print after the_content() option
*/
// ++ drag and drop to change the order of particular posts
// ++ preview using 1-tile layout && maybe api
// override global with shortcode attributes and all with local on-page meta settings
    // ++make multiple in terms of css
    // attributes are settings: inherit if unset, override if is set
    // attributes are meta boxes: same, but can have s="%slug" or category or category only..