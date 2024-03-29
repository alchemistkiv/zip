<?php
/**
 * Created by PhpStorm.
 * User: lucian
 * Date: 3/26/2019
 * Time: 5:05 PM
 */

class tagdiv_theme_plugins_setup {

	protected $page_slug;

	protected $page_url;

	protected $plugin_path = '';

	protected $plugin_url = '';

	protected $tgmpa_instance;

	protected $tgmpa_menu_slug = 'tgmpa-install-plugins';

	protected $tgmpa_url = 'themes.php?page=td_plugins';

	private static $instance = null;

	public static function get_instance() {
		if ( ! self::$instance ) {
			self::$instance = new self;
		}

		return self::$instance;
	}

	public function __construct() {
		$this->init_globals();
		$this->init_actions();
	}

	public function init_globals() {

		$this->page_slug = 'theme-plugins-setup';
		$this->page_url = 'admin.php?page='.$this->page_slug;

		//set relative plugin path url
		$this->plugin_path = trailingslashit( $this->cleanFilePath( dirname( __FILE__ ) ) );
		$relative_url = str_replace( $this->cleanFilePath( TAGDIV_ROOT_DIR ), '', $this->plugin_path );
		$this->plugin_url = trailingslashit( get_template_directory_uri() . $relative_url );
	}

	public function init_actions() {
		if( class_exists( 'TGM_Plugin_Activation' ) && isset( $GLOBALS['tgmpa'] ) ) {
			add_action( 'init', function (){
				$this->tgmpa_instance = call_user_func( array( get_class( $GLOBALS['tgmpa'] ), 'get_instance' ) );
            }, 30 );
			add_action( 'init', function(){

				$this->tgmpa_menu_slug = ( property_exists($this->tgmpa_instance, 'menu' ) ) ? $this->tgmpa_instance->menu : $this->tgmpa_menu_slug;
				$tgmpa_parent_slug = ( property_exists($this->tgmpa_instance, 'parent_slug' ) && $this->tgmpa_instance->parent_slug !== 'themes.php' ) ? 'admin.php' : 'themes.php';
				$this->tgmpa_url = $tgmpa_parent_slug.'?page='.$this->tgmpa_menu_slug;

			}, 40 );
		}
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_filter( 'tgmpa_load', function (){
			return is_admin() || current_user_can( 'install_themes' );
        });
		add_action( 'wp_ajax_theme_plugins_setup', array( $this, 'ajax_plugins' ) );
	}

	public static function cleanFilePath( $path ) {
		$path = str_replace( '', '', str_replace( array( "\\", "\\\\" ), '/', $path ) );
		if ( $path[ strlen( $path ) - 1 ] === '/' ) {
			$path = rtrim( $path, '/' );
		}
		return $path;
	}

	public function enqueue_scripts() {

		wp_enqueue_script(
			'theme-plugins-setup',
			TAGDIV_ROOT . '/includes/wp-booster/wp-admin/js/tagdiv-theme-plugins-setup.js',
			array( 'jquery' ),
			wp_get_theme()->get('Version')
		);

		wp_localize_script(
			'theme-plugins-setup',
			'theme_plugins_setup_params',
			array(
				'tgm_plugin_nonce' => array(
						'update' => wp_create_nonce( 'tgmpa-update' ),
						'install' => wp_create_nonce( 'tgmpa-install' ),
					),
				'tgm_bulk_url' => admin_url( $this->tgmpa_url ),
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'wpnonce' => wp_create_nonce( 'theme_plugins_setup_nonce' ),
				'verify_text' => 'Verifying...',
			)
		);
	}

	private function _get_plugins( $all_plugins = true ) {
	    $instance = call_user_func( array( get_class( $GLOBALS['tgmpa'] ), 'get_instance' ) );
		$plugins = array(
			'all'      => array(), // Meaning: all plugins which still have open actions.
			'install'  => array(),
			'update'   => array(),
			'activate' => array(),
		);
		$theme_plugins = array();

		// get all theme plugins slugs
		foreach( tagdiv_global::$theme_plugins_list as $plugin ) {
			if( isset( $plugin['slug'] ) ) {
				$theme_plugins[] = $plugin['slug'];
			}
		}

		foreach ( $instance->plugins as $slug => $plugin ) {

		    // go to the next if it's not a theme plugin
		    if ( ! in_array( $slug, $theme_plugins ) ) {
		        continue;
            }

			// go further only for plugins set from config to be installed from the welcome panel and if plugin was not deactivated by the theme
			if ( ! $all_plugins && false === $this->theme_deactivated_plugin( $plugin ) && false === $plugin['td_install_in_welcome'] ) {
				continue;
			}

			// skip to the next if this plugin is active and is not outdated
			if ( tagdiv_util::is_active( $plugin ) && false === $instance->does_plugin_have_update( $slug ) && false === $this->theme_plugin_has_update( $slug ) ) {
                continue;
			} else {
				$plugins['all'][ $slug ] = $plugin;

				if ( ! $instance->is_plugin_installed( $slug ) ) {
					$plugins['install'][ $slug ] = $plugin;
				} else {
					if ( false !== $instance->does_plugin_have_update( $slug ) || false !== $this->theme_plugin_has_update( $slug ) ) {
						$plugins['update'][ $slug ] = $plugin;
					}

					if ( $instance->can_plugin_activate( $slug ) ) {
						$plugins['activate'][ $slug ] = $plugin;
					}
				}
			}
		}

		return $plugins;
	}

	public function theme_plugins( $plugins_for_update = null ) {

		tgmpa_load_bulk_installer();

		// install plugins with TGM.
		if ( ! class_exists( 'TGM_Plugin_Activation' ) || ! isset( $GLOBALS['tgmpa'] ) ) {
			die( 'Failed to find TGM' );
		}
		$url = wp_nonce_url( add_query_arg( array( 'plugins' => 'go' ) ), 'theme-plugins-setup' );

		// copied from TGM
		$method = ''; // Leave blank so WP_Filesystem can populate it as necessary.
		$fields = array_keys( $_POST ); // Extra fields to pass to WP_Filesystem.

		if ( false === ( $creds = request_filesystem_credentials( esc_url_raw( $url ), $method, false, false, $fields ) ) ) {
			return true; // Stop the normal page form from displaying, credential request form will be shown.
		}

		// Now we have some credentials, setup WP_Filesystem.
		if ( ! WP_Filesystem( $creds ) ) {
			// Our credentials were no good, ask the user for them again.
			request_filesystem_credentials( esc_url_raw( $url ), $method, true, false, $fields );

			return true;
		}

		/* If we arrive here, we have the filesystem */

        $all_plugins = true;
        if ( ! is_array( $plugins_for_update ) ) {
            $all_plugins = false;
        }

        $plugins = $this->_get_plugins($all_plugins);
        $td_theme = ( defined('TD_COMPOSER') && td_util::get_option('tds_white_label') !== '' )  ? td_util::get_wl_val('tds_wl_theme_name', TD_THEME_NAME) : TD_THEME_NAME;
        $td_brand = ( defined('TD_COMPOSER') && td_util::get_option('tds_white_label') !== '' )  ? td_util::get_wl_val('tds_wl_brand', 'tagDiv') : 'tagDiv';

        if ( count( $plugins['all'] ) ) {

            ?>
            <div class="td-admin-setup-plugins">
                <form class="one-col" method="post">
                    <input type="hidden" id="td_theme_welcome_link" value="<?php echo admin_url( 'admin.php?page=td_theme_welcome' ); ?>">
                    <?php
                    if ( empty( $plugins_for_update ) ) {
                        ?>
                            <h2>1. Install or Update the required <?php echo $td_theme ?> plugins</h2>
                            <p class="about-description">Easily Install and Activate the following  <?php echo $td_brand?> plugins</p>
                        <?php
                    } else {
                        ?>
                            <p class="about-description">Updating all tagDiv plugins...</p>
                        <?php
                    }
                    ?>

                    <ul class="theme-plugins-setup">
                        <?php foreach ( $plugins['all'] as $slug => $plugin ) {

                            if ( is_array( $plugins_for_update ) && ! in_array( $slug, $plugins_for_update )) {
                                continue;
                            }

                            ?>
                            <li data-slug="<?php echo esc_attr( $slug );?>">
                                <div class="themes-plugin-txt"><?php echo esc_html( $plugin['name'] );?></div>
                                <div class="themes-plugin-status">
                                    <div class="themes-plugin-status-txt">
                                        <?php
                                            if ( isset( $plugins['install'][ $slug ] ) ) { echo 'Not installed'; }
                                            if ( isset( $plugins['update'][ $slug ] ) ) {
                                                if ( isset( $plugins['activate'][ $slug ] ) ) { echo 'Outdated <span> / </span>'; } else { echo 'Outdated';  }
                                            }
                                            if ( isset( $plugins['activate'][ $slug ] ) ) { echo 'Inactive'; }
                                        ?>
                                    </div>
                                    <div class="spinner"></div>
                                </div>
                            </li>
                        <?php } ?>
                    </ul>
                    <div class="td-button-install-wrap" <?php echo( empty( $plugins_for_update ) ? '': 'style="visibility: hidden"') ?>>
                        <a class="td-wp-admin-button td-button-install-plugins" href="#" data-callback="install_plugins">Install / Activate plugins</a>
                    </div>
                </form>
            </div>

            <?php
        }

        ?>
        <div class="theme-plugins-installed" <?php if ( count( $plugins['all'] ) ) echo 'style="display:none"' ?>>
            <h2>1. <?php echo $td_brand ?> Plugins are now Successfully Installed</h2>
            <p class="about-description">Done! You have Installed all the Required Plugins</p>
            <svg class="td-wp-admin-ok-svg" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><path fill="#6dc25f" d="M504 256c0 136.967-111.033 248-248 248S8 392.967 8 256 119.033 8 256 8s248 111.033 248 248zM227.314 387.314l184-184c6.248-6.248 6.248-16.379 0-22.627l-22.627-22.627c-6.248-6.249-16.379-6.249-22.628 0L216 308.118l-70.059-70.059c-6.248-6.248-16.379-6.248-22.628 0l-22.627 22.627c-6.248 6.248-6.248 16.379 0 22.627l104 104c6.249 6.249 16.379 6.249 22.628.001z"></path></svg>
        </div>

        <?php

        wp_nonce_field( 'theme-plugins-setup' );

		return '';
	}

	public function ajax_plugins() {

		if ( ! check_ajax_referer( 'theme_plugins_setup_nonce', 'wpnonce' ) || empty( $_POST['slug'] ) ) {
			wp_send_json_error( array( 'error' => 1, 'message' => 'No Slug Found' ) );
		}

		$json = array();

		// send back some json we use to hit up TGM
		$plugins = $this->_get_plugins();

		$post_slug = sanitize_text_field( $_POST['slug'] );

		// what are we doing with this plugin?
		foreach ( $plugins['activate'] as $slug => $plugin ) {
			if ( $post_slug == $slug ) {
				$json = array(
					'url' => admin_url( $this->tgmpa_url ),
					'plugin' => array( $slug ),
					'tgmpa-page' => $this->tgmpa_menu_slug,
					'plugin_status' => 'all',
					'_wpnonce' => wp_create_nonce( 'bulk-plugins' ),
					'action' => 'tgmpa-bulk-activate',
					'action2' => -1,
					'message' => 'Activating Plugin',
				);
				break;
			}
		}
		foreach ( $plugins['update'] as $slug => $plugin ) {
			if ( $post_slug == $slug ) {
				$json = array(
					'url' => admin_url( $this->tgmpa_url ),
					'plugin' => array( $slug ),
					'tgmpa-page' => $this->tgmpa_menu_slug,
					'plugin_status' => 'all',
					'_wpnonce' => wp_create_nonce( 'bulk-plugins' ),
					'action' => 'tgmpa-bulk-update',
					'action2' => -1,
					'message' => 'Updating Plugin',
				);
				break;
			}
		}
		foreach ( $plugins['install'] as $slug => $plugin ) {
			if ( $post_slug == $slug ) {
				$json = array(
					'url' => admin_url( $this->tgmpa_url ),
					'plugin' => array( $slug ),
					'tgmpa-page' => $this->tgmpa_menu_slug,
					'plugin_status' => 'all',
					'_wpnonce' => wp_create_nonce( 'bulk-plugins' ),
					'action' => 'tgmpa-bulk-install',
					'action2' => -1,
					'message' => 'Installing Plugin',
				);
				break;
			}
		}

		if ( $json ) {
			$json['hash'] = md5( serialize( $json ) ); // used for checking if duplicates happen, move to next plugin
			wp_send_json($json);
		} else {
			wp_send_json(
                array(
                    //'$plugins' => $plugins,
                    //'$post_slug' => $post_slug,
                    'done' => 1,
                    'message' => 'Success'
                )
            );
		}
		exit;

	}

	public function theme_plugin_has_update( $slug ) {

        //there are issues with ABSPATH on wp.com servers, so we use WP_PLUGIN_DIR check $file_data bellow
//		$plugins_path = ABSPATH . 'wp-content/plugins';
		$plugin = $slug . '/' . $slug . '.php';

	    $has_update = false;

		foreach ( tagdiv_global::get_td_plugins() as $constant => $settings ) {

			$plugin_name = strtolower( str_replace('_', '-', $constant) );

			if ( $plugin_name !== $slug ) {
			    continue;
            }

            //read plugin file
            global $wp_filesystem;
            //there are issues with ABSPATH on wp.com servers, so we use WP_PLUGIN_DIR
            $file_data = $wp_filesystem->get_contents( WP_PLUGIN_DIR . '/' . $plugin );

            preg_match('/define\s*\(\s*\'' . $constant . '\'\s*\,\s*\'(.*)\'\s*\)/', $file_data, $matches);

			if ( ! isset( $matches[1] ) || $matches[1] !== $settings['version'] ) {
				$has_update = true;
			}
        }

		return $has_update;
	}

	public function theme_deactivated_plugin( $plugin ) {

		$theme_deactivated_plugin_array = tagdiv_options::get_array( 'td_theme_deactivated_plugins' );

		// if the plugin is set in the deactivated plugins list
		if ( isset( $theme_deactivated_plugin_array[$plugin['slug']] ) ) {

			// if the plugin has been updated remove it from theme deactivated plugins list
			if ( false === $this->theme_plugin_has_update( $plugin['slug'] ) && tagdiv_util::is_active( $plugin ) ) {
				unset( $theme_deactivated_plugin_array[$plugin['slug']] );
				tagdiv_options::update_array('td_theme_deactivated_plugins', $theme_deactivated_plugin_array );
			} else {
				return true;
			}
		}

		return false;

	}

}
