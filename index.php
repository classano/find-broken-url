<?php
/*
Plugin Name: Find broken url
Plugin URI: https://github.com/classano/find-broken-url
Description: Find broken url in post and postmeta. On daily basis it checks for broken url. You can ignore broken url and hide them from the list.
Author: Nitea AB (Claes Norén)
Author URI: http://www.nitea.se
Version: 1.0.1
License: GPLv2
Text Domain: nfbu
*/

defined('ABSPATH') or die('No script kiddies please!');

/**
 * När man aktiverar pluginet.
 */
function nfbu_activate() {
	global $wpdb;
	$charset_collate = $wpdb->get_charset_collate();
	
	/**
	 * Skapa tabellen som samlar alla felaktiga adresser
	 */
	$wpdb->query("CREATE TABLE {$wpdb->prefix}nfbu_url (
		nfbu_url_id int(11) unsigned NOT NULL AUTO_INCREMENT,
		post_id INT(11) UNSIGNED NOT NULL,
		url varchar(500) DEFAULT '',
		status INT(5) DEFAULT '0',
		create_datetime timestamp NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY(nfbu_url_id),
		UNIQUE KEY nfbu_url_id (nfbu_url_id)
	) $charset_collate;");

	/**
	 * Skapa tabellen som samlar alla felaktiga adresser som ska ignoreras
	 */
	$wpdb->query("CREATE TABLE {$wpdb->prefix}nfbu_ignore (
		nfbu_ignore_id int(11) unsigned NOT NULL AUTO_INCREMENT,
		post_id INT(11) UNSIGNED NOT NULL,
		url varchar(500) DEFAULT '',
		create_datetime timestamp NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY(nfbu_ignore_id),
		UNIQUE KEY nfbu_ignore_id (nfbu_ignore_id)
	) $charset_collate;");

	nfbu_schedule_start();
}
register_activation_hook(__FILE__, 'nfbu_activate');

/**
 * När man avinstallerar pluginet
 */
function nfbu_uninstall() {
	global $wpdb;

	$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}nfbu_url");
	$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}nfbu_ignore");
}
register_uninstall_hook(__FILE__, 'nfbu_uninstall');

/**
 * När man inaktiverar pluginet
 */
function nfbu_deactivation() {
	/**
	 * Ta bort schemalagd aktivitet
	 */
	if (wp_next_scheduled('nfbu_schedule_cron')) {
		wp_clear_scheduled_hook('nfbu_schedule_cron');
	}
}
register_deactivation_hook(__FILE__, 'nfbu_deactivation');


/**
 * Ladda in språk
 */
function nfbu_load_textdomain() {
	load_plugin_textdomain('nfbu', false, dirname(plugin_basename(__FILE__)).'/lang/');
}
add_action('plugins_loaded', 'nfbu_load_textdomain');

/**
 * Skapa ett eget tisintervall
 */
function nfbu_cron_add_custom_interval($schedules) {
	$schedules['nfbu_cron_custom_interval'] = array(
		//'interval' => 600, // 10 minuter
		'interval' => 10800, // var 3 timme
		'display' => __('Every 3 hours')
	);
	return $schedules;
}
add_filter('cron_schedules', 'nfbu_cron_add_custom_interval');

/**
 * Skapa schemalagd aktivitet
 */
function nfbu_schedule_start() {
	if( !wp_next_scheduled('nfbu_schedule_cron')) {  
		wp_schedule_event(time(), 'nfbu_cron_custom_interval', 'nfbu_schedule_cron'); 
	}
}
// add_action ('nfbu_schedule_cron', 'nfbu_find');
add_action ('nfbu_schedule_cron', function(){
	require_once 'lib/classes/nfbu.php';
	$nfbu = new nfbu();
	$nfbu->find_broken_url();
});

function nitea_find_broken_url() { 
	add_menu_page('Broken url', 'Broken url', 'publish_pages', 'nfbu', 'nitea_find_broken_url_list');
}
add_action('admin_menu','nitea_find_broken_url');


function nitea_find_broken_url_list(){ 
	global $wpdb;
	
	require_once 'lib/classes/nfbu.php';
	$nfbu = new nfbu();

	/**
	 * Hämta tidzon som användaren har valt
	 */
	$timezone = get_option('gmt_offset');

	/**
	 * Hämta tid när nästa gång cron ska köras och gör om det till datum med vald tidzon
	 */
	$next_check_time 		= wp_next_scheduled('nfbu_schedule_cron');
	$next_check_datetime 	= gmdate("Y-m-d H:i:s", $next_check_time+3600*($timezone+date("I")));

	/**
	 * Hämta tiden just nu i vald tidzon
	 */
	$server_datetime 		= gmdate("Y-m-d H:i:s", time()+3600*($timezone+date("I")));

	if(isset($_POST['nfbu_bulk_ignore_submit'])) {

		if(isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'nfbu_bulk-ignore-comment_'.get_current_user_id())) {

			if(current_user_can('edit_posts')) {
				$nfbu->bulk_ignore_submit($_POST['nfbu_ignore']);
			}
		}
	}
	
	if(isset($_GET['broken-url']) && $_GET['broken-url'] == 'find') {
		if(current_user_can('edit_posts')) {
			$nfbu->find_broken_url();
		}
	}

	/**
	 * Output
	 */
	?>
	<div class="wrap">
		<h1><?php echo __('Find broken URL\'s', 'nfbu'); ?> 
			<a href="<?php echo get_admin_url(); ?>admin.php?page=nfbu&amp;broken-url=find" class="page-title-action"><?php echo __('Find broken URL\'s', 'nfbu'); ?></a>
		</h1>

		<?php 
		$nfbul = $wpdb->get_results('SELECT post_id, url, status FROM '.$wpdb->prefix.'nfbu_url');
		$nfbul_arr = array();
		foreach($nfbul AS $k => $r) {
			$nfbul_arr[$r->post_id][] = array(
				'url' => $r->url,
				'status' => $r->status
			);
		}
		?>
		<form method="post" action="<?php echo get_admin_url(); ?>admin.php?page=nfbu" target="_blank">
			<table class="wp-list-table widefat striped">
				<thead>
					<tr>
						<th><?php echo __('Page', 'nfbu'); ?></th>
						<th><?php echo __('URL', 'nfbu'); ?></th>
						<th><?php echo __('Status', 'nfbu'); ?></th>
					</tr>
				</thead>
				<?php if(count($nfbul_arr) > 0) : ?>
				<tfoot>
					<tr>
						<td colspan="3"><button type="submit" name="nfbu_bulk_ignore_submit" id="nfbu-bulk-ignore-submit" class="button button-primary button-large"><?php echo __('Ignore selected', 'nfbu'); ?></button></td>
					</tr>
				</tfoot>
				<tbody>
					<?php foreach($nfbul_arr AS $k => $r) : ?>
						<tr>
							<td colspan="3">
								<a href="<?php echo get_admin_url(); ?>post.php?post=<?php echo $k; ?>&action=edit" target="_blank">
									<strong><?php echo get_the_title($k); ?></strong>
								</a>
							</td>
						</tr>
						<?php foreach($r AS $k2 => $r2) : ?>
						<tr>
							<td>
								<label>
									<input type="checkbox" name="nfbu_ignore[<?php echo $k; ?>][]" value="<?php echo esc_url($r2['url']); ?>" />
								</label>
							</td>
							<td>
								<a href="<?php echo esc_url($r2['url']); ?>" target="_blank"><?php echo esc_url($r2['url']); ?></a>
							</td>
							<td><?php echo esc_html($r2['status']); ?></td>
						</tr>
						<?php endforeach; ?>
					<?php endforeach; ?>
				<?php else : ?>
				</tbody>
				<tbody>
					<tr>
						<td colspan="3"><?php echo __('No broken URL\'s found', 'nfbu'); ?></td>
					</tr>
				</tbody>
				<?php endif; ?>
			</table>

			<em><?php echo __('Next check will be at', 'nfbu'); ?> <?php echo $next_check_datetime; ?> (<?php echo $server_datetime; ?>)</em>

			<?php wp_nonce_field('nfbu_bulk-ignore-comment_'.get_current_user_id()); ?>
		</form>
	</div>
<?php

	// $cron_jobs = get_option( 'cron' );
	// var_dump($cron_jobs);

}