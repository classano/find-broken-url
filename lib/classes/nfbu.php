<?php
class nfbu {
	function __construct() {

	}

	public function bulk_ignore_submit($post) {
		global $wpdb;
		/**
		 * Definiera varibler
		 */
		$values = array();
		$place_holders = array();

		/**
		 * Loopa igenom alla rader som vi får med och fyll på arrayerna så vi får värden till databasen
		 */
		if(is_array($post) && count($post > 0)) {
			foreach($post as $post_id => $v) {
				foreach($v AS $k => $url) {
					array_push($values, intval($post_id), preg_replace('/^(http(s)?)?:?\/*/u','http$2://',trim($url)));
					$place_holders[] = '(%d, %s)';
					
					$wpdb->query(
						$wpdb->prepare('DELETE FROM '.$wpdb->prefix.'nfbu_url WHERE url = %s AND post_id = %d',preg_replace('/^(http(s)?)?:?\/*/u','http$2://',trim($url)), intval($post_id))
					);
				}
			}
		}

		$wpdb->query(
			$wpdb->prepare('INSERT INTO '.$wpdb->prefix.'nfbu_ignore (post_id,url) VALUES'.implode(', ', $place_holders).'',$values)
		);
	}

	public function find_broken_url() {
		global $wpdb;
		/**
		 * Hämta alla länkar som vi ska ignorera.
		 */
		$nfbui = $wpdb->get_results('SELECT post_id, url FROM '.$wpdb->prefix.'nfbu_ignore');
		
		$ignore_list = array();
		foreach($nfbui AS $k => $r) {
			$ignore_list[$r->post_id][] = $r->url;
		}

		$values = array();
		$place_holders = array();

		/**
		 * regex för att hitta alla länkar (urler)
		 */
		$reg_exUrl = '#\bhttps?://[^\s()<>]+(?:\([\w\d]+\)|([^[:punct:]\s]|/))#';

		$all_posts = $wpdb->get_results('SELECT ID,post_content,post_type FROM '.$wpdb->prefix.'posts WHERE post_status = "publish"
				AND (
					post_content LIKE "%http://%"
					OR 
					post_content LIKE "%https://%"
				)
			'
		);
		foreach($all_posts AS $r) {
			if(preg_match_all($reg_exUrl, $r->meta_value, $url)) {
				foreach($url[0] AS $k => $v) {
					
					if((isset($ignore_list[$r->ID]) && in_array($v,$ignore_list[$r->ID]))) {
					}else{
						$status = $this->get_url_status($v);
						if($status != 200) {
							array_push($values, intval($r->post_id), preg_replace('/^(http(s)?)?:?\/*/u','http$2://',trim($v)), intval($status));
							$place_holders[] = '(%d, %s, %d)';
						}
					}
				}
			}
		}

		$all_posts_meta = $wpdb->get_results('SELECT wpm.post_id,wpm.meta_value FROM '.$wpdb->prefix.'postmeta wpm
			LEFT JOIN '.$wpdb->prefix.'posts wp
			ON wp.ID = wpm.post_id

			WHERE wp.post_status = "publish" 
			AND (
				wpm.meta_value LIKE "%http://%"
				OR 
				wpm.meta_value LIKE "%https://%"
			)
			ORDER BY meta_id 
			-- DESC LIMIT 0,100'
		);
		foreach($all_posts_meta AS $r) {
			if(preg_match_all($reg_exUrl, $r->meta_value, $url)) {
				foreach($url[0] AS $k => $v) {
					
					if((isset($ignore_list[$r->post_id]) && in_array($v,$ignore_list[$r->post_id]))) {
					}else{
						$status = $this->get_url_status($v);
						if($status != 200) {
							array_push($values, intval($r->post_id), preg_replace('/^(http(s)?)?:?\/*/u','http$2://',trim($v)), intval($status));
							$place_holders[] = '(%d, %s, %d)';
						}
					}
				}
			}
		}

		/**
		 * Ta bort alla eventuella länkar
		 */
		$wpdb->query('DELETE FROM '.$wpdb->prefix.'nfbu_url WHERE nfbu_url_id > 0');
		/**
		 * Lägg till alla felaktiga länkar
		 */
		$wpdb->query(
			$wpdb->prepare('INSERT INTO '.$wpdb->prefix.'nfbu_url (post_id,url,status) VALUES'.implode(', ', $place_holders).'',$values)
		);
	}

	private function get_url_status($url) {
		/**
		 * Hämta status för URL
		 */

		$html_brand = $url;
		$ch = curl_init();

		$options = array(
			CURLOPT_URL            => $html_brand,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HEADER         => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_ENCODING       => "",
			CURLOPT_AUTOREFERER    => true,
			CURLOPT_CONNECTTIMEOUT => 120,
			CURLOPT_TIMEOUT        => 120,
			CURLOPT_MAXREDIRS      => 10,
		);
		curl_setopt_array( $ch, $options );
		curl_exec($ch); 
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

		curl_close($ch);
		
		return $httpCode;
	}

}