<?php

class YTDownloader
{

	private $cache_dir;

	private $cookie_dir;

	private $itag_info = array(

		18 => "MP4[640x360]",

		22 => "HD MP4[1280x720]",

		36 => "3GP[320x180]",

		43 => "WEBM[640x360]",

		17 => "3GP[176x144]"

	);

	private $itag_ext = array(

		18 => ".mp4",

		22 => "",

		36 => ".3gp",

		43 => ".webm",

		17 => ".3gp"

	);


	function __construct()
	{

		$this->cache_dir = dirname(__FILE__) . '/.cache';

		$this->cookie_dir = sys_get_temp_dir();

		if (!file_exists($this->cache_dir)) {

			mkdir($this->cache_dir, 0755);

		}

	}

	public function getDownloadLinks($id)
	{

		$videoID = $id;

		$webPage = $this->curlGet('https://www.youtube.com/embed/' . $videoID);

		$sts = null;

		if (preg_match('|"sts":([0-9]{4,}),"|i', $webPage, $matches)) {

			$sts = $matches[1];

		}

		foreach (array('vevo', 'embedded', 'detailpage') as $elKey) {

			$query = http_build_query(array(

				'c' => 'web',

				'el' => $elKey,

				'hl' => 'en_US',

				'sts' => $sts,

				'cver' => 'html5',

				'eurl' => "https://youtube.googleapis.com/v/{$videoID}",

				'html5' => '1',

				'iframe' => '1',

				'authuser' => '1',

				'video_id' => $videoID,

			));


			if ($this->is_Ok($videoData = $this->curlGet("https://www.youtube.com/get_video_info?{$query}"))) {

				parse_str($videoData, $videoData);

				break;

			}

		}

		$draftLink = explode(',', $videoData['url_encoded_fmt_stream_map']);

		foreach ($draftLink as $dlink) {

			parse_str($dlink, $mLink[]);

		}
		parse_str($videoData['rvs'], $Info_Video);

		foreach ($mLink as $linker) {

			if (isset($linker['s'])) {

				$linkData[] = array(

					'url' => preg_replace('@(https\:\/\/)[^\.]+(\.googlevideo\.com)@', 'https://redirector$2', $linker['url']) . '&signature=' . $this->sig_decipher($linker['s'], $this->get_instructions($webPage)) . '&title=' . $this->clean_name($videoData['title']) . $this->itag_ext[$linker['itag']],

					'itag' => $linker['itag'],

					'type' => $this->itag_info[$linker['itag']]

				);

			} else {

				$linkData[] = array(

					'url' => preg_replace('@(https\:\/\/)[^\.]+(\.googlevideo\.com)@', 'https://redirector$2', $linker['url']) . '&title=' . $this->clean_name($videoData['title']) . $this->itag_ext[$linker['itag']],

					'itag' => $linker['itag'],

					'type' => $this->itag_info[$linker['itag']]

				);

			}

		}

		return $linkData;

	}

	private function is_Ok($var)
	{

		if (!preg_match('|status=fail|i', $var)) {

			return true;

		}

	}

	public function curlGet($url)
	{


		$tmpfname = $this->cookie_dir . '/cookie.txt';


		$ch = curl_init($url);

		curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.3; WOW64; rv:49.0) Gecko/20100101 Firefox/49.0');

		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

		curl_setopt($ch, CURLOPT_HEADER, 0);

		curl_setopt($ch, CURLOPT_COOKIEJAR, $tmpfname);

		curl_setopt($ch, CURLOPT_COOKIEFILE, $tmpfname);

		//curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);

		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);


		$result = curl_exec($ch);

		curl_close($ch);


		return $result;

	}

	private function get_instructions($html)
	{

		$playerPattern = '/"assets":.+?"js":\s*("[^"]+")/';

		if (preg_match($playerPattern, $html, $matches) &&

			is_string($_player = json_decode($matches[1])) && strlen($_player) >= 1

		) {

			$playerLink = substr($_player, 0, 2) == '//' ? "https:{$_player}" : "https://www.youtube.com{$_player}";

			$cache_player = $this->cache_dir . '/.ht-' . md5($_player);

			if (file_exists($cache_player)) {

				return unserialize(file_get_contents($cache_player));

			} else {

				$js_code = $this->curlGet($playerLink);

				$instructions = $this->sig_js_decode($js_code);


				if ($instructions) {

					file_put_contents($cache_player, serialize($instructions));

					return $instructions;

				}

			}

		}

		return false;

	}

	private function clean_name($name)
	{

		$special_chars = array("?", "[", "]", "/", "\\", "=", "<", ">", ":", ";", ",", "'", "\"", "&", "$", "#", "*", "(", ")", "|", "~", "`", "!", "{", "}", "%", "+", chr(0));

		$filename = str_replace($special_chars, ' ', $name);

		$filename = preg_replace("#\x{00a0}#siu", ' ', $filename);

		$filename = str_replace(array('%20', '+', ' '), '-', $filename);

		$filename = preg_replace('/[\r\n\t -]+/', '-', $filename);

		$filename = trim($filename, '.-_');

		return $filename;

	}

	private function sig_decipher($signature, $instructions)
	{


		foreach ($instructions as $opt) {


			$command = $opt[0];

			$value = $opt[1];


			if ($command == 'swap') {


				$temp = $signature[0];

				$signature[0] = $signature[$value % strlen($signature)];

				$signature[$value] = $temp;


			} elseif ($command == 'splice') {

				$signature = substr($signature, $value);

			} elseif ($command == 'reverse') {

				$signature = strrev($signature);

			}

		}


		return trim($signature);

	}

	private function sig_js_decode($player_html)
	{

		$signatureFunctionPatterns = [
			'/(["\'])signature\1\s*,\s*([a-zA-Z0-9$]+)\(/',
			'/\.sig\|\|([a-zA-Z0-9$]+)\(/',
			'/yt\.akamaized\.net\/\)\s*\|\|\s*.*?\s*c\s*&&\s*d\.set\([^,]+\s*,\s*([a-zA-Z0-9$]+)\(/',
			'/\bc\s*&&\s*d\.set\([^,]+\s*,\s*([a-zA-Z0-9$]+)\(/',
		];

		foreach ($signatureFunctionPatterns as $pattern) {

			if (preg_match($pattern, $player_html, $matches)) {

				$func_name = $matches[1];
				$func_name = preg_quote($func_name);


				if (preg_match('/' . $func_name . '=function\([a-z]+\){(.*?)}/', $player_html, $matches)) {

					$js_code = $matches[1];

					if (preg_match_all('/([a-z0-9]{2})\.([a-z0-9]{2})\([^,]+,(\d+)\)/i', $js_code, $matches) != false) {

						// must be identical
						$obj_list = $matches[1];

						$func_list = $matches[2];

						// extract javascript code for each one of those statement functions
						preg_match_all('/(' . implode('|', $func_list) . '):function(.*?)\}/m', $player_html, $matches2, PREG_SET_ORDER);

						$functions = array();

						// translate each function according to its use
						foreach ($matches2 as $m) {

							if (strpos($m[2], 'splice') !== false) {
								$functions[$m[1]] = 'splice';
							} elseif (strpos($m[2], 'a.length') !== false) {
								$functions[$m[1]] = 'swap';
							} elseif (strpos($m[2], 'reverse') !== false) {
								$functions[$m[1]] = 'reverse';
							}
						}

						// FINAL STEP! convert it all to instructions set
						$instructions = array();

						foreach ($matches[2] as $index => $name) {
							$instructions[] = array($functions[$name], $matches[3][$index]);
						}

						return $instructions;
					}
				}
				break;
			}
		}

		return false;
	}

}