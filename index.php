<?php

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
    $url_base = $parts['scheme'] . "://" . $parts['host'];

    if (strtolower($_SERVER['REQUEST_METHOD']) === 'post') {
        curl_setopt($curl_handle, CURLOPT_POST, true);
        curl_setopt($curl_handle, CURLOPT_POSTFIELDS, $_POST);
    }

    if ($_GET['send_cookies']) {
        $cookie = array();
        foreach ($_COOKIE as $key => $value) {
            $cookie[] = $key . '=' . $value;
        }
        if ($_GET['send_session']) {
            $cookie[] = SID;
        }
        $cookie = implode('; ', $cookie);

        curl_setopt($curl_handle, CURLOPT_COOKIE, $cookie);
    }

    curl_setopt($curl_handle, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($curl_handle, CURLOPT_HEADER, true);
    curl_setopt($curl_handle, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl_handle, CURLOPT_ENCODING, "");

    curl_setopt($curl_handle, CURLOPT_USERAGENT, $_GET['user_agent'] ? $_GET['user_agent'] : $_SERVER['HTTP_USER_AGENT']);

    list($header, $contents) = preg_split('/([\r\n][\r\n])\\1/', curl_exec($curl_handle), 2);

    $replace['/href="(?!https?:\/\/)(?!data:)(?!#)/'] = 'href="' . $url_base;
    $replace['/src="(?!https?:\/\/)(?!data:)(?!#)/'] = 'src="' . $url_base;
    $replace['/href=\'(?!https?:\/\/)(?!data:)(?!#)/'] = 'href="' . $url_base;
    $replace['/src=\'(?!https?:\/\/)(?!data:)(?!#)/'] = 'src="' . $url_base;
    $replace['/@import[\n+\s+]"\//'] = '@import "' . $url_base;
    $replace['/@import[\n+\s+]"\./'] = '@import "' . $url_base;

    $contents = preg_replace(array_keys($replace), array_values($replace), $contents);

    $status = curl_getinfo($curl_handle);

    curl_close($curl_handle);
}

// Split header text into an array.
$header_text = preg_split('/[\r\n]+/', $header);

if ($_GET['mode'] === 'native') {
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
    if ($_GET['full_headers']) {
        $data['headers'] = array();

        foreach ($header_text as $header) {
            preg_match('/^(.+?):\s+(.*)$/', $header, $matches);
            if ($matches) {
                $data['headers'][$matches[1]] = $matches[2];
            }
        }
    }

    // Propagate all cURL request / response info to the JSON data object.
    if ($_GET['full_status']) {
        $data['status'] = $status;
    } else {
        $data['status'] = array();
        $data['status']['http_code'] = $status['http_code'];
    }

    // Set the JSON data object contents, decoding it from JSON if possible.
    $decoded_json = json_decode($contents);
    $data['contents'] = $decoded_json ? $decoded_json : $contents;

    // Generate appropriate content-type header.
    $is_xhr = strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
    header('Content-type: application/' . ($is_xhr ? 'json' : 'x-javascript'));

    // Get JSONP callback.
    $jsonp_callback = $enable_jsonp && isset($_GET['callback']) ? $_GET['callback'] : null;

    // Generate JSON/JSONP string
    $json = json_encode($data);

    print $jsonp_callback ? "$jsonp_callback($json)" : $json;
}
