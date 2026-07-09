<?php
/**
 * @package TenUp\MU_Migration
 */
namespace TenUp\MU_Migration\Helpers;

/**
 * Checks if WooCommerce is active.
 *
 * @return bool
 */
function is_woocommerce_active() {
	return in_array(
		'woocommerce/woocommerce.php',
		apply_filters( 'active_plugins', get_option( 'active_plugins' ) )
	);
}

/**
 * Checks if $filename is a zip file by checking it's first few bytes sequence.
 *
 * @param string $filename
 * @return bool
 */
function is_zip_file( $filename ) {
	$fh = fopen( $filename, 'r' );

	if ( ! $fh ) {
		return false;
	}

	$blob = fgets( $fh, 5 );

	fclose( $fh );

	if ( strpos( $blob, 'PK' ) !== false ) {
		return true;
	} else {
		return false;
	}
}

/**
 * Parses a url for use in search-replace by removing its protocol.
 *
 * @param string $url
 * @return string
 */
function parse_url_for_search_replace( $url ) {
	$parsed_url = parse_url( esc_url( $url ) );

	$path = isset( $parsed_url['path'] ) ? $parsed_url['path'] : '';

	return $parsed_url['host'] . $path;
}

/**
 * Recursively removes a directory and its files.
 *
 * @param string $dirPath
 * @param bool   $deleteParent
 */
function delete_folder( $dirPath, $deleteParent = true ) {
	$limit = 0;

	/*
	 * We may hit the recursion depth,
	 * so let's keep trying until everything has been deleted.
	 *
	 * The limit check avoids infinite loops.
	 */
	while ( file_exists( $dirPath ) && $limit++ < 10 ) {
		foreach (
			new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $dirPath, \FilesystemIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::CHILD_FIRST
			) as $path
		) {
			$path->isFile() ? @unlink( $path->getPathname() ) : @rmdir( $path->getPathname() );
		}

		if ( $deleteParent ) {
			rmdir( $dirPath );
		}
	}
}

/**
 * Recursively copies a directory and its files.
 *
 * @param string $source
 * @param string $dest
 */
function move_folder( $source, $dest ) {
	if ( ! file_exists( $dest ) ) {
		mkdir( $dest );
	}

	foreach (
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $source, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST ) as $item
	) {
		if ( $item->isDir() ) {
			$dir = $dest . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
			if ( ! file_exists( $dir ) ) {
				mkdir( $dir );
			}
		} else {
			$dest_file = $dest . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
			if ( ! file_exists( $dest_file ) ) {
				rename( $item, $dest_file );
			}
		}
	}

}

/**
 * Retrieves the db prefix based on the $blog_id.
 *
 * @uses wpdb
 *
 * @param int $blog_id
 * @return string
 */
function get_db_prefix( $blog_id ) {
	global $wpdb;

	if ( $blog_id > 1 ) {
		$new_db_prefix = $wpdb->base_prefix . $blog_id . '_';
	} else {
		$new_db_prefix = $wpdb->prefix;
	}

	return $new_db_prefix;
}

/**
 * Does the same thing that add_user_to_blog does, but without calling switch_to_blog().
 *
 * @param int    $blog_id
 * @param int    $user_id
 * @param string $role
 * @return \WP_Error
 */
function light_add_user_to_blog( $blog_id, $user_id, $role ) {
	$user = get_userdata( $user_id );

	if ( ! $user ) {
		restore_current_blog();
		return new \WP_Error( 'user_does_not_exist', __( 'The requested user does not exist.' ) );
	}

	if ( ! get_user_meta( $user_id, 'primary_blog', true ) ) {
		update_user_meta( $user_id, 'primary_blog', $blog_id );
		$details = get_blog_details( $blog_id );
		update_user_meta( $user_id, 'source_domain', $details->domain );
	}

	$user->set_role( $role );

	/**
	 * Fires immediately after a user is added to a site.
	 *
	 * @since MU
	 *
	 * @param int    $user_id User ID.
	 * @param string $role    User role.
	 * @param int    $blog_id Blog ID.
	 */
	do_action( 'add_user_to_blog', $user_id, $role, $blog_id );
	wp_cache_delete( $user_id, 'users' );
	wp_cache_delete( $blog_id . '_user_count', 'blog-details' );
}

/**
 * Frees up memory for long running processes.
 */
function stop_the_insanity() {
	global $wpdb, $wp_actions, $wp_filter, $wp_object_cache;

	//reset queries
	$wpdb->queries = array();
	// Prevent wp_actions from growing out of control
	$wp_actions = array();

	if ( is_object( $wp_object_cache ) ) {
		$wp_object_cache->group_ops      = array();
		$wp_object_cache->stats          = array();
		$wp_object_cache->memcache_debug = array();
		$wp_object_cache->cache          = array();

		if ( method_exists( $wp_object_cache, '__remoteset' ) ) {
			$wp_object_cache->__remoteset();
		}
	}

	/*
	 * The WP_Query class hooks a reference to one of its own methods
	 * onto filters if update_post_term_cache or
	 * update_post_meta_cache are true, which prevents PHP's garbage
	 * collector from cleaning up the WP_Query instance on long-
	 * running processes.
	 *
	 * By manually removing these callbacks (often created by things
	 * like get_posts()), we're able to properly unallocate memory
	 * once occupied by a WP_Query object.
	 *
	 */
	if ( isset( $wp_filter['get_term_metadata'] ) ) {
		/*
		 * WordPress 4.7 has a new Hook infrastructure, so we need to make sure
	     * we're accessing the global array properly.
		 */
		if ( class_exists( 'WP_Hook' ) && $wp_filter['get_term_metadata'] instanceof \WP_Hook ) {
			$filter_callbacks = &$wp_filter['get_term_metadata']->callbacks;
		} else {
			$filter_callbacks = &$wp_filter['get_term_metadata'];
		}

		if ( isset( $filter_callbacks[10] ) ) {
			foreach ( $filter_callbacks[10] as $hook => $content ) {
				if ( preg_match( '#^[0-9a-f]{32}lazyload_term_meta$#', $hook ) ) {
					unset( $filter_callbacks[10][ $hook ] );
				}
			}
		}
	}

}

/**
 * Add START TRANSACTION and COMMIT to the sql export.
 * shamelessly stolen from http://stackoverflow.com/questions/1760525/need-to-write-at-beginning-of-file-with-php
 *
 * @param string $orig_filename SQL dump file name.
 */
function addTransaction( $orig_filename ) {
	$context   = stream_context_create();
	$orig_file = fopen( $orig_filename, 'r', 1, $context );

	$temp_filename = tempnam( sys_get_temp_dir(), 'php_prepend_' );
	file_put_contents( $temp_filename, 'START TRANSACTION;' . PHP_EOL );
	file_put_contents( $temp_filename, $orig_file, FILE_APPEND );
	file_put_contents( $temp_filename, 'COMMIT;', FILE_APPEND );

	fclose( $orig_file );
	unlink( $orig_filename );
	rename( $temp_filename, $orig_filename );
}

/**
 * Switches to another blog if on Multisite
 *
 * @param $blog_id
 */
function maybe_switch_to_blog( $blog_id ) {
	if ( is_multisite() ) {
		switch_to_blog( $blog_id );
	}
}

/**
 * Restore the current blog if on multisite
 */
function maybe_restore_current_blog() {
	if ( is_multisite() ) {
		restore_current_blog();
	}
}


/**
 * Extracts a zip file to the $dest_dir.
 *
 * @uses ZipArchive
 *
 * @param string $filename
 * @param string $dest_dir
 * @return bool True on success, false on failure.
 */
function extract( $filename, $dest_dir ) {
	$zip = new \ZipArchive();

	if ( true !== $zip->open( $filename ) ) {
		return false;
	}

	mkdir( $dest_dir );
	$extracted = $zip->extractTo( $dest_dir );
	$zip->close();

	return $extracted;
}

/**
 * Creates a zip file with the provided files/folders.
 *
 * $files_to_zip maps the in-archive name (key) to the source path on disk
 * (value). File sources are added at that name; directory sources are added
 * recursively under that name.
 *
 * @uses ZipArchive
 *
 * @param string $zip_file     The name of the zip file to create.
 * @param array  $files_to_zip The files/folders to include in the zip file.
 *
 * @return string The path to the created zip file.
 * @throws \RuntimeException If the zip file can not be created.
 */
function zip( $zip_file, $files_to_zip ) {
	$zip = new \ZipArchive();

	if ( true !== $zip->open( $zip_file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
		throw new \RuntimeException( sprintf( 'Unable to create zip file: %s', $zip_file ) );
	}

	foreach ( $files_to_zip as $local_name => $source ) {
		// Support plain (non-associative) arrays of paths.
		if ( is_int( $local_name ) ) {
			$local_name = basename( $source );
		}

		if ( is_dir( $source ) ) {
			add_folder_to_zip( $zip, $source, $local_name );
		} elseif ( is_file( $source ) ) {
			$zip->addFile( $source, $local_name );
		}
	}

	$zip->close();

	return $zip_file;
}

/**
 * Recursively adds a folder and its contents to an open ZipArchive.
 *
 * @param \ZipArchive $zip         The open archive to add files to.
 * @param string      $source_dir  The directory on disk to add.
 * @param string      $archive_dir The path the directory should have inside the archive.
 */
function add_folder_to_zip( \ZipArchive $zip, $source_dir, $archive_dir ) {
	$source_dir  = rtrim( $source_dir, '/' );
	$archive_dir = rtrim( $archive_dir, '/' );

	$zip->addEmptyDir( $archive_dir );

	$files = new \RecursiveIteratorIterator(
		new \RecursiveDirectoryIterator( $source_dir, \FilesystemIterator::SKIP_DOTS ),
		\RecursiveIteratorIterator::SELF_FIRST
	);

	foreach ( $files as $file ) {
		$relative_path = substr( $file->getPathname(), strlen( $source_dir ) + 1 );
		$entry         = $archive_dir . '/' . $relative_path;

		if ( $file->isDir() ) {
			$zip->addEmptyDir( $entry );
		} elseif ( $file->isFile() ) {
			$zip->addFile( $file->getPathname(), $entry );
		}
	}
}

/**
 * Run a command within WP_CLI
 *
 * @param string $command     The command to run
 * @param array  $args        The command arguments
 * @param array  $assoc_args  The associative arguments
 * @param array  $global_args The global arguments
 *
 * @return
 */
function runcommand( $command, $args = [], $assoc_args = [], $global_args = [] ) {
	$assoc_args = array_merge( $assoc_args, $global_args );

	$transformed_assoc_args = [];

	foreach ( $assoc_args as $key => $arg ) {
		$transformed_assoc_args[] = '--' . $key . '=' . $arg;
	}
	$params = sprintf( '%s %s', implode( ' ', $args ), implode( ' ', $transformed_assoc_args ) );

	$options = [
		'return'     => 'all',
		'launch'     => false,
		'exit_error' => false,
	];

	return \WP_CLI::runcommand( sprintf( '%s %s', $command, $params ), $options );
}
