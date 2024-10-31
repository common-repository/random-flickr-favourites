<?php
/*
Plugin Name: Random Flickr Favorites 2
Plugin URI: http://narcanti.keyboardsamurais.de/random-flickr-favourite.html
Description: Generates a IMG tag with one of your favourites @ flickr.com.
Version: 2.12
Author: M. Serhat Cinar
Author URI: http://narcanti.keyboardsamurais.de
*/

/*
	To use just place the following code anywhere in your templates
	if (function_exists('RandomFlickrFav()')) {
		RandomFlickrFav();
	}
	
	Thanks to the wordpress community for this great blogging software,
	the php.net community for their fantastic documentation,
	Krischan Jodies for the recent comments plugin, which I analysed to learn how to write a wp plugin,
	Brian "ColdForged" Dupuis for the headline images plugin, which I also analysed to learn how to write a wp plugin.
*/

// get snoopy http client
require_once(ABSPATH.'wp-includes/class-snoopy.php');

global $RandomFlickrFavs_image_ids, $RandomFlickrFavs_table_name, $wpdb, $RandomFlickrFavs_debug_info, $RandomFlickrFavs_debug;

// option getter for rff
if( !function_exists( 'RandomFlickrFavs_get_option' ) ){
	function RandomFlickrFavs_get_option($option_name){
		global $RandomFlickrFavs_options_initialized, $RandomFlickrFavs_debug_info, $RandomFlickrFavs_debug;
		if ($RandomFlickrFavs_options_initialized == 0) {
			if ($RandomFlickrFavs_debug){
				$RandomFlickrFavs_debug_info .= "RandomFlickrFavs_get_option - INITIALIZING\n";
			}
			add_option('RandomFlickrFavs_flickr_fav_url', 'http://www.flickr.com/photos/95202115@N00/favorites');
			add_option('RandomFlickrFavs_update_intervall', 168); // hours
			add_option('RandomFlickrFavs_last_update', 0);
			add_option('RandomFlickrFavs_image_size', 'm'); // s=square, t=thumbnail, m=small, '-'=medium, b=large, o=original if changed clear cache
			add_option('RandomFlickrFavs_image_size_2', 'b'); // s=square, t=thumbnail, m=small, '-'=medium, b=large, o=original if changed clear cache
			add_option('RandomFlickrFavs_installed', 'false');
			add_option('RandomFlickrFavs_layout', '<center><a href="%%image_page_url" alt="%%artist"><img src="%%image_url" width="190" alt="%%artist"/><br\>"%%image_title"</a></center>');
			add_option('RandomFlickrFavs_debug', 'false');
			add_option('RandomFlickrFavs_show_list', 'false');
			add_option('RandomFlickrFavs_show_thumbs', 'false');
			$RandomFlickrFavs_options_initialized = 1;
		}
		$option = get_option("RandomFlickrFavs_".$option_name);
		if ($RandomFlickrFavs_debug){
			$RandomFlickrFavs_debug_info .= "RandomFlickrFavs_get_option(".$option_name."):".$option."\n";
		}
		return $option;
	}
}

// options updater for rff
if( !function_exists( 'RandomFlickrFavs_update_option' ) ){
	function RandomFlickrFavs_update_option($option_name, $option_value){
		global $RandomFlickrFavs_debug_info, $RandomFlickrFavs_debug;
		if ($RandomFlickrFavs_debug){
			$RandomFlickrFavs_debug_info .= "RandomFlickrFavs_update_option($option_name, $option_value)\n";
		}
		update_option("RandomFlickrFavs_".$option_name, $option_value);
	}
}

// initialization hook
if( !function_exists( 'RandomFlickrFavs_init' ) ){
	function RandomFlickrFavs_init(){
		global $table_prefix, $wpdb, $RandomFlickrFavs_table_name, $RandomFlickrFavs_image_ids, $RandomFlickrFavs_debug_info, $RandomFlickrFavs_debug;
		if (RandomFlickrFavs_get_option('debug')=='true'){
			$RandomFlickrFavs_debug = true;
		}
		else{
			$RandomFlickrFavs_debug = false;
		}
		if ($RandomFlickrFavs_debug){
			$RandomFlickrFavs_debug_info .= "BEGIN RandomFlickrFavs_init with debug: ".$RandomFlickrFavs_debug."\n";
		}
		if (!isset($RandomFlickrFavs_table_name)){
			$RandomFlickrFavs_table_name = $table_prefix . "randomflickrfavs";
		}
		if ($RandomFlickrFavs_debug){
			$RandomFlickrFavs_debug_info .= "RandomFlickrFavs_table_name: ".$RandomFlickrFavs_table_name."\n";
		}
		if (RandomFlickrFavs_get_option('installed')=='false'){
			RandomFlickrFavs_install();
		}
		if ((!isset($RandomFlickrFavs_image_ids)) || sizeof($RandomFlickrFavs_image_ids)<=0){
			$RandomFlickrFavs_image_ids = RandomFlickrFavs_update_image_id_cache();
		}
		if (RandomFlickrFavs_get_option('last_update')+RandomFlickrFavs_get_option('update_intervall')*3600 < time()){
			RandomFlickrFavs_update_favourites();
		}
		
		if ($RandomFlickrFavs_debug){
			$RandomFlickrFavs_debug_info .= "END RandomFlickrFavs_init\n";
		}

	}
}

// installs and creates all neccessary stuff for rff
if( !function_exists( 'RandomFlickrFavs_install' ) ){
	function RandomFlickrFavs_install(){
		global $wpdb, $RandomFlickrFavs_table_name, $RandomFlickrFavs_debug_info, $RandomFlickrFavs_debug;
		if ($RandomFlickrFavs_debug){
		 	$RandomFlickrFavs_debug_info .= "BEGIN RandomFlickrFavs_install: ".$RandomFlickrFavs_table_name."\n";
		}
		
		$sql = 	"DROP TABLE IF EXISTS ".$RandomFlickrFavs_table_name;
		$wpdb->query($sql);

		$sql = "CREATE TABLE ".$RandomFlickrFavs_table_name." (".
				"  `id` mediumint(9) UNSIGNED NOT NULL AUTO_INCREMENT,".
				"  `image_uri` VARCHAR(100) NOT NULL DEFAULT '',".
				"  `image_title` VARCHAR(100),".
				"  `image_page_uri` VARCHAR(100),".
				"  `user_name` VARCHAR(100),".
				"  `last_modified` bigint(11) UNSIGNED NOT NULL DEFAULT 0,".
				"  PRIMARY KEY(`id`),".
				"  UNIQUE `unique`(`image_uri`(100))".
				")";
		$wpdb->query($sql);
		RandomFlickrFavs_update_option('installed', 'true');
		RandomFlickrFavs_update_favourites();
		
		if ($RandomFlickrFavs_debug){
		 	$RandomFlickrFavs_debug_info .= "END RandomFlickrFavs_install\n";
		}

	}
}

// synchronizes db with flickr.com
if( !function_exists( 'RandomFlickrFavs_update_favourites' ) ){
	function RandomFlickrFavs_update_favourites(){
		global $RandomFlickrFavs_image_ids, $RandomFlickrFavs_table_name, $RandomFlickrFavs_debug_info, $wpdb, $RandomFlickrFavs_debug;
		if ($RandomFlickrFavs_debug){
			$RandomFlickrFavs_debug_info .= "BEGIN RandomFlickrFavs_update_favourites\n";
		}
		$RandomFlickrFavs_image_ids = array();
		
		$actual_time = time();
		$has_match = true;
		$next_page = 1;
		$url = RandomFlickrFavs_get_option('flickr_fav_url');
		if ($RandomFlickrFavs_debug){
		 	$RandomFlickrFavs_debug_info .= "flickr_fav_url: ".$url."\n";
		}

		$client = new Snoopy();
		$all_image_objects =  array();
		$single_image_data = array();
		$all_links = array();
		unset($RandomFlickrFavs_image_ids);
		$RandomFlickrFavs_image_ids = array();
		
		while ($has_match){
			// get page
			if ($RandomFlickrFavs_debug){
			 	$RandomFlickrFavs_debug_info .= "getting url: ".$url."\n";
			}
			
			$client->fetch($url);

			if ($RandomFlickrFavs_debug){
			 	$RandomFlickrFavs_debug_info .= "client returned:\n***\n".$client->results."\n***\n";
			}
			

			// 1. filter links
			// if (preg_match_all("/(<a[^>]*?title=\"from.*?<\/a>)/ims", $client->results, $all_links, PREG_SET_ORDER)){
			
			// as 23.01.2008 
			// <span class="photo_container pc_s" style="margin:3px"><a href="/photos/dotlyc/195316216/" title="Weathered ... {}"><img src="http://farm1.static.flickr.com/58/195316216_7941474704_s.jpg" width="75" height="75" alt="Weathered ... {}" class="pc_img" /></a></span>
			if (preg_match_all("/(<span[^>]*?class=\"photo_container pc_s\"[^>]*?>.*?<\/span>)/ims", $client->results, $all_links, PREG_SET_ORDER)){
				$next_page++;
				$url = RandomFlickrFavs_get_option('flickr_fav_url')."/page".$next_page."/";
				
				// 2. split links
				foreach ($all_links as $a_link){
					if ($RandomFlickrFavs_debug){
					 	$RandomFlickrFavs_debug_info .= "ripping from matched line: ".$a_link[1]."\n";
					}
				
					preg_match("/<a[^>]*?href=\"\/photos\/([^\/]*)\/(.*?)\".*?title=\"(.*?)\".*?<img.*?src=\"http:\/\/.*?static.flickr.com\/(.*?)_s.jpg\"/ims", $a_link[1], $single_image_data);
					$image = new RFFImage();
					$image->image_title = $single_image_data[3];
					$image->image_page_uri = $single_image_data[1]."/".$single_image_data[2];
					$image->image_uri = $single_image_data[4];
					$image->user_name = $single_image_data[1];

					if ($RandomFlickrFavs_debug){
					 	$RandomFlickrFavs_debug_info .= "matched favourite image data: 1=".$single_image_data[1].", 2=".$single_image_data[2].", 3=".$single_image_data[3].", 4=".$single_image_data[4]."\n";
					}

					array_push($all_image_objects, $image);
				}
			}
			else{
				$has_match = false;
			}
		}
		unset($all_links);
		unset($single_image_data);
		
		foreach ($all_image_objects as $a_image){
			$a_image->synchronize_with_db();
			array_push($RandomFlickrFavs_image_ids, $a_image->id);
		}
		unset($all_image_objects);
		
		RandomFlickrFavs_update_option('last_update', time());
		
		// now delete all old ones
		$delete_old = "DELETE FROM ".$RandomFlickrFavs_table_name." WHERE last_modified<".$actual_time;
		$wpdb->query($delete_old);
		RandomFlickrFavs_update_image_id_cache();

		if ($RandomFlickrFavs_debug){
			$RandomFlickrFavs_debug_info .= "END RandomFlickrFavs_update_favourites\n";
		}

	}
}

// gets all existing ids of images from db
if( !function_exists( 'RandomFlickrFavs_update_image_id_cache' ) ){
	function RandomFlickrFavs_update_image_id_cache(){
		global $RandomFlickrFavs_image_ids, $RandomFlickrFavs_table_name, $wpdb, $RandomFlickrFavs_debug_info, $RandomFlickrFavs_debug;
		if ($RandomFlickrFavs_debug){
			$RandomFlickrFavs_debug_info .= "BEGIN RandomFlickrFavs_update_image_id_cache\n";
		}
		$RandomFlickrFavs_image_ids = $wpdb->get_col("SELECT id FROM ".$RandomFlickrFavs_table_name." ORDER BY id");
		if ($RandomFlickrFavs_debug){
			$RandomFlickrFavs_debug_info .= "found ".sizeof($RandomFlickrFavs_image_ids)." image ids in DB\n";
			$RandomFlickrFavs_debug_info .= "END RandomFlickrFavs_update_image_id_cache\n";
		}

		return $RandomFlickrFavs_image_ids;
	}
}

// removes all entries in db
if( !function_exists( 'RandomFlickrFavs_clear_db' ) ){
	function RandomFlickrFavs_clear_db(){
		global $RandomFlickrFavs_image_ids, $RandomFlickrFavs_table_name, $wpdb, $RandomFlickrFavs_debug_info, $RandomFlickrFavs_debug;
		if ($RandomFlickrFavs_debug){
			$RandomFlickrFavs_debug_info .= "BEGIN RandomFlickrFavs_clear_db\n";
		}
		$wpdb->query( "DELETE FROM ".$RandomFlickrFavs_table_name);
		$RandomFlickrFavs_image_ids = array();
		if ($RandomFlickrFavs_debug){
			$RandomFlickrFavs_debug_info .= "END RandomFlickrFavs_clear_db\n";
		}
	}
}


// ripps of the image title from the image page
//if (!function_exists( 'RandomFlickrFavs_get_image_title' ) ){
//	function RandomFlickrFavs_get_image_title($image_object){
//		global $RandomFlickrFavs_debug_info, $RandomFlickrFavs_debug;
//
//		if ($RandomFlickrFavs_debug){
//			$RandomFlickrFavs_debug_info .= "BEGIN RandomFlickrFavs_get_image_title(".$image_object->id.")\n";
//		}
//
//		$client = new Snoopy();
//		$client->fetch($image_object->get_image_page_url());
//		if (preg_match("/<title>([^>]*?) on flickr/ims", $client->results, $title)){
//			$image_object->image_title = $title[1];
//		}
//		else{
//			$image_object->image_title = "-untitled-";
//		}
//		$image_object->synchronize_with_db();
//
//		if ($RandomFlickrFavs_debug){
//			$RandomFlickrFavs_debug_info .= "END RandomFlickrFavs_get_image_title\n";
//		}
//
//		return $image_object;
//	}
//}

// loads all image id's and select one randomly
if( !function_exists( 'RandomFlickrFavs_get_random_image_id' ) ){
	function RandomFlickrFavs_get_random_image_id(){
		global $RandomFlickrFavs_image_ids, $RandomFlickrFavs_table_name, $wpdb, $RandomFlickrFavs_debug_info, $RandomFlickrFavs_debug;
		if ($RandomFlickrFavs_debug){
			$RandomFlickrFavs_debug_info .= "BEGIN RandomFlickrFavs_get_random_image_id\n";
			$RandomFlickrFavs_debug_info .= sizeof($RandomFlickrFavs_image_ids)." ids in cache\n";
		}
		if (sizeof($RandomFlickrFavs_image_ids)<=0){
			if ($RandomFlickrFavs_debug){
				$RandomFlickrFavs_debug_info .= "id cache is empty, will update id cache\n";
			}
			
			// select all ids
			$RandomFlickrFavs_image_ids = RandomFlickrFavs_update_image_id_cache();
			// if still no images there, return -1
			if (sizeof($RandomFlickrFavs_image_ids)<=0){
				if ($RandomFlickrFavs_debug){
					$RandomFlickrFavs_debug_info .= "no image ids returned from update image ids cache\n";
				}
				return -1;
			}
		}

		if ($RandomFlickrFavs_debug){
			$RandomFlickrFavs_debug_info .= "size before return: ".sizeof($RandomFlickrFavs_image_ids)." ids in cache\n";		
		}
		// define a random one
		$random_one = rand(0, sizeof($RandomFlickrFavs_image_ids)-1);
		
		if ($RandomFlickrFavs_debug){
			$RandomFlickrFavs_debug_info .= "END RandomFlickrFavs_update_image_id_cache\n";
		}
		
		return $RandomFlickrFavs_image_ids[$random_one];
	}
}

// the main plugin hook
if( !function_exists( 'RandomFlickrFav' ) ){
	function RandomFlickrFav(){
		global $RandomFlickrFavs_debug_info, $RandomFlickrFavs_debug;
		if ($RandomFlickrFavs_debug){
			$RandomFlickrFavs_debug_info .= "\n+++\nBEGIN RandomFlickrFav\n";
		}
		$random_one = RandomFlickrFavs_get_random_image_id();
		if ($RandomFlickrFavs_debug){
			$RandomFlickrFavs_debug_info.= "random: ".$random_one."\n";
		}
		if ($random_one>=0){
			$image = new RFFImage();
			$image->load_from_db($random_one);
//			if ((!isset($image->image_title)) || $image->image_title==''){
//				$image = RandomFlickrFavs_get_image_title($image);
//			}
			$layout = stripslashes(RandomFlickrFavs_get_option('layout'));
			$layout = preg_replace('/\%\%image_page_url/i', $image->get_image_page_url(), $layout);
			$layout = preg_replace('/\%\%image_url/i', $image->get_image_url(RandomFlickrFavs_get_option('image_size')), $layout);
                        $layout = preg_replace('/\%\%image_url2/i', $image->get_image_url(RandomFlickrFavs_get_option('image_size_2')), $layout);
			$layout = preg_replace('/\%\%artist/i', $image->user_name, $layout);
			$layout = preg_replace('/\%\%image_title/i', $image->image_title, $layout);
			echo "<!-- start RandomFlickrFavs2 -->";		
			echo $layout;
			echo "<!-- end RandomFlickrFavs2 -->";
		}
		if ($RandomFlickrFavs_debug){
			$RandomFlickrFavs_debug_info .= "END RandomFlickrFav\n";
			echo "<!-- DEBUG: \n".htmlspecialchars($RandomFlickrFavs_debug_info)."\n-->";
		}
	}
}

// the options page
if( !function_exists( 'RandomFlickrFavs_options_page' ) ){
	function RandomFlickrFavs_options_page(){
		global $RandomFlickrFavs_image_ids, $RandomFlickrFavs_debug_info, $RandomFlickrFavs_debug;
		if ($RandomFlickrFavs_debug){
			$RandomFlickrFavs_debug_info .= "BEGIN RandomFlickrFavs_options_page()\n";
		}
		// check for updates options
		if (isset($_GET['a'])){
			if ($_GET['a'] == 'force_update'){
				RandomFlickrFavs_update_favourites();
			}
			else if ($_GET['a'] == 'debug_on'){
				RandomFlickrFavs_update_option('debug', 'true');
				$RandomFlickrFavs_debug = true;
			}
			else if ($_GET['a'] == 'debug_off'){
				RandomFlickrFavs_update_option('debug', 'false');
				$RandomFlickrFavs_debug = false;
			}
			else if ($_GET['a'] == 'list_on'){
				RandomFlickrFavs_update_option('show_list', 'true');
			}
			else if ($_GET['a'] == 'list_off'){
				RandomFlickrFavs_update_option('show_list', 'false');
			}
			else if ($_GET['a'] == 'thumbs_on'){
				RandomFlickrFavs_update_option('show_thumbs', 'true');
			}
			else if ($_GET['a'] == 'thumbs_off'){
				RandomFlickrFavs_update_option('show_thumbs', 'false');
			}
			else if ($_GET['a'] == 'clear_db'){
				RandomFlickrFavs_clear_db();
			}
		}
		else{
			if (!empty($_POST['update_intervall'])){
				RandomFlickrFavs_update_option('update_intervall', (int) $_POST['update_intervall']);
			}
			if (!empty($_POST['fav_url'])){
				if (RandomFlickrFavs_get_option('flickr_fav_url')!=$_POST['fav_url']){
					RandomFlickrFavs_update_option('flickr_fav_url', $_POST['fav_url']);
					RandomFlickrFavs_update_favourites();
				}
			}
			if (!empty($_POST['fav_size'])){
				RandomFlickrFavs_update_option('image_size', $_POST['fav_size']);
			}
			if (!empty($_POST['fav_template'])){
				RandomFlickrFavs_update_option('layout', $_POST['fav_template']);
			}
		}

		$l_img_size = RandomFlickrFavs_get_option('image_size');
		if (RandomFlickrFavs_get_option('show_thumbs')=='true'){
			$l_show_thumb = true;
		}
		else{
			$l_show_thumb = false;
		}
		if (RandomFlickrFavs_get_option('show_list')=='true'){
			$l_show_list = true;
		}
		else{
			$l_show_list = false;
		}
		$l_last_update = RandomFlickrFavs_get_option('last_update');
		$l_update_intervall = RandomFlickrFavs_get_option('update_intervall');
?>
<div class="wrap">
<style>
  table.list{
    border: 1px solid black;
  }
  td.list{
    border: 1px solid black;
    text-align: left;
  }
  th.list{
    border: 1px solid black;
    text-align: center;
    font-weight: bold;
    background-color: lightgray;
  }
</style>
	<form method="post" action="<?php echo $_SERVER['PHP_SELF']; ?>?page=RandomFlickrFavs2.php">
		<fieldset class="options"> 
			<legend><?php _e('Options') ?></legend>
			<table width="100%" cellspacing="2" cellpadding="5" class="editform">
				<tr valign="top">
					<th width="33%" scope="row"><?php _e('Debug:'); ?></th>
					<td>
						<?php	
							if ($RandomFlickrFavs_debug){
								echo '<a href="'.$_SERVER['PHP_SELF'].'?page=RandomFlickrFavs2.php&amp;a=debug_off">disable debug</a>';
							}
							else{
								echo '<a href="'.$_SERVER['PHP_SELF'].'?page=RandomFlickrFavs2.php&amp;a=debug_on">enable debug</a>';
							}
						?>
						<p/>
						Enabling debug will show a comment block in the html source with detailed debug information, and therefore slow down
						this plugin a little bit.
					</td>
				</tr>
				<tr valign="top">
					<th width="33%" scope="row"><?php _e('Favorites URL:'); ?></th>
					<td nowrap><input name="fav_url" type="text" id="fav_url" value="<?php echo RandomFlickrFavs_get_option('flickr_fav_url'); ?>" size="60" /></td>
				</tr>
				<tr valign="top">
					<th width="33%" scope="row"><?php _e('Synchronize intervall:'); ?></th>
					<td nowrap>Synchronize every <input name="update_intervall" type="text" id="update_intervall" value="<?php echo $l_update_intervall; ?>" size="3" /> hours with flickr.com</td>
				</tr>
				<tr valign="top">
					<th width="33%" scope="row"><?php _e('Last updated:'); ?></th>
					<td nowrap>
						<?php echo date("D dS M Y H:i:s", $l_last_update); ?> &nbsp; - &nbsp; <?php echo round(((time()-$l_last_update)/3600), 2); ?> hours ago<br/>
					  Next update <?php echo date("D dS M Y H:i:s", $l_last_update+3600*$l_update_intervall); ?></td>
				</tr>
				<tr valign="top">
					<th width="33%" scope="row"><?php _e('Image size:'); ?></th>
					<td>
						<select name="fav_size" id="fav_size" size="1">
							<option<?php if ($l_img_size=='s'){echo ' selected="selected"';} ?> value="s">square (s)</option>
							<option<?php if ($l_img_size=='t'){echo ' selected="selected"';} ?> value="t">thumbnail (t)</option>
							<option<?php if ($l_img_size=='m'){echo ' selected="selected"';} ?> value="m">small (m)</option>
							<option<?php if ($l_img_size=='-'){echo ' selected="selected"';} ?> value="-">medium (-)</option>
							<option<?php if ($l_img_size=='b'){echo ' selected="selected"';} ?> value="b">large (b)</option>
							<option<?php if ($l_img_size=='o'){echo ' selected="selected"';} ?> value="o">original (o)</option>
						</select>
						<p/>
						The size defines, which of the sizes will be included for the image_url.<br/>
						Selecting "small" will generate http://static-flickr.com/.../anyimage<strong>_m</strong>.jpg
						for the %%image_url placeholder, while selecting "large" will generate http://static-flickr.com/.../anyimage<strong>_b</strong>.jpg.<br/>
						Since the different image sizes generated by flickr depend on the proportions of the original image,
						this will not fix the displayed images width / height.
					</td>
				</tr>
				<tr valign="top">
					<th width="33%" scope="row"><?php _e('Template:'); ?></th>
					<td>
						<textarea name="fav_template" cols="60" rows="2" id="fav_template" style="width: 98%; font-size: 12px;" class="code"><?php echo stripslashes(htmlspecialchars(RandomFlickrFavs_get_option('layout'))); ?></textarea>
						<p/>
						Usable placeholders:<br/>
						<strong>%%image_page_url</strong> - url of page containing the image, for use with href attribute of anchor tag<br/>
						<strong>%%artist</strong> - name of artist, which uploaded the image to flickr<br/>
						<strong>%%image_url</strong> - url to image, for use with src attribute of img tag<br/>
						<strong>%%image_title</strong> - title of image, "-untitled-" if no title was given
					</td>
				</tr>
				<tr valign="top">
					<th width="33%" scope="row"><?php _e('Images:'); ?></th>
					<td nowrap>Currently there are <strong><?php echo sizeof($RandomFlickrFavs_image_ids); ?></strong> images in the database</td>
				</tr>
				<tr valign="top">
					<th width="33%" scope="row"><?php _e('Clear Database:'); ?></th>
					<td nowrap><a href="<?php echo $_SERVER['PHP_SELF']; ?>?page=RandomFlickrFavs2.php&amp;a=clear_db">Clear Database</a><p/>Will delete all database entries leading to a update on next call.</td>
				</tr>
				<tr valign="top">
					<th width="33%" scope="row"><?php _e('List all images on this page:'); ?></th>
					<td>
						<?php
							if ($l_show_list){
								echo '<a href="'.$_SERVER['PHP_SELF'].'?page=RandomFlickrFavs2.php&amp;a=list_off">disable</a>';
							}
							else{
								echo '<a href="'.$_SERVER['PHP_SELF'].'?page=RandomFlickrFavs2.php&amp;a=list_on">enable</a>';
							}
						?>
						<p/>
						Enabling this option, your admin page (this page) will contain a list of all current images in the database.
					</td>
				</tr>
				<?php if ($l_show_list){ ?>
					<tr valign="top">
						<th width="33%" scope="row"><?php _e('Show thumbs in list:') ?></th>
						<td>
							<?php
								if ($l_show_thumb){
									echo '<a href="'.$_SERVER['PHP_SELF'].'?page=RandomFlickrFavs2.php&amp;a=thumbs_off">disable</a>';
								}
								else{
									echo '<a href="'.$_SERVER['PHP_SELF'].'?page=RandomFlickrFavs2.php&amp;a=thumbs_on">enable</a>';
								}
							?>
							<p/>
							Enabling this option, the list of all current images in the database will also contain a thumbnail view of that image.
						</td>
					</tr>
				<?php } ?>
				<tr valign="top">
					<th width="33%" scope="row"></th>
					<td nowrap><a href="<?php echo $_SERVER['PHP_SELF']; ?>?page=RandomFlickrFavs2.php&amp;a=force_update">Synchronize now with flickr.com</a></td>
				</tr>
			</table>
		</fieldset>
		<p class="submit">
			<input type="submit" name="Submit" value="<?php _e('Update Options'); ?> &raquo;" />
		</p>
	</form>
	<?php if ($l_show_list){ ?>
		Empty image titles denote that the title of the image has not been ripped from the image page. At the first access
		to that image, it will be updated.
		<p/>
		<table width="100%" cellspacing="2" cellpadding="5" class="editform list">
			<tr valign="top">
				<th class="list">db id</th>
				<?php if ($l_show_thumb){ ?>
				<th class="list">thumb</th>
				<?php } ?>
				<th class="list">image title</th>
				<th class="list">uri</th>
				<th class="list">artist</th>
				<th class="list">last modified</th>
			</tr>
			<?php 
					$a_image = new RFFImage();
					foreach ($RandomFlickrFavs_image_ids as $a_image_id){
						$a_image->load_from_db($a_image_id);
						?>
						<tr valign="top">
							<td class="list"><?php echo htmlspecialchars($a_image->id); ?></td>
							<?php if ($l_show_thumb){ ?>
							<td class="list"><img src="<?php echo $a_image->get_image_url('s'); ?>" width="75" height="75" /></th>
							<?php } ?>
							<td class="list"><?php echo htmlspecialchars($a_image->image_title); ?></td>
							<td class="list">
								image: <a href="<?php echo $a_image->get_image_url($l_img_size); ?>"><?php echo htmlspecialchars($a_image->image_uri); ?></a><br/>
								page: <a href="<?php echo $a_image->get_image_page_url(); ?>"><?php echo htmlspecialchars($a_image->image_page_uri); ?></a>
							</td>
							<td class="list"><?php echo htmlspecialchars($a_image->user_name); ?></td>
							<td class="list"><?php echo date("d.m.y H:i:s", $a_image->last_modified); ?></td>
						</tr>
						<?php
					}
			?>
		</table>
	<?php } 
		if ($RandomFlickrFavs_debug){
			$RandomFlickrFavs_debug_info .= "END RandomFlickrFavs_options_page()\n";
	?>
<table width="100%" cellspacing="2" cellpadding="5" class="editform">
	<tr>
		<td>Debug information</td>
	</tr>
	<tr>
		<td><?php echo ereg_replace("\n+", "<br/>", htmlspecialchars($RandomFlickrFavs_debug_info)); ?></td>
	</tr>
</table>
		<?php
		}
		?>
</div>
<?php
	}
}

if( !function_exists( 'RandomFlickrFavs_add_options_page' ) ){
	function RandomFlickrFavs_add_options_page() {
		global $RandomFlickrFavs_debug_info, $RandomFlickrFavs_debug;
		if ($RandomFlickrFavs_debug){
			$RandomFlickrFavs_debug_info .= "BEGIN RandomFlickrFavs_add_options_page()\n";
		}
		if (function_exists('add_options_page')) {
			// WordPress 1.5 sometimes doesn't show the options page if called in the first style
			if ( $wp_version > "1.5" ) {
				add_options_page('Random Flickr Favorites 2 Plugin', 'RandomFlickrFav', 8, basename(__FILE__), 'RandomFlickrFavs__options_page');
			}
			else {
				add_options_page('Random Flickr Favorites 2 Plugin', 'RandomFlickrFav', 8, basename(__FILE__));
			}
		}
		if ($RandomFlickrFavs_debug){
			$RandomFlickrFavs_debug_info .= "END RandomFlickrFavs_add_options_page()\n";
		}

	}
}

if ( function_exists("is_plugin_page") && is_plugin_page() ) {
	RandomFlickrFavs_options_page(); 
	return;
}

// a class for handling the images
class RFFImage{
	var $user_name = null;
	var $image_page_uri = null;
	var $image_title = null;
	var $image_uri = null;
	var $last_modified = 0;
	var $id = -1;
	
	function RFFImage(){}
	
	function get_debug_string(){
		$info = "";
		if (isset($this->id)){
			$info .= "id:".$this->id." ";
		}
		else{
			$info .= "id:- ";
		}
		if (isset($this->image_title)){
			$info .= "title:".$this->image_title." ";

		}
		else{
			$info .= "title:- ";
		}
		if (isset($this->image_uri)){
			$info .= "uri:".$this->image_uri." ";

		}
		else{
			$info .= "uri:- ";
		}
		if (isset($this->image_page_uri)){
			$info .= "page:".$this->image_page_uri." ";

		}
		else{
			$info .= "page:- ";
		}
		if (isset($this->user_name)){
			$info .= "artist:".$this->user_name." ";

		}
		else{
			$info .= "artist:- ";
		}
		if (isset($this->last_modified)){
			$info .= "lm:".date("D dS M Y H:i:s", $this->last_modified)." ";

		}
		else{
			$info .= "lm:- ";
		}

		return $info;
	}
	
	function get_image_page_url(){
		return "http://www.flickr.com/photos/".$this->image_page_uri;
	}
	
	// s=square, t=thumbnail, m=small, -=medium, b=large, o=original if changed clear cache
	function get_image_url($size = "m"){
		// http://static.flickr.com/35/71552846_6f05c743b8
		$size_extension = "_m";
		switch ($size) {
			case "s":
				$size_extension = "_s";
				break;
			case "t":
				$size_extension = "_t";
				break;
			case "m": default:
				$size_extension = "_m";
				break;
			case "-":
				$size_extension = "";
				break;
			case "t":
				$size_extension = "_t";
				break;
			case "b":
				$size_extension = "_b";
				break;
			case "o":
				$size_extension = "_o";
				break;
		}
		return "http://static.flickr.com/".$this->image_uri.$size_extension.".jpg";
	}
	
	function synchronize_with_db(){
		// check if image exists in db
		global $wpdb, $RandomFlickrFavs_table_name, $RandomFlickrFavs_debug_info, $RandomFlickrFavs_debug;
		if ($RandomFlickrFavs_debug){
			$RandomFlickrFavs_debug_info .= "synchronize ".$this->get_image_url('s')."\n";
			$RandomFlickrFavs_debug_info .= "synchronize ".$this->get_debug_string()."\n";
		}
		$image_url_query = "SELECT * FROM ".$RandomFlickrFavs_table_name." WHERE image_uri LIKE '".$wpdb->escape($this->image_uri)."'";
		$image_data_in_db = $wpdb->get_row( $image_url_query, ARRAY_A );
		if (sizeof($image_data_in_db)<=0){
			if ($RandomFlickrFavs_debug){
				$RandomFlickrFavs_debug_info .= " synchronize using insert\n";
			}
			// existiert nicht, also insert
			$insert = "INSERT INTO ".$RandomFlickrFavs_table_name." (last_modified, image_uri, image_page_uri";
			$insert_values = " VALUES (".time().",'".$wpdb->escape($this->image_uri)."','".$wpdb->escape($this->image_page_uri)."'";
			if (isset($this->image_title) && $this->image_title!='' && $this->image_title!=null){
				$insert = $insert.", image_title";
				$insert_values = $insert_values.", '".$wpdb->escape($this->image_title)."'";
			}
			if (isset($this->user_name) && $this->user_name!='' && $this->user_name!=null){
				$insert = $insert.", user_name";
				$insert_values = $insert_values.", '".$wpdb->escape($this->user_name)."'";
			}
			$insert = $insert.") ".$insert_values.")";
			$wpdb->query( $insert );
			$this->id = $wpdb->insert_id;
		}
		else{
			$update_title = false;
			//if (isset($this->image_title) && $this->image_title!='' && $this->image_title!=null && $this->image_title!=$image_data_in_db['image_title']){
			//	$update_title = true;
			//}
			// existiert, also vergleichen
			if (	$image_data_in_db['image_page_uri']!=$this->image_page_uri || 
				$image_data_in_db['image_uri']!=$this->image_uri || 
				$image_data_in_db['user_name']!=$this->user_name ||
				$update_title
				){
				// nicht gleich -> datenbank aktualisieren
				if ($update_title){
					if ($RandomFlickrFavs_debug){
						$RandomFlickrFavs_debug_info .= "synchronize update with image_title\n";
					}
					$update = "UPDATE ".$RandomFlickrFavs_table_name." SET last_modified=".time().", image_uri='".$wpdb->escape($this->image_uri)."', image_page_uri='".$wpdb->escape($this->image_page_uri)."', user_name='".$wpdb->escape($this->user_name)."', image_title='".$wpdb->escape($this->image_title)."' WHERE id='".$image_data_in_db['id']."'";
				}
				else{
					if ($RandomFlickrFavs_debug){
						$RandomFlickrFavs_debug_info .= "synchronize update WITHOUT image_title\n";
					}
					$update = "UPDATE ".$RandomFlickrFavs_table_name." SET last_modified=".time().", image_uri='".$wpdb->escape($this->image_uri)."', image_page_uri='".$wpdb->escape($this->image_page_uri)."', user_name='".$wpdb->escape($this->user_name)."' WHERE id='".$image_data_in_db['id']."'";
				}
				$wpdb->query( $update );
			}
			else{
				// gleich, also nur timestamp aktualisieren
				if ($RandomFlickrFavs_debug){
					$RandomFlickrFavs_debug_info .= "synchronize update timestamp\n";
				}
				$update = "UPDATE ".$RandomFlickrFavs_table_name." SET last_modified=".time()." WHERE id='".$image_data_in_db['id']."'";
				$wpdb->query( $update );
			}
		}
		$wpdb->flush();
	}

	function load_from_db($id){
		global $wpdb, $RandomFlickrFavs_table_name;
		$image_id_query = "SELECT * FROM ".$RandomFlickrFavs_table_name." WHERE id=".$id;
		$image_data_in_db = $wpdb->get_row( $image_id_query, ARRAY_A );
		$this->user_name = $image_data_in_db['user_name'];
		$this->image_page_uri = $image_data_in_db['image_page_uri'];
		$this->image_title = $image_data_in_db['image_title'];
		$this->image_uri = $image_data_in_db['image_uri'];
		$this->last_modified = $image_data_in_db['last_modified'];
		$this->id = $image_data_in_db['id'];
		$wpdb->flush();
	}
}

// wp plugin api hooks to activate
add_action('init', 'RandomFlickrFavs_init');
add_action('admin_menu', 'RandomFlickrFavs_add_options_page');

?>