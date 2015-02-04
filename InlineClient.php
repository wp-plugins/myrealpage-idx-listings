<?php

/**
 * Client class that handles proxying/communication with MRP.
 **/

class InlineClient
{

  public static $uri_segment = '/l/';

  protected $context = '',
  $listing_def = '',
  $init_attr = '',
  $perm_attr = '',
  $account_id = '',
  $page_name='',
  $extension = '',
  $details_def='',
  $details_photos_def='',
  $details_videos_def='',
  $details_map_def='',
  $searchform_def='',
  $debug=false,
  $request_logging=false,
  $cache_timeout=604800, // One week, in seconds.
  $cache_dir='',
  $google_map_api_key='';

  protected $direct_proxy_host = 'http://listings.myrealpage.com';
  protected $base_mrp_url = 'http://listings.myrealpage.com/wps/';
  //protected $direct_proxy_host = 'http://192.168.100.51:8080';
  //protected $base_mrp_url = 'http://192.168.100.51:8080/wps/';

  public $mrp_headers = array('MrpStripPowered: false',
                              'MrpInlinePort: 80');

  public function __construct($context='', $listing_def='', $init_attr='',
                              $perm_attr='', $account_id='', $page_name='',
                              $extension='', $details_def='', $details_photos_def='',
                              $details_videos_def='', $details_map_def='',
                              $searchform_def='')
  {
    $this->context = $context;
    $this->listing_def = $listing_def;
    $this->init_attr = $init_attr;
    $this->perm_attr = $perm_attr;
    $this->account_id = $account_id;
    $this->page_name = $page_name;
    $this->details_def = $details_def;
    $this->details_photos_def = $details_photos_def;
    $this->details_videos_def = $details_videos_def;
    $this->details_map_def = $details_map_def;
    $this->searchform_def = $searchform_def;
    $this->extension = $extension;

    // Parse the extension, if required.
    if ($this->extension != '' && substr($this->extension, 0, 4) == 'wps/')
    {
      // Strip out wps/<context>/<account_id> so we're left with the controller part and
      // any query string variables
      preg_match('/wps\/.+?\/\d+[\/]?(.*)$/', $this->extension, $matches);
      if (isset($matches[1]))
      {
        $this->extension = $matches[1];
      }
      else
      {
        $this->extension = 'BROKEN';
      }
    }

    // Any query strings? Attach.
    if (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] != '')
    {
      $this->extension .= '?' . $_SERVER['QUERY_STRING'];
    }

    if ($this->listing_def != '')
    {
      $this->mrp_headers[] = 'Listing-Definition: ' . $this->listing_def;
    }

    if ($this->perm_attr != '')
    {
      $this->mrp_headers[] = 'Listing-ViewAttrs: ' . $this->perm_attr;
    }

    if ($this->page_name != '')
    {
      // Check special case for evow-[NUMBER]/l/...
      preg_match('@^(evow-\d+).*@', $this->page_name, $matches);
      if (isset($matches[1]))
      {
        $this->mrp_headers []= 'MrpInlineRoot: ' . '/' . $matches[1] . self::$uri_segment;
      }
      else
      {
        $this->mrp_headers[] = 'MrpInlineRoot: ' . '/' . $this->page_name . self::$uri_segment;
      }
    }
    else
    {
      $this->mrp_headers[] = 'MrpInlineRoot: ' . self::$uri_segment;
    }

    // Addition from Bill - include the GoogleMapServerName header.
    $this->mrp_headers[] = 'GoogleMapServerName: ' . $_SERVER['HTTP_HOST'];

    // Additional from Bill - include an X WordPress header.
    $this->mrp_headers[] = 'X-WordPress-Site: ' . $_SERVER['HTTP_HOST'];
    $this->mrp_headers[] = 'X-WordPress-Referer: ' . $_SERVER['HTTP_REFERER'];
    $this->mrp_headers[] = 'X-WordPress-Theme: ' . get_template();
    $this->mrp_headers[] = 'X-WordPress-Canonical-Capable: true';

    // Add cookies if present.
    $cookie_header = $this->get_cookie_header();
    if ($cookie_header != '')
    {
      $this->mrp_headers []= $cookie_header;
    }

	/*
    $this->cache_dir = realpath($_ENV["TMP"]) . "/mrpIdxCache";
    if (!file_exists($this->cache_dir))
    {
		if( is_writable( dirname( $this->cache_dir ) ) ) {
	      mkdir($this->cache_dir, 0777, true);
		}
    }

    if (!file_exists($this->cache_dir))
	 {
			$this->cache_dir = dirname(__FILE__) . '/cache';
	 }
	 */
	$this->cache_dir = dirname(__FILE__) . '/cache';

    if (!file_exists($this->cache_dir))
	 {
			if( is_writable( dirname( $this->cache_dir ) ) ) {
				mkdir($this->cache_dir, 0777, true);	
			}
	  }
  }




  public function set_debug($debug)
  {
    $this->debug = $debug;
  }

  public function set_cache_timeout($cache_timeout)
  {
    $this->cache_timeout = $cache_timeout;
  }

  public function set_request_logging($request_logging)
  {
    $this->request_logging = $request_logging;
  }

  public function set_google_map_api_key($google_map_api_key)
  {
    $this->google_map_api_key = $google_map_api_key;
    if ($this->google_map_api_key != '')
    {
      $this->mrp_headers[] = 'X-Mrp-GoogleMapKey: ' . $this->google_map_api_key;
    }
  }

  /**
   * Gets inline content based on page definition if we're not hitting a /wps/
   * page. If we are, proxy this directly (don't use the page definition) to MRP
   * and return the results.
   **/

  public function getInlineContent($page_name)
  {
    $request_uri = $_SERVER['REQUEST_URI'];

    // Generate the URL based on page definition.
    $mrp_url = $this->generateUrl($page_name);

    if ($_SERVER['REQUEST_METHOD'] == 'POST')
    {

      //$custom_content = $this->getURIContents($mrp_url, $this->mrp_headers, $_POST);
      // ewiltshi: Read raw input from stdin and use that as POST params.
      $post_params = file_get_contents("php://input");
      $custom_content = $this->getURIContents($mrp_url, $this->mrp_headers, $post_params);
    }
    else
    {
      $custom_content = $this->getURIContents($mrp_url, $this->mrp_headers);
    }
    // Rejig the content for CSS/JS to be absolute URLs.
    $content = $custom_content['content'];
    $content = str_replace('"/wps-listings.css', '"http://listings.myrealpage.com/wps-listings.css', $content);
    return array('content' => $content, 'status' => $custom_content['status'], 'cookies' => $custom_content['cookies'], 'http_headers' => $custom_content['http_headers']);
  }

  /**
   * Parses the inline content, extracting <head>, <body> tags. Also returns
   * HTTP redirects if they are required.
   **/

  public function getParsedInlineContent($page_name)
  {
    $content = array();
    $inline = $this->getInlineContent($page_name);
    $html_content = $inline['content'];
    $status = $inline['status'];
    $headers = $inline['http_headers'];
    $content['cookies'] = $inline['cookies'];
    if ($status == 200 || $status == 404)
    {
      // If the content type is text/plain we do some magic - we check that it begins
      // with text/plain in case it is followed by an encoding.
      if (isset($headers['Content-Type']) && substr($headers['Content-Type'],0,10) == 'text/plain')
      {
        $content['body'] = "<div style='margin-top: 100px; text-align: center;'><pre>" .
                           $html_content .
                           "</pre></div>";
      }
      else
      {
        $content = $this->parseInlineContent($html_content);
        $content['cookies'] = $inline['cookies'];
      }

      if ($status == 404)
      {
        if ($content['title'] == '')
        {
          $content['title'] = 'Not found';
        }
      }
      $content['status'] = $status;
      return $content;
    }
    elseif ($status == 301 || $status == 302)
    {
      if (!isset($headers['Location']) || $headers['Location'] == '')
      {
        // No location header, or blank location
        return $content;
      }

      // Redirect after rooting the returned URL to the calling site.
      $location = $headers['Location'];
      $location = preg_replace('@http://(.+?)/(.*)@', 'http://'.$_SERVER['HTTP_HOST'].'/$2', $location);
      return array('redirect' => $location, 'status' => $status, 'cookies' => $inline['cookies']);
    }

  }

  public function parseInlineContent($content)
  {
	$supported_themes = array( 'twentyten' => 'twentyten.css', 'thesis_18' => 'thesis.css' );

    $head = $this->getStringBetween($content, '</TITLE>', '</head>');

    $adapt_css = $supported_themes[get_template()];
    if( !$adapt_css ) {
    	$adapt_css = 'generic.css';
    }
    if( $adapt_css ) {
		$head = "<!-- start of custom myrealpage theme support css -->\n" .
			"<link type='text/css' rel='stylesheet' href='http://listings.myrealpage.com/wps/css/wp-themes/" . $adapt_css . "'/>\n" .
			"<!-- end of custom myrealpage theme support css -->\n" .
			$head;
    }

    $body = $this->getStringBetween($content, '<body>', '</body>');
    $title = $this->getStringBetween($content, '<TITLE>', '</TITLE>');
    $meta_description = $this->getStringBetween($content,  "<META name=\"description\" content=\"", "\">");
    if ($title != '')
    {
      $title = html_entity_decode($title);
    }

    if ($meta_description != '')
    {
      $meta_description = html_entity_decode($meta_description);
    }
    return array('head' => $head,
                 'body' => $body,
                 'title' => $title,
                 'meta_description' => $meta_description);
  }

  /**
   * Gets string between two other strings. Code taken from:
   * http://www.justin-cook.com/wp/2006/03/31/php-parse-a-string-between-two-strings/
   *
   **/
  public function getStringBetween($string, $start, $end){
    $string = " ".$string;
    $ini = strpos($string,$start);
    if ($ini == 0) return "";
    $ini += strlen($start);
    $len = strpos($string,$end,$ini) - $ini;
    return substr($string,$ini,$len);
}

  /**
   * Generates MRP URL based on parameters.
   **/

  public function generateUrl($page_name)
  {
    $url = $this->base_mrp_url;
    $url .= $this->context;
    $url .= '/' . $this->account_id;
    $url .= '/';

    header("X-Extension: " . $this->extension);

    if( $this->extension && $this->extension != "/evow") {
      $url .= $this->extension;
    }
    elseif( $this->listing_def ){
      $url .= 'listing-page';
    }
    elseif ($this->details_def)
    {
      $url .= 'details-' . $this->details_def;
    }
    elseif ($this->details_photos_def)
    {
      $url .= 'photos-' . $this->details_photos_def;
    }
    elseif ($this->details_videos_def)
    {
      $url .= 'videos-' . $this->details_videos_def;
    }
    elseif ($this->details_map_def)
    {
      $url .= 'map-' . $this->details_map_def;
    }
    elseif ($this->searchform_def)
    {
      // Numeric (integer) and non-numeric get handled differently.
      if (ctype_digit($this->searchform_def))
      {
        $url .= $this->searchform_def . '.searchform';
      }
      else
      {
        $url .= 'Search.form?_sf_=' . $this->searchform_def;
      }
    }

    // Add initial attributes if we don't already have a query string
    if ($this->init_attr != '' && stripos($url, '?') == false)
    {
      $init_attr_qs = preg_replace( '/~/', '=', $this->init_attr );
      $init_attr_qs = preg_replace( '/,/', '&', $init_attr_qs );
      $url .= '?' . $init_attr_qs;
    }

    return $url;
  }

  public function do_direct_proxy_with_cache($uri, $post_params="")
  {
    $ret = array();

    // Is this a cacheable file, and NOT a POST request?
    if ($this->can_cache($uri) && count($post_params) == 0)
    {
      $cache_location = $this->get_cache_location($uri);
      if ($this->request_logging)
      {
        error_log("CACHE: Location for $uri is $cache_location");
      }
      // Can we use the cached version, or is it expired?
      if ($this->should_use_cache($cache_location))
      {
        if ($this->request_logging)
        {
          error_log("CACHE: Hit for $cache_location");
        }
        //$ret = $this->do_direct_proxy($uri);

        // Not expired, use cached version.
        $ret['content'] = file_get_contents($cache_location);
        $ret['headers'] = array('content_type' => $this->get_mime_type($uri),
                                'download_content_length' => strlen($ret['content']));
      }
      else
      {
        if ($this->request_logging)
        {
          error_log("CACHE: Miss for $cache_location");
        }
        // Cached version is expired, so fetch and re-cache it.
        $ret = $this->do_direct_proxy($uri);
        $this->cache_contents($cache_location, $ret['content']);
        //file_put_contents($cache_location, $ret['content']);
      }
    }
    else
    {
      if ($this->request_logging)
      {
        error_log("CACHE: No caching allowed for $uri (POST params = " . count($post_params).")");
      }
      // No caching allowed, so proxy normally.
      $ret = $this->do_direct_proxy($uri, $post_params);
    }

    return $ret;
  }

  /**
   * Handles direct proxying of resources from the webserver to MRP.
   **/

  public function do_direct_proxy($uri, $post_params="")
  {

    $headers = array( 'GoogleMapServerName: ' . $_SERVER['HTTP_HOST'],
                      'X-WordPress-Site: ' . $_SERVER['HTTP_HOST'],
                      'X-WordPress-Referer: ' . $_SERVER['HTTP_REFERER']);
    if ($this->page_name != '')
    {
      $headers []= 'MrpInlineRoot: ' . '/' . $this->page_name . self::$uri_segment;
    }
    else
    {
      $headers []= 'MrpInlineRoot: ' . self::$uri_segment;
    }

    if ($this->google_map_api_key != '')
    {
      $headers []= 'X-Mrp-GoogleMapKey: ' . $this->google_map_api_key;
    }

    $cookie_header = $this->get_cookie_header();
    if ($cookie_header != '')
    {
      $headers []= $cookie_header;
    }
    $c = $this->getURIContents($this->direct_proxy_host . $uri,
			       $headers,
			       $post_params);
    if ($this->request_logging)
    {
      error_log('PROXY REQUEST: ' . $uri);
    }
    // Return content and headers from proxied request.
    return $c;
  }

  /**
   * Static function which will generate the script tags for embedded forms.
   **/

  public static function getEmbeddedFormJS($account_id, $context, $perm_attr,
					   $init_attr, $searchform_def) {
    $url = "//idx.myrealpage.com/wps/rest/$account_id/l/idx2/$context/";
    // Include perm_attr if defined. Otherwise we just use noframe~true.
    if ($perm_attr != "") {
      $url .= $perm_attr . ",noframe~true/";
    } else {
      $url .= "noframe~true/";
    }
    // Include init_attr if defined. Otherwise use a - to maintain position.
    if ($init_attr != "") {
      $url .= $init_attr . "/";
    } else {
      $url .= "-/";
    }
    $url .= $searchform_def . ".searchform/in.js";
    return "<script id='mrpscript' type='text/javascript' src='$url'></script>";
  }

  /**
   * Uses libcurl to make a GET request of a specific URL.
   * Returns an associative array containing both the content, and response status
   * code (keyed on content and status.)
   * Also allows custom headers to be added.
   * If $post_params has data, we assume the request is a post and include those
   * parameters.
   **/

  private function getURIContents($uri, $headers=array(), $post_params="")
  {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $uri);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    // Ensure we send along client side UA string in case mobile conversion
    // is done.
    curl_setopt($ch, CURLOPT_USERAGENT, $_SERVER['HTTP_USER_AGENT']);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

    if (count($headers) > 0)
    {
      // bill: disable expect header: causes confusion on server
      $headers[] = "Expect:";
      curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    //if (count($post_params) > 0)
    if ($post_params != "")
    {
      curl_setopt($ch, CURLOPT_POST, true);
      // This is sent as a string now, to support
      // forms which send multiple values of the same parameter.
      $post_string = $post_params;
      /*
      $post_string = '';
      foreach($post_params as $name => $value)
      {
        $value = urlencode($value);
        if ($name == 'captcha_e')
        {
          $name = 'captcha.e';
        }
        $post_string .= "&$name=$value";
      }
      $post_string = substr($post_string, 1);
      */
      curl_setopt($ch, CURLOPT_POSTFIELDS, $post_string);
    }
    $request_headers = $headers;
    $full_content = @curl_exec($ch);
    $response_headers = curl_getinfo($ch);
    $ret['headers'] = $response_headers;
    $ret['status'] = $response_headers['http_code'];
    list($headers, $content) = explode("\r\n\r\n", $full_content, 2);
    $http_headers = explode("\r\n", $headers);
    $ret['cookies'] = $this->get_set_cookie_headers($http_headers);
    $ret['http_headers'] = $this->parse_headers($http_headers);
    if ($this->debug == true)
    {
      // Logs request and response to file.
      $this->log_request($uri, $request_headers, $post_params, $response_headers, $http_headers, $full_content);
    }
    if (curl_errno($ch))
    {
      $ret['content'] = '';
    }
    else
    {
      $ret['content'] = $content;
      curl_close( $ch );
    }
    return $ret;
  }

  /**
   * Extracts all Set-Cookie: headers from the given array of HTTP headers
   * for replay, emulating a server.
   **/

  private function get_set_cookie_headers($headers)
  {
    $cookie_headers = array();
    if (count($headers) > 0)
    {
      foreach($headers as $header)
      {
        // Set-Cookie headers get added to the array.
        if (strchr($header, 'Set-Cookie: '))
        {
          $cookie_headers []= $header;
        }
      }
    }
    return $cookie_headers;
  }

  /**
   * Returns all current cookies as an HTTP header, emulating a browser.
   **/

  private function get_cookie_header()
  {
    $cookie = '';
    if (count($_COOKIE) > 0)
    {
      foreach($_COOKIE as $name => $value)
      {
	if (!is_array($value)) {
	  $cookie .= "$name=".urlencode($value)."; ";
	}
      }
    }
    return "Cookie: $cookie";
  }

  private function parse_headers($headers)
  {
    $ret = array();
    foreach($headers as $header)
    {
      list($name, $value) = explode(':', $header, 2);
      $ret[$name] = $value;
    }
    return $ret;
  }

  private function log_request($uri, $headers, $post_params,
                               $response_info, $response_headers, $response_content)
  {
    list($usec, $sec) = explode(' ', microtime());
    $file = $sec . intval((1000*$usec));
    //$file = dirname(__FILE__) .'/logs/'.$file.'.log';
    $dir = realpath($_ENV["TMP"]) . '/mrpIdxLogs/';
    if (!file_exists($dir)) {
      mkdir($dir, 0777, true);
    }
    $file = $dir . $file.'.log';
    $txt = date(DATE_ATOM)."\r\n\r\n".
      'REQUEST URI: ' . $uri ."\r\n\r\n".
      'REQUEST HEADERS: ' . "\r\n".print_r($headers, true) . "\r\n\r\n".
      'REQUEST METHOD: ' . (count($post_params) == 0 ? 'GET' : 'POST' . "\r\n\r\nPOST PARAMS:" . print_r($post_params,true)) . "\r\n\r\n".
      'RESPONSE INFO: ' . "\r\n" . print_r($response_info, true). "\r\n\r\n".
      'RESPONSE HEADERS: ' . "\r\n" . print_r($response_headers, true) . "\r\n\r\n".
      'RESPONSE CONTENT: ' . "\r\n" . $response_content."\r\n\r\n\r\n";

    $open_file = fopen($file,'a');
    fwrite($open_file, $txt);
    fclose($open_file);
  }

  /**
   * Is this a URI that is on our list of cacheable ones?
   **/

  private function can_cache($uri)
  {
    $ret = false;
    if (preg_match('@^(/wps/(js|css|img)/|/mrp-js-listings/|/gmform15/).*$@', $uri) &&
        //preg_match('@^(/wps/(js|css|img)/).*$@', $uri) &&
        !preg_match('@^(.*nocache|/wps/rest/|.*\.swf\?).*@', $uri))
    {
      $ret = true;
    }

    return $ret;
  }

  /**
   * Determines whether we can use the cached version of a file (does it exist?
   * is it younger than the cache timeout? is it writable?)
   **/

  private function should_use_cache($cache_file)
  {
    $ret = false;

    if ($this->cache_timeout > 0 &&
        file_exists($cache_file) &&
        filemtime($cache_file) > (time() - $this->cache_timeout) &&
        is_writable($cache_file))
    {
      $ret = true;
    }
    return $ret;
  }

  /**
   * Generates the filename on disk from a given URI.
   **/

  private function get_cache_location($uri)
  {
    list($directory, $filename) = explode('?', $uri, 2);
    if ($filename == '')
    {
      // No query string, so default filename is used.
      $filename = '_';
    }
    else
    {
      // Replace all potential badness in the query string with underscores
      $filename = str_replace(array('/', '?', '='), array('_', '_', '_'), $filename);
    }
    if (substr($directory, -1, 1) != '/')
    {
      $filename = '/' . $filename;
    }
    return $this->cache_dir . $directory . $filename;
  }

  /**
   * PHP doesn't have a great way of determining MIME type easily, that doesn't
   * involve 3rd party extensions. Since we're caching a known set of file types,
   * this isn't so bad.
   **/

  private function get_mime_type($uri)
  {
    $ret = 'text/html';
    list($filename, $query_string) = explode('?', $uri, 2);
    $segments = explode('/', $filename);
    $filename = $segments[count($segments)-1];
    $segments = explode('.', $filename);
    $extension = $segments[count($segments)-1];
    switch($extension)
    {
      case 'js':
        $ret = 'text/javascript';
        break;
      case 'css':
        $ret = 'text/css';
        break;
      case 'gif':
        $ret = 'image/gif';
        break;
      case 'jpg':
      case 'jpeg':
        $ret = 'image/jpeg';
        break;
      case 'png':
        $ret = 'image/png';
        break;
      case 'swf':
        $ret = 'application/x-shockwave-flash';
        break;
      default:
        break;
    }
    if ($this->request_logging)
    {
      error_log("CACHE: MIME type of $uri determined to be $ret");
    }
    return $ret;
  }

  /**
   * Caches content in the specified directory by creating the directory if required,
   * and writing the file.
   **/

  private function cache_contents($cache_location, $contents)
  {
    $directory = substr($cache_location, 0, strrpos($cache_location, '/'));
    if (file_exists($directory) || mkdir($directory, 0777, true))
    {
      file_put_contents($cache_location, $contents);
    }
  }
}
