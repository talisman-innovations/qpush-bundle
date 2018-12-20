<?php

/**
 * Copyright Talisman Innovations Ltd. (2018). All rights reserved.
 */

namespace Uecode\Bundle\QPushBundle\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Talisman\TideBundle\Service\TenantManager;
use Talisman\TideBundle\Repository\TenantAwareBaseRepository;
use Uecode\Bundle\QPushBundle\Entity\DoctrineMessageResult;
use Doctrine\ORM\Query\Expr\Join;

class DoctrineResultRepository extends TenantAwsareBaseRepository {

    const DEFAULT_PERIOD = 300;

    public function __construct(EntityManagerInterface $em, TenantManager $tenantManager) {
        parent::__construct($em, $tenantManager);
        $this->repository = $em->getRepository(DoctrineMessageResult::class);
    }

    public function paginate($data = []) {

        $statement = $this->createQueryBuilder('r');

        $statement->addSelect('q.queue');
        $statement->addSelect('t.name');

        $statement->join('Uecode\Bundle\QPushBundle\Entity\DoctrineMessage', 'q', Join::WITH, 'r.message = q');
        $statement->join('Talisman\TideBundle\Entity\Tenant', 't', Join::WITH, 't = r.tenant');

        if (isset($data['result']) && $data['result'] !== null) {

            $statement->andWhere('r.result = :result');
            $statement->setParameter('result', $data['result']);
            $statement->andWhere('q.queue = :queue');
            $statement->setParameter('queue', $data['queue']);

            if (isset($data['from']) && $data['from'] !== null) {
                $statement->andWhere('q.created >= :from');
                $statement->setParameter('from', $data['from']);
            }

            if (isset($data['to']) && $data['to'] !== null) {
                $statement->andWhere('q.created <= :to');
                $statement->setParameter('to', $data['to']);
            }
        }

        return $statement->getQuery();
    }

    public function getCount($queue, $result, $data) {
        
        $statement = $this->createQueryBuilder('r');

        if (isset($data['period']) && $data['period'] !== null) {
            $period = $data['period'];
        } else {
            $period = self::DEFAULT_PERIOD;
        }

        /* WARNING - don't remove any of the parentheses, add any spaces, or change any of the names without extensive testing */
        $expression = 'FROM_UNIXTIME(((FLOOR((UNIX_TIMESTAMP(r.created)/' . $period . ')))*' . $period . ')) as time, count(r.created) as counter';

        $statement->select($expression);
        $statement->addSelect('q.queue');

        $statement->innerJoin('Uecode\Bundle\QPushBundle\Entity\DoctrineMessage', 'q');

        $statement->andWhere('q.queue = :queue');
        $statement->setParameter('queue', $queue);
        $statement->andWhere('r.result = :result');
        $statement->setParameter('result', $result);

        if (isset($data['from']) && $data['from'] !== null) {
            $statement->andWhere('r.created >= :from');
            $statement->setParameter('from', $data['from']);
        }

        if (isset($data['to']) && $data['to'] !== null) {
            $statement->andWhere('r.created <= :to');
            $statement->setParameter('to', $data['to']);
        }

        $statement->groupBy('time');
        $statement->orderBy('time', 'ASC');

        $query = $statement->getQuery();

        $results = $query->getArrayResult();

        return $results;
    }

}
