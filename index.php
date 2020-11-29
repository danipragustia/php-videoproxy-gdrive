<?php

declare(strict_types=1);
error_reporting(0);

// Change as your require
function get_proxy() : array {
    return [
	'ip' => '',
	'type' => '',
	'auth' => ''
    ];
}

function set_header(array $header) : bool {
    array_map(function($x) { header($x); }, $header);
    return true;
}

function cache_path(string $id) : string {
    !file_exists('_cache') && mkdir('_cache', 0777);
    return '_cache/' . (strlen($id) === 64 ? $id : hash('sha256',$id, false));
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
    
    (strlen($driveId) === 64 && $fdata = read_data($id)) && $fdata['id'] && $driveId = enc('decrypt', $fdata['id']);
    
    $fpath = cache_path($driveId);
    if ($fhandle = fopen($fpath,'w')) {
	
	$ar_list = [];

	// Check whenever file was available or not
	$ch = curl_init('https://drive.google.com/get_video_info?docid=' . $driveId);
	curl_setopt_array($ch,[
	    CURLOPT_FOLLOWLOCATION => 1,
	    CURLOPT_RETURNTRANSFER => 1
	]);
	$x = curl_exec($ch);
	parse_str($x,$x);
	
	if ($x['status'] === 'fail') {
	    curl_close($ch);

	    // Use Proxy Instead Direct
	    $ch = curl_init('https://drive.google.com/get_video_info?docid=' . $driveId);
	    $proxy = get_proxy();

	    curl_setopt_array($ch,[
		CURLOPT_FOLLOWLOCATION => 1,
		CURLOPT_RETURNTRANSFER => 1
	    ]);

	    // Check if proxy present
	    if ($proxy['ip'] !== '') {
		curl_setopt($ch, CURLOPT_PROXY, $proxy['ip']);

		// Check if proxy need auth
		if ($proxy['auth'] !== '') {
		    curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxy['auth']);
		}

		// Check if proxy type was SOCKS5
		if ($proxy['type'] == 'socks5') {
		    curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
		}
		
	    }
	    
	    $x = curl_exec($ch);
	    parse_str($x,$x);

	    // If fail using proxy
	    if ($x['status'] == 'fail') {
		curl_close($ch);
		fclose($fhandle);
		return null;
	    }
	    
	}
	
	curl_close($ch);
	
	// Fetch Google Drive File
	$ch = curl_init('https://drive.google.com/get_video_info?docid=' . $driveId);
	curl_setopt_array($ch,[
	    CURLOPT_FOLLOWLOCATION => 1,
	    CURLOPT_RETURNTRANSFER => 1,
	    CURLOPT_HEADER => 1
	]);
	$result = curl_exec($ch);
	curl_close($ch);

	// Get Cookies
	preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $result, $matches);

	// Parse Resolution
	parse_str($result,$data);
	if (!isset($data['fmt_stream_map'])) {
	    return null;
	}
	$sources = explode(',',$data['fmt_stream_map']);
	$fname = $data['title'];
	$content = array_map(function($x) use ($matches) {
            switch((int)substr($x, 0, 2)) {
		case 18:
		    $res = '360p';
		    break;
		case 22:
		    $res = '720p';
		    break;
		case 37:
		    $res = '1080p';
		    break;
		case 59:
		    $res = '480p';
		    break;
            }
            $src = substr($x,strpos($x, '|') + 1);

            if (filter_var($src, FILTER_VALIDATE_URL, FILTER_FLAG_PATH_REQUIRED)) {
		$ch = curl_init(substr($x, strpos($x, '|') + 1));
		curl_setopt_array($ch, [
                    CURLOPT_HEADER > 1,
                    CURLOPT_CONNECTTIMEOUT => 0,
                    CURLOPT_TIMEOUT => 1000, // 1 sec
                    CURLOPT_NOBODY => 1,
                    CURLOPT_RETURNTRANSFER => 1,
                    CURLOPT_FOLLOWLOCATION => 1,
                    CURLOPT_HTTPHEADER => [
			'Connection: keep-alive',
			'Cookie: ' . $matches[1][0]
                    ]
		]);

		$result = curl_exec($ch);
		$length = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
		curl_close($ch);

		// Make sure the link was return the size of resolution
		return (isset($res,$length) ? [
                    'resolution' => $res,
                    'src' => substr($x, strpos($x, '|') + 1),
                    'content-length' => $length
		] : []);

	    }
            
        }, explode(',',$data['fmt_stream_map']));
	
	// Get thumbnail Image
	$ch = curl_init('https://drive.google.com/thumbnail?authuser=0&sz=w9999&id=' . $driveId);
	curl_setopt_array($ch,[
	    CURLOPT_HEADER => 1,
	    CURLOPT_FOLLOWLOCATION => 1,
	    CURLOPT_RETURNTRANSFER => 1
	]);
	$result = curl_exec($ch);
	curl_close($ch);
	
	if (preg_match('~Location: (.*)~i', $result, $match)) {
	    $thumbnail = trim($match[1]);
	} else {
	    $thumbnail = '';
	}
	
	// Write to file
	fwrite($fhandle, json_encode([
	    'thumbnail' => $thumbnail,
	    'cookies' => $matches[1][0],
	    'sources' => $content,
	    'id' => enc('encrypt', $driveId)
	]));
	fclose($fhandle);
	
	$ar_list = array_map(function($res) {
            return $res['resolution'];
	}, $content);
	
	return [
	    'status' => 200,
	    'hash' => hash('sha256', $driveId, false),
	    'sources' => $ar_list
	]; // Serve as JSON
	
    } else {
	
	return null; // Return null
	
    }
}

function fetch_video(array $data) : int {
    
    $content_length = $data['content-length'];
    $headers = [
	'Connection: keep-alive',
	'Cookie: ' . $data['cookie']
    ];
    
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

	set_header([
	    'HTTP/1.1 206 Partial Content',
	    'Accept-Ranges: bytes',
	    'Content-Range: bytes ' . $initial . '-' . ($initial + $final) . '/' . $data['content-length']
	]);
	
    } else {
	
	header('Accept-Ranges: bytes'); 
	
    }
    
    $ch = curl_init();
    
    curl_setopt_array($ch, [
	CURLOPT_URL => $data['src'],
	CURLOPT_CONNECTTIMEOUT => 0,
	CURLOPT_TIMEOUT => 1000,
	CURLOPT_RETURNTRANSFER => 1,
	CURLOPT_FOLLOWLOCATION => 1,
	CURLOPT_FRESH_CONNECT => 1,
	CURLOPT_HTTPHEADER => $headers
    ]);
    
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($curl, $body) {
	echo $body;
	return strlen($body);
    });

    set_header([
	'Content-Type: video/mp4',
	'Content-length: ' . $content_length
    ]);
    
    curl_exec($ch);

}

function stream($fdata) {
    if (is_array($fdata)) { // Check whenever data on file was array
	
	$reso = $_GET['stream'];
	
	if ($reso == 'thumbnail') {
	    
	    header('Location:' . $fdata['thumbnail']);
	    
	} else {

	    foreach($fdata['sources'] as $x) {
		if ($x['resolution'] == $_GET['stream']) {
		    fetch_video([
			'content-length' => $x['content-length'],
			'src' => $x['src'],
			'cookie' => $fdata['cookies']
		    ]);
		    break;
		}
	    }

	}
	
    } else { // If not remove it and tell file was corrupt
	
	unlink(cache_path($_GET['id']));
	header('Content-Type: application/json');
	die(json_encode([
	    'status' => 413,
	    'error' => 'File was corrupt, please re-generate file.'
	]));
	
    }
}

function enc($action, $string) : string {
    $output = false;
    
    $encrypt_method = "AES-256-CBC";
    $secret_key = 'This is my secret key';
    $secret_iv = 'This is my secret iv';
    
    // hash
    $key = hash('sha256', $secret_key);
    
    // iv - encrypt method AES-256-CBC expects 16 bytes - else you will get a
    // warning
    $iv = substr(hash('sha256', $secret_iv), 0, 16);
    
    return ($action == 'encrypt')
	 ? base64_encode(openssl_encrypt($string, $encrypt_method, $key, 0, $iv))
	 : $action === 'decrypt' && openssl_decrypt(base64_decode($string), $encrypt_method, $key, 0, $iv);
    
    return $output;
}

if (isset($_GET['id'])) {
    
    if (isset($_GET['stream'])) {

	$fdata = read_data($_GET['id']);
	
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
		    die(json_encode([
			'status' => 412,
			'error' => 'Failed write data'
		    ]));
		    
		}
		
	    } else {
		
		stream($fdata);
		
	    }
	    
	} else { // If not cache file was missing or expired
	    
	    header('Content-Type: application/json');
	    die(json_encode([
		'status' => 414,
		'error' => 'Invalid file.'
	    ]));
	    
	}
	
    } else {

	if (in_array(strlen($_GET['id']), range(28,33))) {
	    $fdata = read_data($_GET['id']);
	    header('Content-Type: application/json');
	    if ($fdata !== null) { // Check whenever data was created before

		$ar_list = [];
		
		foreach($fdata['sources'] as $x) {
		    array_push($ar_list,$x['resolution']);
		}
		
		echo json_encode([
		    'status' => 200,
		    'hash' => hash('sha256', $_GET['id'], false),
		    'sources' => $ar_list
		]); // Server as JSON
		
	    } else {
		
		$fres = write_data($_GET['id']); // Write it to file
		echo json_encode(($fres !== null) ? $fres : [
		    'status' => 412,
		    'error' => 'Failed write data.'
		]);
		
	    }

	}

    }

}

?>
