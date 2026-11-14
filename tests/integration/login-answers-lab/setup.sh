#!/bin/bash
set -e
. "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/env.sh"
mkdir -p $LAB/sites $LAB/state
# Instruments are written here, not committed as PHP, so they stay out of the plugin's lint scope.
cat > $LAB/lab-mu.php <<'PHPMU'
<?php
/**
 * Lab instruments for the #378 Login Answers lab. Not shipped.
 * Records counts only: no message bodies, keys, passwords or cookies.
 */
define( 'KEEL_LAB_DIR', getenv( 'KEEL_LA_STATE' ) . '/' . ( defined( 'KEEL_LAB_SITE' ) ? KEEL_LAB_SITE : 'x' ) );
if ( ! is_dir( KEEL_LAB_DIR ) ) { @mkdir( KEEL_LAB_DIR, 0777, true ); }
function keel_lab_flag( $f ) { return file_exists( KEEL_LAB_DIR . '/' . $f ); }
function keel_lab_log( $f, $row ) { file_put_contents( KEEL_LAB_DIR . '/' . $f, wp_json_encode( $row ) . "\n", FILE_APPEND ); }

// Mail capture: recipient count per request; optional failure.
add_filter( 'pre_wp_mail', function ( $return, $atts ) {
	$to = is_array( $atts['to'] ) ? $atts['to'] : explode( ',', (string) $atts['to'] );
	keel_lab_log( 'mail.log', array( 'rid' => isset( $_SERVER['HTTP_X_LAB_RID'] ) ? $_SERVER['HTTP_X_LAB_RID'] : '', 'recipients' => count( array_filter( $to ) ), 'fail' => keel_lab_flag( 'mail-fail' ) ) );
	return keel_lab_flag( 'mail-fail' ) ? false : true;
}, PHP_INT_MAX, 2 );

// P13: what a limiter sees.
add_action( 'wp_login_failed', function ( $username ) {
	keel_lab_log( 'login-failed.log', array( 'rid' => isset( $_SERVER['HTTP_X_LAB_RID'] ) ? $_SERVER['HTTP_X_LAB_RID'] : '', 'username' => $username ) );
} );

// P14: another plugin's lockout from authenticate.
add_filter( 'authenticate', function ( $user ) {
	if ( keel_lab_flag( 'lockout' ) ) {
		return new WP_Error( 'too_many_retries', 'Locked out for 20 minutes.' );
	}
	return $user;
}, 30 );

// P19: reset not allowed for one real account.
add_filter( 'allow_password_reset', function ( $allow, $user_id ) {
	$u = get_userdata( $user_id );
	return ( $u && 'noreset' === $u->user_login ) ? false : $allow;
}, 10, 2 );

// P24: let the timing runner know mail would have gone (no-op otherwise).
// P25 fallback: a third-party form that calls wp_signon() itself.
add_action( 'init', function () {
	if ( isset( $_POST['lab_signon'] ) ) {
		$r = wp_signon( array( 'user_login' => wp_unslash( $_POST['log'] ), 'user_password' => wp_unslash( $_POST['pwd'] ) ) );
		status_header( 200 );
		echo is_wp_error( $r ) ? 'ERR:' . $r->get_error_code() . ':' . wp_strip_all_tags( $r->get_error_message() ) : 'OK';
		exit;
	}
} );

// P23: application passwords need HTTPS outside a local environment; the lab is plain HTTP.
add_filter( 'wp_is_application_passwords_available', '__return_true', 5 );
// php -S does not fill PHP_AUTH_USER from a Basic Authorization header; Apache and nginx+FPM setups do.
if ( empty( $_SERVER['PHP_AUTH_USER'] ) && ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) && 0 === stripos( $_SERVER['HTTP_AUTHORIZATION'], 'basic ' ) ) {
	list( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] ) = array_pad( explode( ':', base64_decode( substr( $_SERVER['HTTP_AUTHORIZATION'], 6 ) ), 2 ), 2, '' );
}
PHPMU
cat > $LAB/set-setting.php <<'PHPSS'
<?php
// wp eval-file set-setting.php key value  -> saves through Keel's own sanitizer as an administrator.
// wp eval-file set-setting.php --network key value|unset -> network policy through keel_defaults_sanitize_network().
$args = $GLOBALS['args'];
$admin = get_users( array( 'role__in' => array( 'administrator' ), 'number' => 1, 'blog_id' => get_current_blog_id() ) );
$super = get_user_by( 'login', 'labadmin' );
wp_set_current_user( $super ? $super->ID : $admin[0]->ID );
do_action( 'admin_init' );
if ( 'network' === $args[0] ) {
	$net    = keel_defaults_network_settings();
	$manage = array_keys( $net );
	$raw    = $net;
	if ( 'unset' === $args[2] ) {
		$manage = array_values( array_diff( $manage, array( $args[1] ) ) );
		unset( $raw[ $args[1] ] );
	} else {
		$manage[]        = $args[1];
		$raw[ $args[1] ] = $args[2];
	}
	update_site_option( KEEL_DEFAULTS_NETWORK_OPTION, keel_defaults_sanitize_network( $raw, array_fill_keys( array_unique( $manage ), '1' ) ) ); // the form posts keel_network_manage[key]=1
	echo wp_json_encode( get_site_option( KEEL_DEFAULTS_NETWORK_OPTION ) ), "\n";
	return;
}
$values = array();
foreach ( keel_defaults_schema() as $key => $field ) {
	$values[ $key ] = keel_defaults_resolve( $key, keel_defaults_stored_settings(), array() );
}
$values[ $args[0] ] = $args[1];
foreach ( $values as $k => $v ) { if ( 'no' === $v ) { unset( $values[ $k ] ); } } // unticked boxes are absent from a form post
update_option( KEEL_DEFAULTS_OPTION, $values );
$stored = get_option( KEEL_DEFAULTS_OPTION );
echo $args[0], '=', isset( $stored[ $args[0] ] ) ? $stored[ $args[0] ] : '(absent)', ' resolved=', keel_defaults_get( $args[0] ), "\n";
PHPSS
cat > $LAB/router.php <<'PHPRT'
<?php
// php -S router: WordPress subdirectory multisite rewrite (same rules as core's .htaccess).
$uri  = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$root = $_SERVER['DOCUMENT_ROOT'];
if ( preg_match( '#^/[_0-9a-zA-Z-]+(/wp-(content|admin|includes).*)#', $uri, $m ) && ! file_exists( $root . $uri ) ) {
	$uri = $m[1];
} elseif ( preg_match( '#^/[_0-9a-zA-Z-]+(/.*\.php)$#', $uri, $m ) && ! file_exists( $root . $uri ) ) {
	$uri = $m[1];
}
$file = $root . $uri;
if ( is_dir( $file ) ) { $file = rtrim( $file, '/' ) . '/index.php'; }
if ( is_file( $file ) && ! preg_match( '/\.php$/', $file ) ) { return false; }
if ( ! is_file( $file ) ) { $file = $root . '/index.php'; }
$_SERVER['SCRIPT_FILENAME'] = $file;
$_SERVER['SCRIPT_NAME']     = substr( $file, strlen( $root ) );
$_SERVER['PHP_SELF']        = $_SERVER['SCRIPT_NAME'];
chdir( dirname( $file ) );
require $file;
PHPRT
[ -f $LAB/state/.pw ] || openssl rand -hex 12 > $LAB/state/.pw
PW=$(cat $LAB/state/.pw)
db_start
trap 'db_stop' EXIT
mysql_root() { mariadb --socket=$LAB/mysql.sock -uroot -e "$1"; }
mk_site() { # name tag engine(mysql|sqlite) multisite(0|1)
  local s=$1 tag=$2 eng=$3 ms=$4 d=$LAB/sites/$1
  rm -rf $d; mkdir -p $d
  git -C ${KEEL_LA_WP_GIT:?clone https://github.com/WordPress/WordPress.git and set KEEL_LA_WP_GIT} archive $tag | tar -x -C $d
  mkdir -p $d/wp-content/mu-plugins $d/wp-content/plugins
  cp $LAB/lab-mu.php $d/wp-content/mu-plugins/
  ln -s $KEEL $d/wp-content/plugins/keel-defaults
  if [ $eng = sqlite ]; then
    cp -rL ${KEEL_LA_SQLITE_GIT:-$LAB/dl/sqlite}/packages/plugin-sqlite-database-integration $d/wp-content/plugins/sqlite-database-integration
    sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$d/wp-content/plugins/sqlite-database-integration#" -e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" $d/wp-content/plugins/sqlite-database-integration/db.copy > $d/wp-content/db.php
  else
    mysql_root "DROP DATABASE IF EXISTS lab_$s; CREATE DATABASE lab_$s;"
  fi
  $WP config create --path=$d --dbname=lab_$s --dbuser=lab --dbpass=lab --dbhost=127.0.0.1:33306 --skip-check --extra-php <<PHP
define( 'WP_ENVIRONMENT_TYPE', 'production' );
define( 'KEEL_LAB_SITE', '$s' );
define( 'WP_HTTP_BLOCK_EXTERNAL', true );
define( 'DISABLE_WP_CRON', true );
PHP
  if [ $ms = 1 ]; then
    $WP core multisite-install --path=$d --url=http://$s.test --title="Lab $s" --admin_user=labadmin --admin_password=$PW --admin_email=admin@example.test --skip-email >/dev/null
  else
    $WP core install --path=$d --url=http://$s.test --title="Lab $s" --admin_user=labadmin --admin_password=$PW --admin_email=admin@example.test --skip-email >/dev/null
  fi
  $WP user create riley riley@example.test --role=author --user_pass=$PW --path=$d >/dev/null
  [ $ms = 1 ] || $WP user create Casey casey@example.test --role=author --user_pass=$PW --path=$d >/dev/null
  $WP user create noreset noreset@example.test --role=author --user_pass=$PW --path=$d >/dev/null
  $WP user create eddy eddy@example.test --role=editor --user_pass=$PW --path=$d >/dev/null
  if [ $ms = 1 ]; then
    $WP site create --slug=site2 --title="Lab site2" --email=admin@example.test --path=$d --url=http://$s.test >/dev/null
    S2=http://$s.test/site2/
    $WP user set-role riley author --url=$S2 --path=$d >/dev/null 2>&1 || $WP user add-role riley author --url=$S2 --path=$d >/dev/null
    $WP user create siteadmin siteadmin@example.test --role=administrator --user_pass=$PW --url=$S2 --path=$d >/dev/null
    $WP user create netonly netonly@example.test --role=subscriber --user_pass=$PW --path=$d >/dev/null
    $WP user create spammy spammy@example.test --role=subscriber --user_pass=$PW --path=$d >/dev/null
    # P28: a site with its own mapped domain (no path of its own) on the subdirectory network.
    $WP site create --slug=site3 --title="Lab site3" --email=admin@example.test --path=$d --url=http://$s.test >/dev/null
    $WP db query "UPDATE wp_blogs SET domain='mapped.test', path='/' WHERE blog_id=3" --path=$d
    $WP option update home http://mapped.test --url=http://mapped.test --path=$d >/dev/null
    $WP option update siteurl http://mapped.test --url=http://mapped.test --path=$d >/dev/null
    $WP eval '$u=get_user_by("login","spammy"); update_user_status($u->ID,"spam",1); wpmu_signup_user("pendingone","pendingone@example.test",array());' --path=$d >/dev/null
  fi
  echo "site $s ($tag, $eng, ms=$ms) ready"
}
for spec in "$@"; do mk_site $spec; done
# P25: WooCommerce on the s71 site, when KEEL_LA_WOO names an unpacked woocommerce/ folder.
if [ -n "${KEEL_LA_WOO:-}" ] && [ -d $LAB/sites/s71 ]; then
  rm -rf $LAB/sites/s71/wp-content/plugins/woocommerce
  cp -r "$KEEL_LA_WOO" $LAB/sites/s71/wp-content/plugins/woocommerce
  $WP plugin activate woocommerce --path=$LAB/sites/s71 >/dev/null
  $WP wc tool run install_pages --user=labadmin --path=$LAB/sites/s71 >/dev/null
  $WP plugin deactivate woocommerce --path=$LAB/sites/s71 >/dev/null
  echo "woocommerce ready on s71"
fi
