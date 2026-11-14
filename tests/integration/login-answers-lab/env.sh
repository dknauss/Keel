LAB=${KEEL_LA_LAB:-/tmp/keel-login-answers-lab}
HERE=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
KEEL=${KEEL_LA_KEEL:-$(cd "$HERE/../../.." && pwd)}
export KEEL_LA_STATE=$LAB/state
WP="${KEEL_LA_WPCLI:-wp} --allow-root"
DATADIR=$LAB/mysql
db_start() {
  [ -d $DATADIR/mysql ] || mariadb-install-db --no-defaults --datadir=$DATADIR --user=root >/dev/null 2>&1
  (mariadbd --no-defaults --datadir=$DATADIR --port=33306 --bind-address=127.0.0.1 --socket=$LAB/mysql.sock --user=root >$LAB/mysqld.log 2>&1 &)
  mariadb-admin --socket=$LAB/mysql.sock --wait=40 ping >/dev/null
  mariadb --socket=$LAB/mysql.sock -uroot -e "CREATE USER IF NOT EXISTS 'lab'@'%' IDENTIFIED BY 'lab'; CREATE USER IF NOT EXISTS 'lab'@'localhost' IDENTIFIED BY 'lab'; GRANT ALL ON *.* TO 'lab'@'%'; GRANT ALL ON *.* TO 'lab'@'localhost';"
}
db_stop() { mariadb-admin --socket=$LAB/mysql.sock -uroot shutdown 2>/dev/null; }
# site name -> port
port_of() { case $1 in s71) echo 8871;; s64) echo 8864;; ms) echo 8872;; sq) echo 8873;; esac; }
web_start() { for s in "$@"; do p=$(port_of $s); (KEEL_LA_STATE=$KEEL_LA_STATE php -S 127.0.0.1:$p -t $LAB/sites/$s $LAB/router.php >$LAB/state/web-$s.log 2>&1 &); done; }
web_stop() { pkill -f "php -S 127.0.0.1:88" 2>/dev/null; true; }
