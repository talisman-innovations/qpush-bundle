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
class QueueControllerCommand extends Command implements ContainerAwareInterface {

    protected $container;
    protected $logger;
    protected $output;

    public function setContainer(ContainerInterface $container = null) {
        $this->container = $container;
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
        $this->logger = $this->container->get('logger');
        $registry = $this->container->get('uecode_qpush');
        $name = $input->getArgument('name');
        $time = ($input->getOption('time') === null) ? PHP_INT_MAX : time() + $input->getOption('time');
        $check = ($input->getOption('check') === null) ? 60000 : $input->getOption('check') * 1000;

        if ($name !== null && !$registry->has($name)) {
            $msg = sprintf("The [%s] queue you have specified does not exist!", $name);
            return $output->writeln($msg);
        }

        if ($name !== null) {
            $queues[] = $registry->get($name);
        } else {
            $queues = $registry->all();
        }

        $context = new \ZMQContext();

        $pullSocket = new \ZMQSocket($context, \ZMQ::SOCKET_PULL);
        $pullSocket->setSockOpt(\ZMQ::SOCKOPT_RCVTIMEO, $check);
        $this->bindPullSocket($queues, $pullSocket);

        $routerSocket = new \ZMQSocket($context, \ZMQ::SOCKET_ROUTER);
        $this->bindRouterSocket($queues, $routerSocket);

        $this->logger->debug('0MQ ready to receive');

        while (time() < $time) {
            $this->pull($pullSocket, $routerSocket);
            gc_collect_cycles();
        }

        $this->logger->debug('0MQ exiting');
        return 0;
    }

    /*
     * Try to receive message from ZeroMQ or timeout and poll queue
     */

    private function pull($pullSocket, $routerSocket) {
        $notification = $pullSocket->recv();

        if ($notification) {
            $this->logger->debug('0MQ notification received', [$notification]);

            if (sscanf($notification, '%s %d', $name, $id) != 2) {
                $this->logger->error('0MQ incorrect notification format', [$notification]);
                return;
            }

            if (!$registry->has($name)) {
                $this->logger->error('0MQ no such queue', [$name]);
                return;
            }

            $this->notifyWorker($name, $id, $routerSocket);
        } else {
            foreach ($registry->all() as $queue) {
                $this->checkQueue($registry, $queue->getName());
            }
        }
    }

    /*
     * Bind the pull socket
     */

    private function bindPullSocket($queues, $socket) {
        $options = $queues[0]->getOptions();
        if (!array_key_exists('zeromq_controller_socket', $options)) {
            return;
        }

        $this->logger->debug('0MQ binding to ', [$options['zeromq_controller_socket']]);
        $socket->bind($options['zeromq_controller_socket']);
    }

    /*
     * Bind the Router socket
     */

    private function bindRouterSocket($queues, $socket) {
        $options = $queues[0]->getOptions();
        if (!array_key_exists('zeromq_worker_socket', $options)) {
            return;
        }

        $this->logger->debug('0MQ binding to ', [$options['zeromq_worker_socket']]);
        $socket->bind($options['zeromq_worker_socket']);
    }

    /*
     * Notify the worker process of a message to process
     */

    private function notifyWorker($name, $id, $socket) {

        // Find the LRU worker which is waiting
        $address = $socket->recv();
        $empty = $socket->recv();
        $read = $socket->recv();

        $socket->send($address, ZMQ::MODE_SNDMORE);
        $socket->send("", ZMQ::MODE_SNDMORE);
        $notification = sprintf('%s %d', $name, $id);
        $socket->send("$notification");
    }

}
