<?php

declare(strict_types=1);
error_reporting(0);

// Change as your require
function get_proxy() : array {
    return [
	'ip' => '127.0.0.1:6000',
	'type' => 'socks5',
	'auth' => ''
    ];
}

function cache_path(string $id) : string {
    if (!file_exists('_cache')) {
	mkdir('_cache', 0777);
    }

    if (strlen($id) == 64) {
	return '_cache/' . $id;
    } else {
	return '_cache/' . hash('sha256',$id, false);
    }
}

function read_data(string $id) {
    $fpath = cache_path($id);
    $fhandle = fopen($fpath,'r');
    if ($fhandle && filesize($fpath) > 0) {
	$content = fread($fhandle,filesize($fpath));
	fclose($fhandle);
	return json_decode($content,true);
    } else {
	return null;
    }
}

function write_data(string $id) {
    $driveId = $id;
    if (strlen($driveId) == 64) {
	if ($fdata = read_data($id)) {
	    if ($fdata['id']) {
		$driveId = enc('decrypt', $fdata['id']);
	    }
	}
    }
    $fpath = cache_path($driveId);
    if ($fhandle = fopen($fpath,'w')) {
	
	$sources_list = array();
	$ar_list = array();
	$cookies = '';

	// Check whenever file was available or not
	$ch = curl_init('https://drive.google.com/get_video_info?docid=' . $driveId);
	curl_setopt_array($ch,array(
	    CURLOPT_FOLLOWLOCATION => 1,
	    CURLOPT_RETURNTRANSFER => 1
	));
	$x = curl_exec($ch);
	parse_str($x,$x);
	if ($x['status'] == 'fail') {
	    curl_close($ch);

	    // Use Proxy Instead Direct
	    $ch = curl_init('https://drive.google.com/get_video_info?docid=' . $driveId);
	    $proxy = get_proxy()

	    curl_setopt_array($ch,array(
		CURLOPT_FOLLOWLOCATION => 1,
		CURLOPT_RETURNTRANSFER => 1
	    ));

	    // Check if proxy present
	    if ($proxy['ip'] !== '') {
		curl_setopt($ch, CURLOPT_PROXY => $proxy['ip']);

		// Check if proxy need auth
		if ($proxy['auth'] !== '') {
		    curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxyList['auth']);
		}

		// Check if proxy type was SOCKS5
		if ($proxy['type'] == 'socks5') {
		    curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
		}
		
	    }
	    
	    $x = curl_exec($ch);
	    parse_str($x,$x);
	    if ($x['status'] == 'fail') {
		curl_close($ch);
		fclose($fhandle);
		return null;
	    }
	    
	}
	curl_close($ch);
	
	// Fetch Google Drive File
	$ch = curl_init('https://drive.google.com/get_video_info?docid=' . $driveId);
	curl_setopt_array($ch,array(
	    CURLOPT_FOLLOWLOCATION => 1,
	    CURLOPT_RETURNTRANSFER => 1,
	    CURLOPT_HEADER => 1
	));
	$result = curl_exec($ch);
	curl_close($ch);

	// Get Cookies
	preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $result, $matches);
	$cookies = array();
	foreach($matches[1] as $item) {
	    parse_str($item, $cookie);
	    $cookies = array_merge($cookies, $cookie);
	}
	
	// Parse Resolution
	parse_str($result,$data);
	$sources = explode(',',$data['fmt_stream_map']);
	$fname = $data['title'];
	foreach($sources as $source){
	    
	    switch ((int)substr($source, 0, 2)) {
		case 18:
		    $resolution = '360p';
		    break;
		case 59:
		    $resolution = '480p';
		    break;
		case 22:
		    $resolution = '720p';
		    break;
		case 37:
		    $resolution = '1080p';
		    break;
	    }
	    
	    $x = substr($source, strpos($source, "|") + 1);
	    
	    // Get Content-Length of sources
	    $curl = curl_init();
	    curl_setopt_array($curl, array(
		CURLOPT_URL => substr($source, strpos($source, "|") + 1),
		CURLOPT_HEADER => 1,
		CURLOPT_CONNECTTIMEOUT => 0,
		CURLOPT_TIMEOUT => 1000,
		CURLOPT_FRESH_CONNECT => 1,
		CURLOPT_SSL_VERIFYPEER => 0,
		CURLOPT_NOBODY => 1,
		CURLOPT_VERBOSE => 1,
		CURLOPT_RETURNTRANSFER => 1,
		CURLOPT_FOLLOWLOCATION => 1,
		CURLOPT_HTTPHEADER => array(
		    'Connection: keep-alive',
		    'Cookie: DRIVE_STREAM=' . $cookies['DRIVE_STREAM']
		)
	    ));
	    
	    curl_exec($curl);
	    $content_length = curl_getinfo($curl, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
	    curl_close($curl);
	    
	    array_push($sources_list, array(
		'resolution' => $resolution,
		'src' => $x,
		'content-length' => $content_length)
	    );
	    
	    array_push($ar_list, $resolution);
	    
	}
	
	// Get thumbnail Image
	$ch = curl_init('https://drive.google.com/thumbnail?authuser=0&sz=w9999&id=' . $driveId);
	curl_setopt($ch, CURLOPT_HEADER, 1);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	$result = curl_exec($ch);
	curl_close($ch);
	if (preg_match('~Location: (.*)~i', $result, $match)) {
	    $thumbnail = trim($match[1]);
	} else {
	    $thumbnail = '';
	}
	
	// Write to file
	fwrite($fhandle, json_encode(array(
	    'thumbnail' => $thumbnail,
	    'cookies' => $cookies,
	    'sources' => $sources_list,
	    'id' => enc('encrypt', $driveId)
	)));
	fclose($fhandle);
	return array(
	    'status' => 200,
	    'hash' => hash('sha256', $driveId, false),
	    'sources' => $ar_list
	); // Serve as JSON
	
    } else {
	
	return null; // Return null
	
    }
}

function fetch_video(array $data) : int {
    
    $content_length = $data['content-length'];
    $headers = array(
	'Connection: keep-alive',
	'Cookie: DRIVE_STREAM=' . $data['cookie']['DRIVE_STREAM']
    );
    
    if (isset($_SERVER['HTTP_RANGE'])) {
	
	$http = 1;
	preg_match('/bytes=(\d+)-(\d+)?/', $_SERVER['HTTP_RANGE'], $range);
	$initial = intval($range[1]);
	$final = $content_length - $initial - 1;
	array_push($headers,'Range: bytes=' . $initial . '-' . ($initial + $final));
	
    } else {
	
	$http = 0;
	
    }
    
    if ($http == 1) {
	
	header('HTTP/1.1 206 Partial Content');
	header('Accept-Ranges: bytes'); 
	header('Content-Range: bytes ' . $initial . '-' . ($initial + $final) . '/' . $data['content-length']);
	
    } else {
	
	header('Accept-Ranges: bytes'); 
	
    }
    
    $ch = curl_init();
    
    curl_setopt_array($ch, array(
	CURLOPT_URL => $data['src'],
	CURLOPT_CONNECTTIMEOUT => 0,
	CURLOPT_TIMEOUT => 1000,
	CURLOPT_RETURNTRANSFER => 1,
	CURLOPT_FOLLOWLOCATION => 1,
	CURLOPT_FRESH_CONNECT => 1,
	CURLOPT_HTTPHEADER => $headers
    ));
    
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($curl, $body) {
	echo $body;
	return strlen($body);
    });
    
    header('Content-Type: video/mp4');
    header('Content-length: ' . $content_length);
    
    curl_exec($ch);

}

function stream($fdata) {
    if (is_array($fdata)) { // Check whenver data on file was array
	
	$reso = $_GET['stream'];
	
	if ($reso == 'thumbnail') {
	    
	    header('Location:' . $fdata['thumbnail']);
	    
	} else {

	    foreach($fdata['sources'] as $x) {
		if ($x['resolution'] == $_GET['stream']) {
		    fetch_video(array(
			'content-length' => $x['content-length'],
			'src' => $x['src'],
			'cookie' => $fdata['cookies']
		    ));
		    break;
		}
	    }

	}
	
    } else { // If not remove it and tell file was corrupt
	
	unlink(cache_path($_GET['id']));
	header('Content-Type: application/json');
	die(json_encode(array(
	    'status' => 413,
	    'error' => 'File was corrupt, please re-generate file.'
	)));
	
    }
}

function enc($action, $string) {
    $output = false;
    
    $encrypt_method = "AES-256-CBC";
    $secret_key = 'This is my secret key';
    $secret_iv = 'This is my secret iv';
    
    // hash
    $key = hash('sha256', $secret_key);
    
    // iv - encrypt method AES-256-CBC expects 16 bytes - else you will get a
    // warning
    $iv = substr(hash('sha256', $secret_iv), 0, 16);
    
    if ($action == 'encrypt') {
	$output = openssl_encrypt($string, $encrypt_method, $key, 0, $iv);
	$output = base64_encode($output);
    } else {
	if ($action == 'decrypt') {
	    $output = openssl_decrypt(base64_decode($string), $encrypt_method, $key, 0, $iv);
	}
    }
    
    return $output;
}

if (isset($_GET['id'])) {
    
    $fdata = read_data($_GET['id']);
    
    if (isset($_GET['stream'])) {
	
	if ($fdata !== null) {

	    /*
	       Set default cached time out for 15 minutes
	       We do this in case that the video is still processing and has only just 1 resolution, eg. 360p
	       With 15 minutes we can update the resolution of the videos after being processed
	    */
	    if (time()-filemtime(cache_path($_GET['id'])) > (count($fdata['sources']) > 1 ? 3 * 3600 : 900)) { // Check cached timeout
		
		$fres = write_data($_GET['id']);
		
		if ($fres !== null) {
		    
		    stream($fdata);

		} else {
		    header('Content-Type: application/json');
		    die(json_encode(array(
			'status' => 412,
			'error' => 'Failed write data'
		    )));
		}
		
	    } else {
		
		stream($fdata);
		
	    }
	    
	} else { // If not cache file was missing or expired
	    
	    header('Content-Type: application/json');
	    die(json_encode(array(
		'status' => 414,
		'error' => 'Invalid file.'
	    )));
	    
	}
	
    } else {

	if (in_array(strlen($_GET['id']), range(28,33))) {
	    if ($fdata !== null) { // Check whenever data was created before
		header('Content-Type: application/json');
		$ar_list = array();
		
		foreach($fdata['sources'] as $x) {
		    array_push($ar_list,$x['resolution']);
		}
		
		echo json_encode(array(
		    'status' => 200,
		    'hash' => hash('sha256', $_GET['id'], false),
		    'sources' => $ar_list
		)); // Server as JSON
		
	    } else {
		
		$fres = write_data($_GET['id']); // Write it to file
		if ($fres !== null) {
		    header('Content-Type: application/json');
		    echo json_encode($fres);  // Server as JSON
		} else {
		    header('Content-Type: application/json');
		    die(json_encode(array(
			'status' => 412,
			'error' => 'Failed write data.'
		    )));
		}
		
	    }
	    
	}

    }
    
}

?>
