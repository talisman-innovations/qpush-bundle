<?php
/**
 * Copyright Talisman Innovations Ltd. (2018). All rights reserved.
 */

namespace Uecode\Bundle\QPushBundle\Repository;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query\Expr\Join;

class DoctrineResultRepository extends EntityRepository {
    
    const DEFAULT_PERIOD = 300;
    

    public function paginate($data = []) {
        
        $statement = $this->getEntityManager()->createQueryBuilder();
        $statement->select('p');
        $statement->from('Uecode\Bundle\QPushBundle\Entity\DoctrineMessageResult', 'p');
        $statement->addSelect('q.queue');
        $statement->innerJoin('Uecode\Bundle\QPushBundle\Entity\DoctrineMessage', 'q', Join::WITH, 'p.message = q.id');
        $statement->addSelect('t.name');
        $statement->innerJoin('Talisman\TideBundle\Entity\Tenant', 't', Join::WITH, 't.id = p.tenantId');
                
        if (isset($data['result']) && $data['result'] !== null) {
           
            $statement->where('p.result = :result');
            $statement->setParameter('result', $data['result']);
            $statement->andWhere('q.queue = :queue');
            $statement->setParameter('queue', $data['queue']);

            if (isset($data['from']) && $data['from'] !== null) {
                $statement->andWhere('p.created >= :from');
                $statement->setParameter('from', $data['from']);
            }

            if (isset($data['to']) && $data['to'] !== null) {
                $statement->andWhere('p.created <= :to');
                $statement->setParameter('to', $data['to']);
            }
          
        }

        return $statement->getQuery();
    }
    
    public function getCount($queue, $result, $data)
    {
        $statement = $this->getEntityManager()->createQueryBuilder();
        
        if (isset($data['period']) && $data['period'] !== null) {
            $period = $data['period'];
        } else {
            $period = self::DEFAULT_PERIOD;
        }

        /* WARNING - don't remove any of the parentheses, add any spaces, or change any of the names without extensive testing */
        $expression = 'FROM_UNIXTIME(((FLOOR((UNIX_TIMESTAMP(r.created)/' . $period . ')))*' . $period . ')) as time, count(r.created) as counter';

        $statement->select($expression);
        $statement->addSelect('q.queue');
        $statement->from('Uecode\Bundle\QPushBundle\Entity\DoctrineMessageResult', 'r');
        $statement->innerJoin('Uecode\Bundle\QPushBundle\Entity\DoctrineMessage', 'q', Join::WITH, 'r.message = q.id');
        $statement->where('q.queue = :queue');
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