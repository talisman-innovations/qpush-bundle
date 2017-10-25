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

/**
 * @author Steven Brookes <steven.brookes@talisman-innovations.com>
 */
class QueueControllerCommand extends Command implements ContainerAwareInterface {

    protected $container;
    protected $registry;
    protected $logger;
    protected $output;
    protected $messageQueue = array();
    protected $workerQueue = array();

    use \Uecode\Bundle\QPushBundle\Traits\EndpointTrait;

    public function setContainer(ContainerInterface $container = null) {

        $this->container = $container;
        $this->registry = $this->container->get('uecode_qpush');
        $this->logger = $this->container->get('logger');
        $this->dispatcher = $this->container->get('event_dispatcher');
    }

    protected function configure() {
        $this
                ->setName('uecode:qpush:controller')
                ->setDescription('Controller process to poll the configured queues')
                ->addArgument(
                        'name', InputArgument::OPTIONAL, 'Name of a specific queue to poll'
                )
                ->addOption(
                        'time', 't', InputOption::VALUE_OPTIONAL, 'Time to run before exit (seconds)'
                )
                ->addOption(
                        'check', 'c', InputOption::VALUE_OPTIONAL, 'Check all queues every (seconds)'
                )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output) {
        $this->output = $output;

        $name = $input->getArgument('name');
        $time = ($input->getOption('time') === null) ? PHP_INT_MAX : time() + $input->getOption('time');
        $check = ($input->getOption('check') === null) ? 60000 : $input->getOption('check') * 1000;

        if ($name !== null && !$this->registry->has($name)) {
            $msg = sprintf("The [%s] queue you have specified does not exist!", $name);
            return $output->writeln($msg);
        }

        if ($name !== null) {
            $queues[] = $this->registry->get($name);
        } else {
            $queues = $this->registry->all();
        }

        $context = new \ZMQContext();
        $pullSocket = new \ZMQSocket($context, \ZMQ::SOCKET_PULL);
        $pullSocket->setSockOpt(\ZMQ::SOCKOPT_RCVTIMEO, $check);

        $this->bindQueues($queues, $pullSocket);

        $routerSocket = new \ZMQSocket($context, \ZMQ::SOCKET_ROUTER);
        $routerSocket->setSockOpt(\ZMQ::SOCKOPT_LINGER, 0);

        $this->bindRouterSocket($queues, $routerSocket);

        $poll = new \ZMQPoll();
        $poll->add($routerSocket, \ZMQ::POLL_IN);
        $poll->add($pullSocket, \ZMQ::POLL_IN);

        $this->logger->debug(getmypid() . ' 0MQ controller ready to receive');

        while (true) {
            $events = $poll->poll($read, $write);

            foreach ($read as $socket) {
                if ($socket === $routerSocket) {
                    $this->processWorkerRequest($socket);
                } elseif ($socket === $pullSocket) {
                    $this->processClientRequest($socket);
                }
            }
        }

        $this->logger->debug(getmypid() . ' 0MQ controller exiting');

        $this->unbindQueues($queues, $pullSocket);
        $this->unbindRouterSocket($queues, $routerSocket);

        return 0;
    }

    /*
     * Bind to all the queues using ZeroMQ
     */

    private function bindQueues($queues, $socket) {
        $endpoints = $this->endpoints($queues, 'zeromq_controller_socket');

        foreach ($endpoints as $endpoint) {
            $this->logger->debug(getmypid() . ' 0MQ binding to ' . $endpoint);
            $socket->bind($endpoint);
        }

        return;
    }

    private function bindRouterSocket($queues, $socket) {
        $endpoints = $this->endpoints($queues, 'zeromq_worker_socket');

        foreach ($endpoints as $endpoint) {
            $this->logger->debug(getmypid() . ' 0MQ binding to ' . $endpoint);
            $socket->bind($endpoint);
        }

        return;
    }

    /*
     * Unbind to all the queues using ZeroMQ
     */

    private function unbindQueues($queues, $socket) {
        $endpoints = $this->endpoints($queues, 'zeromq_controller_socket');

        foreach ($endpoints as $endpoint) {
            $this->logger->debug(getmypid() . ' 0MQ unbinding from ' . $endpoint);
            $socket->unbind($endpoint);
        }

        return;
    }

    private function unbindRouterSocket($queues, $socket) {
        $endpoints = $this->endpoints($queues, 'zeromq_worker_socket');

        foreach ($endpoints as $endpoint) {
            $this->logger->debug(getmypid() . ' 0MQ unbinding from ' . $endpoint);
            $socket->unbind($endpoint);
        }

        return;
    }

    /*
     * Process any messages not delivered by ZeroMQ locally
     */

    private function pollQueue($name) {

        $messages = $this->registry->get($name)->receive();

        foreach ($messages as $message) {
            $messageEvent = new MessageEvent($name, $message);
            $this->dispatcher->dispatch(Events::Message($name), $messageEvent);
        }

        $msg = sprintf('Polling Queue %s, %d messages fetched', $name, sizeof($messages));
        $this->logger->debug($msg);
        $this->output->writeln($msg);

        return sizeof($messages);
    }

    /*
     * Notify the worker process of a message to process
     */

    private function processQueues() {

        $this->logger->debug('0MQ controller messageQueue', $this->messageQueue);
        $this->logger->debug('0MQ controller workerQueue', $this->workerQueue);

        for ($i = 0; $i < min(count($this->workerQueue), count($this->messageQueue)); $i++) {
            $address = array_shift($this->workerQueue);
            $message = array_shift($this->messageQueue);
            $this->logger->debug(getmypid() . ' 0MQ controller notify worker', [$address, $message]);
            $socket->send($address, \ZMQ::MODE_SNDMORE);
            $socket->send("", \ZMQ::MODE_SNDMORE);
            $socket->send($message);
        }
    }

    /*
     * Process a request from a workder
     * READY - add it to the list of availabale workers
     * BUSY - remove from list of available workers
     * finally check if any messages to pass onto available workers
     */

    private function processWorkerRequest($socket) {
        $address = $socket->recv();
        $socket->recv();
        $state = $socket->recv();

        switch ($state) {
            case 'READY':
                $this->workerQueue[] = $address;
                break;
            case 'BUSY':
                unset($this->workerQueue[array_search($address, $this->workerQueue)]);
                break;
            default:
                $this->logger->debug('0MQ controller unknow worker state', [$state]);
                break;
        }
        $this->processQueues();
    }

    /*
     * Process a request froma client
     * Add a list of worker messages to the messgae queue
     * finally check if any messages to pass onto available workers
     */

    private function processClientRequest($socket) {

        $notification = $socket->recv();

        if (sscanf($notification, '%s %d', $name, $id) != 2) {
            $this->logger->debug('0MQ controller incorrect client message format', [$notification]);
            return;
        }

        if (!$this->registry->has($name)) {
            $this->logger->debug('0MQ controller no such queue', [$name]);
            return;
        }

        $eventName = $name . '.message_received';
        $listeners = $this->dispatcher->getListeners($eventName);

        foreach ($listeners as $listener) {
            if ($this->dispatcher->getListenerPriority($eventName, $listener) < 0) {
                continue;
            }
            $callable = get_class($listener[0]) . '::' . $listener[1];
            $this->messageQueue[] = sprintf('%s %d %s', $name, $id, $callable);
        }

        $this->processQueues();
    }

}
