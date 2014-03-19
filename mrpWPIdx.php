<?php

/**
 * Plugin Name: myRealPage IDX Listings
 * Description: Embeds myRealPage IDX and Listings solution into WordPress. Uses shortcodes. Create a post or page and use integrated shortcode button to launch myRealPage Listings Shortcode Wizard and generate a shortcode based on your choice of listing content, as well as functional and visual preferences.
 * Version: 0.6.8
 * Author: myRealPage (support@myrealpage.com)
 * Author URI: http://myrealpage.com
**/

require_once('InlineClient.php');

if (!class_exists('MRPListing'))
{

  class MRPListing
  {

    const shortcode_name = 'mrp';

    protected $inline_client;

    protected $plugin_name;

    private $blog_uri;

    // Current option values, loaded when plugin starts.
    protected $current_options;

    // Option names.
    const debug_opt_name = 'mrp_debug';
    const request_logging_opt_name = 'mrp_request_logging';
    const cache_timeout_opt_name = 'mrp_cache_timeout';
    const cleanup_timeout_opt_name = 'mrp_cleanup_timeout';
    const google_map_api_key = 'mrp_google_map_key';

    public function __construct()
    {
      $this->plugin_name = plugin_basename(__FILE__);
      $this->inline_client = new InlineClient();
      register_activation_hook($this->plugin_name, array(&$this, 'install'));
      register_deactivation_hook($this->plugin_name, array(&$this, 'uninstall'));
      $this->register_hooks();
      $this->load_options();
      $this->inline_client->set_debug($this->current_options[self::debug_opt_name]);
      $this->inline_client->set_cache_timeout($this->current_options[self::cache_timeout_opt_name]);
      $this->inline_client->set_request_logging($this->current_options[self::request_logging_opt_name]);
      $this->inline_client->set_google_map_api_key($this->current_options[self::google_map_api_key]);
      $this->blog_uri = get_bloginfo('url');
      wp_register_script('mrp-sc-editor', plugins_url('mrp_sc_editor.js', __FILE__), array('jquery'), '1.0.12');
    }

    public function install()
    {
      // Check for libCURL - if it doesn't exist, we can't install.
      if (!$this->can_install())
      {
        // Spit out error message - we can't install without CURL
      }
      else
      {
        $this->register_hooks();
      }
    }

    public function uninstall()
    {
      global $wp_rewrite;
      remove_shortcode(self::shortcode_name);
      // Regenerate rewrite rules also.
      $wp_rewrite->flush_rules();
    }

    public function flush_rules()
    {
      global $wp_rewrite;
      $wp_rewrite->flush_rules();
    }

    public function register_hooks()
    {
      // Flush rewrites on post/page save and front page option setting changes
      // so we can manage which permalinks we get called in.
      add_filter('save_post', array(&$this, 'flush_rules'));
        add_filter('update_option_page_on_front', array(&$this, 'flush_rules'));
      // Add rewrite rules in so we can handle URI segments past the end
      // of a permalink.
      add_filter('generate_rewrite_rules', array(&$this, 'add_rewrites'));
      add_filter('query_vars', array(&$this, 'add_queryvars'));
      // Register with shortcode API to do content replacement.
      add_shortcode(self::shortcode_name, array(&$this, 'replace_content'));
      // Adds merged MRP header.
      add_action('wp_head', array(&$this, 'add_header'));
      // Generates saved values.
      add_action('wp', array(&$this, 'replaced_wp'));
      // Replace title with custom MRP one.
      add_filter('wp_title', array(&$this, 'custom_title'), 11);
      // Method that handles direct proxying
      add_action('parse_request', array(&$this, 'direct_proxy'));
      // Init method to trap /wps/evow/ requests
      add_action('init', array(&$this, 'evow_handler'));
      // Add admin/options menu
      add_action('admin_menu', array(&$this, 'add_menu'));
      // Buttons for MRP shortcode
      add_action('edit_form_advanced', array(&$this, 'add_html_button'));
      add_action('edit_page_form', array(&$this, 'add_html_button'));
      // TinyMCE button hooks
      add_filter('mce_external_plugins', array(&$this, 'add_tinymce_plugin'));
      add_filter('mce_buttons', array(&$this, 'add_tinymce_button') );
      // Add admin javascript for button functionality.
      add_action('admin_print_scripts', array(&$this, 'load_admin_scripts'));

    }

    public function add_menu()
    {
      add_options_page('mrpWPIdx', 'myRealPage Plugin', 8, __FILE__, array(&$this, 'options_page'));
    }

    /**
     * Loads option values from WP, adding any that don't exist.
     **/

    public function load_options()
    {
      // Set option defaults.
      $default_options = array(self::debug_opt_name => 0,
                               self::request_logging_opt_name => 0,
                               self::cache_timeout_opt_name => 604800,
                               self::cleanup_timeout_opt_name => 2592000,
                               self::google_map_api_key => '');
      $options = array(self::debug_opt_name,
                       self::request_logging_opt_name,
                       self::cache_timeout_opt_name,
                       self::cleanup_timeout_opt_name,
                       self::google_map_api_key);
      // Load options into the current_options array, and creating if needed.
      foreach($options as $option_name)
      {
        $opt = get_option($option_name);
        if ($opt == false || $opt == '')
        {
          $this->current_options[$option_name] = $default_options[$option_name];
          add_option($option_name, $default_options[$option_name]);
        }
        else
        {
          $this->current_options[$option_name] = $opt;
        }
      }
    }

    /**
     * Adds button to default HTML editor.
     **/

    public function add_html_button()
    {
      $button_html = '<input type="button" id="mrp-shortcode" class="ed_button" title="myRealPage shortcodes" value="Listing Shortcodes" onClick="return mrp_openSC(this);"/>';
      echo $this->get_button_action($button_html);
    }

    private function get_button_action($button_html)
    {
      return "<script type=\"text/javascript\">
        jQuery(document).ready(function(){
          // Add the buttons to the HTML view
          jQuery(\"#ed_toolbar\").append('$button_html');
        });
      </script>";
    }

    /**
     * Adds button to TinyMCE editor (Visual)
     **/

    public function add_tinymce_button($buttons)
    {
      array_push($buttons, "mrpShortCode");
      return $buttons;
    }

    public function add_tinymce_plugin($plugins)
    {
      $plugins['mrplisting'] = plugins_url('/tinymce/editor.js', __FILE__);
      return $plugins;
    }

    public function load_admin_scripts()
    {
      wp_enqueue_script('mrp-sc-editor');
    }

    /**
     * Add rewrite rules for pages followed by an extension.
     **/

    public function add_rewrites($wp_rewrite)
    {
      global $wpdb, $wp_query;
      // Look for pages/posts that contain our shortcode
      $shortcode = self::shortcode_name;
      $mrp_pages = $wpdb->get_results("SELECT ID, post_name, post_type FROM $wpdb->posts WHERE post_content LIKE '%[$shortcode %]%' AND post_status='publish' AND post_type != 'post'");
      $new_rules = array();
      if ($mrp_pages)
      {
        foreach($mrp_pages as $page)
        {
          $slug = substr(strstr(str_replace(get_bloginfo('url'), '', get_permalink($page->ID)),'/'),1);
	  $post_type = "";
	  if ($page->post_type != "page") {
	    $post_type="&post_type=" . $page->post_type;
	  }
            $regex = $slug.InlineClient::$uri_segment.'(.*)';
            // clean up the root slug
            if (substr($regex, 0, 1) == '/') {
                $regex = substr($regex, 1);
            }
	    $new_rules[$regex] = 'index.php?page_id='.$page->ID. $post_type . '&extension=' . $wp_rewrite->preg_index(1);
        }
      }

      $posts_regex = $this->generate_rewrite_from_permalink($wp_rewrite->permalink_structure);
      if ($posts_regex != false)
      {
        $new_rules [$posts_regex['match'].InlineClient::$uri_segment.'(.*)'] =
          'index.php?name='.$wp_rewrite->preg_index($posts_regex['postname_backref']).'&extension='.$wp_rewrite->preg_index($posts_regex['total_backrefs']+1);
      }

      $wp_rewrite->rules = $new_rules + $wp_rewrite->rules;
      return $wp_rewrite->rules;
    }

    /**
     * Generates a regex and replacement string for a given permalink definition,
     * which will extract the postname and extension from a given permalink.
     **/

    private function generate_rewrite_from_permalink($permalink)
    {
      $ret = false;
      preg_match_all('(%.+?%)', $permalink, $matches);
      if (count($matches) > 0 && strpos($permalink, '%postname%') != false)
      {
        $i=1;
        $postname_position = 1;
        $replace = array();
        foreach($matches[0] as $match)
        {
          $replace[$match] = '(.+?)';
          if ($match == '%postname%')
          {
            $postname_position = $i;
          }
          $i++;
        }
        $ret = array('match' => str_replace(array_keys($replace), array_values($replace), substr($permalink,1)),
                     'postname_backref' => $postname_position,
                     'total_backrefs' => count($matches[0]));
      }
      return $ret;
    }

    /**
     * Add the extension query variable in so we can pass it around.
     **/

    public function add_queryvars($current_query_vars)
    {
      $current_query_vars []= 'extension';
      return $current_query_vars;
    }

    /**
     * Header changes.
     **/

    public function add_header()
    {
      global $wp_query, $post;
      // If no MRP key, we don't do anything.
      if (isset($_REQUEST['mrp-head']))
      {
        echo($_REQUEST['mrp-head']);
        echo("<meta name=\"description\" content=\"".$_REQUEST['mrp-meta'].'"/>');
      }
    }

    public function custom_title($title)
    {
      global $wp_query, $post;
      if (isset($_REQUEST['mrp-title']) &&
          $this->is_replaceable_title($_SERVER['REQUEST_URI']))
      {
        return ($_REQUEST['mrp-title']);
      }
      else
      {
        return $title;
      }
    }

    private function is_replaceable_title($uri)
    {
      $ret = false;
      if (preg_match('@.*/(listing\..+|details\-|photos\-|videos\-|map\-|walkscore\-|'.
                           'print\-|ListingPrint\.form|ListingWalkScore\.form|'.
                           'ListingVideos\.form|ListingPhotos\.form|'.
                           'ListingDetails\.form|ListingGoogleMap\.form|'.
                           'VowLanding\.form|VowSaveSearch\.form|'.
                           'VowCategory\.form|.+\.vowsearch).*$@', $uri))
      {
        $ret = true;
      }
      return $ret;
    }


    /* bill hacking */
    /* we need to skip the "embed" (do js embed) logic if we have a POST
      request, which will come in from the "quick search" widget, and also
      if we have an 'extended' URL on our hands, like '/l/SearchResults.form...'
      (unless of course it's /l/Search.form, in which case the user may have clicked
      on "Modify Search" button)
      This should support users initiating search from "quick search" widgets, browsing
      results, then clicking on "Modify Search" button and ending up with embedded form
      again
    */
    private function skip_embed() {
      if( $_SERVER['REQUEST_METHOD'] == 'POST' ||
        ( $_SERVER['REQUEST_URI'] &&
          strstr( $_SERVER['REQUEST_URI'], '/l/' ) != false &&
          strstr( $_SERVER['REQUEST_URI'], '/l/Search.form' ) == false ) ) {
        return true;
      }
      else {
        return false;
      }
    }

    public function replaced_wp($wp)
    {
      global $wp_query, $post;
      // Post contains an MRP shortcode, so we need to process it.
      if (strstr($post->post_content, '[mrp ') != false)
      {
        $attrs = array();
        // Get content and parse out mrp shortcode.
        $shortcode = $this->getStringBetween($post->post_content, '[mrp', ']');
        $pairs = explode(' ', $shortcode);
        foreach($pairs as $pair)
        {
          list($name, $value) = explode('=', $pair, 2);
          $attrs[$name] = $value;
        }
        extract(shortcode_atts(array( 'context' => '',
				      'listing_def' => '',
				      'init_attr' => '',
				      'perm_attr' => '',
				      'account_id' => '',
				      'details_def' => '',
				      'details_photos_def' => '',
				      'details_videos_def' => '',
				      'details_map_def' => '',
				      'searchform_def' => '',
				      'embed' => ''),
                               $attrs));

        if ($this->skip_embed() ) {
          $embed = "";
          $attrs["embed"] = "";
        }

	if ($embed == "") {
	  $extension = $wp_query->query_vars['extension'];
	  $page_name = substr(str_replace($this->blog_uri, '', get_permalink($post->ID)),1);
	  $ic = new InlineClient($context, $listing_def, $init_attr, $perm_attr,
				 $account_id, $page_name, $extension, $details_def,
				 $details_photos_def, $details_videos_def,
				 $details_map_def, $searchform_def);
	  $ic->set_debug($this->current_options[self::debug_opt_name]);
	  $ic->set_cache_timeout($this->current_options[self::cache_timeout_opt_name]);
	  $ic->set_request_logging($this->current_options[self::request_logging_opt_name]);
	  $ic->set_google_map_api_key($this->current_options[self::google_map_api_key]);
	  $content = $ic->getParsedInlineContent($page_name);
	  if (isset($content['redirect']))
	    {
	      header('HTTP/1.1 ' . $content['status']);
	      // Output Set-Cookie headers, if any.
	      if (isset($content['cookies']))
		{
		  foreach($content['cookies'] as $header)
		    {
		      header($header, false);
		    }
		}
	      header('Location: ' . $content['redirect']);
	      die();
	    }
	  else
	    {
	      header('HTTP/1.1 ' . $content['status']);
	      // Populate post with extra info
	      $post_id = $post->ID;
	      $_REQUEST['mrp-title'] = $content['title'].' ';
	      $_REQUEST['mrp-body'] = $content['body'];
	      $_REQUEST['mrp-head'] = $content['head'];
	      $_REQUEST['mrp-meta'] = $content['meta_description'];
	      // Output Set-Cookie headers, if any.
	      if (isset($content['cookies']))
		{
		  foreach($content['cookies'] as $header)
		    {
		      header($header, false);
		    }
		}
	    }
	}
      }
    }

    /**
     * Main functionality - extracts data from shortcode, uses the inline client
     * to fetch the required data from MRP and displays the returned content.
     **/

    public function replace_content($attrs, $content='')
    {
      global $wp, $wp_query, $wp_rewrite, $post;

      if ( $this->skip_embed() ) {
        $attrs["embed"] = "";
      }


      if (isset($attrs["searchform_def"]) && $attrs["searchform_def"] != "" &&
	  isset($attrs["embed"]) && $attrs["embed"] != "") {
	extract($attrs);
	return InlineClient::getEmbeddedFormJS($account_id, $context,
					       $perm_attr, $init_attr,
					       $searchform_def);
      } else {
	return $_REQUEST["mrp-body"];
      }
    }

    public function evow_handler()
    {
      $request_uri = $_SERVER['REQUEST_URI'];

      // Issue a redirect if we're seeing /wps/evow/ACCOUNT_ID/ExternalView.form?
      if (preg_match("@^/wps/evow/\d+/ExternalView.form?@", $request_uri))
      {
        preg_match('@^/wps/evow/(.*)@', $request_uri, $matches);
        if (isset($matches[1]))
        {
          header('Location: /evow-' . $matches[1]);
          die();
        }
      }

      if (preg_match("@^/evow-\d+@", $request_uri))
      {
        require_once('fakepage.php');
        $slug = substr($request_uri, 1);
        if (stripos($request_uri, '?'))
        {
          $slug = substr($slug, 0, stripos($request_uri, '?') - 1);
        }
        preg_match('@^/evow-(\d+)/.+@', $request_uri, $matches);
        if (isset($matches[1]))
        {
          // Generate shortcode for fake page.
          new FakePage($slug, '', '[mrp context=evow account_id='.$matches[1].']');
        }
        else
        {
          new FakePage($slug, '', '<p>Malformed URL (no account ID given)</p>');
        }
      }
    }

    public function direct_proxy($wp)
    {
      // Get current request URI and determine if we need to proxy it
      $request_uri = $_SERVER['REQUEST_URI'];

      // Proxy /wps/, /gmform15/ and /mrp-js-listings/
      if (preg_match("/^(\/wps\/|\/mrp\-js\-listings\/|\/gmform15\/)/", $request_uri))
      {
        // Return the proxied content and end the request right here.
        if ($_SERVER['REQUEST_METHOD'] == 'POST')
        {
          //$c = $this->inline_client->do_direct_proxy_with_cache($request_uri, $_POST);
	  // ewiltshi: Now use raw POST data from stdin.
	  $post_data = file_get_contents("php://input");
	  $c = $this->inline_client->do_direct_proxy_with_cache($request_uri, $post_data);
        }
        else
        {
          $c = $this->inline_client->do_direct_proxy_with_cache($request_uri);
        }

        $headers = $c['headers'];
        $content = $c['content'];

        // Handle redirects.
        if ($headers['http_code'] == '302' || $headers['http_code'] == '301')
        {
          // There should be a Location header.
          if (isset($c['http_headers']['Location']))
          {
            $location = $c['http_headers']['Location'];
            if ($headers['http_code'] == '302')
            {
              header('HTTP/1.1 302 Moved Temporarily');
            }
            else
            {
              header('HTTP/1.1 301 Moved Permanently');
            }
            header('Location: ' . $location);
          }
        }

        // Give same HTTP response code as we got on the server side.
        header('HTTP/1.1 ' . $headers['http_code']);
        if (isset($headers['content_type']))
        {
          header('Content-Type: ' . $headers['content_type'], true);
        }

        $content = str_replace('"/wps-listings.css', '"http://listings.myrealpage.com/wps-listings.css', $content);
        if (isset($headers['download_content_length']))
        {
          // Don't use the download content header, since it may have changed size with the
          // above search and replace!
          header('Content-length: ' . strlen($content));
        }

        // Output Set-Cookie: headers, if any
        if (isset($c['cookies']))
        {
          foreach($c['cookies'] as $header)
          {
            header($header,false);
          }
        }

        // Output any HTTP caching headers, if present.
        if (isset($c['http_headers']))
        {
          $http_headers = $c['http_headers'];
          foreach($http_headers as $name => $value)
          {
            if ($name == 'Cache-Control' || $name == 'Expires' ||
                $name == 'ETag' || $name == 'Last-Modified')
            {
              header("$name: $value");
            }
          }
        }

        echo($content);
        exit();
      }

      // Fall through - we don't need to do anything else.
    }

    /**
     * Ouputs HTML and handles form processing on options page within the WP admin.
     **/

    public function options_page()
    {
      $hidden_field_name = 'mrp_submit_hidden';

      // Update?
      if (isset($_POST[$hidden_field_name]) && $_POST[$hidden_field_name] =='Y')
      {
        update_option(self::debug_opt_name, $_POST[self::debug_opt_name]);
        update_option(self::request_logging_opt_name, $_POST[self::request_logging_opt_name]);
        update_option(self::cache_timeout_opt_name, $_POST[self::cache_timeout_opt_name]);
        update_option(self::cleanup_timeout_opt_name, $_POST[self::cleanup_timeout_opt_name]);
        update_option(self::google_map_api_key, $_POST[self::google_map_api_key]);
        // Reload
        $this->load_options();
      }

      // Ouput. Hate how WP combines this.
      echo( '<div class="wrap">' );
      echo('<h2>'.__('mrpWPIdx Options', 'mrp_domain').'</h2>');
      echo('<form name="mrp_options" method="POST" action="'.
          str_replace( '%7E', '~', $_SERVER['REQUEST_URI']).'">');
      echo( '<input type="hidden" name="'.$hidden_field_name.'" value="Y">' );
      // Debug option
      echo( '<p>'.__( 'Debug Mode? : ', 'mrp_domain' ) );
      echo( '<input type="checkbox" name="'.self::debug_opt_name.'" value="1" ');
      if ($this->current_options[self::debug_opt_name])
      {
        echo('checked');
      }
      echo(' /></p>');
      // Request logging option
      echo( '<p>'.__( 'Request Logging? : ', 'mrp_domain' ) );
      echo( '<input type="checkbox" name="'.self::request_logging_opt_name.'" value="1" ');
      if ($this->current_options[self::request_logging_opt_name])
      {
        echo('checked');
      }
      echo(' /></p>');
      // Cache timeout
      echo( '<p>'.__( 'Cache Timeout (in seconds, set to 0 to disable) : ', 'mrp_domain' ) );
      echo( '<input type="text" name="'.self::cache_timeout_opt_name.'" value="'.
           $this->current_options[self::cache_timeout_opt_name].'" /></p>');
      // Cleanup timeout
      echo( '<p>'.__( 'Cleanup Timeout (in seconds) : ', 'mrp_domain' ) );
      echo( '<input type="text" name="'.self::cleanup_timeout_opt_name.'" value="'.
           $this->current_options[self::cleanup_timeout_opt_name].'" /></p>');
      // Google maps API key
      echo( '<p>'.__( 'Google Maps API Key : ', 'mrp_domain' ) );
      echo( '<input type="text" name="'.self::google_map_api_key.'" value="'.
           $this->current_options[self::google_map_api_key].'" /></p>');
      echo('<input type="submit" name="submit" value="Submit" />');
      echo('</div>');
    }

    private function can_install()
    {
      $ret = true;
      if (!function_exists('curl_init'))
      {
        $ret = false;
      }
      return $ret;
    }

   /**
    * Gets string between two other strings. Code taken from:
    * http://www.justin-cook.com/wp/2006/03/31/php-parse-a-string-between-two-strings/
    **/
    private function getStringBetween($string, $start, $end){
      $string = " ".$string;
      $ini = strpos($string,$start);
      if ($ini == 0) return "";
      $ini += strlen($start);
      $len = strpos($string,$end,$ini) - $ini;
      return substr($string,$ini,$len);
    }
  }

  $mrp = new MRPListing();
}
