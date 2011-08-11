<?php
/*
Plugin Name: WordPress Push
Plugin URI: http://rowatt.com/wp_push
Description: Easy separation of live and development versions of your site
Version: 0.1.1alpha
Author: Mark Rowatt Anderson
Author URI: http://rowatt.com
License: GPL2
*/

/*  Copyright 2009-2011  Mark Rowatt Anderson  (wp_push -at- mark.anderson.vg)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as 
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/


/* -------------------------------------------------------------- */

//set default constants
define( 'MRA_WPP_BASE_CAPABILITY', 'delete_plugins' );

//initialisation
add_action('init','mra_wpp_activate_plugins_for_site');
add_action('init','mra_wpp_clear_cache');
add_action('admin_init','mra_wpp_admin_init');
add_action('admin_menu','mra_wp_push_register_settings');
add_action('admin_menu','mra_wpp_options_init');
add_action('admin_menu','mra_wp_push_menu');

//uninstall
register_uninstall_hook(__FILE__, 'mra_wpp_uninstall');

//add settings to plugin listing page
add_filter( 'plugin_action_links', 'mra_wpp_plugin_links', 10, 2 );
add_filter( 'plugin_action_links', 'mra_wpp_plugin_admin_override', 10, 2 );

//include required files
require_once('screens/options.php');
require_once('screens/push.php');
require_once('class.wp_push.php');

//delete options entry when plugin is deleted
function mra_wpp_uninstall()
{
	delete_option('mra_wp_push_options');
}

//add settings to plugin listing page
//called by plugin_action_links filter
function mra_wpp_plugin_links( $links, $file )
{
	if ( $file == plugin_basename( __FILE__ ) )
	{
		$add_link = '<a href="'.get_admin_url().'admin.php?page=mra_wpp_options">'.__('Settings').'</a>'; //@todo
		array_unshift( $links, $add_link );
	}
	return $links;
}

/* -------------------------------------------------------------- */

//make sure we have all options set and valid
function mra_wpp_options_init()
{
	global $mra_wpp_options;

	//get options from DB
	$mra_wpp_options = get_option( 'mra_wp_push_options' );

	//make sure various option defaults are set
	if( empty($mra_wpp_options['cache']) )
		$mra_wpp_options['cache'] = 'none';
	
	//activate/deactivate plugin options for live site(s)
	//for non-live sites plugins are switched to the opposite state
	//these are all hard wired for now, but will later be added to user configurable options
	// key is ID of plugin for hiding activate/deactivate
	$mra_wpp_options['plugins']['activate'][] = 'google-analytics-for-wordpress/googleanalytics.php';
	$mra_wpp_options['plugins']['activate'][] = 'google-sitemap-generator/sitemap.php';
	$mra_wpp_options['plugins']['activate'][] = 'jetpack/jetpack.php';
	$mra_wpp_options['plugins']['deactivate'][] = 'debug-bar/debug-bar.php';
	$mra_wpp_options['plugins']['deactivate'][] = 'debug-bar-console/debug-bar-console.php';

	
	//get options from WP_DB & validate all user set params
	$mra_wpp_options = mra_wpp_validate_options( $mra_wpp_options );
	if( !empty( $mra_wpp_options['notices'] ) )
	{
		//one or more options not OK, so stop here and leave WP Push inactive
		$mra_wpp_options['ok'] = FALSE;
		return FALSE;
	}

	//get site info from the sites.conf file
	$mra_wpp_options['sites'] = parse_ini_file($mra_wpp_options['sites_conf'],TRUE);

	//make sure certain sites options set correctly
	foreach( $mra_wpp_options['sites'] as $site=>$params )
	{
		$mra_wpp_options['sites'][ $site ]['label'] = empty( $params['label'] ) ? $site : $params['label'];
		$mra_wpp_options['sites'][ $site ]['default'] = empty( $mra_wpp_options['default_push'] ) ? $params['default'] : $mra_wpp_options['default_push'];
		$mra_wpp_options['sites'][ $site ]['admin_only'] =  empty( $params['wpp_admin_only'] ) ? FALSE : $params['wpp_admin_only'];
		$mra_wpp_options['sites'][ $site ]['name'] =  $site;
	}

	$mra_wpp_options['current_site'] = $mra_wpp_options['sites'][ mra_wpp_get_current_site() ];

	//all options OK, so plugin can do its stuff!
	$mra_wpp_options['ok'] = TRUE;
}

//set up the WP admin menu
//called by admin_menu action
function mra_wp_push_menu()
{
	global $mra_wpp_options;

	//if options aren't OK and user doesn't have admin capability don't add WP Push menus
	//if( ! mra_wpp_can_admin() && ! $mra_wpp_options['ok'] ) return;

	//add menu(s) - only options page is shown if not configured properly
	$page_title = 'WP Push';
	$menu_title = 'WP Push';
	$capability = $mra_wpp_options['capability'];
	$menu_slug = $mra_wpp_options['ok'] ? 'mra_wp_push' : 'mra_wpp_options';
	$function = $mra_wpp_options['ok'] ? 'mra_wp_push_html' : 'mra_wpp_options_html';
	$icon_url = '';
	$position = 3;
	add_menu_page( $page_title, $menu_title, $capability, $menu_slug, $function, $icon_url, $position );

	$parent_slug = $menu_slug;
	
	//add WP Push if options are OK
	if( $mra_wpp_options['ok'] )
	{	
		$page = add_submenu_page( $parent_slug, $page_title, $menu_title, $capability, $menu_slug, $function);
		add_action('admin_print_styles-' . $page, 'mra_wpp_admin_styles' ); //add custom stylesheet
	}
	
	if( mra_wpp_can_admin() )
	{
		//add options page if we have admin capability
		$page_title = 'WP Push Options';
		$menu_title = 'Options';
		$menu_slug = 'mra_wpp_options';
		$function = 'mra_wpp_options_html';
		
		$page = add_submenu_page( $parent_slug, $page_title, $menu_title, $capability, $menu_slug, $function);

		add_action('admin_print_styles-' . $page, 'mra_wpp_admin_styles' ); //add custom stylesheet
	}
}

function mra_wpp_admin_init()
{
	wp_register_style( 'mra-wpp-styles', plugins_url( 'styles.css', __FILE__ ) );
}

// load css
function mra_wpp_admin_styles()
{
  wp_enqueue_style( 'mra-wpp-styles' );
}

/* -------------------------------------------------------------- */

function mra_wpp_can_admin()
{
	global $mra_wpp_options;
	return current_user_can( $mra_wpp_options['admin_capability'] ) || current_user_can( MRA_WPP_BASE_CAPABILITY );
}

function mra_wpp_can_use()
{
	global $mra_wpp_options;
	return current_user_can( $mra_wpp_options['capability'] ) || current_user_can( MRA_WPP_BASE_CAPABILITY );
}

function mra_wpp_do_the_push( $my_push, $push_options )
{
	global $mra_wpp_options;
	
	//if we are going to do a push, check that we were referred from options page as expected
	check_admin_referer('wp_push','mra_wp_push'); //@todo check this is correct
	
	$my_push->sites_conf_path = $mra_wpp_options['sites_conf'];
	$my_push->dbs_conf_path = $mra_wpp_options['dbs_conf'];
	
	$my_push->source = $push_options['source'];
	$my_push->dest = $push_options['dest'];
	
	$my_push->dry_run = $push_options['dry_run'] ? TRUE : FALSE;
	$my_push->do_backup = $push_options['do_backup'] ? TRUE : FALSE;
	$my_push->backup_path = $mra_wpp_options['backup_path'];
	
	$my_push->echo_output = TRUE;
	$my_push->output_level = mra_wpp_can_admin() ? 2 : 1;
	
	//initialise some parameters
	$push_files = FALSE;
	$result = '';
	$db_types = array();
	
/* -------------------------------------------------------------- */
/* !Push WordPress Files */
/* -------------------------------------------------------------- */
	if( $push_options['push_uploads'] )
	{
		$push_files = TRUE;
		$my_push->push_uploads = TRUE;
	}
	
	if( $push_options['push_themes'] )
	{
		$push_files = TRUE;
		$my_push->push_themes = TRUE;
	}
	
	if( $push_options['push_theme'] && ! $push_options['push_themes'])
	{
		//pushes current (child) theme
		$push_files = TRUE;
		$themes = get_themes();
		$my_push->theme = $themes[ get_current_theme() ]['Stylesheet'];
	}
	
	if( $push_options['push_plugins'] )
	{
		$push_files = TRUE;
		$my_push->push_plugins = TRUE;
	}
	
	if( $push_options['push_wp_core'] )
	{
		$push_files = TRUE;
		$my_push->push_wp_files = TRUE;
	}

	//do the push
	if( $push_files) $result .= $my_push->push_files();

/* -------------------------------------------------------------- */
/* !Push WordPress Database */
/* -------------------------------------------------------------- */
	if( $push_options['db_all_tables'] )
	{
		//with no params entire DB is pushed
		$result .= $my_push->push_db();
	}
	else
	{
		if( $push_options['db_post_content'] ) $db_types[] = 'content';
		if( $push_options['db_users'] ) $db_types[] = 'users';
		if( $push_options['db_options'] ) $db_types[] = 'options';
		//if( $push_options['db_gravity_options'] ) $db_types[] = 'forms';
		//if( $push_options['db_gravity_data'] ) $db_types[] = 'form-data';
	
		//do the push
		if( $db_types ) $result .= $my_push->push_db( $db_types );
	}
/* -------------------------------------------------------------- */
/* !Clear Cache */
/* -------------------------------------------------------------- */

	if( $push_options['clear_cache'] && !empty($mra_wpp_options['cache']) && 'none'<>$mra_wpp_options['cache'] )
	{
		$my_push->cache_type = $mra_wpp_options['cache'];
		$my_push->cache_key = urlencode( $mra_wpp_options['cache_key'] );
		$result .= $my_push->clear_cache();
	}
	
/* -------------------------------------------------------------- */
/* !Other things to do */
/* -------------------------------------------------------------- */
	//normally result should be empty - results to display are captured in class and output separately
	//if anything is output here it probably means something went wrong
	if( $result ) echo "<div class='error'>{$result}</div>";

	return TRUE;
}

//output HTML for push option
function mra_wpp_option_html($option, $label, $admin_only='admin_only', $checked='not_checked' )
{
	global $mra_wpp_options;

	$checked_html = 'checked'==$checked ? ' checked="checked"' : '';
	if( 'admin_only'==$admin_only && ! mra_wpp_can_admin() )
		return '';
	else
		return "<label title='{$option}'><input type='checkbox' name='{$option}' value='{$option}'{$checked_html} /> {$label}</label><br />\n";
}

//clear cache for this site
/**
 * mra_wpp_clear_cache
 * 
 * Clear cache(s) based on HTTP GET parameters. Allows another site to tell this site to clear its cache.
 * Will only run if GET params include correct secret key, which is defined in WP Push options
 *
 * @return string result code
 */
function mra_wpp_clear_cache()
{
	//check $_GET to see if someone has asked us to clear the cache
	//for example a push from another server to this one
	$cmd = isset($_GET['mra_wpp_cmd']) ? $_GET['mra_wpp_cmd'] : FALSE;
	$key = isset($_GET['mra_wpp_key']) ? $_GET['mra_wpp_key'] : FALSE;

	//do nothing if the secret key isn't correct
	$options = get_option('mra_wp_push_options');
	if( ! $key == urlencode( $options['cache_key'] ) ) return;

	switch( $cmd )
	{
		case 'clear_w3tc':
			// Purge the entire w3tc page cache:
			if (function_exists('w3tc_pgcache_flush')) {
				w3tc_pgcache_flush();
				die('W3TC cache cleared');
			}
			else
			{
				die('W3TC cache not present');
			}
			break;
			
		case '':
			//no command supplied
			break;
		
		default:
			die('Unrecognised cache command');
			break;
	}	
}

//make sure correct plugins are activated/deactivated for the site we are viewing
function mra_wpp_activate_plugins_for_site()
{
	global $mra_wpp_options;
	
	//initialise vars if we haven't run plugin init already
	if( empty($mra_wpp_options) ) mra_wpp_options_init();
	
	//check if settings OK
	if( !$mra_wpp_options['ok'] ) return FALSE;
	
	//make sure WP plugin code is loaded
	require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
	
	if( !empty($mra_wpp_options['current_site']['live']) )
	{
		//site is live so activate/deactivate plugins for live site(s) as per options
		foreach( $mra_wpp_options['plugins']['activate'] as $plugin )
		{
			if( !is_plugin_active($plugin) ) activate_plugin($plugin);
		}

		foreach( $mra_wpp_options['plugins']['deactivate'] as $plugin )
		{
			if( is_plugin_active($plugin) ) deactivate_plugins($plugin);
		}
	}
	else
	{
		//activate/deactivate plugins for non-live site(s) as per opposite of options for live site(s)
		foreach( $mra_wpp_options['plugins']['deactivate'] as $plugin )
		{
			if( !is_plugin_active($plugin) ) activate_plugin($plugin);
		}

		foreach( $mra_wpp_options['plugins']['activate'] as $plugin )
		{
			if( is_plugin_active($plugin) ) deactivate_plugins($plugin);
		}
	}

	//caching plugins - make sure plugin is on if WP_CACHE constant is TRUE
	if( WP_CACHE )
	{
		if( !is_plugin_active($mra_wpp_options['cache']) ) activate_plugin( $mra_wpp_options['cache'] );	
	}
	else
	{
		if( is_plugin_active($mra_wpp_options['cache']) ) deactivate_plugins( $mra_wpp_options['cache'] );	
	}
	
}

//removes activate/deactivate links for plugins controlled by WP_Push
function mra_wpp_plugin_admin_override( $links, $file )
{
	global $mra_wpp_options;

	//check if settings OK
	if( !$mra_wpp_options['ok'] ) return FALSE;
	
	foreach( array_merge( $mra_wpp_options['plugins']['activate'], $mra_wpp_options['plugins']['deactivate'] ) as $plugin )
	{
		if ( $file == $plugin )
		{
			if( array_key_exists('activate', $links) )
				$links['activate'] = "Deactivated by WP Push";
			elseif( array_key_exists('deactivate', $links) )
				$links['deactivate'] = "Activated by WP Push";
			return $links;
		}
	}
	
	return $links;
}

//figure out which of our sites is currently running
function mra_wpp_get_current_site()
{
	global $mra_wpp_options;
	
	$this_site = '';
	$default = '';
	
	foreach( $mra_wpp_options['sites'] as $site=>$site_conf )
	{
		if( !empty( $site_conf['domain'] ) )
			$site_conf['domains'][] = $site_conf['domain'];
		
		//check if this site is the default and remember if it is
		if( $site_conf['default'] )
			$default = $site;
	
		if( in_array( $_SERVER['SERVER_NAME'], $site_conf['domains'] ) )
		{
			//we found a match so we know what site we are on
			$this_site = $site;
			break;
		}
	}
	
	//we didn't recognise the URL, so assume we are in the default site
	if( !$this_site )
		$this_site = $default;
	
	if( $this_site )
		return $this_site;
	else
		die("<div id='mra_wpp_site_error' class='error settings-error'>This site ({$_SERVER['SERVER_NAME']}) is not recognised and you have not set a default in sites.conf. Please configure sites.conf with the domain of this site, or set a default.</div>");
}

//get all sites which are valid given current capability
function mra_wpp_get_sites( $exclude_current='no' )
{
	global $mra_wpp_options;
	$sites_list = array();
	
	$exclude = ('exclude_current'==$exclude_current) ? mra_wpp_get_current_site() : '';

	foreach( $mra_wpp_options['sites'] as $site=>$site_conf )
	{
		if( $site<>$exclude && (mra_wpp_can_admin() || !$site_conf['admin_only']) )
			$sites_list[] = $site;
	}
	return $sites_list;
}

//equivalent to WP function get_query_var, but works in admin
function mra_wpp_get_query_var( $var )
{
	return empty( $_REQUEST[ $var ] ) ? FALSE : $_REQUEST[ $var ];
}

function mra_wpp_settings_notices()
{
	global $mra_wpp_options;
	
	if( empty( $mra_wpp_options['notices'] ) ) return FALSE; //nothing to display
	
	foreach( $mra_wpp_options['notices'] as $notice=>$type )
	{
		$class = 'error'==$type ? 'error settings-error' : 'updated settings-error';
		$output .= "<div id='mra_wpp_options_{$type}' class='{$class}'>";
		$output .= "<p>{$notice}</p>";
		$output .= "</div>";
	}
	return $output;
}

/* EOF */