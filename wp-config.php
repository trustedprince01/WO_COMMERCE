<?php
/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the installation.
 * You don't have to use the web site, you can copy this file to "wp-config.php"
 * and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * Database settings
 * * Secret keys
 * * Database table prefix
 * * Localized language
 * * ABSPATH
 *
 * @link https://wordpress.org/support/article/editing-wp-config-php/
 *
 * @package WordPress
 */

// ** Database settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define( 'DB_NAME', 'wp_dn2mf' );

/** Database username */
define( 'DB_USER', 'wp_wq6tu' );

/** Database password */
define( 'DB_PASSWORD', '&eABo5tGpK8&7dkc' );

/** Database hostname */
define( 'DB_HOST', 'localhost:3306' );

/** Database charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8' );

/** The database collate type. Don't change this if in doubt. */
define( 'DB_COLLATE', '' );

/**#@+
 * Authentication unique keys and salts.
 *
 * Change these to different unique phrases! You can generate these using
 * the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}.
 *
 * You can change these at any point in time to invalidate all existing cookies.
 * This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define('AUTH_KEY', '1sNWkUg]DE|KtXCaI)#zW26*MsC~7t7ztL81tj87~t@~loL+Um(5i)2f-;8nG&65');
define('SECURE_AUTH_KEY', '646Hv03*97ZO0BK6yP8rz&w)#W2*A)x2C2YX;#P8r|gScj)B36|;84xnyA+@L6DB');
define('LOGGED_IN_KEY', 'e]0PD109_LnA_S7T%tg0Jn|m70%3V7WHp]H35k~-12*5+7;/9p[VQZdC&E(1V3_-');
define('NONCE_KEY', 'PPl005qv02!6-aK~b~]F]+]x[tf6)H13a;FtMDO6:Y~0i3@*xxBeD|h[RkJ9DwG*');
define('AUTH_SALT', 'g;xtp:PqKOjUo&Yv1;h:RP;G9-6_[5fAhr94Gw!;;0)ot~0~RvkeflYs95Afq~82');
define('SECURE_AUTH_SALT', 'P+2wHc2lSg6X[Xq:432T8hpy%Jw&-[bq+1X433*7n]ch72B3+)Stn[t)|zvhBJfj');
define('LOGGED_IN_SALT', 'GUN4NOe|43&Xh|nWKH(Yl7/ciH(+JW0A]v92iyPk8Z:!15Nf3707Qg8cO/Mz-/~1');
define('NONCE_SALT', 'a8&~~+166921Jc8(~29e5t]]TW85@W:oZUm3q3Q:Om6-fNO_L]gX13]%gOs!5]J7');


/**#@-*/

/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 */
$table_prefix = '8H1kL5_';


/* Add any custom values between this line and the "stop editing" line. */

define('WP_ALLOW_MULTISITE', true);
/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the documentation.
 *
 * @link https://wordpress.org/support/article/debugging-in-wordpress/
 */
if ( ! defined( 'WP_DEBUG' ) ) {
	define( 'WP_DEBUG', false );
}

define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', true);

/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';