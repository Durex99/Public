<?php

define('BASE_DIR', realpath(__DIR__.'/../'));

$crawler_mode = isset($argv[1]) ? $argv[1] : 'movies';

error_reporting(E_ALL & ~E_NOTICE);

if(function_exists('mb_internal_encoding')) {
	mb_internal_encoding("UTF-8");
}

include BASE_DIR.'/config.php';
include BASE_DIR.'/includes/functions.php';
include BASE_DIR.'/includes/sql.class.php';
include BASE_DIR.'/includes/template.class.php';
include BASE_DIR.'/includes/module.class.php';
include BASE_DIR.'/includes/pagination.class.php';

$sql = new Sql($config['dbhost'], $config['dbuser'], $config['dbpass'], $config['dbname']);

# set utf8 charset
$sql->query('SET NAMES utf8');

$genres = array_column($sql->fetch_all("SELECT * FROM genres"),'genre_id', 'genre_name');

$settings = [];

$url_versions = [
	'Lektor / PL' => 'lector',
];

$domain = 'https://zalukaj.com/';
$urls = [];

switch($crawler_mode) {
	case 'movies':
		$last_id = $sql->fetch_row("SELECT MAX(video_zalukaj_id) FROM videos WHERE video_type = 'movie' AND video_zalukaj_id IS NOT NULL")[0];
		if(!$last_id) {
			$last_id = 1;
		}
		$max_id = $last_id + 5000;
		$curl = curl_get($domain, $settings);
		$xpath = get_xpath($curl['content']);
		$nodes = $xpath->query("//a[contains(@href, '/zalukaj-film/')]");
		if($nodes->length > 0) {
			$node = $nodes->item(0);
			if(preg_match('/film\/([0-9]+)/i', $node->getAttribute('href'), $matches)) {
				$max_id = $matches[1];
			}
		}
		$start_url = 'https://zalukaj.com/zalukaj-film/';
		$crawler_regex = '/film\//';
		for($i = $last_id; $i <= $max_id; $i++) {
			$urls[] = $start_url.$i.'/movie.html';
		}
	break;
	case 'episodes':
		$last_id = $sql->fetch_row("SELECT MAX(video_zalukaj_id) FROM videos WHERE video_type = 'episode' AND video_zalukaj_id IS NOT NULL")[0];
		if(!$last_id) {
			$last_id = 50000;
		}
		$max_id = $last_id + 5000;
		$curl = curl_get('https://zalukaj.com/seriale', $settings);
		$xpath = get_xpath($curl['content']);
		$nodes = $xpath->query("//a[contains(@href, '/kategoria-serialu/')]");
		if($nodes->length > 0) {
			$curl = curl_get(get_url($nodes->item(0)->getAttribute('href')), $settings);
			$xpath = get_xpath($curl['content']);
			$nodes = $xpath->query("//a[contains(@href, '/serial-online/')]");
			if($nodes->length > 0) {
				$node = $nodes->item(0);
				if(preg_match('/serial-online\/([0-9]+)/i', $node->getAttribute('href'), $matches)) {
					$max_id = $matches[1];
				}
			}
		}
		$start_url = 'https://zalukaj.com/serial-online/';
		$crawler_regex = '/film\//';
		for($i = $last_id; $i <= $max_id; $i++) {
			$urls[] = $start_url.$i.'/episode.html';
		}
	break;
}

$skip = array();
$i = 0;
$videos = 0;

while(count($urls) > $i) {

	$url = $urls[$i];
	$i++;
	
	sleep(mt_rand(1, 3));

	$curl = curl_get($url, $settings);
	
	echo date('Y-m-d H:i:s')." | {$i} ({$videos}) / ".count($urls)." | {$url}\n";
	
	if(strpos($curl['url'], $domain) === false || $curl['http_code'] != '200' || strpos($curl['content_type'], 'text/html') === false || strpos($curl['url'], 'not_found') !== false) {
		$skip[] = $url;
		continue;
	}
	
	$xpath = get_xpath($curl['content']);

	$hosts = [];
	$nodes = $xpath->query("//iframe[contains(@src, 'player.php')]");
	if($nodes->length > 0) {
		for($x = 0; $x < $nodes->length; $x++) {
			$node = $nodes->item($x);
			if(preg_match('/w=([^&]+)/i', $node->getAttribute('src'), $matches)) {
				$hosts[] = 'http://vshare.io/d/'.$matches[1].'/';
			}
		}
	}
	
	if(count($hosts) > 0 || strpos($url, '/serial/') !== false) {
		$zalukaj_id = null;
		if(preg_match('/(?:film|serial-online)\/([0-9]+)/i', $url, $matches)) {
			$zalukaj_id = $matches[1];
		}
		
		$videos++;
		
		$video_type = '';
		switch($crawler_mode) {
			case 'movies':
				$video_type = 'movie';
			break;
			case 'episodes':
				$video_type = 'episode';
			break;
		}
		if(!$video_type) die;
		
		$year = null;
		$season = null;
		$episode = null;
		
		$title = '';
		$tmp = $xpath->query("//*[@id='pw_title']");
		if($tmp->length > 0) {
			$title = trim(preg_replace('/[\s]+/', ' ', $tmp->item(0)->nodeValue));
		}
		
		$description = '';
		$tmp = $xpath->query("//*[@id='pw_description']");
		if($tmp->length > 0) {
			$description = trim($tmp->item(0)->nodeValue);
		}
		
		$parent_id = null;
		$season_id = null;
		$episode_id = null;
		$categories = [];
		$url_version = '';
		$image = '';
		$about = $xpath->query("//div[contains(@class, 'about_movie')][table]");
		if($about->length > 0) {
			$about = $about->item(0);
			
			$tmp = $xpath->query(".//th[contains(text(), 'Gatunek:')]/../td//a", $about);
			if($tmp->length > 0) {
				for($x = 0; $x < $tmp->length; $x++) {
					$item = $tmp->item($x);
					if(preg_match('/sezon-serialu\/[^\/]+,([0-9]+)/i', $item->getAttribute('href'), $matches)) {
						$parent_id = $matches[1];
					}
					$categories[] = trim($item->nodeValue);
				}
				$categories = array_unique($categories);
			}
			
			$tmp = $xpath->query(".//th[contains(text(), 'Sezon:')]/../td", $about);
			if($tmp->length > 0) {
				$season_id = trim($tmp->item(0)->nodeValue);
			}
			
			$tmp = $xpath->query(".//th[contains(text(), 'Odcinek:')]/../td", $about);
			if($tmp->length > 0) {
				$episode_id = trim($tmp->item(0)->nodeValue);
			}
			
			$tmp = $xpath->query(".//th[contains(text(), 'Typ:')]/../td", $about);
			if($tmp->length > 0) {
				$url_version = trim($tmp->item(0)->nodeValue);
			}
			
			$tmp = $xpath->query(".//img[contains(@src, '/image/')]", $about);
			if($tmp->length > 0) {
				$image = get_url(trim($tmp->item(0)->getAttribute('src')));
			}
		}
		
		if(preg_match('/\(?([0-9]{4})\)?$/', $title, $matches)) {
			$year = $matches[1];
			$title = trim(preg_replace('/\(?([0-9]{4})\)?$/', '', $title));
		}
		$ex = explode('/', str_replace('&amp;', '&', $title));
		$title = trim($ex[0]);
		$subtitle = trim($ex[1]);
		
		$tv_show_id = null;
		if($video_type == 'episode') {
			if(!$parent_id) continue;
			
			$simage = '';
			$curl = curl_get('https://zalukaj.com/sezon-serialu/tvshow,'.$parent_id.'/', $settings);
			$xpath = get_xpath($curl['content']);
			$nodes = $xpath->query("//img[contains(@src, 'promote_serial')]");
			if($nodes->length > 0) {
				$simage = get_url($nodes->item(0)->getAttribute('src'));
			}
			$stitle = array_shift($categories);
			$values = [
				'video_type'        => 'tv_show',
				'video_name'        => $sql->clear($stitle),
				'video_name_alt'    => $categories ? implode(' / ', $categories) : '',
				'video_description' => $sql->clear($description),
				'video_image'       => $simage,
				'video_status'      => 1,
				'video_date'        => time(),
				'video_zalukaj_id'  => $parent_id,
			];
			$categories = [];
			$description = '';
			
			$old_video = $sql->fetch_assoc("
				SELECT *
				FROM videos
				WHERE video_type = 'tv_show' AND video_zalukaj_id = '{$parent_id}'
				LIMIT 1
			");
		
			if($old_video) {
				$tv_show_id = $old_video['video_id'];
			} else {
				$sql->insert('videos', $values);
				$tv_show_id = $sql->insert_id;
			}
			
			if($simage && (!$old_video || ($old_video && !$old_video['video_image']))) {
				$image_file = '/assets/images/covers/'.$tv_show_id.'.'.pathinfo($simage, PATHINFO_EXTENSION);
				$image_filename = BASE_DIR.$image_file;
				$curl = curl_get($simage, $settings);
				file_put_contents($image_filename, $curl['content']);
				$sql->update('videos', ['video_image' => $image_file], "video_id = '{$tv_show_id}'");
			}
			
			$title = sprintf('s%02de%02d', $season_id, $episode_id);
			
		}
		
		echo "parent_id: {$parent_id}\n";
		echo "season_id: {$season_id}\n";
		echo "episode_id: {$episode_id}\n";
		echo "title: {$title}\n";
		echo "subtitle: {$subtitle}\n";
		echo "year: {$year}\n";
		echo "description: {$description}\n";
		echo "categories: ".print_r($categories, true)."\n";
		echo "url_version: {$url_version}\n";
		echo "image: {$image}\n";
		echo "urls: ".print_r($hosts, true)."\n";
		
		$values = [
			'video_type'        => $video_type,
			'video_name'        => $sql->clear($title),
			'video_name_alt'    => $sql->clear($subtitle),
			'video_description' => $sql->clear($description),
			'video_image'       => $image,
			'video_status'      => 1,
			'video_date'        => time(),
			'video_zalukaj_id'  => $zalukaj_id,
		];
		
		if($video_type == 'episode') {
			$values['video_parent_id']      = $tv_show_id;
			$values['video_season_number']  = $season_id;
			$values['video_episode_number'] = $episode_id;
		}
		
		if($year) {
			$values['video_year'] = $year;
		}
		
		$old_video = $sql->fetch_assoc("
			SELECT *
			FROM videos
			WHERE video_type = '{$values['video_type']}' AND video_zalukaj_id = '{$zalukaj_id}'
			LIMIT 1
		");
		
		if($old_video) {
			$insert_id = $old_video['video_id'];
		} else {
			$sql->insert('videos', $values);
			$insert_id = $sql->insert_id;
		}
		
		if($image && (!$old_video || ($old_video && !$old_video['video_image']))) {
			$image_file = '/assets/images/covers/'.$insert_id.'.'.pathinfo($image, PATHINFO_EXTENSION);
			$image_filename = BASE_DIR.$image_file;
			$curl = curl_get($image, $settings);
			file_put_contents($image_filename, $curl['content']);
			$sql->update('videos', ['video_image' => $image_file], "video_id = '{$insert_id}'");
		}
		
		if($categories && !$old_video) {
			$genre_ids = [];
			foreach($categories as $category) {
				$category = mb_strtolower($category);
				if(!isset($genres[$category])) {
					$values = [
						'genre_name' => $category,
					];
					$sql->insert('genres', $values);
					$genres[$category] = $sql->insert_id;
				}
				$values = [
					'vg_genre_id' => $genres[$category],
					'vg_video_id' => $insert_id,
				];
				$sql->insert('video_genres', $values);
				$genre_ids[] = $genres[$category];
			}
			$sql->update('videos', ['video_genres' => implode(',', $genre_ids)], "video_id = '{$insert_id}'");
		}
		
		$url_version = isset($url_versions[$url_version]) ? $url_versions[$url_version] : $url_version;
		$real_hosts_count = $sql->count('video_urls', 'url_id', "url_video_id = '{$insert_id}' AND url_status = 1");
		if(count($hosts) > 0) {
			foreach($hosts as $hurl) {
				$hurl = str_replace('&amp;amp;', '&amp;', $hurl);
				
				if($old_video && $sql->count('video_urls', 'url_id', "url_video_id = '{$insert_id}' AND url_address = '{$hurl}'") > 0) continue;
				
				$real_hosts_count++;
				
				$values = [
					'url_video_id' => $insert_id,
					'url_address'  => $hurl,
					'url_version'  => $url_version,
					'url_user_id'  => 1,
					'url_status'   => 1,
					'url_date'     => time(),
				];
				$sql->insert('video_urls', $values);
			}
		}
		
		$sql->update('videos', ['video_status' => ($real_hosts_count > 0 ? 1 : 0)], "video_id = '{$insert_id}'");
		//break;
	}
}

function get_url($url) {
	// jeśli link jest bez protokołu to dodajemy z przodu http
	if(substr($url, 0, 2) == '//') {
		$url = 'http:'.$url;
	}

	// jeśli brakuje http:// z przodu to dodajemy nazwę domeny
	if(stripos($url, 'http://') === false && stripos($url, 'https://') === false) {
		global $domain;
		$url = $domain.ltrim($url, '/');
	}
	return $url;
}

function get_xpath($contents) {
	$contents = iconv('UTF-8', 'UTF-8//IGNORE', $contents);
	libxml_use_internal_errors(true);
	$dom = new DOMDocument("1.0", "UTF-8");
	$dom->strictErrorChecking = false;
	$dom->validateOnParse = false;
	$dom->recover = true;
	$dom->loadHTML('<?xml encoding="UTF-8">'.$contents);

	libxml_clear_errors();
	libxml_use_internal_errors(false);

	foreach($dom->childNodes as $item) {
		if($item->nodeType == XML_PI_NODE) {
			$dom->removeChild($item);
		}
	}
	
	$dom->encoding = 'UTF-8';
	
	return new DOMXPath($dom);
}

/**
 * Pobiera stronę za pomocą cURL
 * @param string $url
 * @param bool $followredirect
 * @param bool $use_proxy
 * @return array
 */
function curl_get($url, $settings = []) {
	return curl($url, [], $settings);
}


/**
 * Wysyła zapytanie POST za pomocą cURL
 * @param string $url
 * @param array $postfields
 * @param bool $followredirect
 * @param bool $use_proxy
 * @return array
 */
function curl_post($url, $postfields, $settings = []) {
	return curl($url, $postfields, $settings);
}

/**
 * Wysyła zapytanie za pomocą cURL
 * @param string $url
 * @param array $postfields
 * @return array
 */
function curl($url, $postfields, $settings = [], $try = 0) {
	global $config;
	
	$httpheader = array(
		'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
		'Accept-Language: pl,en-US;q=0.7,en;q=0.3',
		'Accept-Encoding: deflate',
	);
	if(!is_array($postfields) && $postfields != '') {
		$httpheader[] = 'Content-Type:';
	}
	if(isset($settings['headers']) && $settings['headers']) {
		$httpheader = array_merge($httpheader, $settings['headers']);
	}
	$options = array(
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_HEADER         => true,
		CURLOPT_VERBOSE        => false,
		CURLOPT_CONNECTTIMEOUT => isset($settings['timeout']) ? $settings['timeout'] : 30,
		CURLOPT_TIMEOUT        => isset($settings['timeout']) ? $settings['timeout'] : 30,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_SSL_VERIFYPEER => false,
		CURLOPT_USERAGENT      => "Mozilla/5.0 (Windows NT 10.0; WOW64; rv:50.0) Gecko/20100101 Firefox/50.0",
		CURLOPT_COOKIEFILE     => isset($settings['cookie_file']) ? $settings['cookie_file'] : 'cookies.txt',
		CURLOPT_COOKIEJAR      => isset($settings['cookie_file']) ? $settings['cookie_file'] : 'cookies.txt',
		CURLOPT_HTTPHEADER     => $httpheader,
		CURLOPT_ENCODING       => "gzip",
		CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
	);
	
	if($settings['referer']) {
		$options[CURLOPT_REFERER] = $settings['referer'];
	}
	
	if(count($postfields) > 0) {
		$options[CURLOPT_POST]       = true;
		$options[CURLOPT_POSTFIELDS] = $postfields;
	}
	
	if($settings['use_proxy'] && isset($config['proxy']) && count($config['proxy']) > 0) {
		$proxy = $config['proxy'][array_rand($config['proxy'])];
		list($host, $port, $login, $password) = explode(':', $proxy);
		$options[CURLOPT_PROXYTYPE] = CURLPROXY_HTTP;
		$options[CURLOPT_PROXY] = $host;
		$options[CURLOPT_PROXYPORT] = $port;
		if($login || $password) {
			$options[CURLOPT_PROXYUSERPWD] = $login.':'.$password;
		}
		
		$options[CURLOPT_COOKIEFILE] = 'cookies_'.$host.'.txt';
		$options[CURLOPT_COOKIEJAR] = 'cookies_'.$host.'.txt';
	}

	$ch      = curl_init( $url );
	curl_setopt_array( $ch, $options );
	$content = curl_exec( $ch );
	$err     = curl_errno( $ch );
	$errmsg  = curl_error( $ch );
	$header  = curl_getinfo( $ch );
	$header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
	curl_close( $ch );

	$header['errno']   = $err;
	$header['errmsg']  = $errmsg;
	$header['headers'] = substr($content, 0, $header_size);
	$header['content'] = substr($content, $header_size);
	$header['options'] = $options;
	
	return $header;
}
