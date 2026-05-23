<?php

namespace App\Messaging;

use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Connection\AMQPSSLConnection;
use PhpAmqpLib\Connection\AMQPStreamConnection;

class AmqpConnectionFactory
{
    private ?AbstractConnection $connection = null;

    public function connection(): AbstractConnection
    {
        if ($this->connection === null || ! $this->connection->isConnected()) {
            $this->connection = $this->createConnection();
        }

        return $this->connection;
    }

    public function close(): void
    {
        if ($this->connection !== null && $this->connection->isConnected()) {
            $this->connection->close();
        }

        $this->connection = null;
    }

    private function createConnection(): AbstractConnection
    {
        $host = config('rabbitmq.host');
        $port = config('rabbitmq.port');
        $user = config('rabbitmq.user');
        $password = config('rabbitmq.password');
        $vhost = config('rabbitmq.vhost');
        $tls = config('rabbitmq.tls');

        if ($tls) {
            return new AMQPSSLConnection($host, $port, $user, $password, $vhost);
        }

        return new AMQPStreamConnection($host, $port, $user, $password, $vhost);
    }
}
