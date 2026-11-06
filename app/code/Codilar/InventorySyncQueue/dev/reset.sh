#!/usr/bin/env bash
#
# Wipe everything back to a clean slate so you can test from zero.
#
#   cd /var/www/midhun2
#   bash app/code/Codilar/InventorySyncQueue/dev/reset.sh          # asks first
#   bash app/code/Codilar/InventorySyncQueue/dev/reset.sh -y       # no prompt
#   bash app/code/Codilar/InventorySyncQueue/dev/reset.sh -y --keep-rows
#
# Clears, in this order:
#   1. the queue           - RabbitMQ (or the MySQL queue tables, whichever is wired up)
#   2. the status table    - every product becomes "never synced" again
#   3. the log file        - var/log/codilar_inventory_sync.log
# and then reports any stale consumer process still running with the OLD config.
#
# --keep-rows  reset the status rows to 'failed, attempts=0' instead of deleting them,
#              so you keep the history but every product is still a candidate.

set -uo pipefail

MAGENTO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../../.." && pwd)"
cd "$MAGENTO_ROOT" || exit 1

MODULE="app/code/Codilar/InventorySyncQueue"
QUEUE="codilar_inventory_sync_queue"
LOG="var/log/codilar_inventory_sync.log"
YES=0
KEEP_ROWS=0
for a in "$@"; do
  case "$a" in
    -y|--yes)     YES=1 ;;
    --keep-rows)  KEEP_ROWS=1 ;;
    *) echo "unknown option: $a"; exit 2 ;;
  esac
done

bold() { printf '\n\033[1;36m%s\033[0m\n' "$*"; }
note() { printf '  \033[2m%s\033[0m\n' "$*"; }
good() { printf '  \033[1;32m%s\033[0m\n' "$*"; }
bad()  { printf '  \033[1;31m%s\033[0m\n' "$*"; }

CONNECTION=$(grep -o 'connection="[a-z]*"' "$MODULE/etc/queue_consumer.xml" | head -1 | cut -d'"' -f2)

sql() {
  php -r '
    $c = include "app/etc/env.php"; $d = $c["db"]["connection"]["default"];
    $h = $d["host"]; $port = 3306;
    if (strpos($h, ":") !== false) { [$h, $port] = explode(":", $h); }
    $m = new mysqli($h, $d["username"], $d["password"], $d["dbname"], (int)$port);
    if ($m->connect_errno) { fwrite(STDERR, "DB: ".$m->connect_error."\n"); exit(1); }
    $r = $m->query($argv[1]);
    if ($r === false) { fwrite(STDERR, "SQL: ".$m->error."\n"); exit(1); }
    if ($r === true) { echo $m->affected_rows, "\n"; exit(0); }
    while ($row = $r->fetch_row()) { echo implode("\t", array_map(fn($v) => $v ?? "NULL", $row)), "\n"; }
  ' "$1"
}

# RabbitMQ management API, using the credentials Magento itself uses. No sudo needed.
rabbit_api() {
  php -r '
    $c = include "app/etc/env.php"; $a = $c["queue"]["amqp"] ?? null;
    if (!$a) { exit(3); }
    echo escapeshellarg($a["user"] . ":" . $a["password"]);
  '
}

# Asks the broker directly - the management API's numbers are sampled and lag by seconds,
# which is misleading right after a purge.
queue_depth() {
  if [ "$CONNECTION" = "amqp" ]; then
    php "$(dirname "${BASH_SOURCE[0]}")/queue-depth.php" "$QUEUE" 2>/dev/null || echo 0
  else
    sql "SELECT COUNT(*) FROM queue_message_status s JOIN queue q ON q.id = s.queue_id
         WHERE q.name = '$QUEUE'" 2>/dev/null
  fi
}

# ----------------------------------------------------------------------------------
bold "What will be cleared"
note "queue backend : $CONNECTION"
printf '  %-22s %s\n' "messages in queue:" "$(queue_depth)"
printf '  %-22s %s\n' "status rows:" "$(sql 'SELECT COUNT(*) FROM codilar_inventory_sync_status' | tr -d '[:space:]')"
printf '  %-22s %s lines\n' "log file:" "$(wc -l < "$LOG" 2>/dev/null || echo 0)"
[ "$KEEP_ROWS" = "1" ] && note "status rows will be RESET, not deleted (--keep-rows)"

if [ "$YES" != "1" ]; then
  echo
  read -r -p "  Clear all of the above? [y/N] " answer
  case "$answer" in [yY]*) ;; *) echo "  aborted."; exit 0 ;; esac
fi

# ----------------------------------------------------------------------------------
bold "1. Queue"
if [ "$CONNECTION" = "amqp" ]; then
  cred=$(rabbit_api)
  if [ -z "$cred" ]; then
    bad "no queue.amqp block in app/etc/env.php - cannot reach RabbitMQ"
  else
    code=$(eval curl -s -o /dev/null -w '%{http_code}' -u "$cred" -X DELETE \
      "'http://localhost:15672/api/queues/%2F/$QUEUE/contents'")
    case "$code" in
      204) good "purged RabbitMQ queue $QUEUE" ;;
      404) note "queue $QUEUE does not exist yet - run bin/magento setup:upgrade" ;;
      000) bad  "could not reach the management API on :15672 - is rabbitmq_management enabled?" ;;
      *)   bad  "purge returned HTTP $code" ;;
    esac
  fi
else
  n=$(sql "DELETE s FROM queue_message_status s JOIN queue q ON q.id = s.queue_id
           WHERE q.name = '$QUEUE'")
  sql "DELETE m FROM queue_message m
       LEFT JOIN queue_message_status s ON s.message_id = m.id
       WHERE s.id IS NULL" > /dev/null
  good "removed $n message(s) from the MySQL queue tables"
fi

# ----------------------------------------------------------------------------------
bold "2. Status table"
if [ "$KEEP_ROWS" = "1" ]; then
  n=$(sql "UPDATE codilar_inventory_sync_status
           SET status='failed', attempts=0, message=NULL,
               queued_at=NULL, processed_at=NULL, last_synced_at=NULL")
  good "reset $n row(s) - every product is a candidate again, history kept"
else
  n=$(sql "DELETE FROM codilar_inventory_sync_status")
  good "deleted $n row(s) - every product is now 'never synced' (candidate rule a)"
fi

# ----------------------------------------------------------------------------------
bold "3. Log"
: > "$LOG"
good "truncated $LOG"

# ----------------------------------------------------------------------------------
bold "4. Stale consumer processes"
# A consumer started BEFORE you changed queue_consumer.xml is still bound to the old
# connection. It will never see the new queue, and it keeps the old one drained - which
# looks exactly like "my messages vanish".
STALE=$(pgrep -af 'codilar.inventory.sync.consumer' 2>/dev/null)
if [ -n "$STALE" ]; then
  bad "a consumer is already running:"
  echo "$STALE" | sed 's/^/    /'
  note "If you changed queue_consumer.xml since it started, it is bound to the OLD"
  note "connection and must be restarted. Kill it with:"
  note "    pkill -f 'codilar.inventory.sync.consumer'"
else
  good "none running"
fi

bold "Clean. Now test:"
note "bash $MODULE/dev/verify.sh"
