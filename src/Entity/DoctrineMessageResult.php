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
 * @copyright   Talisman Innovations Ltd. (2017)
 * @license     Apache License, Version 2.0
 */

namespace Uecode\Bundle\QPushBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Talisman\TideBundle\Interfaces\TenantInterface;
use Talisman\TideBundle\Interfaces\TransactionInterface;
use Talisman\TideBundle\Traits\TenantTrait;
use Talisman\TideBundle\Traits\TransactionTrait;

/**
 * 
 * @ORM\Entity(repositoryClass="Uecode\Bundle\QPushBundle\Repository\DoctrineResultRepository")
 * @ORM\Table(name="uecode_qpush_message_result")
 * 
 */
class DoctrineMessageResult implements TenantInterface, TransactionInterface {

    use TenantTrait;
    use TransactionTrait;
    
    /**
     * @ORM\Id 
     * @ORM\GeneratedValue 
     * @ORM\Column(type="integer") 
     */
    private $id;

    /**
     * @var \DateTime $created
     *
     * @Gedmo\Timestampable(on="create")
     * @ORM\Column(type="datetime")
     */
    private $created;

    /**
     * @ORM\ManyToOne(targetEntity="DoctrineMessage", inversedBy="results")
     * @ORM\JoinColumn(name="message_id", referencedColumnName="id", 
     *  nullable=false, onDelete="CASCADE" )
     */
    private $message;
   
      /**
     * @ORM\Column(type="string") 
     */
    private $callable;

    /**
     * @ORM\Column(type="integer") 
     */
    private $result;
 
    public function getId() {
        return $this->id;
    }

    public function getCreated() {
        return $this->created;
    }

    public function getMessage() {
        return $this->message;
    }

    public function getCallable() {
        return $this->callable;
    }

    public function getResult() {
        return $this->result;
    }

    public function setId($id) {
        $this->id = $id;
    }

    public function setCreated(\DateTime $created) {
        $this->created = $created;
    }

    public function setMessage($message) {
        $this->message = $message;
    }

    public function setCallable($callable) {
        $this->callable = $callable;
    }

    public function setResult($result) {
        $this->result = $result;
    }


}
