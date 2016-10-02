<?php

/*
 * Copyright Talisman Innovations Ltd. (2016). All rights reserved.
 */

namespace Uecode\Bundle\QPushBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Uecode\Bundle\QPushBundle\Event\Events;
use Uecode\Bundle\QPushBundle\Event\MessageEvent;
use Psr\Log\LoggerInterface;

/**
 * @author Steven Brookes <steven.brookes@talisman-innovations.com>
 */
class QueueWorkerCommand extends Command implements ContainerAwareInterface
{

    protected $container;
    protected $logger;

    public function setContainer(ContainerInterface $container = null)
    {
        $this->container = $container;
    }

    protected function configure()
    {
        $this
                ->setName('uecode:qpush:worker')
                ->setDescription('Worker process to poll the configured queues')
                ->addArgument(
                        'name', InputArgument::OPTIONAL, 'Name of a specific queue to poll', null
                )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->logger = $this->container->get('logger');

        $context = new \ZMQContext();
        $this->logger->debug('0MQ context setup');
        $socket = new \ZMQSocket($context, \ZMQ::SOCKET_PULL);
        $this->logger->debug('0MQ socket setup');

        $registry = $this->container->get('uecode_qpush');
        $name = $input->getArgument('name');

        if ($name !== null && !$registry->has($name)) {
            return $output->writeln(
                            sprintf("The [%s] queue you have specified does not exist!", $name));
        }

        if ($name !== null) {
            $queues[] = $registry->get($name);
        } else {
            $queues = $registry->all();
        }

        $binds = 0;
        foreach ($queues as $queue) {
            $binds += $this->bindQueue($queue, $socket);
        }

        $this->logger->debug('0MQ ready to receive');
        while ($binds) {
            $notification = $socket->recv();
            $this->logger->debug('0MQ notification received', [$notification]);

            if (sscanf($notification, '%s %d', $name, $id) != 2) {
                continue;
            }
            $messages = $registry->get($name)->receive();
            $msg = sprintf('Received notification for %s Queue, %d messages fetched', 
                    $name, sizeof($messages));
            $this->logger->debug($msg);
            $output->writeln($msg);
        }

        $output->writeln('No 0MQ sockets to bind to');
        return 1;
    }

    private function bindQueue($queue, $socket)
    {
        $options = $queue->getOptions();
        if (!array_key_exists('zeromq_socket', $options)) {
            return 0;
        }
        
        $this->logger->debug('0MQ binding to ', [$options['zeromq_socket']]);
        $socket->bind($options['zeromq_socket']);

        return 1;
    }

}
