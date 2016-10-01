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
    protected $output;
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
        $this->output = $output;
        $this->logger = $this->container->get('logger');

        $context = new \ZMQContext();
        $this->logger->debug('0MQ context setup');
        $socket = new \ZMQSocket($context, \ZMQ::SOCKET_PULL);
        $this->logger->debug('0MQ socket setup');

        $registry = $this->container->get('uecode_qpush');
        $name = $input->getArgument('name');

        $dispatcher = $this->container->get('event_dispatcher');

         if ($name !== null && !$registry->has($name)) {
             return $this->output->writeln(
                            sprintf("The [%s] queue you have specified does not exist!", $name));
         }
         
        if ($name  !== null ) {
            $queues[] = $this->bindQueue($registry, $name, $socket);        
        } else {
            $queues = $registry->all();
        }
        
        foreach ($queues as $queue) {
            $this->bindQueue($queue, $socket);
        }
        
        $this->logger->debug('0MQ ready to receive');
        while (true) {
            $notification = $socket->recv();
            $this->logger->debug('0MQ notification received', $notification);

            if (sscanf($notification, '%s %d', $name, $id) != 2) {
                continue;
            }
            $registry->get($name)->receive();
        }

        return 0;
    }

    private function bindQueue($queue, $socket)
    {
        $options = $queue->getOptions();
        if (array_key_exists('zeromq_socket', $options)) {
            $this->logger->debug('0MQ binding to ', $options['zeromq_socket']);
            $socket->bind($options['zeromq_socket']);
        }
       
        return 0;
    }

}
