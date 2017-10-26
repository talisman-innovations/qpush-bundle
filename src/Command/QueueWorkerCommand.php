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

    use \Uecode\Bundle\QPushBundle\Traits\EndpointTrait;

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
        $check = ($input->getOption('time') === null) ? -1 : $input->getOption('time') * 1000;

        $queues = $this->registry->all();

        $context = new \ZMQContext();
        $socket = new \ZMQSocket($context, \ZMQ::SOCKET_REQ);
        $socket->setSockOpt(\ZMQ::SOCKOPT_IDENTITY, getmypid());
        $socket->setSockOpt(\ZMQ::SOCKOPT_LINGER, 0);
        $socket->setSockOpt(\ZMQ::SOCKOPT_REQ_RELAXED, 1);

        if ($input->getOption('time')) {
            $socket->setSockOpt(\ZMQ::SOCKOPT_RCVTIMEO, $input->getOption('time') * 1000);
        }

        $this->connect($queues, $socket);
        $poll = new \ZMQPoll();
        $poll->add($socket, \ZMQ::POLL_IN);

        $read = $write = array();

        $this->logger->debug(getmypid() . ' 0MQ ready to receive');

        while (time() < $time) {
            $socket->send('READY');
            $this->logger->debug(getmypid() . ' 0MQ sent ready');

            try {
                $events = $poll->poll($read, $write, $check);
                $errors = $poll->getLastErrors();

                if (count($errors) > 0) {
                    $this->logger->error('Error polling', $errors);
                }
            } catch (ZMQPollException $e) {
                $this->logger->error('Exception polling', [$e->getMessage()]);
            }

            if ($events == 0) {
                continue;
            }

            foreach ($read as $socket) {
                
                $notification = $socket->recv();
                
                if (sscanf($notification, '%s %d %s', $name, $id, $callable) != 3) {
                    $this->logger->error(getmypid() . ' 0MQ worker incorrect notification format', [$notification]);
                    return;
                }

                if (!$this->registry->has($name)) {
                    $this->logger->error(getmypid() . ' 0MQ worker no such queue', [$name]);
                    return;
                }

                $this->logger->debug(getmypid() . ' 0MQ worker notification received ', [$notification]);
                $this->pollQueueOne($name, $id, $callable);
            }
        }

        $this->logger->debug(getmypid() . ' 0MQ worker exiting');

        $socket->send('BUSY');
        $this->disconnect($queues, $socket);
        return 0;
    }

    /*
     * Connect to the controller router socket
     */

    private function connect($queues, $socket) {
        $endpoints = $this->endpoints($queues, 'zeromq_worker_socket');

        foreach ($endpoints as $endpoint) {
            $this->logger->debug(getmypid() . ' 0MQ connecting to ', [$endpoint]);
            $socket->connect($endpoint);
        }
    }

    /*
     * Disconnect from the controller router socket
     */

    private function disconnect($queues, $socket) {
        $endpoints = $this->endpoints($queues, 'zeromq_worker_socket');

        foreach ($endpoints as $endpoint) {
            $this->logger->debug(getmypid() . ' 0MQ disconnecting from ', [$endpoint]);
            $socket->disconnect($endpoint);
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
        $message = $queue->receiveOne($id);
        $messageEvent = new MessageEvent($name, $message);

        try {
            $result = call_user_func($listener, $messageEvent, $eventName, $this->dispatcher);
            $result = is_null($result) ? 0 : $result;
        } catch (\Exception $e) {
            $this->logger->error('Caught exception: ' . $e->getMessage());
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
