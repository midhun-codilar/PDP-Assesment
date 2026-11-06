# Codilar_InventorySyncQueue

A cron finds products that need an inventory sync and publishes their IDs to a queue. It
does **not** sync anything itself. A consumer picks each message up, performs the sync,
and records the outcome.

Uses **RabbitMQ** (`connection="amqp"`). The exchange `codilar.inventory` and the queue
`codilar_inventory_sync_queue` are declared from `etc/queue_topology.xml` when you run
`setup:upgrade`, so they appear in the RabbitMQ management UI without any manual setup.

## Why split it this way

The cron's only job is deciding *what* needs doing. The actual sync - which in a real
integration is a network call to a WMS or ERP - happens in the consumer, so a slow or
failing remote API never blocks the cron thread, and consumers can be scaled
independently of the schedule.

```
cron (*/5)                     queue (RabbitMQ)            consumer
  |                                  |                         |
  find candidates  --claim-->  mark pending                     |
  publish id       ------------>  message  -------------->  mark processing
                                                                |
                                                           sync inventory
                                                                |
                                                     completed / failed
```

## Folder structure

```
app/code/Codilar/InventorySyncQueue/
├── registration.php
├── README.md
├── Console/Command/
│   ├── PublishCommand.php              codilar:inventory-sync:publish
│   └── StatusCommand.php               codilar:inventory-sync:status
├── Cron/
│   └── PublishInventorySync.php        the */5 job - publishes only
├── Model/
│   ├── Consumer/InventorySyncHandler.php   status machine around the service
│   ├── Publisher/InventorySyncPublisher.php  claim + publish, shared by cron and CLI
│   ├── ResourceModel/SyncStatusResource.php  ALL database access
│   ├── InventorySyncService.php        the sync itself (external API goes here)
│   ├── ProductSyncCandidates.php       the "needs sync" rules
│   └── SyncStatus.php                  status vocabulary
└── etc/
    ├── communication.xml               topic + handler
    ├── crontab.xml                     group codilar_inventory_sync, */5
    ├── db_schema.xml
    ├── db_schema_whitelist.json
    ├── di.xml                          logger virtual types + CLI commands
    ├── module.xml
    ├── queue_consumer.xml
    ├── queue_publisher.xml
    └── queue_topology.xml
```

## The duplicate-prevention rule

`ProductSyncCandidates` returns a product only if it matches one of four branches:

| | Condition | Why |
| --- | --- | --- |
| a | no row in the status table | never synced |
| b | `completed` and `product.updated_at > last_synced_at` | changed since last sync |
| c | `failed` and `attempts < 3` | retry, with a ceiling |
| d | `pending`/`processing` and `queued_at` older than 30 min | consumer died, re-queue |

A product that is `pending` or `processing` **inside** the 30 minute window matches none
of them, so it cannot be published twice while a consumer may still hold it. That is the
whole rule, expressed once, in one SQL statement.

`attempts` is deliberately **not** reset when a product is re-queued. Resetting it would
let a permanently broken SKU loop forever.

### The claim race

Selecting and claiming happen in one transaction, with the select taking row locks
(`FOR UPDATE`):

```php
$connection->beginTransaction();
$productIds = $this->candidates->getCandidateIds(true);   // locks the rows
$this->syncStatusResource->markPending($productIds);
$connection->commit();
```

A second run blocks on those locks, then re-evaluates the criteria and sees the products
as freshly `pending`, so they no longer qualify. Magento's scheduler also refuses to run
the same job code concurrently, so this is belt and braces.

The claim happens **before** publishing, never after. A crash between the two leaves rows
marked `pending` with nothing in the queue, which branch (d) recovers after 30 minutes.
The reverse order would leave messages that no row knows about - and those get processed
twice.

## Install

### 1. RabbitMQ itself

```bash
sudo apt-get install -y rabbitmq-server
sudo systemctl enable --now rabbitmq-server
sudo rabbitmq-plugins enable rabbitmq_management      # management UI on :15672
```

The built-in `guest` user only works over localhost. Create a real user so the UI and
Magento both work from anywhere:

```bash
sudo rabbitmqctl add_user magento magento
sudo rabbitmqctl set_user_tags magento administrator
sudo rabbitmqctl set_permissions -p / magento ".*" ".*" ".*"
```

### 2. Point Magento at it

```bash
bin/magento setup:config:set \
    --amqp-host=127.0.0.1 --amqp-port=5672 \
    --amqp-user=magento --amqp-password=magento --amqp-virtualhost=/ -n
```

This writes a `queue.amqp` block into `app/etc/env.php`. Magento will not connect to
RabbitMQ without it, and `setup:upgrade` will fail while it is missing.

### 3. The module

```bash
bin/magento module:enable Codilar_InventorySyncQueue
bin/magento setup:db-declaration:generate-whitelist --module-name=Codilar_InventorySyncQueue
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

`setup:upgrade` is what declares the exchange and queue in RabbitMQ.

## Running the consumer

```bash
bin/magento queue:consumers:start codilar.inventory.sync.consumer --max-messages=50
```

**`--max-messages=N` means "process N messages then exit", not "process up to N".** With
only 4 messages waiting, a consumer started with `--max-messages=50` will handle those 4
and then sit polling for the other 46 until you kill it. That is normal, not a hang. Pass
a number you actually expect, or let `cron_consumers_runner` manage it.

For it to run automatically, `app/etc/env.php` needs:

```php
'cron_consumers_runner' => [
    'cron_run' => true,
    'max_messages' => 1000,
    'consumers' => ['codilar.inventory.sync.consumer'],
],
```

With `cron_run => true`, Magento's `consumers_runner` cron job starts the consumer
process for you every minute. It is `false` on many dev installs, which is the usual
reason "nothing is consuming my queue". This also requires Magento cron to be installed
(`bin/magento cron:install`).

## CLI

```bash
bin/magento codilar:inventory-sync:publish    # same logic as the cron, on demand
bin/magento codilar:inventory-sync:status     # row counts by status
```

## Logging - the step trail

Everything goes to **`var/log/codilar_inventory_sync.log`**, not `system.log`. One file,
one `tail -f`, the whole journey of a product in order:

```bash
tail -f var/log/codilar_inventory_sync.log
```

`Logger/StepLogger.php` is the only class that writes to it. Every other class in the
pipeline takes a `StepLogger` rather than a raw `LoggerInterface`, which is what keeps
the file a numbered trail instead of a pile of unrelated lines. Every line has the same
shape:

```
[run:0a56dd7e] [STEP 2 SCAN      ] matched 4 product(s) -> 3, 4, 5, 6
 ^ correlation id  ^ which stage     ^ what happened
```

The nine steps, and what each one proves:

| Step | Written by | Tells you |
|---|---|---|
| `1 RUN-START` | `Cron\PublishInventorySync` / `PublishCommand` | a publish run began, and whether cron or CLI started it |
| `2 SCAN` | `Model\ProductSyncCandidates` | which product IDs matched, and the rules used |
| `3 CLAIM` | `Model\Publisher\InventorySyncPublisher` | those products were marked `pending` in one transaction - this is the duplicate guard |
| `4 PUBLISH` | `Model\Publisher\InventorySyncPublisher` | one line per message actually handed to the queue |
| `5 RUN-END` | `Model\Publisher\InventorySyncPublisher` | `candidates=` / `published=` / `failed=` and the run's duration |
| `6 RECEIVE` | `Model\Consumer\InventorySyncHandler` | the consumer pulled a message off the queue |
| `7 PROCESSING` | `Model\Consumer\InventorySyncHandler` | status moved to `processing`, and which attempt this is |
| `8 SYNC` | `Model\InventorySyncService` | the inventory read itself - **where your WMS/ERP call goes** |
| `9 RESULT` | `Model\Consumer\InventorySyncHandler` | `completed` or `failed`, the result string, and how long it took |

Steps 1-5 are the publish side, 6-9 the consumer side. Between step 5 and step 6 the work
is sitting in RabbitMQ - that gap is the queue doing its job.

### The run id

Each publish run gets one id shared by its steps 1-5. Each *message* gets a fresh id for
its steps 6-9, because one consumer process handles many messages and several consumers
may run at once. To read one product's story without the noise:

```bash
grep 'product 3' var/log/codilar_inventory_sync.log
grep 'run:0a56dd7e'  var/log/codilar_inventory_sync.log    # one whole publish run
grep 'STEP 9'        var/log/codilar_inventory_sync.log    # just the outcomes
grep -E 'WARNING|ERROR' var/log/codilar_inventory_sync.log # just the problems
```

### A real run, start to finish

```
[run:0a56dd7e] [STEP 1 RUN-START ] triggered by cli (codilar:inventory-sync:publish)
[run:0a56dd7e] [STEP 2 SCAN      ] matched 4 product(s) -> 3, 4, 5, 6 | rules: never-synced OR changed-since-sync OR failed-with-attempts<3 OR stuck->1800s | batch limit 500
[run:0a56dd7e] [STEP 3 CLAIM     ] marked 4 product(s) pending in one transaction -> 3, 4, 5, 6 | they now fail every candidate rule, so no other run can pick them up
[run:0a56dd7e] [STEP 4 PUBLISH   ] product 3 | status -> pending | message body "3" sent to topic codilar.inventory.sync | 1 of 4
[run:0a56dd7e] [STEP 4 PUBLISH   ] product 4 | status -> pending | message body "4" sent to topic codilar.inventory.sync | 2 of 4
[run:0a56dd7e] [STEP 4 PUBLISH   ] product 5 | status -> pending | message body "5" sent to topic codilar.inventory.sync | 3 of 4
[run:0a56dd7e] [STEP 4 PUBLISH   ] product 6 | status -> pending | message body "6" sent to topic codilar.inventory.sync | 4 of 4
[run:0a56dd7e] [STEP 5 RUN-END   ] candidates=4 published=4 failed=0 | took 0.145s | waiting for the consumer now

[run:1a0ba679] [STEP 1 RUN-START ] triggered by cli (codilar:inventory-sync:publish)
[run:1a0ba679] [STEP 2 SCAN      ] matched 0 product(s) | rules: ...
[run:1a0ba679] [STEP 3 CLAIM     ] nothing to claim
[run:1a0ba679] [STEP 5 RUN-END   ] candidates=0 published=0 failed=0 | took 0.008s | everything is already in sync

[run:a3a4e2f0] [STEP 6 RECEIVE   ] took message body "3" off queue codilar_inventory_sync_queue -> product 3
[run:a3a4e2f0] [STEP 7 PROCESSING] product 3 | status pending -> processing | attempt 1 of 3
[run:a3a4e2f0] [STEP 8 SYNC      ] product 3 | sku 130917 | reading inventory (this is where the WMS/ERP call goes)
[run:a3a4e2f0] [STEP 8 SYNC      ] product 3 | inventory read back | sku=130917 qty=100 salable=yes
[run:a3a4e2f0] [STEP 9 RESULT    ] product 3 | status processing -> completed | sku=130917 qty=100 salable=yes | took 0.531s | last_synced_at updated
```

The second run above is the duplicate guard, visible: same products, `matched 0`, because
step 3 of the first run had already claimed them.

### Problems look like this

```
WARNING: [STEP 9 RESULT] product 999 | skipped | no status row, the product was deleted after publishing
ERROR:   [STEP 9 RESULT] discarded | message body "abc" is not a usable product id
ERROR:   [STEP 9 RESULT] product 7 | status processing -> failed | Connection timed out | took 30.004s | the next publish run will retry it
ERROR:   [STEP 4 PUBLISH] product 7 | status pending -> failed | queue rejected it: ...
```

### If the file stays empty

`di.xml` binds `StepLogger` to the dedicated file. Magento caches that binding in the
**`compiled_config`** cache, and a plain `cache:flush` does not always clear it. After any
`di.xml` change:

```bash
bin/magento cache:clean compiled_config config
rm -rf generated/metadata generated/code
```

If the lines show up in `var/log/system.log` with channel `main.INFO` instead of
`codilarInventorySync.INFO`, that is exactly this: the old binding is still cached.

## How to show it is working

Two scripts live in `dev/`:

```bash
cd /var/www/midhun2

bash app/code/Codilar/InventorySyncQueue/dev/reset.sh     # clear queue + status + log
bash app/code/Codilar/InventorySyncQueue/dev/verify.sh    # run the proof

bash app/code/Codilar/InventorySyncQueue/dev/verify.sh --reset   # both in one go
```

Both detect whether the module is wired to `amqp` or `db` and do the right thing for
each, so neither needs editing when you switch backends.

### Clearing everything and starting over

```bash
bash app/code/Codilar/InventorySyncQueue/dev/reset.sh        # prompts first
bash app/code/Codilar/InventorySyncQueue/dev/reset.sh -y     # no prompt
bash app/code/Codilar/InventorySyncQueue/dev/reset.sh -y --keep-rows
```

It clears three separate things, and all three matter - clearing only one leaves you in a
state that looks broken:

| | What | Why it has to go |
|---|---|---|
| 1 | the queue | messages published earlier are still waiting, and get processed the moment anything consumes - so your "clean" run is polluted by old work |
| 2 | `codilar_inventory_sync_status` | if every product is `completed`, the candidate query matches nothing and your test publishes 0 |
| 3 | `var/log/codilar_inventory_sync.log` | so the step trail you read afterwards is only this run |

By default the status rows are **deleted**, which puts every product back to "never
synced" (candidate rule a). `--keep-rows` resets them to `failed, attempts=0` instead, so
you keep the history and they are still candidates (rule c).

Doing it by hand, if you prefer:

```bash
# 1. queue  (RabbitMQ)
curl -u magento:magento -X DELETE \
  'http://localhost:15672/api/queues/%2F/codilar_inventory_sync_queue/contents'
#    or, with sudo:  sudo rabbitmqctl purge_queue codilar_inventory_sync_queue

# 2. status
mysql -e "DELETE FROM codilar_inventory_sync_status" <dbname>

# 3. log
: > var/log/codilar_inventory_sync.log
```

### Reading the queue depth correctly

`dev/queue-depth.php` prints the exact number of messages waiting:

```bash
php app/code/Codilar/InventorySyncQueue/dev/queue-depth.php
```

It asks the broker over AMQP with a passive `queue_declare`. Two things it avoids:

- **`sudo rabbitmqctl list_queues`** needs a password. Without one it prints nothing and
  still exits 0, so a script reads an empty string as "queue is empty".
- **The management API** (`/api/queues`) serves numbers from RabbitMQ's statistics
  database, which is sampled every few seconds. Read it immediately after publishing or
  purging and it hands back the *previous* value. This is why the UI can show 0 right
  after you published, then 4 a moment later - the UI is not wrong, it is just behind.

### Driving it by hand, to watch the RabbitMQ graph

`dev/verify.sh` runs too fast to see. `dev/demo.sh` exists to slow it down and let you
drive each step:

```bash
D=app/code/Codilar/InventorySyncQueue/dev/demo.sh

bash $D stop          # take Magento's cron off the box - nothing runs by itself
bash $D status        # queue depth, consumers attached, DB counts, what is automatic
bash $D fill 20 2     # 20 batches, one every 2s - watch Ready climb
bash $D drain         # consume everything - watch Ready fall to 0
bash $D slow-drain    # one message per second, so the rate chart stays readable
bash $D watch         # poll depth + consumers + DB counts every 2s in the terminal
bash $D start         # put Magento's cron back
```

**`stop` first, always.** Magento's cron runs every minute and starts the consumers for
you, so published messages are gone inside a second and the graph never leaves zero.
`stop` runs `bin/magento cron:remove` and kills any consumer process; `start` runs
`bin/magento cron:install`. Both are reversible and neither touches `env.php`.

`fill` resets the status rows before each batch on purpose. Without that the second
publish finds nothing to do - every product is already `pending`, which is the duplicate
guard working correctly. Resetting is what lets you generate repeated load from only a
handful of products.

#### What each column in the UI means

Open <http://localhost:15672> → **Queues** → `codilar_inventory_sync_queue`.

| Column | What it tells you | When it moves |
|---|---|---|
| **Ready** | messages waiting for a consumer | climbs during `fill`, falls during `drain` |
| **Unacked** | delivered but not yet acknowledged - work in flight | brief, one per message being processed |
| **Total** | Ready + Unacked | |
| **Consumers** | processes attached and listening | `0 → 1` the moment a consumer starts |
| **State** | `running` while there is activity, `idle` after a short quiet spell | flips to `running` during fill/drain |
| **incoming** | publish rate | spikes during `fill` |
| **deliver / get**, **ack** | consume rate | spikes during `drain` |

A queue showing `idle` with `Ready = 0` is not broken - it means nothing is happening,
which is the normal resting state.

#### A run that is actually visible

```
terminal 1:  bash $D stop
             bash $D watch

terminal 2:  bash $D fill 20 2      # ~40s of climbing
             bash $D slow-drain     # ~1 message/s back down

browser:     Queues -> codilar_inventory_sync_queue, chart set to 'last minute'
```

With 4 products, `fill 20` produces 80 messages. One `fill 1` is 4 messages consumed in
about 300ms - too fast for a 5-second sampling interval to draw, which is why the chart
looks flat if you publish just once.

Put the cron back when you are done:

```bash
bash $D start
```

### The four proofs

| | What it shows | What you point at |
|---|---|---|
| **Proof 1** | the cron publishes and does **not** sync | queue depth `0 -> 4`, status table all `pending`, nothing `completed` yet |
| **Proof 2** | the same product is never published twice | second publish reports `candidates found: 0` |
| **Proof 3** | a separate process does the work | queue depth `4 -> 0` |
| **Proof 4** | the result is recorded | status table all `completed`, `last_synced_at` set, `0 failed` |

### The four places that have to agree

Any one of them alone proves nothing. Together they are the demo:

1. **CLI output** - `candidates found` / `published` / `failed to publish`
2. **Queue depth** - `rabbitmqctl list_queues`, or the Queues tab of the management UI
3. **Status table** - `codilar_inventory_sync_status`, or `codilar:inventory-sync:status`
4. **Log trail** - `var/log/codilar_inventory_sync.log`, steps 1-9

### The one thing that will confuse you

Magento's `cron:run` starts the message queue consumers **by itself every minute**
(`cron_consumers_runner.cron_run` defaults to `true` when it is not set in
`app/etc/env.php`). So you publish 4 messages, look at RabbitMQ, and see 0 - because a
consumer Magento started already drained them.

That is correct production behaviour and wrong for a demo. To hold messages in the queue
long enough to point at them, add to `app/etc/env.php`:

```php
'cron_consumers_runner' => [
    'cron_run' => false,
],
```

then `bin/magento cache:clean config`. **Put it back when you are done**, or nothing will
consume automatically. `dev/verify.sh` detects this setting and warns you either way.

A second symptom of the same thing: `queue:consumers:start ... --max-messages=4` appears
to hang. `--max-messages=N` means "process exactly N then exit" - if Magento's own
consumer already took some, yours sits waiting for messages that will never arrive. It is
not stuck, it is polling. `dev/verify.sh` takes its count from the status table and wraps
the call in a timeout for exactly this reason.

### The cron group never runs (silently)

A custom cron group needs **`etc/cron_groups.xml`**. Declaring `<group id="...">` in
`crontab.xml` registers the group but gives it no settings, and Magento reads those from
`system/cron/<group>/*`, which only `cron_groups.xml` populates.

Without that file `schedule_ahead_for` resolves to `NULL`, which
`ProcessCronQueueObserver` casts to `0`:

```php
$scheduleAheadFor = (int)$this->getCronGroupConfigurationValue($groupId, 'schedule_ahead_for'); // 0
$timeAhead = $currentTime + $scheduleAheadFor * 60;          // == $currentTime
for ($time = $currentTime; $time < $timeAhead; $time += 60)  // body never runs
```

No rows are written to `cron_schedule`, so the job never fires - with **no error
anywhere**. Magento's own groups all ship this file; see
`Magento_Indexer/etc/cron_groups.xml`.

Note that `etc/config.xml` does **not** work for this. `Magento\Cron\Model\System\Config\Initial\Converter`
is a plugin on the initial-config converter that overwrites `default/system/cron`
wholesale from `cron_groups.xml`, discarding anything a `config.xml` put there.

Diagnosing it:

```bash
# Does the group have settings at all? NULL here means this is your problem.
bin/magento config:show system/cron/codilar_inventory_sync/schedule_ahead_for

# Has the job ever been scheduled?
mysql -e "SELECT status, COUNT(*) FROM cron_schedule
          WHERE job_code='codilar_inventory_sync_publish' GROUP BY status" <dbname>

# Every working group has its own log; a missing one is the same symptom.
ls var/log/magento.cron.*.log
```

With `use_separate_process` set, `bin/magento cron:run --group=<id>` only *spawns* the
real worker. To run it in the foreground and see its output:

```bash
bin/magento cron:run --group=codilar_inventory_sync --bootstrap=standaloneProcessStarted=1
```

### A consumer started before you changed the config

A consumer process is bound to whatever `queue_consumer.xml` said **when it started**.
Switch the module from `db` to `amqp` and any consumer already running keeps draining the
old MySQL queue and never touches RabbitMQ - while also stopping `consumers_runner` from
starting a fresh one, because it sees a consumer is already up. The symptom is a RabbitMQ
queue that fills and never empties, with a consumer process plainly visible in `ps`.

```bash
pgrep -af 'codilar.inventory.sync.consumer'     # how old is it?
pkill -f 'codilar.inventory.sync.consumer'      # cron restarts it within a minute
```

`dev/reset.sh` reports any such process at the end of every run.

### Mapping to the functional requirements

| Requirement | Where it lives | How you prove it |
|---|---|---|
| Custom cron job | `etc/crontab.xml`, `Cron/PublishInventorySync.php` | `SELECT * FROM cron_schedule WHERE job_code='codilar_inventory_sync_publish'`; `STEP 1 RUN-START` says `triggered by cron` |
| Fetch products needing sync | `Model/ProductSyncCandidates.php` | `STEP 2 SCAN` lists the matched IDs and the rules |
| Publish IDs to the queue | `Model/Publisher/InventorySyncPublisher.php` | `STEP 4 PUBLISH` per product + queue depth rising |
| Consumer processes updates | `Model/Consumer/InventorySyncHandler.php`, `etc/queue_consumer.xml` | `bin/magento queue:consumers:list`; `STEP 6/7/8` |
| Update sync status | `Model/ResourceModel/SyncStatusResource.php` | status table `pending -> processing -> completed`; `STEP 9 RESULT` |
| No repeat while pending | the claim in `markPending()` + the candidate SQL | second publish reports `candidates: 0` (Proof 2) |
| Log results | `Logger/StepLogger.php` | `var/log/codilar_inventory_sync.log`, nine steps |

### Showing a failure, not just the happy path

A demo that only shows success does not show the retry logic. Force a failure:

```sql
UPDATE codilar_inventory_sync_status SET status='failed', attempts=2 WHERE product_id=3;
```

Then `codilar:inventory-sync:publish` - product 3 is a candidate (branch c, `attempts < 3`).
Set `attempts=3` instead and it is **not** a candidate: the retry ceiling holds. Both are
visible in the `STEP 2 SCAN` line.

## Test steps

### 1. The consumer is registered

```bash
bin/magento queue:consumers:list | grep codilar
# codilar.inventory.sync.consumer
```

### 2. Publish, then prove no duplicates

```bash
bin/magento codilar:inventory-sync:publish    # candidates: 4   published: 4
bin/magento codilar:inventory-sync:publish    # candidates: 0   published: 0
```

The second run must publish **0**. Everything is `pending` and inside the 30 minute
window, so nothing qualifies.

### 3. Consume

```bash
bin/magento queue:consumers:start codilar.inventory.sync.consumer --max-messages=4
bin/magento codilar:inventory-sync:status     # completed: 4
```

### 4. Prove branch (b) - changed product is re-queued

Save any product in admin, then:

```bash
bin/magento codilar:inventory-sync:publish    # candidates: 1
```

### 5. Prove branches (c) and (d) at the database level

```sql
-- (c) retry ceiling
UPDATE codilar_inventory_sync_status SET status='failed', attempts=2 WHERE product_id=3;  -- candidate
UPDATE codilar_inventory_sync_status SET status='failed', attempts=3 WHERE product_id=3;  -- NOT a candidate

-- (d) stuck window
UPDATE codilar_inventory_sync_status
   SET status='processing', queued_at = UTC_TIMESTAMP() - INTERVAL 2 MINUTE  WHERE product_id=4;  -- NOT a candidate
UPDATE codilar_inventory_sync_status
   SET status='processing', queued_at = UTC_TIMESTAMP() - INTERVAL 33 MINUTE WHERE product_id=4;  -- candidate
```

Run `codilar:inventory-sync:publish` after each and watch the candidate count.

### 6. Prove the consumer cannot lose a message

Create a product, publish, delete the product, then run the consumer. The foreign key
cascades the status row away, the handler logs
`status (no row) -> skipped` and exits 0. Nothing crashes and no message is stranded.

## Where the real integration goes

`Model/InventorySyncService::sync()` currently reads Magento's own stock through
`StockRegistryInterface` and returns a summary string. The comment block in that method
marks the spot where a WMS/ERP call belongs. Anything thrown there is caught by the
handler and recorded as `failed`, which is exactly how a timeout or a remote 500 is meant
to be handled - branch (c) then retries it up to three times.
