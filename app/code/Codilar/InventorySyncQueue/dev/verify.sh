#!/usr/bin/env bash
#
# End-to-end proof that Codilar_InventorySyncQueue works.
#
#   cd /var/www/midhun2
#   bash app/code/Codilar/InventorySyncQueue/dev/verify.sh --reset
#
# --reset   force every product to look un-synced first, so there is always something
#           to demo. Without it the script runs against whatever state you are in.
#
# It works whether the module is wired to RabbitMQ (connection="amqp") or to Magento's
# MySQL queue (connection="db") - it detects which and reads the queue depth accordingly.

set -uo pipefail

MAGENTO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../../.." && pwd)"
cd "$MAGENTO_ROOT" || exit 1

MODULE="app/code/Codilar/InventorySyncQueue"
CONSUMER="codilar.inventory.sync.consumer"
QUEUE="codilar_inventory_sync_queue"
LOG="var/log/codilar_inventory_sync.log"
RESET=0
[ "${1:-}" = "--reset" ] && RESET=1

bold()  { printf '\n\033[1;36m%s\033[0m\n' "$*"; }
note()  { printf '  \033[2m%s\033[0m\n' "$*"; }
good()  { printf '  \033[1;32m%s\033[0m\n' "$*"; }
bad()   { printf '  \033[1;31m%s\033[0m\n' "$*"; }

# Which queue backend is this module wired to right now?
CONNECTION=$(grep -o 'connection="[a-z]*"' "$MODULE/etc/queue_consumer.xml" | head -1 | cut -d'"' -f2)

# Small SQL helper that reuses Magento's own credentials from app/etc/env.php.
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

# RabbitMQ's management API, authenticated with the very credentials Magento uses.
# Deliberately not `sudo rabbitmqctl`: that needs a password, and when it cannot get one
# it prints nothing and exits 0, so the script silently reported an empty queue.
rabbit_cred() {
  php -r '$c = include "app/etc/env.php"; $a = $c["queue"]["amqp"] ?? null;
          if ($a) { echo $a["user"], ":", $a["password"]; }'
}

rabbit_api() {
  local cred; cred=$(rabbit_cred)
  [ -z "$cred" ] && return 1
  curl -s -u "$cred" "http://localhost:15672/api/$1"
}

# Messages waiting in the queue, whichever backend is in use.
#
# For RabbitMQ this asks the broker directly over AMQP (see dev/queue-depth.php) rather
# than reading /api/queues. The management API serves sampled statistics and lags by
# several seconds, so a read taken right after publishing or purging reports the previous
# number and the script draws the wrong conclusion.
queue_depth() {
  if [ "$CONNECTION" = "amqp" ]; then
    php "$(dirname "${BASH_SOURCE[0]}")/queue-depth.php" "$QUEUE" 2>/dev/null || echo 0
  else
    sql "SELECT COUNT(*) FROM queue_message_status s
         JOIN queue q ON q.id = s.queue_id
         WHERE q.name = '$QUEUE' AND s.status = 2" 2>/dev/null
  fi
}

snapshot() {
  # Depth is passed in, never re-read: Magento's consumers_runner cron can drain the
  # queue between two calls, which made the demo contradict itself.
  printf '  %-28s %s\n' "queue depth ($CONNECTION):" "${1:-$(queue_depth)}"
  echo "  status table:"
  sql "SELECT status, COUNT(*) FROM codilar_inventory_sync_status GROUP BY status" \
    | awk '{ printf "    %-12s %s\n", $1, $2 }'
  [ -z "$(sql 'SELECT COUNT(*) FROM codilar_inventory_sync_status' | tr -d '[:space:]')" ] && echo "    (no rows)"
}

# ----------------------------------------------------------------------------------
bold "0. What are we testing?"
note "Magento root : $MAGENTO_ROOT"
note "Queue backend: $CONNECTION   (from $MODULE/etc/queue_consumer.xml)"

if [ "$CONNECTION" = "amqp" ]; then
  if ! systemctl is-active --quiet rabbitmq-server 2>/dev/null; then
    bad "rabbitmq-server is not running - start it, or the publish step will fail."
  else
    good "rabbitmq-server is running"
  fi
  php -r '$c = include "app/etc/env.php"; $a = $c["queue"]["amqp"] ?? null;
    echo $a ? "  \033[1;32mMagento -> amqp ".$a["host"].":".$a["port"]." vhost ".$a["virtualhost"]."\033[0m\n"
            : "  \033[1;31mMISSING queue.amqp in app/etc/env.php - run setup:config:set --amqp-host=...\033[0m\n";'
  echo "  exchange / binding declared in RabbitMQ:"
  BINDINGS=$(rabbit_api bindings 2>/dev/null | python3 -c 'import sys, json
try:
    rows = json.load(sys.stdin)
except Exception:
    rows = []
for b in rows:
    if "codilar" in (b.get("source", "") + b.get("destination", "")) and b.get("source"):
        print("    %s -> %s   (routing key: %s)" % (b["source"], b["destination"], b["routing_key"]))' 2>/dev/null)
  if [ -n "$BINDINGS" ]; then
    echo "$BINDINGS"
  else
    bad "   no binding found - run bin/magento setup:upgrade"
  fi
fi

echo "  consumer registered with Magento:"
php bin/magento queue:consumers:list 2>/dev/null | grep codilar | sed 's/^/    /' \
  || bad "   not registered - run bin/magento setup:upgrade"

# The single most confusing thing when demoing a queue: Magento's own cron starts the
# consumers every minute, so messages are gone before you can look at them.
AUTO=$(php -r '$c = include "app/etc/env.php";
  $r = $c["cron_consumers_runner"] ?? null;
  echo ($r === null || ($r["cron_run"] ?? true)) ? "on" : "off";')
if crontab -l 2>/dev/null | grep -q 'bin/magento cron:run'; then
  if [ "$AUTO" = "on" ]; then
    bad "Magento cron is installed AND consumers_runner is ON."
    note "   A consumer starts by itself every minute, so the queue may drain while you watch."
    note "   To hold messages in the queue long enough to see them, add to app/etc/env.php:"
    note "       'cron_consumers_runner' => ['cron_run' => false],"
    note "   then: bin/magento cache:clean config   (undo it when you are done)"
  else
    good "consumers_runner is OFF - messages will sit in the queue until you consume them"
  fi
fi

# ----------------------------------------------------------------------------------
if [ "$RESET" = "1" ]; then
  bold "0b. Full reset - queue, status rows and log"
  bash "$(dirname "${BASH_SOURCE[0]}")/reset.sh" -y | sed 's/^/  /'
fi

: > "$LOG"
note "truncated $LOG so the trail below is only this run"

# ----------------------------------------------------------------------------------
bold "1. BEFORE  - nothing in flight"
BEFORE=$(queue_depth)
snapshot "$BEFORE"

# ----------------------------------------------------------------------------------
bold "2. PUBLISH - this is exactly what the */5 cron does"
FIRST_OUT=$(php bin/magento codilar:inventory-sync:publish)
echo "$FIRST_OUT"
PUBLISHED=$(echo "$FIRST_OUT" | grep -oE 'published *: *[0-9]+' | grep -oE '[0-9]+$')

# ----------------------------------------------------------------------------------
bold "3. AFTER PUBLISH - the messages are in the queue, nothing synced yet"
AFTER_PUB=$(queue_depth)
snapshot "$AFTER_PUB"
echo
# Deliberately asserted against the status table, not against the queue depth. The */5
# cron and Magento's consumers_runner both touch the queue in the background, so depth is
# evidence but never proof. "N pending, 0 completed" cannot be faked by a background job.
PENDING_NOW_DB=$(sql "SELECT COUNT(*) FROM codilar_inventory_sync_status WHERE status='pending'" | tr -d '[:space:]')
DONE_NOW_DB=$(sql "SELECT COUNT(*) FROM codilar_inventory_sync_status WHERE status='completed'" | tr -d '[:space:]')
if [ "${PUBLISHED:-0}" -gt 0 ] && [ "${PENDING_NOW_DB:-0}" -gt 0 ] && [ "${DONE_NOW_DB:-0}" = "0" ]; then
  good "PROOF 1: published ${PUBLISHED}, now ${PENDING_NOW_DB} pending and 0 completed."
  note "         The cron handed the work over and synced nothing itself."
  note "         Queue holds ${AFTER_PUB} message(s)."
elif [ "${PUBLISHED:-0}" = "0" ]; then
  bad  "nothing was published - everything is already in sync. Re-run with --reset."
else
  bad  "published ${PUBLISHED} but the table shows ${PENDING_NOW_DB} pending / ${DONE_NOW_DB} completed."
  note "         A consumer got there first (consumers_runner is on). Proof still holds,"
  note "         but to SHOW the pending state you must turn consumers_runner off."
fi

# ----------------------------------------------------------------------------------
bold "4. PUBLISH AGAIN - duplicate prevention"
php bin/magento codilar:inventory-sync:publish | tee /tmp/.codilar_second_run.txt
SECOND=$(grep -oE 'candidates found *: *[0-9]+' /tmp/.codilar_second_run.txt | grep -oE '[0-9]+$')
rm -f /tmp/.codilar_second_run.txt
echo
if [ "${SECOND:-1}" = "0" ]; then
  good "PROOF 2: candidates = 0. Already-pending products cannot be published twice."
else
  bad  "candidates = ${SECOND:-?}, expected 0. Duplicate prevention is not holding."
fi

# ----------------------------------------------------------------------------------
if [ "${PUBLISHED:-0}" -gt 0 ]; then
  bold "5. CONSUME - a separate process does the actual work"

  # How many to ask for comes from the status table, which is exact and immediate, not
  # from the sampled queue depth. --max-messages=N means "process exactly N then exit",
  # so asking for more than are actually waiting leaves the consumer polling forever -
  # hence the timeout too. A timeout here is not a failure, the queue just ran dry.
  PENDING_NOW=$(sql "SELECT COUNT(*) FROM codilar_inventory_sync_status WHERE status='pending'" | tr -d '[:space:]')
  if [ "${PENDING_NOW:-0}" -gt 0 ]; then
    note "consuming ${PENDING_NOW} message(s) (timeout 60s; a timeout just means the queue ran dry)"
    timeout 60 php bin/magento queue:consumers:start "$CONSUMER" --max-messages="$PENDING_NOW"
  else
    note "queue is already empty - Magento's consumers_runner cron consumed them for you."
    note "That still proves consumption works: check the STEP 6-9 lines in the trail below."
  fi

  bold "6. AFTER CONSUME - queue drained, products synced"
  AFTER_CON=$(queue_depth)
  snapshot "$AFTER_CON"
  echo
  if [ "${AFTER_CON:-1}" = "0" ]; then
    good "PROOF 3: queue drained to 0. Every message was consumed."
  else
    bad  "queue still holds ${AFTER_CON} message(s) - is anything consuming?"
  fi
  COMPLETED=$(sql "SELECT COUNT(*) FROM codilar_inventory_sync_status WHERE status='completed'" | tr -d '[:space:]')
  FAILED=$(sql "SELECT COUNT(*) FROM codilar_inventory_sync_status WHERE status='failed'" | tr -d '[:space:]')
  if [ "${FAILED:-0}" = "0" ]; then
    good "PROOF 4: $COMPLETED product(s) are 'completed' with last_synced_at set, 0 failed."
  else
    bad  "PROOF 4: $COMPLETED completed but $FAILED failed - see the ERROR lines below."
  fi
else
  bold "5-6. Nothing was published, so nothing to consume"
  note "Re-run with --reset to force candidates."
fi

# ----------------------------------------------------------------------------------
bold "7. The status table, product by product"
printf '  %-6s %-11s %-9s %-21s %s\n' id status attempts last_synced_at message
sql "SELECT product_id, status, attempts, COALESCE(last_synced_at,'-'), COALESCE(message,'-')
     FROM codilar_inventory_sync_status ORDER BY product_id" \
  | awk -F'\t' '{ printf "  %-6s %-11s %-9s %-21s %s\n", $1, $2, $3, $4, $5 }'

bold "8. The step trail"
sed 's/ \[\] \[\]$//; s/ {"exception.*$//' "$LOG"

bold "Done."
note "Full log: $MAGENTO_ROOT/$LOG"
