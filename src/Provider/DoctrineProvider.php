<?php

/**
 * Copyright Talisman Innovations Ltd. (2016). All rights reserved
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * @package     qpush-bundle
 * @copyright   Talisman Innovations Ltd. (2016)
 * @license     Apache License, Version 2.0
 */

namespace Uecode\Bundle\QPushBundle\Provider;

use Doctrine\ORM\Query\Expr\Join;
use Doctrine\Common\Cache\Cache;
use Monolog\Logger;
use Uecode\Bundle\QPushBundle\Message\Message;
use Uecode\Bundle\QPushBundle\Entity\DoctrineMessage;
use Uecode\Bundle\QPushBundle\Entity\DoctrineMessageResult;

class DoctrineProvider extends AbstractProvider {

    const DEFAULT_PERIOD = 300;
    const METADATA_TENANT_ID = 'tenantId';
    const METADATA_TRANSACTION_ID = 'transactionId';

    protected $em;
    protected $repository;
    protected static $entityName = 'Uecode\Bundle\QPushBundle\Entity\DoctrineMessage';
    protected $context;
    protected $sender;

    /**
     * Constructor for Provider classes
     *
     * @param string $name    Name of the Queue the provider is for
     * @param array  $options An array of configuration options for the Queue
     * @param mixed  $client  A Queue Client for the provider
     * @param Cache  $cache   An instance of Doctrine\Common\Cache\Cache
     * @param Logger $logger  An instance of Symfony\Bridge\Mongolog\Logger
     */
    public function __construct($name, array $options, $client, Cache $cache, Logger $logger) {
        $this->name = $name;
        $this->options = $options;
        $this->cache = $cache;
        $this->logger = $logger;
        $this->em = $client;
        $this->repository = $this->em->getRepository(self::$entityName);

        if (class_exists('\ZMQ') &&
                array_key_exists('zeromq_controller_socket', $this->options)) {
            $this->context = new \ZMQContext();
            $this->sender = new \ZMQSocket($this->context, \ZMQ::SOCKET_PUSH);
            $this->sender->connect($this->options['zeromq_controller_socket']);
        }
    }

    /**
     * Returns the Queue Provider name
     *
     * @return string
     */
    public function getProvider() {
        return 'Doctrine';
    }

    /**
     * Get repository
     *
     * @return array
     */
    public function getRepository() {
        return $this->repository;
    }

    /**
     * Creates the Queue
     * Checks to see if the underlying table has been created or not
     *
     * @return bool
     */
    public function create() {
        $sm = $this->em->getConnection()->getSchemaManager();
        $table = $this->em->getClassMetadata(self::$entityName)->getTableName();

        return $sm->tablesExist(array($table));
    }

    /**
     * Publishes a message to the Queue
     *
     * This method should return a string MessageId or Response
     *
     * @param array $message The message to queue
     * @param array $options An array of options that override the queue defaults
     *
     * @return string
     *
     * @throws \Exception
     */
    public function publish(array $message, array $options = []) {

        $doctrineMessage = new DoctrineMessage();
        $doctrineMessage->setQueue($this->name)
                ->setDelivered(false)
                ->setMessage($message)
                ->setLength(strlen(serialize($message)));

        $this->em->persist($doctrineMessage);
        $this->em->flush();
        $id = $doctrineMessage->getId();
        $tenantId = $doctrineMessage->getTenant()->getId();
        $transactionId = $doctrineMessage->getTransactionId();

        if (isset($this->sender)) {
            $this->push($this->sender, $this->name, $id, $tenantId, $transactionId);
        }

        return (string) $id;
    }

    /**
     * Pushes a message to the controller using ZeroMQ
     *
     * @param socket $sender The ZeroMQ socket to send to
     * @param string $name The name of the queue
     * @param integer $id  The ID os the message
     */
    protected function push($sender, $name, $id, $tenantId, $transactionId) {

        $notification = sprintf('%s %d %d %s', $name, $id, $tenantId, $transactionId);
        $sender->send($notification);
    }

    /**
     * Polls the Queue for Messages
     *
     * Depending on the Provider, this method may keep the connection open for
     * a configurable amount of time, to allow for long polling.  In most cases,
     * this method is not meant to be used to long poll indefinitely, but should
     * return in reasonable amount of time
     *
     * @param  array $options An array of options that override the queue defaults
     *
     * @return array
     */
    public function receive(array $options = []) {

        $doctrineMessages = $this->repository->findBy(
                array('delivered' => false, 'queue' => $this->name), array('id' => 'ASC')
        );

        $messages = [];
        foreach ($doctrineMessages as $doctrineMessage) {
            $messages[] = new Message($doctrineMessage->getId(), $doctrineMessage->getMessage(), [self::METADATA_TENANT_ID => $doctrineMessage->getTenantId(),
                self::METADATA_TRANSACTION_ID => $doctrineMessage->getTransactionId()]);
            $doctrineMessage->setDelivered(true);
        }
        $this->em->flush();

        return $messages;
    }

    /*
     * Receive a single message from the Queue
     *
     * @param $id ID of the message to receieve
     *
     * @return Message
     */

    public function receiveOne($id) {

        $doctrineMessage = $this->getById($id);
        $message = new Message($id, $doctrineMessage->getMessage(), [self::METADATA_TENANT_ID => $doctrineMessage->getTenantId(),
            self::METADATA_TRANSACTION_ID => $doctrineMessage->getTransactionId()]);
        $doctrineMessage->setDelivered(true);
        $this->em->flush();
        return $message;
    }

    /**
     * Deletes the Queue Message
     *
     * @param mixed $id A message identifier or resource
     */
    public function delete($id) {
        $doctrineMessage = $this->repository->find($id);
        $doctrineMessage->setDelivered(true);
        $this->em->flush();

        return true;
    }

    /**
     * Destroys a Queue and clears any Queue related Cache
     *
     * @return bool
     */
    public function destroy() {
        $qb = $this->repository->createQueryBuilder('dm');
        $qb->delete();
        $qb->where('dm.queue = :queue');
        $qb->setParameter('queue', $this->name);
        $qb->getQuery()->execute();

        return true;
    }

    /**
     * Returns a specific message
     *
     * @param integer $id
     *
     * @return Message
     */
    public function getById($id) {
        return $this->repository->find($id);
    }

    /**
     * Returns a query of the message queue
     *
     * @param array $data ['field'=>'id', 'search'=>'text', 'to'=>date, from=>date]
     * @return Query
     *
     */
    public function paginate($data) {
        return $this->repository->paginate($this->name, $data);
    }

    /*
     * Returns an array of times and messgae counts
     * @praram $data ['from' => date, 'to' => date, 'period' => seconds
     * @return ['time', 'count']
     */

    public function counts($data = null) {

        $results = $this->repository->getCount($this->name, $data);

        return $results;
    }

    /**
     * Re deliver message to queue
     * @param int $id
     * @return string
     */
    public function redeliver($id) {

        $message = $this->repository->find($id);
        $tenantId = $message->getTenantId();
        $transactionId = $message->getTransactionId();

        $message->setDelivered(false);
        $this->em->flush();

        if (isset($this->sender)) {
            $this->push($this->sender, $this->name, $id, $tenantId, $transactionId);
        }

        return (string) $id;
    }

    /*
     * Store the result of a message being processed
     */

    public function storeResult($id, $callable, $result) {

        $doctrineMessage = $this->repository->find($id);

        $doctrineMessageResult = new DoctrineMessageResult();
        $doctrineMessageResult->setCallable($callable);
        $doctrineMessageResult->setResult($result);
        $doctrineMessageResult->setMessage($doctrineMessage);

        $this->em->persist($doctrineMessageResult);
        $this->em->flush();

        return $doctrineMessageResult;
    }

}
