<?php
/*
Plugin Name: Logged in
Plugin URI: http://dessibelle.se/
Description: A plugin that allows you to close the entire site to non-logged in users.
Author: Simon Fransson
Plugin URI: http://dessibelle.se/
Version: 1.0
Text Domain: loggedin
Domain Path: /languages
*/


class LoggedIn
{
	const FILTER_DOMAIN = 'logged_in';
	
	protected static $instance;
	protected $callback;
	protected $message;
	protected $options_page;
	protected $defaults = array();
	
	/*
	 * =========
	 * Internals
	 * =========
	 */
	
	protected function __construct()
	{
		$this->initialize();
	}

	public static function instance()
	{
		if (!isset(self::$instance)) {
			$className = __CLASS__;
			self::$instance = new $className;
		}
		return self::$instance;
	}

	protected function initialize()
	{
		register_activation_hook(__FILE__, array(&$this, 'install'));
		
		load_plugin_textdomain( 'loggedin', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' ); 
		$this->options_page = 'privacy';
		
		add_action('admin_init', array(&$this, 'initSettings'));
		add_action('admin_enqueue_scripts', array(&$this, 'initAdmin'));
		
		$this->defaults['action'] = 'login';
		$this->defaults['message'] = '<h1>' . __('This site is currently not available. Please come back later.', 'logged_in') . '</h1>';
		$this->defaults['fallback'] = 'fallback.php';
		$this->message = get_option($this->slugPrefix('message'), $this->defaults['message']);
		add_action('init', array(&$this, 'validateRequest'));
	}
	
	public function initAdmin($hook) {
		
		if( $hook == sprintf('options-%s.php', $this->options_page) ) {
			wp_enqueue_script('logged_in', plugins_url('js/admin.js', __FILE__), array('jquery'));
		}
	}
	
	protected function slugPrefix($name) {
		return sprintf("%s_%s", self::FILTER_DOMAIN, $name);
	}
	
	public static function actions() {
		return array(
			'login' => array(
				'label' => __('Redirect to login page', 'loggedin'),
				'method' => 'gotoLogin',
			),
			'message' => array(
				'label' => __('Display a message', 'loggedin'),
				'method' => 'displayMessage',
			),
			'fallback' => array(
				'label' => __('Display theme fallback file', 'loggedin'),
				'method' => 'gotoThemeFallback',
			),
		);
	}
	
	public function install() {
		add_option($this->slugPrefix('action'), 'login');
		add_option($this->slugPrefix('message'), $this->defaults['message']);
		add_option($this->slugPrefix('fallback'), $this->defaults['fallback']);
	}
	
	/*
	 * ==============
	 * URL validation
	 * ==============
	 */
	
	public function validateRequest()
	{
		if (!$this->urlIsValid()) {	
			$cb = apply_filters($this->slugPrefix('cb'), $this->callback());
			if ($cb) {
				call_user_func($cb);
			}
		}
	}
	
	protected function callback()
	{
		$default_action = $this->defaults['action'];
		
		$actions = $this->actions();
		$action = apply_filters($this->slugPrefix('action'), get_option($this->slugPrefix('action'), $default_action));
		$method = $actions[$default_action];
		
		if (array_key_exists($action, $actions)) {
			$method = $actions[$action]['method'];
		}
		
		return array(&$this, $method);
	}
	
	protected function urlIsValid($request_url = null)
	{
		if (!$request_url) {
			$request_url = sprintf('http%s://%s%s', (empty($_SERVER['HTTPS']) ? '' : 's'), $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI']);
		}
		$login_url = wp_login_url();
		
		return !(!is_admin() && !is_user_logged_in() && strpos($request_url, $login_url) === false);
	}
	
	/*          
	 * =========
	 * Accessors
	 * =========
	 */
	
	public function fallbackFilename() {
		return apply_filters($this->slugPrefix('fallback_filename'), get_option($this->slugPrefix('fallback')));
	}
	
	public function fallbackPath() {
		return apply_filters($this->slugPrefix('fallback_path'), STYLESHEETPATH . '/' . $this->fallbackFilename());
	}
	
	/*
	 * ==========================
	 * Redirection (and the like)
	 * ==========================
	 */
	
	public function gotoLogin()
	{
		$status = apply_filters($this->slugPrefix('redirect_status'), 302);
		
		wp_redirect( wp_login_url( site_url() ), $status );
		$this->kill();
	}
	
	public function gotoThemeFallback()
	{
		$path = $this->fallbackPath();
		
		include_once($path);
		
		$this->kill();
	}
	
	protected function displayMessage() {
		
		$this->message = apply_filters($this->slugPrefix('message'), $this->message);
		
		get_header();
		
		echo $this->message;
		
		get_footer();
		
		$this->kill();
	}
	
	protected function kill($m = null)
	{
		//add_action('posts_selection', array(&$this, 'deinitialize'), $m);
		die($m);
	}           
	
	public function deinitialize($m) {
		die($m);
	}
	            
	/*          
	 * ========
	 * Settings
	 * ========
	 */
	
	public function initSettings() {

		add_settings_section($this->slugPrefix('settings'),
			__('Unauthenticated visitors', 'loggedin'),
			array(&$this, 'renderSettingsHeader'),
			$this->options_page);

		add_settings_field($this->slugPrefix('action'),
			__('Action', 'loggedin'),
			array(&$this, 'renderActionSetting'),
			$this->options_page,
			$this->slugPrefix('settings'));
		
		add_settings_field($this->slugPrefix('message'),
			__('Message', 'loggedin'),
			array(&$this, 'renderMessageSetting'),
			$this->options_page,
			$this->slugPrefix('settings'));
			
		add_settings_field($this->slugPrefix('fallback'),
			__('Fallback filename', 'loggedin'),
			array(&$this, 'renderFallbackSetting'),
			$this->options_page,
			$this->slugPrefix('settings'));
		
		register_setting($this->options_page, $this->slugPrefix('action'));
		register_setting($this->options_page, $this->slugPrefix('message'));
		register_setting($this->options_page, $this->slugPrefix('fallback'), array(&$this, 'sanitizeSettingFallback'));
		
		add_filter('plugin_action_links', array(&$this, 'actionLinks'), 10, 2 );
	}
	
	public function actionLinks($links, $file) {
		static $this_plugin;
		if (!$this_plugin)
			$this_plugin = plugin_basename(__FILE__);

		if ($file == $this_plugin){
		$settings_link = sprintf('<a href="options-%s.php#logged-in">%s</a>', $this->options_page, __("Settings", "loggedin"));
			array_unshift($links, $settings_link);
		}
		return $links;
	}

	
	public function sanitizeSettingFallback($val = null, $moo = null) {
		if (empty($val)) {
			return $this->defaults['fallback'];
		}
		
		return $val;
	}
	
	public function renderSettingsHeader() {
		echo '<p id="logged-in">' . __("Let's you decide how to handle visitors that are not logged in.", 'loggedin') . '</p>';
	}


	public function renderActionSetting() {
		$val = get_option($this->slugPrefix('action'));
		$name = $this->slugPrefix('action');
		$actions = $this->actions();
		$options = array();
		
		foreach ($actions as $action => $data) {
			$options[] = sprintf('<option value="%s" %s>%s</option>', $action, selected($val, $action, false), $data['label']);
		}
		
		printf('<select name="%s" id="%s" class="postform">%s</select>', $name, $name, implode("\n", $options));
	}
	
	public function renderMessageSetting() {
		$val = get_option($this->slugPrefix('message'), $this->defaults['message']);
		$name = $this->slugPrefix('message');
		$description = sprintf(__('This message will be placed in between calls to %s and %s of your theme.', 'loggedin'), '<code>get_header()</code>', '<code>get_footer()</code>');
				
		printf('<textarea name="%s" id="%s" rows="6" cols="35" class="code">%s</textarea>  <p class="description">%s</p>', $name, $name, $val, $description);
	}
	
	public function renderFallbackSetting() {
		$val = get_option($this->slugPrefix('fallback'), $this->defaults['fallback']);
		$name = $this->slugPrefix('fallback');
		$description = __('Place a file with this name in your <code>stylesheet_directory</code>.', 'loggedin');
		
		printf('<input name="%s" id="%s" value="%s" class="code" /> <span class="description">%s</span>', $name, $name, $val, $description);
	}
}

$li = LoggedIn::instance();