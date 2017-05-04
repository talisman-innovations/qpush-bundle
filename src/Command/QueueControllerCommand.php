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
    protected $registry;
    protected $logger;
    protected $output;

    public function setContainer(ContainerInterface $container = null) {

        $this->container = $container;
        $this->logger = $this->container->get('logger');
        $this->registry = $this->container->get('uecode_qpush');
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
        $socket = new \ZMQSocket($context, \ZMQ::SOCKET_PULL);
        $socket->setSockOpt(\ZMQ::SOCKOPT_RCVTIMEO, $check);

        $this->bindQueues($queues, $socket);

        $this->logger->debug('0MQ ready to receive');
        while (time() < $time) {
            $notification = $socket->recv();

            if ($notification) {
                $this->logger->debug('0MQ notification received', [$notification]);

                if (sscanf($notification, '%s %d', $name, $id) != 2) {
                    continue;
                }

                if (!$this->registry->has($name)) {
                    $this->logger->debug('0MQ no such queue', [$name]);
                    continue;
                }

                $this->pollQueueOne($name, $id);
            } else {
                foreach ($this->registry->all() as $queue) {
                    $this->pollQueue($queue->getName());
                }
            }
            gc_collect_cycles();
        }

        return 0;
    }

    /*
     * Bind to all the queues using ZeroMQ
     */

    private function bindQueues($queues, $socket) {

        foreach ($queues as $queue) {
            $options = $queue->getOptions();
            if (!array_key_exists('zeromq_controller_socket', $options)) {
                continue;
            }
            $endpoints[] = $options['zeromq_controller_socket'];
        }

        if (!isset($endpoints)) {
            return;
        }

        $endpoints = array_unique($endpoints);
        foreach ($endpoints as $endpoint) {
            $this->logger->debug('0MQ binding to ' . $endpoint);
            $socket->bind($endpoint);
        }

        return;
    }

    private function pollQueue($name) {
        $dispatcher = $this->container->get('event_dispatcher');
        $messages = $this->registry->get($name)->receive();

        foreach ($messages as $message) {
            $messageEvent = new MessageEvent($name, $message);
            $dispatcher->dispatch(Events::Message($name), $messageEvent);
        }

        $msg = sprintf('Polling Queue %s, %d messages fetched', $name, sizeof($messages));
        $this->logger->debug($msg);
        $this->output->writeln($msg);

        return sizeof($messages);
    }

    private function pollQueueOne($name, $id) {
        $dispatcher = $this->container->get('event_dispatcher');
        $message = $this->registry->get($name)->receiveOne($id);

        $messageEvent = new MessageEvent($name, $message);
        $dispatcher->dispatch(Events::Message($name), $messageEvent);

        $msg = sprintf('Polling Queue %s, message %d fetched', $name, $id);
        $this->logger->debug($msg);
        $this->output->writeln($msg);

        return 1;
    }

}
