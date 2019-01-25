<?php
/**
 * Copyright Talisman Innovations Ltd. (2019). All rights reserved.
 */

namespace Uecode\Bundle\QPushBundle\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Talisman\TideBundle\Service\TenantManager;

abstract class TenantAwareBaseRepository extends BaseRepository {

    protected $tenantManager;

    /**
     * @inheritdoc
     * @var TenantManager $tenantManager
     */
    public function __construct(EntityManagerInterface $em, $className, TenantManager $tenantManager) {
        parent::__construct($em, $className);
        $this->tenantManager = $tenantManager;
    }

    /**
     * @inheritdoc
     */
    public function createQueryBuilder($alias, $indexBy = null) {

        $qb = $this->repository->createQueryBuilder($alias, $indexBy);

        if ($this->tenantManager->getTenantId() === null) {
            return $qb;
        }

        $qb->where($qb->expr()->eq($alias . '.tenant', ':tenant'));
        $qb->setParameter('tenant', $this->tenantManager->getTenant());

        return $qb;
    }

    /**
     * @inheritdoc
     */
    public function find($id, $lockMode = null, $lockVersion = null) {

        $entity = $this->repository->find($id, $lockMode, $lockVersion);

        if ($this->tenantManager->getTenantId() === null) {
            return $entity;
        }

        if ($entity->getTenant() !== $this->tenantManager->getTenant()) {
            return null;
        }

        return $entity;
    }

    /**
     * @inheritdoc
     */
    public function findAll() {

        if ($this->tenantManager->getTenantId() === null) {
            return $this->repository->findAll();
        } else {
            return $this->repository->findBy($this->addTenantCriteria([]));
        }
    }

    /**
     * @inheritdoc
     */
    public function findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null) {

        if ($this->tenantManager->getTenantId() === null) {
            return $this->repository->findBy($criteria, $orderBy, $limit, $offset);
        } else {
            return $this->repository->findBy($this->addTenantCriteria($criteria), $orderBy, $limit, $offset);
        }
    }

    /**
     * @inheritdoc
     */
    public function findOneBy(array $criteria, array $orderBy = null) {

        if ($this->tenantManager->getTenantId() === null) {
            return $this->repository->findOneBy($criteria, $orderBy);
        } else {
            return $this->repository->findOneBy($this->addTenantCriteria($criteria), $orderBy);
        }
    }

    /**
     * Additional criteria for tenant to be added to passed in criteria
     *
     * @param array $criteria
     * @return array
     */
    protected function addTenantCriteria(array $criteria) {

        return array_merge($criteria, ['tenant' => $this->tenantManager->getTenant()]);
    }

}