<?php
/**
 * Copyright Talisman Innovations Ltd. (2019). All rights reserved.
 */

namespace Uecode\Bundle\QPushBundle\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;

abstract class BaseRepository {

    /** @var EntityManagerInterface */
    protected $em;

    /** @var EntityRepository */
    protected $repository;

    /*
     * @var EntityManagerInterface $em
     * @var string $className
     */
    public function __construct(EntityManagerInterface $em, $className) {
        $this->em = $em;
        $this->repository = $em->getRepository($className);
    }

    /**
     * @inheritdoc
     */
    public function createQueryBuilder($alias, $indexBy = null) {
        return $this->repository->createQueryBuilder($alias, $indexBy);
    }

    /**
     * @inheritdoc
     */
    public function find($id, $lockMode = null, $lockVersion = null) {
        return $this->repository->find($id, $lockMode, $lockVersion);
    }

    /**
     * @inheritdoc
     */
    public function findAll() {
        return $this->repository->findAll();
    }

    /**
     * @inheritdoc
     */
    public function findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null) {
        return $this->repository->findBy($criteria, $orderBy, $limit, $offset);
    }

    /**
     * @inheritdoc
     */
    public function findOneBy(array $criteria, array $orderBy = null) {
        return $this->repository->findOneBy($criteria, $orderBy);
    }

    /**
     * @inheritdoc
     */
    public function add($entity) {
        $this->em->persist($entity);
    }

    /**
     * @inheritdoc
     */
    public function save() {
        $this->em->flush();
    }

    /**
     * @inheritdoc
     */
    public function delete($entity) {
        $this->em->remove($entity);
    }

    /**
     * @inheritdoc
     */
    public function detach($entity) {
        $this->em->detach($entity);
    }

    /**
     * @inheritdoc
     */
    public function clear() {
        $this->em->clear();
    }

    /**
     * @inheritdoc
     */
    public function merge($entity) {
        return $this->em->merge($entity);
    }
}