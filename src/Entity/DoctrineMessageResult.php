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
use Doctrine\ORM\Mapping\Index as Index;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * @ORM\Entity
 * @ORM\Table(name="uecode_qpush_message_result")
 */
class DoctrineMessageResult {

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
     * @ORM\ManyToOne(targetEntity="DoctineMessage")
     * @ORM\JoinColumn(name="queue_id", referencedColumnName="id")
     */
    private $doctrineMessage;
   
      /**
     * @ORM\Column(type="string") 
     */
    private $callable;

    /**
     * @ORM\Column(type="integer") 
     */
    private $result;

    function getId() {
        return $this->id;
    }

    function getCreated() {
        return $this->created;
    }

    function getDoctrineMessage() {
        return $this->doctrineMessage;
    }

    function getResult() {
        return $this->result;
    }

    function setCreated($created) {
        $this->created = $created;
        return $this;
    }

    function setDoctrineMessage($doctrineMessage) {
        $this->doctrineMessage = $doctrineMessage;
        return $this;
    }

    function setResult($result) {
        $this->result = $result;
        return $this;
    }
    
    function getCallable() {
        return $this->callable;
    }

    function setCallable($callable) {
        $this->callable = $callable;
        return $this;
    }
    
}
