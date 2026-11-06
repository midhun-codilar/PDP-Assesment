#!/usr/bin/env bash
#
# Manual control of the inventory sync, so the RabbitMQ queue graph actually shows
# something. Nothing here is needed in production - it exists to make the moving parts
# visible one at a time.
#
#   cd /var/www/midhun2
#   D=app/code/Codilar/InventorySyncQueue/dev/demo.sh
#
#   bash $D stop          # take Magento's cron off the box - nothing runs by itself
#   bash $D status        # queue depth, consumers attached, DB status, what is automatic
#   bash $D fill 20       # publish 20 batches, paced - watch Ready climb and the graph move
#   bash $D drain         # attach a consumer and watch Ready fall back to 0
#   bash $D slow-drain    # consume one message per second, so 'deliver/ack' stays visible
#   bash $D start         # put Magento's cron back
#
# Why `stop` first: Magento's cron runs every minute and starts the message queue
# consumers for you, so messages are gone within a second of being published and the
# graph never shows anything but a flat line at zero.

set -uo pipefail

MAGENTO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../../.." && pwd)"
cd "$MAGENTO_ROOT" || exit 1

DEV="app/code/Codilar/InventorySyncQueue/dev"
QUEUE="codilar_inventory_sync_queue"
CONSUMER="codilar.inventory.sync.consumer"
LOG="var/log/codilar_inventory_sync.log"

bold() { printf '\n\033[1;36m%s\033[0m\n' "$*"; }
note() { printf '  \033[2m%s\033[0m\n' "$*"; }
good() { printf '  \033[1;32m%s\033[0m\n' "$*"; }
bad()  { printf '  \033[1;31m%s\033[0m\n' "$*"; }

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
    while ($row = $r->fetch_row()) { echo implode("\t", $row), "\n"; }
  ' "$1"
}

depth()     { php "$DEV/queue-depth.php" "$QUEUE" 2>/dev/null || echo 0; }
consumers() { php "$DEV/queue-depth.php" "$QUEUE" --consumers 2>/dev/null || echo 0; }

cmd_status() {
  bold "RabbitMQ"
  read -r m c <<<"$(php "$DEV/queue-depth.php" "$QUEUE" --full 2>/dev/null || echo '? ?')"
  printf '  %-26s %s\n' "messages waiting (Ready):" "$m"
  printf '  %-26s %s\n' "consumers attached:" "$c"
  note "UI: http://localhost:15672  ->  Queues  ->  $QUEUE"

  bold "Status table"
  sql "SELECT status, COUNT(*) FROM codilar_inventory_sync_status GROUP BY status" \
    | awk '{ printf "  %-12s %s\n", $1, $2 }'
  local total; total=$(sql "SELECT COUNT(*) FROM codilar_inventory_sync_status" | tr -d '[:space:]')
  printf '  %-12s %s\n' "TOTAL" "${total:-0}"

  bold "What is running by itself"
  if crontab -l 2>/dev/null | grep -q 'bin/magento cron:run'; then
    bad "Magento cron IS installed - the */5 job and the consumers start on their own."
    note "Run '$0 stop' first if you want to drive this by hand."
  else
    good "Magento cron is NOT installed - nothing happens unless you make it happen."
  fi
  local procs; procs=$(pgrep -cf "$CONSUMER" 2>/dev/null || echo 0)
  if [ "${procs:-0}" -gt 0 ]; then
    bad "$procs consumer process(es) running:"
    pgrep -af "$CONSUMER" | sed 's/^/    /'
  else
    good "no consumer process running"
  fi
}

cmd_stop() {
  bold "Taking Magento's cron off this machine"
  php bin/magento cron:remove 2>&1 | sed 's/^/  /'
  good "crontab entry removed - the */5 publish and consumers_runner are both off"
  note "This is reversible: '$0 start' puts it back with bin/magento cron:install."

  bold "Stopping consumer processes"
  if pgrep -f "$CONSUMER" >/dev/null 2>&1; then
    pkill -f "$CONSUMER" && sleep 1
    good "stopped"
  else
    note "none were running"
  fi

  bold "Now nothing moves unless you move it"
  note "$0 fill 20     publish batches and watch Ready climb"
  note "$0 drain       consume them and watch it fall"
}

cmd_start() {
  bold "Reinstalling Magento's cron"
  php bin/magento cron:install 2>&1 | sed 's/^/  /'
  good "back to normal - the */5 job and consumers_runner resume within a minute"
}

# Publish repeatedly so the graph has something to draw.
#
# Each batch resets the status rows first: without that the second publish finds nothing,
# because every product is already 'pending' - which is the duplicate guard doing its job.
cmd_fill() {
  local batches="${1:-10}" pause="${2:-1}"
  bold "Publishing $batches batch(es), one every ${pause}s"
  note "each batch resets the status rows first, otherwise the duplicate guard"
  note "correctly refuses to publish the same products twice"
  echo
  for i in $(seq 1 "$batches"); do
    sql "UPDATE codilar_inventory_sync_status
         SET status='failed', attempts=0, message=NULL" > /dev/null
    local out published
    out=$(php bin/magento codilar:inventory-sync:publish 2>&1)
    published=$(echo "$out" | grep -oE 'published *: *[0-9]+' | grep -oE '[0-9]+$')
    printf '  batch %-3s published %-3s | queue now holds %s\n' \
      "$i" "${published:-0}" "$(depth)"
    sleep "$pause"
  done
  echo
  good "done - queue holds $(depth) message(s), $(consumers) consumer(s) attached"
  note "In the UI the Ready line should have climbed and 'incoming' shown a rate."
}

cmd_drain() {
  local n; n=$(depth)
  if [ "${n:-0}" = "0" ]; then
    bold "Nothing to drain"; note "run '$0 fill 10' first"; return
  fi
  bold "Consuming $n message(s)"
  note "watch the UI: consumers goes 0 -> 1, Ready falls to 0, deliver/ack show a rate"
  timeout 300 php bin/magento queue:consumers:start "$CONSUMER" --max-messages="$n"
  echo
  good "queue now holds $(depth) message(s)"
}

# One message per second, so the rate chart has time to render instead of one spike.
cmd_slow_drain() {
  local n; n=$(depth)
  if [ "${n:-0}" = "0" ]; then
    bold "Nothing to drain"; note "run '$0 fill 10' first"; return
  fi
  bold "Consuming $n message(s), one per second"
  note "the queue stays in 'running' state the whole time, and deliver/ack hold a steady rate"
  for i in $(seq 1 "$n"); do
    timeout 30 php bin/magento queue:consumers:start "$CONSUMER" --max-messages=1 >/dev/null 2>&1
    printf '  %-3s/%-3s consumed | Ready = %s\n' "$i" "$n" "$(depth)"
    sleep 1
  done
  good "done"
}

cmd_watch() {
  bold "Polling every 2s - Ctrl-C to stop"
  printf '  %-10s %-9s %-11s %s\n' time Ready consumers 'status table'
  while true; do
    read -r m c <<<"$(php "$DEV/queue-depth.php" "$QUEUE" --full 2>/dev/null || echo '? ?')"
    local counts
    counts=$(sql "SELECT CONCAT(status,'=',COUNT(*)) FROM codilar_inventory_sync_status GROUP BY status" \
             | tr '\n' ' ')
    printf '  %-10s %-9s %-11s %s\n' "$(date +%H:%M:%S)" "$m" "$c" "$counts"
    sleep 2
  done
}

case "${1:-status}" in
  stop)        cmd_stop ;;
  start)       cmd_start ;;
  status)      cmd_status ;;
  fill)        cmd_fill "${2:-10}" "${3:-1}" ;;
  drain)       cmd_drain ;;
  slow-drain)  cmd_slow_drain ;;
  watch)       cmd_watch ;;
  *)
    echo "usage: $0 {stop|start|status|fill [batches] [pause]|drain|slow-drain|watch}"
    exit 2 ;;
esac
