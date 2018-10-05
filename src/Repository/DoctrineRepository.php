<?php

/**
 * Copyright Talisman Innovations Ltd. (2018). All rights reserved.
 */

namespace Uecode\Bundle\QPushBundle\Repository;

use Doctrine\ORM\EntityRepository;

class DoctrineRepository extends EntityRepository {

    const DEFAULT_PERIOD = 300;

    /**
     * @param string $queue
     * @param array $data
     * @return array
     */
    public function getCount($queue, $data = null) {
        $statement = $this->getEntityManager()->createQueryBuilder();

        if (isset($data['period']) && $data['period'] !== null) {
            $period = $data['period'];
        } else {
            $period = self::DEFAULT_PERIOD;
        }

        /* WARNING - don't remove any of the parentheses, add any spaces, or change any of the names without extensive testing */
        $expression = 'FROM_UNIXTIME(((FLOOR((UNIX_TIMESTAMP(q.created)/' . $period . ')))*' . $period . ')) as time, count(q.created) as counter';

        $statement->select($expression);
        $statement->from('Uecode\Bundle\QPushBundle\Entity\DoctrineMessage', 'q');
        $statement->where('q.queue = :queue');
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

    /**
     * Returns a query of the message queue
     *
     * @param array $data ['field'=>'id', 'search'=>'text', 'to'=>date, from=>date]
     * @return Query
     *
     */
    public function paginate($name, $data) {

        $qb = $this->getEntityManager()->createQueryBuilder();

        $qb->select('p');
        $qb->from('Uecode\Bundle\QPushBundle\Entity\DoctrineMessage', 'p');

        $qb->addSelect('t.name');
        $qb->join('Talisman\TideBundle\Entity\Tenant', 't');

        $qb->where('p.queue = :queue');
        $qb->setParameter('queue', $name);

        $field = (isset($data['field'])) ? $data['field'] : 'message';

        if (isset($data['search']) && $data['search'] !== null) {
            switch ($field) {
                case 'message':
                    $qb->andWhere('MATCH(p.' . $field . ') AGAINST(:contains boolean) > 0');
                    $qb->setParameter('contains', $data['search']);
                    break;
                case 'sourceId':
                    $qb->andWhere('MATCH(p.message) AGAINST(:contains boolean) > 0');
                    $qb->setParameter('contains', 'sourceId +' . $data['search']);
                    break;
                default :
                    $qb->andWhere('p.' . $field . ' = :equals');
                    $qb->setParameter('equals', $data['search']);
                    break;
            }
        }

        if (isset($data['from']) && $data['from'] !== null && isset($data['to']) && $data['to'] !== null) {
            $qb->andWhere('p.created BETWEEN :from AND :to');
            $qb->setParameter('from', $data['from']);
            $qb->setParameter('to', $data['to']);
        }

        return $qb->getQuery();
    }

}
