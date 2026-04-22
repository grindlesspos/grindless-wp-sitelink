<?php

if (is_user_logged_in() === false)
	wp_die('You must be <a href="'. wp_login_url(get_permalink()) . '">logged in</a> to use this feature.');

if (!current_user_can('manage_options') && !isset($_GET['script']))
	wp_die('Your account is not permitted to use this feature.');

global $sandbox_debug;
$sandbox_debug = isset($_GET['sandbox_debug']) ? ($_GET['sandbox_debug'] === 'true') : false;

function page_enqueue() {
	
}
add_action('wp_enqueue_scripts', 'page_enqueue');

function sandbox_inline() {
	?>
	<script type="text/javascript">
		jQuery(document).ready(function($) {
			$('.xdebug-var-dump').on('click', function() {
				$(this).css('max-height', 800);
			});
		});
	</script>
	<style>
		.xdebug-var-dump {
			width: 100%;
			overflow: auto;
			/*background: #131313;*/
			padding: 0.5em;
			font-size: 85%;
			max-height: 400px;
			border: 1px solid #646464;
			margin: 10px 0;
		}
		.content {
			background-image: none;
		}
	</style>
    <?php
}
add_action('wp_head', 'sandbox_inline', 11);


ini_set('xdebug.var_display_max_depth', 8);
ini_set('xdebug.var_display_max_children', 256);
ini_set('xdebug.var_display_max_data', 1024);
error_reporting(E_ALL);
ini_set('display_errors', true);

$theme_path = get_template_directory();
$theme_url = get_template_directory_uri();

get_header();
?>
<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>><div style="width: 70%; margin: auto;">

<?php
$time_start = microtime(true);
$current_script = (isset($_GET['script'])) ? $_GET['script'] : false;
$directory = wp_normalize_path(WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'sandbox');

if (is_dir($directory)) {
	if ($current_script) {
		$script_path = ($current_script) ? $directory . DIRECTORY_SEPARATOR . $current_script . '.php' : '';
		if (file_exists($script_path)) {
			include($script_path);
		} else {
			echo 'Script does not exist. Searching for ' . $script_path;
		}
	} else {
		echo '<h2>Sandbox Scripts</h2>';
		$files = array_diff(scandir($directory), array('..', '.'));

		if (is_array($files) && count($files)) {
			echo '<ul>';
			foreach ($files as $file) {
				if (strpos($file, '.php') === false) continue;
			
				printf('<li><a href="%s?script=%s">%s</a></li>',
					  get_permalink(),
					  str_replace('.php', '', $file),
					  $file
				);
			}
			echo '</ul>';
		} else {
			echo 'No Scripts';
		}
	}
} else {
	echo '<p>Error: <abbr title="'. $directory .'">Sandbox directory</abbr> does not exist. Please create it (in wp-content) and place scripts inside for testing.</p>';
}

	
if ($current_script) { ?>
<div class="row" style="font-size: 75%; margin-top: 3em; border-top: 2px solid #777;">
	<div class="column">
		<div class="content">
			<span><strong>Script</strong>: <?php echo str_replace('/', DIRECTORY_SEPARATOR, $script_path); ?></span><br>
			<span><strong>Execution Time</strong>: <?php echo round((microtime(true) - $time_start), 3); ?> seconds</span><br>
			<a href="<?php echo get_permalink(); ?>"><i class="fa fa-arrow-circle-left"></i> Return to Sandbox Scripts List</a>
		</div>
	</div>
</div>
<?php } ?>
	
</div></article>
<?php get_footer();
