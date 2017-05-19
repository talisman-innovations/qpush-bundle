<?php

/*
 * Copyright Talisman Innovations Ltd. (2016). All rights reserved.
 */

namespace Uecode\Bundle\QPushBundle\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Uecode\Bundle\QPushBundle\Event\MessageEvent;

/**
 * @author Steven Brookes <steven.brookes@talisman-innovations.com>
 */
class QueueWorkerCommand extends Command implements ContainerAwareInterface {

    protected $container;
    protected $logger;
    protected $output;
    protected $dispatcher;
    protected $registry;

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
        $this->dispatcher = $this->container->get('event_dispatcher');
        $this->registry = $this->container->get('uecode_qpush');

        $time = ($input->getOption('time') === null) ? PHP_INT_MAX : time() + $input->getOption('time');

        $queues = $this->registry->all();

        $context = new \ZMQContext();
        $socket = new \ZMQSocket($context, \ZMQ::SOCKET_REQ);
        $socket->setSockOpt(\ZMQ::SOCKOPT_IDENTITY, getmypid());

        $this->connect($queues, $socket);

        $this->logger->debug('0MQ ready to receive');
        gc_enable();
        while (time() < $time) {
            $socket->send('ready');
            $notification = $socket->recv();

            if (sscanf($notification, '%s %d %s', $name, $id, $callable) != 3) {
                $this->logger->error('0MQ incorrect notification format', [$notification]);
                return;
            }

            if (!$this->registry->has($name)) {
                $this->logger->error('0MQ no such queue', [$name]);
                return;
            }

            $this->logger->debug(getmypid() .' 0MQ notification received ' . $notification);
            $this->pollQueueOne($name, $id, $callable);
            
            unset($notification);
            gc_collect_cycles();
        }

        return 0;
    }

    /*
     * Connect to the controller router socket
     */

    private function connect($queues, $socket) {
        foreach ($queues as $queue) {
            $options = $queue->getOptions();
            if (!array_key_exists('zeromq_worker_socket', $options)) {
                continue;
            }
            $endpoints[] = $options['zeromq_worker_socket'];
        }

        if (!isset($endpoints)) {
            return;
        }

        $endpoints = array_unique($endpoints);

        foreach ($endpoints as $endpoint) {
            $this->logger->debug('0MQ binding to ', [$endpoint]);
            $socket->connect($endpoint);
        }
    }

    /*
     * Retrieve the message, lookup the callable and call it
     */

    private function pollQueueOne($name, $id, $callable) {

        $eventName = $name . '.message_received';
        if (!$listener = $this->findListener($eventName, $callable)) {
            return 0;
        }

        $queue = $this->registry->get($name);
        $message=$queue->receiveOne($id);
        $messageEvent = new MessageEvent($name, $message);

        try {
            $result = call_user_func($listener, $messageEvent, $eventName, $this->dispatcher);
            $result = is_null($result) ? 0 : $result;
        } catch (\Exception $e) {
            $this->logger->error('Caught exception: '. $e->getMessage());
            $result = MessageEvent::MESSAGE_EVENT_EXCEPTION;
        }
        
        $queue->storeResult($id, $callable, $result);
        unset($message, $messageEvent);
        
        return 1;
    }

    private function findListener($eventName, $callable) {
        $listeners = $this->dispatcher->getListeners($eventName);
        foreach ($listeners as $listener) {
            if (get_class($listener[0]) . '::' . $listener[1] === $callable) {
                return $listener;
            }
        }
    }

}
