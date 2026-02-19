<?php

// Add CORS headers to allow frontend requests
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$enable_jsonp    = false;
$enable_native   = false;
$valid_url_regex = '/.*/';

$url = $_GET['url'];

if (!$url) {
    $contents = 'ERROR: url not specified';
    $status = array('http_code' => 'ERROR');
} else if (!preg_match($valid_url_regex, $url)) {
    $contents = 'ERROR: invalid url';
    $status = array('http_code' => 'ERROR');
} else {
    $curl_handle = curl_init($url);
    $url_parsed = parse_url($url);
    $url_base = $url_parsed['scheme'] . "://" . $url_parsed['host'];

    if (strtolower($_SERVER['REQUEST_METHOD']) === 'post') {
        curl_setopt($curl_handle, CURLOPT_POST, true);
        curl_setopt($curl_handle, CURLOPT_POSTFIELDS, $_POST);
    }

    if (isset($_GET['send_cookies']) && $_GET['send_cookies']) {
        $cookie = array();
        foreach ($_COOKIE as $key => $value) {
            $cookie[] = $key . '=' . $value;
        }
        if (isset($_GET['send_session']) && $_GET['send_session']) {
            $cookie[] = SID;
        }
        $cookie = implode('; ', $cookie);

        curl_setopt($curl_handle, CURLOPT_COOKIE, $cookie);
    }

    curl_setopt($curl_handle, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($curl_handle, CURLOPT_HEADER, true);
    curl_setopt($curl_handle, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl_handle, CURLOPT_ENCODING, "");

    curl_setopt($curl_handle, CURLOPT_USERAGENT, isset($_GET['user_agent']) ? $_GET['user_agent'] : (isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'Mozilla/5.0'));
    
    // Set Accept header to handle various content types
    $accept_header = isset($_SERVER['HTTP_ACCEPT']) ? $_SERVER['HTTP_ACCEPT'] : '*/*';
    curl_setopt($curl_handle, CURLOPT_HTTPHEADER, array('Accept: ' . $accept_header));

    $response = curl_exec($curl_handle);
    
    // Check for cURL errors
    if ($response === false) {
        $contents = 'ERROR: ' . curl_error($curl_handle);
        $status = array('http_code' => 'ERROR');
        $header = '';
    } else {
        // Split headers from content - look for double newline (CRLF or LF)
        $header_size = curl_getinfo($curl_handle, CURLINFO_HEADER_SIZE);
        $header = substr($response, 0, $header_size);
        $contents = substr($response, $header_size);

        // Debug: Add response info if debug parameter is set
        if (isset($_GET['debug'])) {
            $debug_info = array(
                'url_requested' => $url,
                'response_length' => strlen($response),
                'header_size' => $header_size,
                'content_length' => strlen($contents),
                'raw_contents' => $contents,  // Show full content for debugging
                'headers' => substr($header, 0, 500)  // First 500 chars of headers
            );
        }

        // Get content type from response
        $status = curl_getinfo($curl_handle);
        $content_type = isset($status['content_type']) ? $status['content_type'] : '';
        
        // Only apply HTML replacements if content is HTML
        if (stripos($content_type, 'html') !== false) {
            $replace['/href="(?!https?:\/\/)(?!data:)(?!#)/'] = 'href="' . $url_base;
            $replace['/src="(?!https?:\/\/)(?!data:)(?!#)/'] = 'src="' . $url_base;
            $replace['/href=\'(?!https?:\/\/)(?!data:)(?!#)/'] = 'href="' . $url_base;
            $replace['/src=\'(?!https?:\/\/)(?!data:)(?!#)/'] = 'src="' . $url_base;
            $replace['/@import[\n+\s+]"\//'] = '@import "' . $url_base;
            $replace['/@import[\n+\s+]"\./'] = '@import "' . $url_base;

            $replaced = preg_replace(array_keys($replace), array_values($replace), $contents);
            if ($replaced !== null) {
                $contents = $replaced;
            }
        }
    }

    curl_close($curl_handle);
}

// Split header text into an array.
$header_text = preg_split('/[\r\n]+/', $header);

if (isset($_GET['mode']) && $_GET['mode'] === 'native') {
    if (!$enable_native) {
        $contents = 'ERROR: invalid mode';
        $status = array('http_code' => 'ERROR');
    }

    // Propagate headers to response.
    foreach ($header_text as $header) {
        if (preg_match('/^(?:Content-Type|Content-Language|Set-Cookie):/i', $header)) {
            header($header);
        }
    }

    print $contents;
} else {
    $data = array();

    // Propagate all HTTP headers into the JSON data object.
    if (isset($_GET['full_headers']) && $_GET['full_headers']) {
        $data['headers'] = array();

        foreach ($header_text as $header) {
            preg_match('/^(.+?):\s+(.*)$/', $header, $matches);
            if ($matches) {
                $data['headers'][$matches[1]] = $matches[2];
            }
        }
    }

    // Propagate all cURL request / response info to the JSON data object.
    if (isset($_GET['full_status']) && $_GET['full_status']) {
        $data['status'] = $status;
    } else {
        $data['status'] = array();
        $data['status']['http_code'] = $status['http_code'];
    }

    // Include debug info if requested
    if (isset($_GET['debug']) && isset($debug_info)) {
        $data['debug'] = $debug_info;
    }

    // Set the JSON data object contents, decoding it from JSON if possible.
    $decoded_json = json_decode($contents);
    // Only use decoded JSON if decoding was successful (check for JSON errors)
    $data['contents'] = (json_last_error() === JSON_ERROR_NONE && $decoded_json !== null) ? $decoded_json : $contents;

    // Generate appropriate content-type header.
    $is_xhr = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
    header('Content-type: application/' . ($is_xhr ? 'json' : 'x-javascript'));

    // Get JSONP callback.
    $jsonp_callback = $enable_jsonp && isset($_GET['callback']) ? $_GET['callback'] : null;

    // Generate JSON/JSONP string
    $json = json_encode($data);

    print $jsonp_callback ? "$jsonp_callback($json)" : $json;
}
