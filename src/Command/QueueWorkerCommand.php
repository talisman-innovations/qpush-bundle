<?php

/*
 * Copyright Talisman Innovations Ltd. (2016). All rights reserved.
 */

namespace Uecode\Bundle\QPushBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
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
class QueueWorkerCommand extends Command implements ContainerAwareInterface {

    protected $container;
    protected $logger;
    protected $output;

    public function setContainer(ContainerInterface $container = null) {
        $this->container = $container;
    }

    protected function configure() {
        $this
                ->setName('uecode:qpush:worker')
                ->setDescription('Worker process to poll the configured queues')
                ->addOption(
                        'time', 't', InputOption::VALUE_OPTIONAL, 'Time to run before exit (seconds)'
                )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output) {
        $this->output = $output;
        $this->logger = $this->container->get('logger');
        $registry = $this->container->get('uecode_qpush');
        $time = ($input->getOption('time') === null) ? PHP_INT_MAX : time() + $input->getOption('time');

        $queues = $registry->all();

        $context = new \ZMQContext();
        $socket = new \ZMQSocket($context, \ZMQ::SOCKET_REQ);
        $this->connect($queues, $socket);

        $this->logger->debug('0MQ ready to receive');
        while (time() < $time) {
            $socket->send('ready');
            $notification = $socket->recv();

            if (sscanf($notification, '%s %d', $name, $id) != 2) {
                $this->logger->error('0MQ incorrect notification format', [$notification]);
                return;
            }

            if (!$registry->has($name)) {
                $this->logger->error('0MQ no such queue', [$name]);
                return;
            }

            $this->pollQueue($registry, $name, $id);
            gc_collect_cycles();
        }

        return 0;
    }

    /*
     * Connect to the controller router socket
     */

    private function connect($queues, $socket) {
        $options = $queues[0]->getOptions();
        if (!array_key_exists('zeromq_worker_socket', $options)) {
            return 0;
        }

        $this->logger->debug('0MQ binding to ', [$options['zeromq_worker_socket']]);
        $socket->connect($options['zeromq_worker_socket']);
    }

    private function pollQueue($registry, $name, $id) {
        $dispatcher = $this->container->get('event_dispatcher');
        $message = $registry->get($name)->receiveOne($id);

        $messageEvent = new MessageEvent($name, $message);
        $dispatcher->dispatch(Events::Message($name), $messageEvent);
    }

}
