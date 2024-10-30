<?php
/**
 * Main class of CSM Bulk User Management
 *
 * @package csm-bulk-user-management
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Main class of CSM Bulk User Management
 */
class CSM_Bulk_User_Management {

	/**
	 * Undocumented function
	 */
	public function __construct() {
		register_activation_hook( __FILE__, array( $this, 'db_install' ) );

		add_action( 'init', array( $this, 'send_csv_download' ) );
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'add_settings' ) );
	}

	/**
	 * Add menu item to WordPress admin dashboard
	 *
	 * @return void
	 */
	public function add_menu() {
		global $menu;

		add_users_page( __( 'Bulk User Management', 'csm_membership' ), __( 'Bulk User Management', 'csm_membership' ), 'edit_users', 'csm-bulk-user-management', array( $this, 'admin_import' ) );
		add_options_page( __( 'Bulk User Options', 'csm_membership' ), __( 'Bulk User Options', 'csm_membership' ), 'manage_options', 'csm-users-opts', array( $this, 'user_settings_page' ) );
	}

	/**
	 * Add settings pages
	 *
	 * @return void
	 */
	public function add_settings() {
		$welcome_msg = $this->get_default_welcome_msg();

		add_settings_section(
			'csm_users', 'Bulk User Import User Options', function() {
				echo '<p>Customise the message sent to new users added by the CSV import.</p>';
			}, 'csm_users_opts'
		);
		add_settings_field(
			'csm_users_welcome', 'Welcome Message', function() {
				global $welcome_msg;
				wp_editor( get_option( 'csm_users_welcome', $welcome_msg ), 'csm_users_welcome' );
			}, 'csm_users_opts', 'csm_users'
		);

		register_setting( 'csm_users', 'csm_users_welcome' );
	}

	/**
	 * Set the default welcome message
	 *
	 * @return string
	 */
	private function get_default_welcome_msg() {
		$default  = "Hello (username),\n\n";
		$default .= "Thank you for joining <a href='(siteurl)'>(sitename)</a>. Your account details are as follows:\n\n";
		$default .= "Username: (username)\n";
		$default .= "Password: (password)\n\n";
		$default .= "Regards,\n";
		$default .= '(sitename)';

		return $default;
	}

	/**
	 * Settings page to configure the default welcome email
	 *
	 * @return void
	 */
	public function user_settings_page() {
		?>
			<div class="wrap">
				<div id="icon-options-general" class="icon32"></div>
				<h1><?php esc_html_e( 'User Options', 'csm_membership' ); ?></h1>
				<form method="post" action="options.php">
				<?php

					// add_settings_section callback is displayed here. For every new section we need to call settings_fields.
					settings_fields( 'csm_users' );

					// all the add_settings_field callbacks is displayed here.
					do_settings_sections( 'csm_users_opts' );

					// Add the submit button to serialize the options.
					submit_button();

				?>
			</form>
		</div>
		<?php
	}

	/**
	 * The main plugin page!
	 *
	 * @return void
	 */
	public function admin_import() {
		global $wpdb;

		$cur_page = 'csm-bulk-user-management';
		if ( isset( $_GET['page'] ) ) {
			$cur_page = sanitize_text_field( wp_unslash( $_GET['page'] ) ); // WPCS: CSRF ok.
		}

		if ( ( isset( $_SERVER['REQUEST_METHOD'] ) && 'GET' === $_SERVER['REQUEST_METHOD'] ) && isset( $_GET['send_emails'] ) ) {
			ob_start();
			ob_clean();

			check_admin_referer( 'csm_send_emails' );

			$emails = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'csm_bulk_emails LIMIT 0,%d', absint( $_GET['send_emails'] ) ) );
			foreach ( $emails as $email ) {
				if ( wp_mail( $email->email_address, 'Welcome to ' . get_bloginfo( 'name' ), $email->email_content, array( 'Content-Type: text/html; charset=UTF-8' ) ) ) {
					$wpdb->delete( $wpdb->prefix . 'csm_bulk_emails', array( 'ID' => $email->ID ) );
					echo '<p>Email to ' . esc_html( $email->email_address ) . ' sent successfully.</p>';
					ob_flush();
				} else {
					echo '<p>Emailing failed to ' . esc_html( $email->email_address ) . '.</p>';
					ob_flush();
				}
			}

			$email_count = $wpdb->get_row( 'SELECT COUNT(ID) as the_count FROM ' . $wpdb->prefix . 'csm_bulk_emails LIMIT 1' );
			if ( $email_count->the_count >= 1 ) {

				$email_url = 'admin.php?page=csm_bulk_user_management&amp;send_emails=' . absint( $_GET['send_emails'] );
				$email_url = wp_nonce_url( $email_url, 'csm_send_emails' );
				echo '<script type="text/javascript">
					location.href = "' . esc_url( $email_url ) . '";
					location.reload();</script>';
				echo '<p>If this page did not automatically refresh, please click <a href="' . esc_url( $email_url ) . '">here</a>.</p>';
			} else {
				echo '<p>All welcome emails have been sent.</p>';
			}

			ob_flush();
			exit();
		}

		echo "<div class='wrap'>\n";
		echo "\t\t<h1 class='wp-heading-inline'>" . esc_html__( 'Bulk User Management', 'csm_membership' ) . "</h1>\n\n";

		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] ) {

			if ( isset( $_POST['action'] ) && 'Import Users' === $_POST['action'] ) {

				check_admin_referer( 'csm_bulk_user' );

				if ( ! current_user_can( 'create_users' ) || ! current_user_can( 'edit_users' ) ) {
					echo "<div class='notice notice-error is-dismissible'><p>" . esc_html__( 'Your account role does not have the capability to create and update users.', 'csm_membership' ) . '</p></div>';
				} else {

					if ( ! isset( $_FILES['csv_file'] ) ) {

						echo "<div class='notice error is-dismissable'><p>" . esc_html__( 'You must upload a CSV file.', 'csm_membership' ) . '</p></div>';

					} else {

						$users = file( $_FILES['csv_file']['tmp_name'] ); // WPCS: sanitization ok.

						$skip_existing = false;
						$send_welcome  = false;

						$new_count    = 0;
						$update_count = 0;

						if ( isset( $_POST['has_headers'] ) && '1' === $_POST['has_headers'] ) {
							unset( $users[0] );
						}

						if ( isset( $_POST['skip_exsiting'] ) && '1' === $_POST['skip_exsiting'] ) {
							$skip_existing = true;
						}

						if ( isset( $_POST['send_welcome'] ) && '1' === $_POST['send_welcome'] ) {
							$send_welcome = true;
						}

						$column_username  = ( isset( $_POST['column_username'] ) && '1' !== $_POST['column_username'] && '0' !== $_POST['column_username'] ) ? intval( $_POST['column_username'] ) - 1 : 0;
						$column_email     = ( isset( $_POST['column_email'] ) && '1' !== $_POST['column_email'] && '0' !== $_POST['column_email'] ) ? intval( $_POST['column_email'] ) - 1 : 1;
						$column_firstname = ( isset( $_POST['column_firstname'] ) && '0' !== $_POST['column_firstname'] ) ? intval( $_POST['column_firstname'] ) - 1 : 0;
						$column_lastname  = ( isset( $_POST['column_lastname'] ) && '0' !== $_POST['column_lastname'] ) ? intval( $_POST['column_lastname'] ) - 1 : 0;
						$column_website   = ( isset( $_POST['column_website'] ) && '0' !== $_POST['column_website'] ) ? intval( $_POST['column_website'] ) - 1 : 0;
						$column_plan      = ( isset( $_POST['column_plan'] ) && '0' !== $_POST['column_plan'] ) ? intval( $_POST['column_plan'] ) - 1 : 0;

						$default_plan = ( isset( $_POST['role'] ) && null !== get_role( sanitize_text_field( wp_unslash( $_POST['role'] ) ) ) ) ? sanitize_text_field( wp_unslash( $_POST['role'] ) ) : get_option( 'default_role' );

						$welcome_msg = get_option( 'csm_users_welcome', $this->get_default_welcome_msg() );

						$user_id = 0;
						foreach ( $users as $line ) {
							$update_user_required = false;

							$user = explode( ',', $line );
							$user = array_map( 'trim', $user );

							$existing_username = username_exists( $user[ $column_username ] );
							$existing_email    = email_exists( $user[ $column_email ] );

							if ( false !== $existing_username || false !== $existing_email ) {
								if ( false !== $existing_username ) {
									$user_id = $existing_username;
								}

								if ( false !== $existing_email ) {
									$user_id = $existing_email;
								}

								$cur_user = get_userdata( $user_id );

								if ( false === $skip_existing ) {
									// this user already exists, update existing.
									$user_data       = array();
									$user_data['ID'] = $user_id;

									if ( $user[ $column_email ] != $cur_user->user_email ) {
										$update_user_required    = true;
										$user_data['user_email'] = $user[ $column_email ];
									}

									if ( 0 !== $column_firstname ) {
										if ( $cur_user->first_name != $user[ $column_firstname ] ) {
											$update_user_required    = true;
											$user_data['first_name'] = $user[ $column_firstname ];
										}
									}

									if ( 0 !== $column_lastname ) {
										if ( $cur_user->last_name !== $user[ $column_lastname ] ) {
											$update_user_required   = true;
											$user_data['last_name'] = $user[ $column_lastname ];
										}
									}

									if ( 0 !== $column_website ) {
										if ( $cur_user->user_url !== $user[ $column_website ] ) {
											$update_user_required  = true;
											$user_data['user_url'] = $user[ $column_website ];
										}
									}

									$role = null;
									if ( 0 !== $column_plan ) {
										$role = get_role( strtolower( $user[ $column_plan ] ) );
									}

									// let's not demote admins.
									if ( ! user_can( $user_id, 'administrator' ) && ! user_can( $user_id, 'super_admin' ) ) {
										if ( null === $role ) {
											if ( ! in_array( $default_plan, $cur_user->roles, true ) ) {
												$update_user_required = true;
												$user_data['role']    = $default_plan;
											}
										} else {
											if ( ! in_array( strtolower( $user[ $column_plan ] ), $cur_user->roles, true ) ) {
												$update_user_required = true;
												$user_data['role']    = strtolower( $user[ $column_plan ] );
											}
										}
									}
								}

								if ( true === $update_user_required ) {
									++$update_count;
									wp_update_user( $user_data );
								}

								unset( $user_data );
								unset( $user_id );
								unset( $cur_user );

							} else {

								// new user, create account.
								// an email is the absolute minimum.
								if ( trim( $user[ $column_email ] ) !== '' ) {

									// determine a username if username is blank.
									if ( trim( $user[ $column_username ] ) == '' ) {

										// let's decide on a username from the data we have.
										if ( 0 !== $column_firstname && '' !== $user[ $column_firstname ] ) {
											$user[ $column_username ] = $user[ $column_firstname ];
										}

										if ( 0 !== $column_lastname && '' !== $user[ $column_lastname ] ) {
											if ( 0 !== $column_firstname && '' !== $user[ $column_firstname ] ) {
												$user[ $column_username ] .= '_';
											}
											$user[ $column_username ] .= $user[ $column_lastname ];
										}

										// last resort, use the email address to determine the username.
										if ( trim( $user[ $column_username ] ) === '' ) {

											$email_split = explode( '@', $user[ $column_username ] );

											$user[ $column_username ] = $email_split[0];

										}

										// if the generated username exists, add the current year.
										if ( username_exists( $user[ $column_username ] ) ) {
											$user[ $column_username ] = $user[ $column_username ] . date( 'Y' );
										}
									}

									$random_password = wp_generate_password( 12, false );
									$user_id         = wp_create_user( $user[ $column_username ], $random_password, $user[ $column_email ] );
									if ( ! is_wp_error( $user_id ) ) {
										$cur_user = get_userdata( $user_id );
									}

									++$new_count;

									$user_data       = array();
									$user_data['ID'] = $user_id;

									if ( 0 !== $column_firstname ) {
										if ( $cur_user->first_name !== $user[ $column_firstname ] ) {
											$user_data['first_name'] = $user[ $column_firstname ];
										}
									}

									if ( 0 !== $column_lastname ) {
										if ( $cur_user->last_name !== $user[ $column_lastname ] ) {
											$user_data['last_name'] = $user[ $column_lastname ];
										}
									}

									if ( 0 !== $column_website ) {
										if ( $cur_user->user_url !== $user[ $column_website ] ) {
											$user_data['user_url'] = $user[ $column_website ];
										}
									}

									if ( 0 !== $column_plan ) {
										$role = get_role( strtolower( $user[ $column_plan ] ) );
									} else {
										$role = null;
									}

									if ( null === $role ) {
										$user_data['role'] = $default_plan;
									} else {
										$user_data['role'] = strtolower( $user[ $column_plan ] );
									}

									wp_update_user( $user_data );

									if ( true === $send_welcome ) {
										$to = $user[ $column_email ];

										$welcome_email = str_replace( '(sitename)', get_bloginfo( 'name' ), $welcome_msg );
										$welcome_email = str_replace( '(siteurl)', get_bloginfo( 'url' ), $welcome_email );
										$welcome_email = str_replace( '(password)', $random_password, $welcome_email );
										$welcome_email = str_replace( '(username)', $user[ $column_username ], $welcome_email );
										$welcome_email = str_replace( '(email)', $user[ $column_email ], $welcome_email );
										$welcome_email = str_replace( '(firstname)', $user[ $column_firstname ], $welcome_email );
										$welcome_email = str_replace( '(lastname)', $user[ $column_lastname ], $welcome_email );
										$welcome_email = wpautop( $welcome_email, true );

										$data       = array(
											'user_id' => $user_id,
											'email_address' => $user[ $column_email ],
											'email_content' => $welcome_email,
										);
										$table_name = $wpdb->prefix . 'csm_bulk_emails';
										$wpdb->insert( $table_name, $data, array( '%d', '%s', '%s' ) );
									}

									unset( $user_id );
									unset( $user_data );

								}
							}
						}

						if ( true === $send_welcome ) {

							if ( ! isset( $_POST['max_send'] ) ) {
								$_POST['max_send'] = 20;
							}

							$email_url = 'admin.php?page=' . esc_attr( $cur_page ) . '&amp;send_emails=' . absint( $_POST['max_send'] );
							$email_url = wp_nonce_url( $email_url, 'csm_send_emails' );
							echo '<p>' . esc_html__( "We are currently sending welcome emails to the users that were just imported. Please don't leave this page until the below indicates the emailing is complete. This may take a long time.", 'csm_membership' ) . '</p>';
							echo '<iframe src="' . esc_url( $email_url ) . '" title="Sending emails" id="sending_emails" width="400" height="300">Frames are not supported in this browser. Please click <a href="users.php?page=csm_bulk_user_management&amp;send_emails=' . absint( $_POST['max_send'] ) . '" target="_blank">here</a> to send welcome emails.</iframe>';

							return;

						} else {
							// translators: The first placeholder is the number of users created and the second is the number of existing user accounts updated.
							echo "<div class='notice notice-success is-dismissible'><p>" . sprintf( esc_html__( 'The users have been imported successfully. %1$d users created and %2$d users were updated.', 'csm_membership' ), intval( $new_count ), intval( $update_count ) ) . '</p></div>';

						}
					}
				}
			}

			if ( isset( $_POST['action'] ) && 'Delete Users' === $_POST['action'] ) {

				check_admin_referer( 'csm_bulk_user_del' );

				if ( ! current_user_can( 'delete_users' ) ) {
					echo "<div class='notice notice-error is-dismissible'><p>" . esc_html__( 'Your account role does not have the capability to delete users.', 'csm_membership' ) . '</p></div>';
				}

				if ( isset( $_FILES['csv_file'] ) ) {

					$column_username = ( isset( $_POST['column_username'] ) && 1 !== $_POST['column_username'] && 0 !== $_POST['column_email'] ) ? intval( $_POST['column_username'] ) - 1 : 0;
					$column_email    = ( isset( $_POST['column_email'] ) && 1 !== $_POST['column_email'] && 0 !== $_POST['column_email'] ) ? intval( $_POST['column_email'] ) - 1 : 1;

					$file = file( wp_unslash( $_FILES['csv_file']['tmp_name'] ) ); // WPCS: sanitization ok.

					echo "<form method='post' action='admin.php?page=" . esc_attr( $cur_page ) . "'>";
					echo "<div class='card'>";
					echo "<h2 class='title'>" . esc_html__( 'Confirm User Deletion', 'csm_membership' ) . '</h2>';

					if ( isset( $_POST['has_headers'] ) && '1' === $_POST['has_headers'] ) {
						unset( $file[0] );
					}

					if ( count( $file ) >= 1 ) {

						echo '<p>' . esc_html__( 'Are you sure you want to delete the following users? If you have changed your mind for any of these users, uncheck the box:', 'csm_membership' ) . '</p>';
						echo '<ul>';

						foreach ( $file as $row ) {
							$user = explode( ',', $row );

							$cur_user = get_user_by( 'email', $user[ $column_email ] );

							// let's not delete admins.
							if ( false === user_can( $cur_user, 'administrator' ) && false === user_can( $cur_user, 'super_admin' ) ) {
								if ( false !== $cur_user ) {
									echo "<li><label><input type='checkbox' name='user[]' value='" . absint( $cur_user->ID ) . "' checked='checked'> ID #" . absint( $cur_user->ID ) . ': ' . esc_html( $cur_user->user_login ) . ' &lt;' . esc_html( $cur_user->user_email ) . '&gt;</label></li>';
								}
							}
						}

						echo '</ul>';

						echo "<p><input type='submit' name='action' value='Confirm Delete Users' class='button button-primary'></p>";

						echo '</div>';

						echo '</form>';

						return;

					} else {

						echo "<div class='notice notice-error is-dismissible'><p>" . esc_html__( 'The provided CSV has no valid users to delete.', 'csm_membership' ) . '</p></div>';

					}
				} else {

					echo "<div class='notice notice-error is-dismissible'><p>" . esc_html__( 'You must upload a file containing users to delete.', 'csm_membership' ) . '</p></div>';

				}
			}

			if ( isset( $_POST['action'] ) && 'Confirm Delete Users' === $_POST['action'] ) {

				$delete_count = 0;

				if ( isset( $_POST['user'] ) && is_array( $_POST['user'] ) && count( $_POST['user'] ) >= 1 ) { // WPCS: sanitization ok.

					foreach ( $_POST['user'] as $user_id ) { // WPCS: sanitization ok.
						$user_id = absint( $user_id );

						// some final protection against deleting admins.
						if ( false === user_can( $user_id, 'administrator' ) && false === user_can( $user_id, 'super_admin' ) ) {

							$delete = wp_delete_user( $user_id );

							if ( true === $delete ) {
								++$delete_count;
							}
						}
					}
					// translators: first placeholder is number of users successfully deleted.
					echo '<div class="notice notice-success is-dismissible"><p>' . sprintf( esc_html__( '%d users have been deleted.', 'csm_membership' ), intval( $delete_count ) ) . '</p></div>';

				} else {

					echo "<div class='notice notice-error is-dismissible'><p>" . esc_html__( 'No users were specified to delete.', 'csm_membership' ) . '</p></div>';

				}
			}
		}

		if ( current_user_can( 'create_users' ) && current_user_can( 'edit_users' ) ) {
			echo "\t\t<div class='card'>\n";
			echo "\t\t\t<form method='post' action='admin.php?page=" . esc_attr( $cur_page ) . "' enctype='multipart/form-data'>\n"; // WPCS: sanitization ok.
			echo "\t\t\t\t<h2 class='title'>" . esc_html__( 'Import Users', 'csm_membership' ) . "</h2>\n";
			// translators: % is replaced with CSV.
			echo "\t\t\t\t<p><label for='csv_file'>" . sprintf( esc_html__( 'Please select the %s file containing your new users using the file picker below. Existing users will be updated unless disabled below in which case they will be skipped.', 'csm_membership' ), "<abbr title='Comma Separated Value'>CSV</abbr>" ) . "</label></p>\n";
			echo "\t\t\t\t<p><input type='file' name='csv_file' id='csv_file' accept='.csv, .txt, text/csv, text/plain' required='required' /></p>\n";
			echo "\t\t\t\t<p><label><input type='number' size='1' value='" . ( isset( $_POST['column_username'] ) ? intval( $_POST['column_username'] ) : 1 ) . "' min='1' name='column_username' required='required' style='width: 60px;'> " . esc_html__( 'Column number containing Username', 'csm_membership' ) . "</label></p>\n";
			echo "\t\t\t\t<p><label><input type='number' size='1' value='" . ( isset( $_POST['column_email'] ) ? intval( $_POST['column_email'] ) : 2 ) . "' min='1' name='column_email' required='required' style='width: 60px;'> " . esc_html__( 'Column number containing Email address', 'csm_membership' ) . "</label></p>\n";
			echo "\t\t\t\t<p><label><input type='checkbox' name='has_headers' value='1' " . checked( 1, ( isset( $_POST['has_headers'] ) ? intval( $_POST['has_headers'] ) : 1 ), false ) . ' /> ' . esc_html__( 'My CSV file has headers (skips the first row)', 'csm_membership' ) . "</label></p>\n";
			echo "\t\t\t\t<p>The following fields are optional. Leave blank or 0 fields that your CSV does not contain.</p>\n";
			echo "\t\t\t\t<p><label><input type='checkbox' name='skip_existing' value='1' " . checked( 1, ( isset( $_POST['skip_existing'] ) ? intval( $_POST['skip_existing'] ) : 0 ), false ) . ' /> ' . esc_html__( 'Disable existing user update (skips existing users)', 'csm_membership' ) . "</label></p>\n";
			echo "\t\t\t\t<p><label><input type='checkbox' name='send_welcome' value='1' " . checked( 1, ( isset( $_POST['send_welcome'] ) ? intval( $_POST['send_welcome'] ) : 0 ), false ) . ' /> ' . esc_html__( 'Send WordPress new user welcome email with password', 'csm_membership' ) . "</label></p>\n";
			echo "\t\t\t\t<p><label><input type='number' size='1' min='0' name='column_firstname' value='" . ( isset( $_POST['column_firstname'] ) ? intval( $_POST['column_firstname'] ) : 0 ) . "' style='width: 60px;'> " . esc_html__( 'Column number containing First Name', 'csm_membership' ) . "</label></p>\n";
			echo "\t\t\t\t<p><label><input type='number' size='1' min='0' name='column_lastname' value='" . ( isset( $_POST['column_lastname'] ) ? intval( $_POST['column_lastname'] ) : 0 ) . "' style='width: 60px;'> " . esc_html__( 'Column number containing Last Name', 'csm_membership' ) . "</label></p>\n";
			echo "\t\t\t\t<p><label><input type='number' size='1' min='0' name='column_website' value='" . ( isset( $_POST['column_website'] ) ? intval( $_POST['column_website'] ) : 0 ) . "' style='width: 60px;'> " . esc_html__( 'Column number containing Website', 'csm_membership' ) . "</label></p>\n";
			echo "\t\t\t\t<p><label><input type='number' size='1' min='0' name='column_plan' value='" . ( isset( $_POST['column_plan'] ) ? intval( $_POST['column_plan'] ) : 0 ) . "' style='width: 60px;'> " . esc_html__( 'Column number containing Role', 'csm_membership' ) . "</label></p>\n";
			echo "\t\t\t\t<p><label><select name='role'>";
			wp_dropdown_roles( ( isset( $_POST['role'] ) ? sanitize_text_field( wp_unslash( $_POST['role'] ) ) : get_option( 'default_role' ) ) );
			echo '</select> ' . esc_html__( 'If plan not specified by above field or invalid, assign this role.', 'csm_membership' ) . "</label></p>\n";
			echo "\t\t\t\t<p><label><input type='number' size='1' value='25' min='0' name='max_send' value='" . ( isset( $_POST['max_send'] ) ? intval( $_POST['max_send'] ) : 20 ) . "' style='width: 60px;'> " . esc_html__( 'Maximum number of welcome emails to send at once', 'csm_membership' ) . "</label></p>\n";
			echo "\t\t\t\t<p><input type='submit' name='action' value='Import Users' class='button button-primary' /></p>\n";
			wp_nonce_field( 'csm_bulk_user' );
			echo "\t\t\t</form>\n";
			echo "\t\t</div>\n";
		}
		if ( current_user_can( 'export' ) ) {
			echo "\t\t<form method='post' action='admin.php?page=" . esc_attr( $cur_page ) . "'>\n";
			echo "\t\t\t<div class='card'>\n";
			echo "\t\t\t<h2 class='title'>" . esc_html__( 'Export Users', 'csm_membership' ) . "</h2>\n";
			echo "\t\t\t<p><label><select name='role'><option value='-1'>All Users</option>";
			wp_dropdown_roles();
			echo "</select></label></p>\n";
			echo "\t\t\t<p><input type='submit' name='action' value='Download Users (CSV)' class='button button-primary' /></p>\n";
			echo "\t\t\t</div>\n";
			wp_nonce_field( 'csm_bulk_dl_cvs' );
			echo "\t\t</form>\n";
		}
		if ( current_user_can( 'delete_users' ) ) {
			echo "\t\t<div class='card'>\n";
			echo "\t\t\t<form method='post' action='admin.php?page=" . esc_attr( wp_unslash( $cur_page ) ) . "' enctype='multipart/form-data'>\n";
			echo "\t\t\t\t<h2 class='title'>" . esc_html__( 'Delete Users', 'csm_membership' ) . "</h2>\n";
			// translators: %s is replaced with CSV.
			echo "\t\t\t\t<p><label for='csv_file'>" . sprintf( esc_html__( 'Please select the %s file containing the users to delete using the file picker below.', 'csm_membership' ), '<abbr title="Comma Separated Value">CSV</abbr>' ) . "</label></p>\n";
			echo "\t\t\t\t<p><input type='file' name='csv_file' id='csv_file' accept='.csv, .txt, text/csv, text/plain' required='required' /></p>\n";
			echo "\t\t\t\t<p><label><input type='number' size='1' value='" . ( isset( $_POST['column_username'] ) ? intval( $_POST['column_username'] ) : 1 ) . "' min='1' name='column_username' required='required' style='width: 60px;'> " . esc_html__( 'Column number containing Username', 'csm_membership' ) . "</label></p>\n";
			echo "\t\t\t\t<p><label><input type='number' size='1' value='" . ( isset( $_POST['column_email'] ) ? intval( $_POST['column_email'] ) : 2 ) . "' min='1' name='column_email' required='required' style='width: 60px;'> " . esc_html__( 'Column number containing Email address', 'csm_membership' ) . "</label></p>\n";
			echo "\t\t\t\t<p><label><input type='checkbox' name='has_headers' value='1' " . checked( 1, ( isset( $_POST['has_headers'] ) ? intval( $_POST['has_headers'] ) : 1 ), false ) . ' /> ' . esc_html__( 'My CSV file has headers (skips the first row)', 'csm_membership' ) . "</label></p>\n";
			echo "\t\t\t\t<p><input type='submit' name='action' value='Delete Users' class='button button-primary' /></p>\n";
			wp_nonce_field( 'csm_bulk_user_del' );
			echo "\t\t\t</form>\n";
			echo "\t\t</div>\n";
		}
		echo "\t</div>\n\n";

	}

	/**
	 * Collect the users requested and return a CSV file to download
	 *
	 * @return void
	 */
	public function send_csv_download() {

		if ( isset( $_POST['action'] ) ) {

			if ( 'Download Users (CSV)' === $_POST['action'] ) { // WPCS: CSRF ok.

				check_admin_referer( 'csm_bulk_dl_cvs' );

				if ( ! current_user_can( 'export' ) ) {
					http_response_code( 403 );
					die( 'You cannot access this page.' );
				}

				$args           = array();
				$args['fields'] = 'all_with_meta';

				if ( isset( $_POST['role'] ) && '-1' !== $_POST['role'] ) {
					$args['role__in'] = array( sanitize_key( wp_unslash( $_POST['role'] ) ) );
				}

				$rows = array();

				$users = get_users( $args );

				$fields                 = array();
				$fields['user_name']    = 'Username';
				$fields['user_email']   = 'Email Address';
				$fields['first_name']   = 'First Name';
				$fields['last_name']    = 'Last Name';
				$fields['display_name'] = 'Display Name';
				$fields['user_url']     = 'Website';
				$fields['role']         = 'Role';
				$fields                 = apply_filters( 'csm_user_export_fields', $fields );

				foreach ( $users as $user ) {
					$rows[ $user->ID ]['user_name']    = $user->user_login;
					$rows[ $user->ID ]['user_email']   = $user->user_email;
					$rows[ $user->ID ]['first_name']   = $user->first_name;
					$rows[ $user->ID ]['last_name']    = $user->last_name;
					$rows[ $user->ID ]['display_name'] = $user->user_nicename;
					$rows[ $user->ID ]['user_url']     = $user->user_url;
					$rows[ $user->ID ]['role']         = implode( '|', $user->roles );
					$rows[ $user->ID ]                 = apply_filters( 'csm_user_export_data', $rows[ $user->ID ], $user->ID );
				}

				$file = implode( ',', $fields ) . "\n";
				foreach ( $rows as $row ) {
					$file .= implode( ',', $row ) . "\n";
				}

				ob_start();
				header( 'Content-Type: text/csv' );

				// Use Content-Disposition: attachment to specify the filename.
				header( 'Content-Disposition: attachment; filename=users.csv' );

				// No cache.
				header( 'Expires: 0' );
				header( 'Cache-Control: must-revalidate' );
				header( 'Pragma: public' );

				ob_clean();
				flush();

				echo esc_html( $file );

				exit();

			}
		}
	}

	/**
	 * Create the table required by the plugin to stagger out the welcome emails
	 *
	 * @return void
	 */
	public function db_install() {
		global $wpdb;

		$table_name = $wpdb->prefix . 'csm_bulk_email';

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
		ID int(11) NOT NULL AUTO_INCREMENT,
		user_id int(11) NOT NULL,
		email_address varchar(254) NOT NULL,
		email_content text NOT NULL,
		PRIMARY KEY  (ID)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		add_option( 'csm_bulk_db_version', '0.1' );
	}

}
