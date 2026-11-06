<?php
/**
 * Exact number of messages waiting in a RabbitMQ queue.
 *
 *   php .../dev/queue-depth.php [queue-name]            -> messages waiting
 *   php .../dev/queue-depth.php [queue-name] --consumers -> consumers attached
 *   php .../dev/queue-depth.php [queue-name] --full      -> "<messages> <consumers>"
 *
 * Why not the management API: /api/queues reports numbers from RabbitMQ's statistics
 * database, which is sampled on an interval (collect_statistics_interval, 5s by default).
 * Read it straight after publishing or purging and it still hands back the previous
 * value, which makes a test script draw the wrong conclusion.
 *
 * A passive queue_declare asks the broker itself and answers with the real count now.
 * Passive means "tell me about this queue, do not create it" - so it also fails loudly
 * when the topology was never declared.
 */
declare(strict_types=1);

$root = dirname(__DIR__, 5);
$queue = $argv[1] ?? 'codilar_inventory_sync_queue';
$mode = $argv[2] ?? '';

require $root . '/vendor/autoload.php';

$config = include $root . '/app/etc/env.php';
$amqp = $config['queue']['amqp'] ?? null;
if (!$amqp) {
    fwrite(STDERR, "No queue.amqp block in app/etc/env.php\n");
    exit(2);
}

try {
    $connection = new \PhpAmqpLib\Connection\AMQPStreamConnection(
        $amqp['host'],
        (int)$amqp['port'],
        $amqp['user'],
        $amqp['password'],
        $amqp['virtualhost'] ?? '/'
    );
    $channel = $connection->channel();
    // [queue, messageCount, consumerCount]; true = passive
    [, $messageCount, $consumerCount] = $channel->queue_declare($queue, true);
    echo match ($mode) {
        '--consumers' => $consumerCount,
        '--full'      => $messageCount . ' ' . $consumerCount,
        default       => $messageCount,
    }, "\n";
    $channel->close();
    $connection->close();
} catch (\PhpAmqpLib\Exception\AMQPProtocolChannelException $e) {
    fwrite(STDERR, "Queue '$queue' does not exist - run bin/magento setup:upgrade\n");
    exit(3);
} catch (\Throwable $e) {
    fwrite(STDERR, 'Cannot reach RabbitMQ: ' . $e->getMessage() . "\n");
    exit(4);
}
