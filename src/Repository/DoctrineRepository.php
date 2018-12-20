<?php

/**
 * Copyright Talisman Innovations Ltd. (2018). All rights reserved.
 */

namespace Uecode\Bundle\QPushBundle\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Talisman\TideBundle\Service\TenantManager;
use Talisman\TideBundle\Repository\TenantAwareBaseRepository;
use Uecode\Bundle\QPushBundle\Entity\DoctrineMessage;

class DoctrineRepository extends TenantAwareBaseRepository {

    const DEFAULT_PERIOD = 300;

    public function __construct(EntityManagerInterface $em, TenantManager $tenantManager) {
        parent::__construct($em, $tenantManager);
        $this->repository = $em->getRepository(DoctrineMessage::class);
    }

    /**
     * @param string $queue
     * @param array $data
     * @return array
     */
    public function getCount($queue, $data = null) {
        
        $statement = $this->createQueryBuilder('q');

        if (isset($data['period']) && $data['period'] !== null) {
            $period = $data['period'];
        } else {
            $period = self::DEFAULT_PERIOD;
        }

        /* WARNING - don't remove any of the parentheses, add any spaces, or change any of the names without extensive testing */
        $expression = 'FROM_UNIXTIME(((FLOOR((UNIX_TIMESTAMP(q.created)/' . $period . ')))*' . $period . ')) as time, count(q.created) as counter';

        $statement->select($expression);
        $statement->andWhere('q.queue = :queue');
        $statement->setParameter('queue', $queue);

        if (isset($data['from']) && $data['from'] !== null) {
            $statement->andWhere('q.created >= :from');
            $statement->setParameter('from', $data['from']);
        }

        if (isset($data['to']) && $data['to'] !== null) {
            $statement->andWhere('q.created <= :to');
            $statement->setParameter('to', $data['to']);
        }

        $statement->groupBy('time');
        $statement->orderBy('time', 'ASC');

        $query = $statement->getQuery();

        $results = $query->getArrayResult();

        return $results;
    }

    /*
     * Get metadata about undelivered messages
     * 
     * @var string $queue
     * @return array()
     */

    public function getUndeliveredMetadata($queue) {

        $query = $this->createQueryBuilder('q');

        $query->select(['q.id', 'q.transactionId'])
                ->addSelect('t.id as tenantId')
                ->join('q.tenant', 't')
                ->andWhere($query->expr()->eq('q.delivered', 'false'))
                ->andWhere($query->expr()->eq('q.queue', ':queue'))
                ->setParameter('queue', $queue)
                ->orderBy('q.id', 'ASC');

        return $query->getQuery()->getResult();
    }

}
