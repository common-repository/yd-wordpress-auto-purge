<?php
/**
 * @package YD_Wordpress-auto-purge
 * @author Yann Dubois
 * @version 0.1.0
 */

/*
 Plugin Name: YD Wordpress Auto Purge
 Plugin URI: http://www.yann.com/en/wp-plugins/yd-wordpress-auto-purge
 Description: Automatically deletes older articles that have not been read, based on Wordpress.com stats. | Funded by <a href="http://www.abc.fr">ABC.FR</a>
 Version: 0.1.0
 Author: Yann Dubois
 Author URI: http://www.yann.com/
 License: GPL2
 */

/**
 * @copyright 2010  Yann Dubois  ( email : yann _at_ abc.fr )
 *
 *  Original development of this plugin was kindly funded by http://www.abc.fr
 *
 *  This program is free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program; if not, write to the Free Software
 *  Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 */

/**
 Revision 0.1.0:
 - Original beta release
 */

include_once( 'inc/yd-widget-framework.inc.php' );

$junk = new YD_Plugin( 
	array(
		'name' 				=> 'YD Wordpress Auto Purge',
		'version'			=> '0.1.0',
		'has_option_page'	=> true,
		'has_shortcode'		=> false,
		'has_widget'		=> false,
		'widget_class'		=> '',
		'has_cron'			=> true,
		'crontab'			=> array(
			'daily'			=> array( 'YDWPAP', 'daily_update' ),
			'hourly'		=> array( 'YDWPAP', 'hourly_update' )
		),
		'has_stylesheet'	=> false,
		'stylesheet_file'	=> 'css/yd.css',
		'has_translation'	=> false,
		'translation_domain'=> '', // must be copied in the widget class!!!
		'translations'		=> array(
			array( 'English', 'Yann Dubois', 'http://www.yann.com/' ),
			array( 'French', 'Yann Dubois', 'http://www.yann.com/' )
		),		'initial_funding'	=> array( 'Yann.com', 'http://www.yann.com' ),
		'additional_funding'=> array(),
		'form_blocks'		=> array(
			'Main options:' => array( 
				'keep'		=> 'text',
				'maxdel_1'	=> 'text',
				'maxdel_24'	=> 'text',
				'debug'		=> 'bool'
			)
		),
		'option_field_labels'=>array(
				'keep'	 	=> 'Total number of posts to keep',
				'maxdel_1'	=> 'Maximum number of posts to delete in one hourly pass',
				'maxdel_24'	=> 'Maximum number of posts to delete in one daily pass',
				'debug'		=> 'Show debug information.'
		),
		'option_defaults'	=> array(
				'keep'		=> 30000,
				'maxdel_1'	=> 100,
				'maxdel_24'	=> 200,
				'debug'		=> 0
		),	
		'form_add_actions'	=> array(
				'Manually run hourly purge'	=> array( 'YDWPAP', 'hourly_update' ),
				'Manually run daily purge'	=> array( 'YDWPAP', 'daily_update' ),
				'Check latest purge'		=> array( 'YDWPAP', 'check_update' )
		),
		'has_cache'			=> false,
		'option_page_text'	=> 'Welcome to the plugin settings page. ',
							//	. 'Your Wordpress API key is: ' . get_option( 'wordpress_api_key' ),
		'backlinkware_text' => 'Features Auto Purge Wordpress Plugin developed by Yann',
		'plugin_file'		=> __FILE__		
 	)
);

class YDWPAP {
	function purge_posts( $op, $f = 1 ) {
		global $wpdb;
		$option_key = 'yd-wordpress-auto-purge';
		$options = get_option( $option_key );
		// $options['keep'] = number of posts to keep
		// $options['maxdel_1'] = max number of posts to delete in one hourly pass
		// $options['maxdel_24'] = max number of posts to delete in one daily pass
		
		// Verify that stats_get_csv() is defined, or exit with error
		if ( ! function_exists( 'stats_get_api_key' ) 
			|| ! function_exists( 'stats_get_option' ) 
			|| ! function_exists( 'stats_get_csv' )
		) {
			$op->error_msg = '<p>Wordpress.com Stats Plugin is not installed!</p>';
			return;
		}
		// Verify that YD Woredpress.com integration plugin is installed
		if ( !class_exists( 'YDWPCSI' ) ) {
			$op->error_msg = '<p>YD Wordpress.com Stats Integration is not installed!</p>';
			return;
		}
		
		$api_key	= stats_get_api_key();
		$blog_id	= stats_get_option('blog_id');
		
		if ( ! $api_key || $api_key == ''
			|| ! $blog_id || $blog_id == ''
		) {
			$op->error_msg = '<p>Wordpress.com Stats Plugin is not configured!</p>';
			return;
		}
		
		$op->update_msg .= 'Number of posts to keep: ' . $options['keep'] . '...<br/>';
		
		//check actual number of posts
		$query = 	"SELECT count(ID) FROM $wpdb->posts p ";
		$query .=	"WHERE p.post_status =  'publish' ";
		$query .= 	"AND p.post_type =  'post'";
		$count = $wpdb->get_var( $query );
		
		$op->update_msg .= 'Actual number of posts: ' . $count . '...<br/>';
		
		$limit = $count - $options['keep'];
		
		if( $limit <= 0 ) {
			$op->update_msg .= 'No posts to purge...<br/>';
		} else {
			$op->update_msg .= 'Selecting ' . $limit . ' unvisited posts...<br/>';
			
			//select posts that have never been visited
			$query = 	"SELECT ID FROM $wpdb->posts AS p ";
			$query .=	"LEFT JOIN $wpdb->postmeta AS m ";
			$query .=	"ON p.ID = m.post_id ";
			$query .=	"AND m.meta_key = 'yd_views_365' ";
			$query .= 	"WHERE m.post_id IS NULL ";
			$query .= 	"AND p.post_status = 'publish' ";
			$query .= 	"AND p.post_type = 'post' ";
			$query .= 	"AND p.comment_count = 0 ";
			$query .= 	"ORDER by p.post_date ";
			$query .= 	"LIMIT $limit";
			//$op->update_msg .= $query . '<br/>';
			
			$posts = $wpdb->get_col( $query );
			$delcount = count( $posts );
			
			$op->update_msg .= 'Found ' . $delcount . ' unvisited posts.<br/>';
			$op->update_msg .= 'Will delete up to ' . $options['maxdel_' . $f] . ' posts in this pass.<br/>';
			
			/**
			 * Unused post selection method for explicitly 0 view posts
			//Create a new filtering function that will add our where clause to the query
			function yd_wpap_filter_where($where = '') {
			  //posts in the last 30 days
			  $where .= " AND post_date < '" . date('Y-m-d', strtotime('-30 days')) . "'";
			  return $where;
			}
			// Register the filtering function
			add_filter('posts_where', 'yd_wpap_filter_where');
			$query_string = 'meta_key=yd_views_365&meta_compare==&meta_value=0&orderby=date&order=ASC&';
			// Perform the query, the filter will be applied automatically
			query_posts($query_string);
			**/
			
			$nbdel = 0;	// number of deleted posts
			if( $options['debug'] ) $op->update_msg .=  '<ul>';
			foreach( $posts as $post_id ) {
				$nbdel ++;
				if( $options['debug'] )
					$op->update_msg .=  '<li>'. $post_id . ' ' 
						. get_the_title( $post_id, true );
				$res = wp_delete_post( $post_id, $force_delete = true );
				if( $res ) {
					if( $options['debug'] ) $op->update_msg .=  ' ...deleted.';
				} else {
					if( $options['debug'] ) $op->update_msg .=  ' ...delete failed!';
				}
				if( $options['debug'] ) $op->update_msg .=  '</li>';
				if( $nbdel >= $options['maxdel_' . $f] ) break;
			}
			if( $options['debug'] ) $op->update_msg .=  '</ul>';
		}
		$op->update_msg .=  $nbdel . ' posts were deleted.<br/>';
		$op->update_msg .= '</p>';
		
		update_option( 'YD_WPAP_last_purged', time() );
	}
	function hourly_update( $op ) {
		if( !$op || !is_object( $op ) ) {
			$op = new YD_OptionPage(); //dummy object
		}
		self::purge_posts( &$op, 1 );
		update_option( 'YD_WPAP_hourly', time() );
	}
	function daily_update( $op ) {
		if( !$op || !is_object( $op ) ) {
			$op = new YD_OptionPage(); //dummy object
		}
		self::purge_posts( &$op, 24 );
		update_option( 'YD_WPAP_daily', time() );
	}
	function check_update( $op ) {
		$op->update_msg .= '<p>';
		if( $last = get_option( 'YD_WPAP_daily' ) ) {
			$op->update_msg .= 'Last daily purge was on: ' 
				. date( DATE_RSS, $last ) . '<br/>';
		} else { 
			$op->update_msg .= 'No daily purge yet.<br/>';
		}
		if( $last = get_option( 'YD_WPAP_hourly' ) ) {
			$op->update_msg .= 'Last hourly purge was on: ' 
				. date( DATE_RSS, $last ) . '<br/>';
		} else { 
			$op->update_msg .= 'No hourly purge yet.<br/>';
		}
		if( $last = get_option( 'YD_WPAP_last_purged' ) ) {
			$op->update_msg .= 'Last purge was on: ' 
				. date( DATE_RSS, $last ) . '<br/>';
		} else { 
			$op->update_msg .= 'No recorded purge yet.<br/>';
		}
		$op->update_msg .= '</p>';
	}
}
?>