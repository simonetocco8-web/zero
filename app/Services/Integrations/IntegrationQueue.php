<?php

namespace App\Services\Integrations;

use App\Jobs\ProcessIntegrationEvent;

class IntegrationQueue
{
    public function dispatch(int $id): void
    {
        $connection = config('integrations.queue_connection');
        if (! in_array(config("queue.connections.$connection.driver"), ['database', 'redis', 'sqs', 'beanstalkd'], true)) {
            throw new \RuntimeException('A durable asynchronous integration queue is required.');
        }
        ProcessIntegrationEvent::dispatch($id)->onConnection($connection)->onQueue(config('integrations.queue'))->afterCommit();
    }
}
